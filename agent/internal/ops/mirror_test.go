package ops

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

// servePeerOn runs a node's mirror listener on a free loopback port (the
// real listener stays off in dry-run).
func servePeerOn(t *testing.T, env *Env) int {
	t.Helper()
	cfg, err := mirrorTLS(env, "", true)
	if err != nil {
		t.Fatal(err)
	}
	ln, err := tls.Listen("tcp", "127.0.0.1:0", cfg)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go servePeer(context.Background(), env, c.(*tls.Conn), func(string, ...any) {})
		}
	}()
	return ln.Addr().(*net.TCPAddr).Port
}

// pairedMirror: a primary and a standby paired over real TLS on loopback.
func pairedMirror(t *testing.T) (primary, standby *Env, primaryPort int) {
	t.Helper()
	primary, _ = testEnv(t)
	standby, _ = testEnv(t)
	out, err := call(t, primary, mirrorInit, map[string]any{"address": "127.0.0.1", "peer_address": "127.0.0.2"})
	if err != nil {
		t.Fatal(err)
	}
	code := out.(map[string]any)["code"].(string)
	if !strings.HasPrefix(code, "WSPM1-") {
		t.Fatalf("code %q", code)
	}
	primaryPort = servePeerOn(t, primary)
	_, err = call(t, standby, mirrorJoin, map[string]any{"primary": "127.0.0.1", "port": primaryPort, "code": code, "address": "127.0.0.1", "lan_address": "192.168.1.20"})
	if err != nil {
		t.Fatal(err)
	}
	// The primary dials the standby on its own listener.
	sport := servePeerOn(t, standby)
	if _, err := updateMirror(primary, func(s *mirrorState) error { s.Peer.Port = sport; return nil }); err != nil {
		t.Fatal(err)
	}
	return primary, standby, primaryPort
}

func TestMirrorPairing(t *testing.T) {
	primary, standby, _ := pairedMirror(t)
	p, s := currentMirror(primary), currentMirror(standby)
	if p.Role != rolePrimary || s.Role != roleStandby {
		t.Fatalf("roles %q %q", p.Role, s.Role)
	}
	if p.Peer == nil || p.Peer.NodeID != s.NodeID || s.Peer == nil || s.Peer.NodeID != p.NodeID {
		t.Fatalf("peers %+v %+v", p.Peer, s.Peer)
	}
	if p.Pairing != nil {
		t.Error("the code must be used up")
	}
	if s.Repl != p.Repl || s.Repl.MariaDB == "" {
		t.Error("both nodes need the replication passwords")
	}
	// The primary's recovery key went to the standby (it opens the snapshots).
	pk, _ := signingKey(primary)
	sk, err := peerRecoveryKey(standby)
	if err != nil || string(pk) != string(sk) {
		t.Fatalf("recovery key: %v", err)
	}
	// Both ways over mutual TLS.
	ctx := context.Background()
	them, err := helloPeer(ctx, standby)
	if err != nil || them.Role != rolePrimary || them.NodeID != p.NodeID {
		t.Fatalf("hello to the primary: %+v %v", them, err)
	}
	them, err = helloPeer(ctx, primary)
	if err != nil || them.Role != roleStandby {
		t.Fatalf("hello to the standby: %+v %v", them, err)
	}
	if b, _ := os.ReadFile(mirrorFile(standby, "node.key")); len(b) == 0 {
		t.Error("the standby's key stays on the standby")
	}
	if _, err := os.Stat(mirrorFile(standby, "ca.key")); err == nil {
		t.Error("the CA's key must never leave the primary")
	}
}

func TestMirrorPairingRefusesWrongCode(t *testing.T) {
	primary, _ := testEnv(t)
	standby, _ := testEnv(t)
	out, err := call(t, primary, mirrorInit, map[string]any{"address": "127.0.0.1", "peer_address": "127.0.0.2"})
	if err != nil {
		t.Fatal(err)
	}
	port := servePeerOn(t, primary)
	code := out.(map[string]any)["code"].(string)
	parts := strings.Split(code, "-")
	// Right certificate, wrong secret.
	bad := parts[0] + "-" + parts[1] + "-" + b32.EncodeToString(make([]byte, 15))
	if _, err := call(t, standby, mirrorJoin, map[string]any{"primary": "127.0.0.1", "port": port, "code": bad, "address": "127.0.0.1"}); err == nil || !strings.Contains(err.Error(), "wrong or expired") {
		t.Fatalf("wrong secret: %v", err)
	}
	// Another server's certificate: the pin refuses it before anything is sent.
	other := parts[0] + "-" + b32.EncodeToString(make([]byte, 20)) + "-" + parts[2]
	if _, err := call(t, standby, mirrorJoin, map[string]any{"primary": "127.0.0.1", "port": port, "code": other, "address": "127.0.0.1"}); err == nil {
		t.Fatal("a code for another certificate must not pair")
	}
	if currentMirror(standby).Role != roleNone {
		t.Error("a refused pairing leaves the standby out")
	}
	// The code still works once, and then never again.
	if _, err := call(t, standby, mirrorJoin, map[string]any{"primary": "127.0.0.1", "port": port, "code": code, "address": "127.0.0.1"}); err != nil {
		t.Fatal(err)
	}
	third, _ := testEnv(t)
	if _, err := call(t, third, mirrorJoin, map[string]any{"primary": "127.0.0.1", "port": port, "code": code, "address": "127.0.0.1"}); err == nil {
		t.Fatal("a used code must not pair a second standby")
	}
}

func TestMirrorRefusesUnpairedClients(t *testing.T) {
	primary, standby, port := pairedMirror(t)
	// A node of another mirror (its own CA) is not let in.
	stranger, _ := testEnv(t)
	if _, err := call(t, stranger, mirrorInit, map[string]any{"address": "127.0.0.1", "peer_address": "127.0.0.3"}); err != nil {
		t.Fatal(err)
	}
	primaryID := currentMirror(primary).NodeID
	if _, err := updateMirror(stranger, func(s *mirrorState) error {
		s.Peer = &mirrorPeer{NodeID: primaryID, Address: "127.0.0.1", Port: port}
		return nil
	}); err != nil {
		t.Fatal(err)
	}
	if _, err := helloPeer(context.Background(), stranger); err == nil {
		t.Fatal("a certificate of another CA must be refused")
	}
	_ = standby
}

func TestRsyncServerArgs(t *testing.T) {
	env, _ := testEnv(t)
	cfg := env.Cfg
	ok, err := rsyncServerArgs(cfg, []string{"rsync", "--server", "--sender", "-logDtprAHe.iLsfxCIvu", "--delete-delay", ".", "sites/"})
	if err != nil {
		t.Fatal(err)
	}
	if got := ok[len(ok)-1]; got != cfg.SitesRoot+"/" {
		t.Errorf("path %q", got)
	}
	sub, err := rsyncServerArgs(cfg, []string{"rsync", "--server", "--sender", "-a", ".", "sites/shop.example.com/"})
	if err != nil || sub[len(sub)-1] != filepath.Join(cfg.SitesRoot, "shop.example.com")+"/" {
		t.Fatalf("a site's own folder: %v %v", sub, err)
	}
	if _, err := rsyncServerArgs(cfg, []string{"rsync", "--server", "--sender", "-a", "--backup", "--backup-dir", "/var/backups/wspanel/mirror/rejoin-x/sites", ".", "sites/"}); err != nil {
		t.Errorf("a rejoin's backup dir: %v", err)
	}
	for _, bad := range [][]string{
		{"rsync", "--server", "--sender", "-a", "--backup-dir", "../x;y", ".", "sites/"},
		{"rsync", "--server", "-a", ".", "sites/"},                                      // receiving: would write here
		{"rsync", "--server", "--sender", "-aL", ".", "sites/"},                         // follows links out of the module
		{"rsync", "--server", "--sender", "-a", "--remove-source-files", ".", "sites/"}, // deletes on the primary
		{"rsync", "--server", "--sender", "-a", ".", "etc/"},                            // not a module
		{"rsync", "--server", "--sender", "-a", ".", "sites/../../etc/"},                // out of it
		{"rsync", "--server", "--sender", "-a", ".", "sites/", "mail/"},                 // two paths
		{"sh", "-c", "id"},
	} {
		if out, err := rsyncServerArgs(cfg, bad); err == nil {
			if len(bad) == 6 && strings.Contains(bad[5], "..") && strings.HasPrefix(out[len(out)-1], cfg.SitesRoot) {
				continue // cleaned into the module: fine
			}
			t.Errorf("%v must be refused (got %v)", bad, out)
		}
	}
}

func TestMirrorEpochFencesTheOlderPrimary(t *testing.T) {
	primary, standby, _ := pairedMirror(t)
	// The standby was promoted while the primary was away.
	if _, err := updateMirror(standby, func(s *mirrorState) error { s.Role, s.Epoch = rolePrimary, 2; return nil }); err != nil {
		t.Fatal(err)
	}
	if _, err := helloPeer(context.Background(), primary); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(5 * time.Second)
	for currentMirror(primary).Role != roleFenced {
		if time.Now().After(deadline) {
			t.Fatal("the older primary must step down")
		}
		time.Sleep(20 * time.Millisecond)
	}
	if currentMirror(primary).Fenced != "replaced" {
		t.Errorf("reason %q", currentMirror(primary).Fenced)
	}
}

func TestMirrorFenceTakesSitesOffTheEdge(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, mirrorInit, map[string]any{"address": "127.0.0.1", "peer_address": "127.0.0.2"}); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(env.Cfg.ProxySitesDir, 0o755); err != nil {
		t.Fatal(err)
	}
	edge := filepath.Join(env.Cfg.ProxySitesDir, "shop.example.com.caddy")
	if err := os.WriteFile(edge, []byte("shop.example.com {}\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := fenceLocal(context.Background(), env, "isolated"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(edge); err == nil {
		t.Error("the site's edge block must be put aside")
	}
	if !hasCall(dr, "systemctl reload "+env.Cfg.ProxyUnit) || !hasCall(dr, "systemctl stop postfix.service") {
		t.Errorf("edge reload and mail stop expected:\n%s", strings.Join(callLines(dr), "\n"))
	}
	if !mirrorSuppressesSites(env) {
		t.Error("a fenced node keeps its sites off")
	}
	if err := unfenceToPrimary(context.Background(), env); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(edge); err != nil {
		t.Error("serving again brings the edge block back")
	}
	if currentMirror(env).Role != rolePrimary {
		t.Error("role")
	}
}

func TestMirrorSettingsNeedAWitness(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, mirrorInit, map[string]any{"address": "10.0.0.1", "peer_address": "10.0.0.2"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mirrorSettings, map[string]any{"auto_failover": true}); code(err) != "conflict" {
		t.Fatalf("no witness: %v", err)
	}
	if _, err := call(t, env, mirrorSettings, map[string]any{"witness": []string{"10.0.0.254", "10.0.0.5:53"}, "auto_failover": true, "failover_after": 45}); err != nil {
		t.Fatal(err)
	}
	st := currentMirror(env)
	if !st.Auto || st.FailoverAfter != 45 || len(st.Witness) != 2 {
		t.Fatalf("%+v", st)
	}
	for _, bad := range []map[string]any{
		{"witness": []string{"not a host!"}},
		{"failover_after": 5},
		{"lan_address": "nope"},
	} {
		if _, err := call(t, env, mirrorSettings, bad); code(err) != "invalid_params" {
			t.Errorf("%v: %v", bad, err)
		}
	}
}

func TestWinDNSRecordsFollowTheSites(t *testing.T) {
	env, _ := testEnv(t)
	for _, d := range []string{"intranet.firma.local", "shop.example.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "laravel", "php_version": "8.3"}); err != nil {
			t.Fatal(err)
		}
	}
	st := &mirrorState{LANAddress: "192.168.1.10", WinDNS: &winDNSConfig{Zones: []string{"firma.local"}, TTL: 60, Extra: []string{"panel.firma.local", "firma.local"}}}
	recs := winDesired(env, st)
	got := map[string]string{}
	for _, r := range recs {
		got[r.Zone+"/"+r.Name] = r.Type + " " + strings.Join(r.Values, ",")
	}
	want := map[string]string{"firma.local/intranet": "A 192.168.1.10", "firma.local/panel": "A 192.168.1.10", "firma.local/@": "A 192.168.1.10"}
	if len(got) != len(want) {
		t.Fatalf("records %v", got)
	}
	for k, v := range want {
		if got[k] != v {
			t.Errorf("%s = %q, want %q", k, got[k], v)
		}
	}
	if z, n, ok := zoneOf([]string{"local", "firma.local"}, "a.b.firma.local"); !ok || z != "firma.local" || n != "a.b" {
		t.Errorf("longest zone: %s %s", z, n)
	}
}

func TestWinDNSSettings(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, mirrorWinDNSSet, map[string]any{"host": "dc1", "user": "wspanel-dns", "zones": []string{"firma.local"}}); code(err) != "conflict" {
		t.Fatalf("before a mirror: %v", err)
	}
	if _, err := call(t, env, mirrorInit, map[string]any{"address": "10.0.0.1", "peer_address": "10.0.0.2"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, mirrorWinDNSSet, map[string]any{"host": "host.docker.internal", "user": "FIRMA\\wspanel", "zones": []string{"firma.local"}})
	if code(err) != "invalid_params" {
		t.Fatalf("a backslash account must be refused (name@domain): %v %v", out, err)
	}
	out, err = call(t, env, mirrorWinDNSSet, map[string]any{"host": "host.docker.internal", "user": "wspanel-dns@firma.local", "zones": []string{"Firma.Local."}})
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(out)
	if !strings.Contains(string(b), `"zones":["firma.local"]`) || !strings.Contains(string(b), "public_key") {
		t.Errorf("%s", b)
	}
	if _, err := winCall(context.Background(), env, currentMirror(env).WinDNS, map[string]any{"action": "list"}); err == nil || !strings.Contains(err.Error(), "not trusted") {
		t.Errorf("an unpinned host must not be called: %v", err)
	}
}

func TestMariaDBServerIDsDiffer(t *testing.T) {
	a, b := mariadbServerID("node-aaaaaaaaaaaa"), mariadbServerID("node-bbbbbbbbbbbb")
	if a == b || a == 0 || b == 0 {
		t.Fatalf("%d %d", a, b)
	}
	if !strings.Contains(string(mariadbMirrorConfig("node-a", true)), "read_only = ON") || strings.Contains(string(mariadbMirrorConfig("node-a", false)), "read_only") {
		t.Error("only the replica is read-only")
	}
	_ = strconv.Itoa
}

func TestServerBackupLeavesTheMirrorOut(t *testing.T) {
	env, _ := testEnv(t)
	if !skipServerPath(env, mirrorFile(env, "mirror.json")) || !skipServerPath(env, filepath.Join(env.Cfg.MariaDBConfDir, mariadbMirrorCf)) ||
		!skipServerPath(env, filepath.Join(env.Cfg.SystemdDir, "wspanel-mirror-kv-web_x.service")) {
		t.Error("a node's mirror files are its own")
	}
	if skipServerPath(env, filepath.Join(env.Cfg.StateDir, "sites", "x.json")) {
		t.Error("the sites' state is in the backup")
	}
}
