package ops

import (
	"archive/tar"
	"context"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"wspanel/agent/internal/executor"
)

// The mirror's audit (2026-11-27): the cases that could leave two nodes
// serving, nobody serving, or the copy damaged.

func TestAPrimaryStepsDownForAPromotionInProgress(t *testing.T) {
	primary, standby, _ := pairedMirror(t)
	// The standby saved its new epoch and is applying the state (minutes):
	// still role standby, but taking over.
	next := currentMirror(primary).Epoch + 1
	if _, err := updateMirror(standby, func(s *mirrorState) error { s.Epoch = next; return nil }); err != nil {
		t.Fatal(err)
	}
	if _, err := helloPeer(context.Background(), primary); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(5 * time.Second)
	for currentMirror(primary).Role != roleFenced {
		if time.Now().After(deadline) {
			t.Fatal("a primary hearing of a newer epoch must step down, whatever the other node's role")
		}
		time.Sleep(20 * time.Millisecond)
	}
}

func TestOnlyAQuietPeerConfirmsNotServing(t *testing.T) {
	set := func(role, action string, epoch int64) {
		mirrorRuntime.mu.Lock()
		mirrorRuntime.PeerUp, mirrorRuntime.PeerSeen = true, time.Now()
		mirrorRuntime.Peer = &helloInfo{Role: role, Action: action, Epoch: epoch}
		mirrorRuntime.mu.Unlock()
	}
	t.Cleanup(func() {
		mirrorRuntime.mu.Lock()
		mirrorRuntime.PeerUp, mirrorRuntime.Peer = false, nil
		mirrorRuntime.mu.Unlock()
	})
	for _, c := range []struct {
		role, action string
		epoch        int64
		want         bool
	}{
		{roleStandby, "", 3, true},
		{roleFenced, "", 2, true}, // an agent restart on a promoted primary: the old one is fenced
		{roleStandby, "", 4, false},
		{roleStandby, "promoting", 3, false},
		{roleStandby, "switchover", 3, false},
		{rolePrimary, "", 3, false},
	} {
		set(c.role, c.action, c.epoch)
		if got := peerConfirmsNotServing(3); got != c.want {
			t.Errorf("%s/%q/epoch %d: %v, want %v", c.role, c.action, c.epoch, got, c.want)
		}
	}
}

func TestRemovingTheLastWitnessNeedsAutomaticFailoverOff(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, mirrorInit, map[string]any{"address": "10.0.0.1", "peer_address": "10.0.0.2"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mirrorSettings, map[string]any{"witness": []string{"10.0.0.254"}, "auto_failover": true}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mirrorSettings, map[string]any{"witness": []string{}}); code(err) != "conflict" {
		t.Fatalf("witness removed with automatic failover on: %v", err)
	}
	if st := currentMirror(env); !st.Auto || len(st.Witness) != 1 {
		t.Fatalf("a refused change must change nothing: %+v", st)
	}
	if _, err := call(t, env, mirrorSettings, map[string]any{"witness": []string{}, "auto_failover": false}); err != nil {
		t.Fatalf("both in one request: %v", err)
	}
}

func TestAPromotionDropsSitesThePrimaryDeleted(t *testing.T) {
	env, _ := testEnv(t)
	for _, d := range []string{"kept.example.com", "gone.example.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "laravel", "php_version": "8.3"}); err != nil {
			t.Fatal(err)
		}
	}
	// This node's old blocks, put aside while it did not serve.
	if err := os.MkdirAll(fencedEdgeDir(env), 0o700); err != nil {
		t.Fatal(err)
	}
	for _, d := range []string{"kept.example.com", "gone.example.com"} {
		_ = os.Remove(filepath.Join(env.Cfg.ProxySitesDir, d+".caddy"))
		if err := os.WriteFile(filepath.Join(fencedEdgeDir(env), d+".caddy"), []byte(d+" {}\n"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	env.Cfg.BackupDir = t.TempDir()
	rep := &RestoreReport{}
	pruneStaleSites(env, []string{"kept.example.com"}, rep)
	restoreFencedEdges(env, map[string]bool{"kept.example.com": true})

	if fileExists(siteStatePath(env.Cfg, "gone.example.com")) {
		t.Error("the deleted site's state must go aside")
	}
	if !fileExists(siteStatePath(env.Cfg, "kept.example.com")) {
		t.Error("the primary's site stays")
	}
	if fileExists(filepath.Join(env.Cfg.ProxySitesDir, "gone.example.com.caddy")) {
		t.Error("the deleted site must not come back to the edge")
	}
	if !fileExists(filepath.Join(env.Cfg.ProxySitesDir, "kept.example.com.caddy")) {
		t.Error("the primary's site serves again")
	}
	if len(rep.Restored) == 0 || !strings.Contains(strings.Join(rep.Restored, " "), "1 sites") {
		t.Errorf("report: %v", rep.Restored)
	}
}

func TestGameServersStayOnTheirNode(t *testing.T) {
	env, _ := testEnv(t)
	if err := saveGame(env, gameState{ID: 7, Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, MemoryMB: 1024}); err != nil {
		t.Fatal(err)
	}
	games := filepath.Join(gameStateDir(env), "7.json")
	if !fileExists(games) {
		t.Fatalf("no game state at %s", games)
	}
	// The mirror's snapshot leaves them out; a server backup keeps them.
	dir := t.TempDir()
	if _, err := makeServerArchive(context.Background(), env, dir, "snapshot-", true); err != nil {
		t.Fatal(err)
	}
	snap, _ := filepath.Glob(filepath.Join(dir, "snapshot-*.wspbak"))
	if len(snap) != 1 {
		t.Fatalf("snapshot: %v", snap)
	}
	key, err := signingKey(env)
	if err != nil {
		t.Fatal(err)
	}
	man, err := readServerManifest(snap[0], key)
	if err != nil {
		t.Fatal(err)
	}
	if len(man.Games) != 0 || len(man.GameUnits) != 0 {
		t.Errorf("the snapshot lists game servers: %v %v", man.Games, man.GameUnits)
	}
	for _, u := range man.Users {
		if strings.HasPrefix(u.Name, "wsg_") {
			t.Errorf("the snapshot carries the game user %s", u.Name)
		}
	}
	err = openServerArchive(snap[0], key, func(_ *tar.Reader, hdr *tar.Header) (bool, error) {
		if target, ok := configPath(hdr.Name); ok && strings.HasPrefix(target, gameStateDir(env)) {
			t.Errorf("the snapshot carries %s", target)
		}
		return false, nil
	})
	if err != nil {
		t.Fatal(err)
	}

	// Off-site, each node has its own folder.
	if got := gameTarget(env, 7).dir; got != "games/7" {
		t.Errorf("outside a mirror: %s", got)
	}
	if err := saveMirror(env, &mirrorState{Role: roleStandby, NodeID: "node-b"}); err != nil {
		t.Fatal(err)
	}
	if got := gameTarget(env, 7).dir; got != "games/node-b/7" {
		t.Errorf("in a mirror: %s", got)
	}
}

func TestErrantGTIDsAreThisServersOwn(t *testing.T) {
	for row, want := range map[string]string{
		"66902441\t0-3494702285-76":              "",
		"66902441\t0-66902441-77":                "0-66902441-77",
		"66902441\t0-3494702285-80,1-66902441-3": "1-66902441-3",
		"66902441\t":                             "",
		"":                                       "",
		"7\t0-17-5,0-7-6":                        "0-7-6",
	} {
		if got := errantGTIDs(row); got != want {
			t.Errorf("%q: %q, want %q", row, got, want)
		}
	}
}

func TestTheCopyKeepsTheSitesTempFolder(t *testing.T) {
	// The folder itself is copied (PHP-FPM's TMPDIR, uploads, sessions), its contents are not.
	if slices.Contains(siteSyncExcludes, "/*/tmp/") || !slices.Contains(siteSyncExcludes, "/*/tmp/*") {
		t.Errorf("excludes %v: a site's tmp/ must arrive empty, not be left out", siteSyncExcludes)
	}
}

func TestTheStartGate(t *testing.T) {
	now := time.Now()
	st := &mirrorState{Role: roleFenced, Fenced: reasonStarting, Epoch: 5, FailoverAfter: 60, Since: now.Add(-10 * time.Second), Witness: []string{"10.0.0.1"}}
	for _, c := range []struct {
		name      string
		them      *helloInfo
		since     time.Duration
		witnessOK bool
		vipFree   bool
		want      startVerdict
	}{
		{"the standby confirms", &helloInfo{Role: roleStandby, Epoch: 5}, 10 * time.Second, true, true, startServe},
		{"a fenced peer of an older epoch", &helloInfo{Role: roleFenced, Epoch: 4}, 10 * time.Second, true, true, startServe},
		{"the other took over", &helloInfo{Role: rolePrimary, Epoch: 6}, 10 * time.Second, true, true, startReplaced},
		{"a newer epoch while it promotes", &helloInfo{Role: roleStandby, Epoch: 6, Action: "promoting"}, 10 * time.Second, true, true, startReplaced},
		{"a switchover running there", &helloInfo{Role: roleStandby, Epoch: 5, Action: "switchover"}, 10 * time.Second, true, true, startWait},
		{"silent, not long enough", nil, 30 * time.Second, true, true, startWait},
		{"silent long enough, witness and free address", nil, 61 * time.Second, true, true, startServe},
		{"silent, but no witness answers", nil, 61 * time.Second, false, true, startWait},
		{"silent, but someone holds the floating IP", nil, 61 * time.Second, true, false, startWait},
	} {
		s := *st
		s.Since = now.Add(-c.since)
		if got := startDecision(&s, c.them, now, c.witnessOK, c.vipFree); got != c.want {
			t.Errorf("%s: %v, want %v", c.name, got, c.want)
		}
	}
	// Without a witness configured (manual failover) the wait and the free address suffice.
	s := *st
	s.Witness, s.Since = nil, now.Add(-61*time.Second)
	if got := startDecision(&s, nil, now, false, true); got != startServe {
		t.Errorf("no witness configured: %v", got)
	}
}

func TestOnlyAPairedPrimaryIsGated(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.DryRun = false // the gate is off in dry runs
	t.Cleanup(func() { env.Cfg.DryRun = true })
	marker := bootMarker
	bootMarker = filepath.Join(t.TempDir(), "booted")
	t.Cleanup(func() { bootMarker = marker })
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1"}) // no peer: a mirror being set up
	MirrorStartGate(context.Background(), env, func(string, ...any) {})
	if r := currentMirror(env).Role; r != rolePrimary {
		t.Errorf("an unpaired primary was gated: %s", r)
	}
	if !fileExists(bootMarker) {
		t.Error("the first start of the machine leaves the marker")
	}
	// The agent restarts (the marker is there): a paired primary keeps serving.
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1", Peer: &mirrorPeer{NodeID: "n2", Address: "10.0.0.2"}})
	MirrorStartGate(context.Background(), env, func(string, ...any) {})
	if r := currentMirror(env).Role; r != rolePrimary {
		t.Errorf("an agent restart gated a serving primary: %s", r)
	}
}

func TestTheEdgeGatesAPairedPrimaryAtBoot(t *testing.T) {
	env, _ := testEnv(t)
	marker := bootMarker
	bootMarker = filepath.Join(t.TempDir(), "booted")
	t.Cleanup(func() { bootMarker = marker })
	env.Cfg.MariaDBConfDir = t.TempDir()
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1", Epoch: 4, Peer: &mirrorPeer{NodeID: "n2", Address: "10.0.0.2"}})
	if err := os.MkdirAll(env.Cfg.ProxySitesDir, 0o755); err != nil {
		t.Fatal(err)
	}
	edge := filepath.Join(env.Cfg.ProxySitesDir, "shop.example.com.caddy")
	_ = os.WriteFile(edge, []byte("shop.example.com {}\n"), 0o644)

	if err := MirrorBootGate(env); err != nil {
		t.Fatal(err)
	}
	st := currentMirror(env)
	if st.Role != roleFenced || st.Fenced != reasonStarting {
		t.Fatalf("after the edge's gate: %s/%s", st.Role, st.Fenced)
	}
	if fileExists(edge) {
		t.Error("the site's block must be aside before the edge starts")
	}
	// The agent starts next: the boot is gated already, it goes on fenced.
	MirrorStartGate(context.Background(), env, func(string, ...any) {})
	if st := currentMirror(env); st.Role != roleFenced || st.Fenced != reasonStarting {
		t.Errorf("after the agent's start: %s/%s", st.Role, st.Fenced)
	}
	// Later starts of the edge in the same boot (a restart) change nothing.
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1", Peer: &mirrorPeer{NodeID: "n2"}})
	_ = os.WriteFile(edge, []byte("shop.example.com {}\n"), 0o644)
	if err := MirrorBootGate(env); err != nil {
		t.Fatal(err)
	}
	if currentMirror(env).Role != rolePrimary || !fileExists(edge) {
		t.Error("an edge restart after the boot gated a serving primary")
	}
}

func TestALateFenceEnforcementLeavesAServingNodeAlone(t *testing.T) {
	// At a start the edge renderer's enforcement may run after the loop
	// already let the node serve: it must not make the databases read-only
	// or stop the sites' units of a serving primary.
	env, dry := testEnv(t)
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1", Peer: &mirrorPeer{NodeID: "n2"}})
	before := len(dry.Calls())
	enforceFence(context.Background(), env)
	for _, c := range dry.Calls()[before:] {
		if strings.Contains(c.Stdin, "read_only") || strings.HasPrefix(c.String(), "systemctl stop") {
			t.Errorf("a serving primary was fenced late: %s", c)
		}
	}
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1"})
	keepFenced(context.Background(), env)
	for _, c := range dry.Calls()[before:] {
		if strings.Contains(c.Stdin, "read_only") {
			t.Errorf("keepFenced touched a serving primary: %s", c)
		}
	}
}

func TestAServingPrimaryTakesWritesAgain(t *testing.T) {
	env, dry := testEnv(t)
	env.Cfg.DBEngines = []string{"mariadb"}
	env.Cfg.MariaDBConfDir = t.TempDir()
	dry.Responder = func(c executor.Call) (executor.Result, error) {
		if strings.Contains(c.Stdin, "SELECT @@read_only") {
			return executor.Result{Stdout: "1\n"}, nil
		}
		return executor.Result{}, nil
	}
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1", Peer: &mirrorPeer{NodeID: "n2"}})
	keepServing(context.Background(), env)
	found := false
	for _, c := range dry.Calls() {
		found = found || strings.Contains(c.Stdin, "read_only = OFF")
	}
	if !found {
		t.Error("a primary with a read-only MariaDB must be made writable")
	}
	// Not on a fenced node.
	_ = saveMirror(env, &mirrorState{Role: roleFenced, NodeID: "n1", Peer: &mirrorPeer{NodeID: "n2"}})
	n := len(dry.Calls())
	keepServing(context.Background(), env)
	for _, c := range dry.Calls()[n:] {
		if strings.Contains(c.Stdin, "read_only = OFF") {
			t.Error("a fenced node must stay read-only")
		}
	}
}

func TestASwitchoverSnapshotCarriesTheFencedEdges(t *testing.T) {
	// The primary is fenced for a planned switchover (its edge blocks aside)
	// when the standby takes its last snapshot: the blocks must be in it,
	// under the sites' folder, or the new primary serves nothing.
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	_ = saveMirror(env, &mirrorState{Role: roleFenced, Fenced: "switchover", NodeID: "n1", Peer: &mirrorPeer{NodeID: "n2"}})
	if err := putEdgesAside(env); err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	if _, err := makeServerArchive(context.Background(), env, dir, "snapshot-", true); err != nil {
		t.Fatal(err)
	}
	snap, _ := filepath.Glob(filepath.Join(dir, "snapshot-*.wspbak"))
	key, _ := signingKey(env)
	edge := filepath.Join(env.Cfg.ProxySitesDir, "shop.example.com.caddy")
	found, dirEntry := false, false
	if err := openServerArchive(snap[0], key, func(_ *tar.Reader, hdr *tar.Header) (bool, error) {
		target, _ := configPath(hdr.Name)
		found = found || target == edge
		dirEntry = dirEntry || (target == env.Cfg.ProxySitesDir && hdr.Typeflag == tar.TypeDir && hdr.Mode&0o077 == 0)
		return false, nil
	}); err != nil {
		t.Fatal(err)
	}
	if !found {
		t.Errorf("the snapshot lacks %s", edge)
	}
	if dirEntry {
		t.Error("the aside folder's own 0700 mode must not land on the sites' folder")
	}
}
