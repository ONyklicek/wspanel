package ops

import (
	"bufio"
	"context"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Roles change here: the standby is promoted (by hand, by the watch, or in
// a planned switchover), the old primary is fenced (it serves nothing: its
// sites' edge blocks are put aside, its mail stops, its databases are
// read-only, its sites' processes stop) and later rejoins as the standby of
// the new primary.

// node-local keys of the panel's .env: the promoted node keeps its own.
var localEnvKeys = []string{"PANEL_PUBLIC_IPV4", "PANEL_PUBLIC_IPV6", "PANEL_SITES_HTTPS_PORT", "PANEL_SSH_PORT", "PANEL_PORT",
	"PANEL_VERSION", "PANEL_INSTALL_METHOD", "PANEL_RELEASE_REPO", "APP_URL"}

const hookDir = "/etc/wspanel/mirror/hooks"

func fenceMarker(env *Env) string   { return mirrorFile(env, "fenced") }
// fencedEdgeDir: beside the edge's sites (one filesystem: a rename, never a
// copy; the agent's state directory is a mount of its own). The edge imports
// sites/*.caddy only.
func fencedEdgeDir(env *Env) string {
	return filepath.Join(filepath.Dir(env.Cfg.ProxySitesDir), "sites-fenced")
}

// mirrorSuppressesSites: this node must not serve the sites (fenced, or a
// standby that holds sites' configuration).
func mirrorSuppressesSites(env *Env) bool {
	r := currentMirror(env).Role
	return r == roleFenced || r == roleStandby
}

// ---------------------------------------------------------------- promotion

// mirrorPromote (panel / CLI on the standby): with the primary reachable it
// is a planned switchover (nothing is lost); without it, the standby takes
// over with what it has.
func mirrorPromote(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Confirm string `json:"confirm"` // this node's id
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	if st.Role != roleStandby {
		return nil, protocol.Conflict("only a standby can be promoted (this node is " + orNone(st.Role) + ")")
	}
	if p.Confirm != st.NodeID {
		return nil, protocol.Invalid("confirm: this node's id (" + st.NodeID + ")")
	}
	if !fsx.Exists(mirrorFile(env, "snapshot.wspbak")) {
		return nil, protocol.Conflict("this standby has no copy of the primary's state yet (the first sync has not finished)")
	}
	if _, err := helloPeer(ctx, env); err == nil {
		return promotePlanned(ctx, env, "ručně, primár předal provoz")
	}
	return promoteLocal(ctx, env, "ručně: primár neodpovídá", false)
}

func orNone(s string) string {
	if s == "" {
		return "not in a mirror"
	}
	return s
}

// promotePlanned: the primary stops taking writes, this node catches up
// with it (databases, files, state) and takes over.
func promotePlanned(ctx context.Context, env *Env, reason string) (any, error) {
	st := currentMirror(env)
	var pos struct {
		MariaDB  string `json:"mariadb_gtid"`
		Postgres string `json:"postgres_lsn"`
	}
	if err := peerCall(ctx, env, "fence", map[string]any{"epoch": st.Epoch + 1, "reason": "switchover", "positions": true}, &pos); err != nil {
		return nil, fmt.Errorf("the primary did not hand over: %w", err)
	}
	mirrorRuntime.event("info", "přepnutí: primár je jen pro čtení, dohánění")
	if err := catchUp(ctx, env, pos.MariaDB, pos.Postgres); err != nil {
		mirrorRuntime.event("warning", "přepnutí: %v", err)
		return nil, err
	}
	return promoteLocal(ctx, env, reason, false)
}

// catchUp: a last sync cycle and the replicas at the primary's positions.
func catchUp(ctx context.Context, env *Env, gtid, lsn string) error {
	if err := syncCycle(ctx, env); err != nil {
		return fmt.Errorf("the last sync: %w", err)
	}
	deadline := time.Now().Add(5 * time.Minute)
	for {
		ok := true
		if gtid != "" && slices.Contains(env.Cfg.DBEngines, "mariadb") {
			out, err := (mariadb{}).sql(ctx, env, fmt.Sprintf("SELECT MASTER_GTID_WAIT('%s', 1)", sqlQuote(gtid)))
			ok = ok && err == nil && strings.TrimSpace(out) == "0"
		}
		if lsn != "" && slices.Contains(env.Cfg.DBEngines, "postgresql") {
			out, err := (postgres{}).psql(ctx, env, "postgres", fmt.Sprintf("SELECT pg_last_wal_replay_lsn() >= '%s'::pg_lsn", sqlQuote(lsn)))
			ok = ok && err == nil && strings.TrimSpace(out) == "t"
		}
		if ok {
			return nil
		}
		if time.Now().After(deadline) {
			return errors.New("the databases did not catch up with the primary within 5 minutes; the primary stays read-only (promote again, or rejoin it)")
		}
		time.Sleep(time.Second)
	}
}

// promoteLocal makes this standby the primary: its replicas take writes,
// the primary's state is applied, the sites start, DNS follows.
func promoteLocal(ctx context.Context, env *Env, reason string, auto bool) (*RestoreReport, error) {
	if !mirrorRuntime.setAction("promoting") {
		return nil, protocol.Conflict("another change of role is running")
	}
	defer mirrorRuntime.setAction("")
	st := currentMirror(env)
	if st.Role != roleStandby {
		return nil, protocol.Conflict("only a standby can be promoted")
	}
	key, err := peerRecoveryKey(env)
	if err != nil {
		return nil, err
	}
	snap := mirrorFile(env, "snapshot.wspbak")
	if !fsx.Exists(snap) {
		return nil, protocol.Conflict("no copy of the primary's state yet")
	}
	mirrorRuntime.event("danger", "povýšení tohoto serveru: %s", reason)
	epoch := st.Epoch + 1
	if _, err := updateMirror(env, func(s *mirrorState) error { s.Epoch = epoch; return nil }); err != nil {
		return nil, err
	}
	// The old primary steps down if it can still hear us.
	fctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	_ = peerCall(fctx, env, "fence", map[string]any{"epoch": epoch, "reason": "replaced"}, nil)
	cancel()

	rep := &RestoreReport{}
	promoteDatabases(ctx, env, st, rep)
	promoteKVReplicas(ctx, env, rep)
	stopTunnels()

	// The panel stops while its database and configuration are replaced.
	for _, u := range []string{"panel-scheduler.service", "panel-queue.service", "panel-web.service"} {
		_, _ = env.Exec.Run(ctx, "systemctl", "stop", u)
	}
	local := readEnvKeys(filepath.Join(panelShared, ".env"), localEnvKeys)
	man, srep, err := restoreServerStateWith(ctx, env, snap, key, restoreOpts{
		skipPaths:      []string{installFlags},
		skipAccountsOf: []string{"mariadb", "postgresql"}, // they came with replication
	})
	rep.Restored = append(rep.Restored, srep.Restored...)
	rep.Problems = append(rep.Problems, srep.Problems...)
	if err != nil {
		rep.problem("applying the primary's state: %v", err)
	}
	if err := writeEnvKeys(env, filepath.Join(panelShared, ".env"), local); err != nil {
		rep.problem("the panel's own settings: %v", err)
	}
	restoreMirrorDumps(ctx, env, rep)
	artisan(ctx, env, rep, "migrate", "--force")
	artisan(ctx, env, rep, "optimize")

	// Roles first: from here on the edge may serve the sites.
	_, _ = updateMirror(env, func(s *mirrorState) error {
		s.Role, s.Since, s.PromotedAt, s.Fenced = rolePrimary, time.Now().UTC(), time.Now().UTC(), ""
		return nil
	})
	_ = os.Remove(fenceMarker(env))
	startServing(ctx, env, man.Enabled, rep)
	dropStaleKVData(ctx, env)
	artisan(ctx, env, rep, "panel:sync")
	runHooks(ctx, env, "promote", rep)
	go reconcileWinDNS(context.WithoutCancel(ctx), env, func(string, ...any) {})
	mirrorRuntime.event("info", "tento server je teď primár (epocha %d)%s", epoch, problemsSuffix(rep))
	_ = auto
	mirrorWake()
	return rep, nil
}

func problemsSuffix(rep *RestoreReport) string {
	if len(rep.Problems) == 0 {
		return ""
	}
	return "; problémy: " + strings.Join(rep.Problems, "; ")
}

// startServing: the sites' units, PHP-FPM, the web tiers, the edge, mail.
func startServing(ctx context.Context, env *Env, enabled []string, rep *RestoreReport) {
	_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
	restoreFencedEdges(env)
	for _, v := range env.Cfg.PHPVersions {
		_, _ = env.Exec.Run(ctx, "systemctl", "restart", config.ForVersion(env.Cfg.FPMUnit, v))
	}
	started := 0
	for _, u := range enabled {
		if strings.HasPrefix(u, "wspanel-mirror-") {
			continue
		}
		_, _ = env.Exec.Run(ctx, "systemctl", "enable", u)
		if _, err := env.Exec.Run(ctx, "systemctl", "restart", u); err != nil {
			rep.problem("unit %s: %v", u, err)
		} else {
			started++
		}
	}
	rep.ok("%d units of the sites started", started)
	for _, u := range []string{"panel-static.service", "panel-web.service", env.Cfg.ProxyUnit} {
		if _, err := env.Exec.Run(ctx, "systemctl", "restart", u); err != nil {
			rep.problem("%s: %v", u, err)
		}
	}
	for _, s := range []string{"postfix", "dovecot", "rspamd", "opendkim"} {
		if res, err := env.Exec.Run(ctx, "systemctl", "is-enabled", s+".service"); err == nil && strings.TrimSpace(res.Stdout) == "enabled" {
			_, _ = env.Exec.Run(ctx, "systemctl", "restart", s+".service")
		}
	}
	for _, u := range []string{"panel-scheduler.service", "panel-queue.service"} {
		_, _ = env.Exec.Run(ctx, "systemctl", "start", u)
	}
}

// restoreMirrorDumps: MongoDB and the dedicated servers' databases from the
// newest dumps (into the shared server, as a server restore does).
func restoreMirrorDumps(ctx context.Context, env *Env, rep *RestoreReport) {
	entries, _ := os.ReadDir(dumpsDir(env))
	n := 0
	for _, e := range entries {
		engine, rest, ok := strings.Cut(e.Name(), "--")
		if !ok || strings.HasPrefix(e.Name(), ".") {
			continue
		}
		eng, err := engineFor(env, engine)
		if err != nil {
			rep.problem("database %s: %v", e.Name(), err)
			continue
		}
		name := strings.TrimSuffix(rest, eng.ext())
		d := dbRef{Engine: engine, Name: name}
		if err := ensureDatabase(ctx, env, d); err != nil {
			rep.problem("database %s/%s: %v", engine, name, err)
			continue
		}
		if err := restoreDump(ctx, env, eng, name, filepath.Join(dumpsDir(env), e.Name())); err != nil {
			rep.problem("database %s/%s: %v", engine, name, err)
			continue
		}
		n++
	}
	if n > 0 {
		rep.ok("%d databases from the newest dumps (MongoDB, dedicated servers)", n)
	}
}

// artisan runs a panel command as the panel's user.
func artisan(ctx context.Context, env *Env, rep *RestoreReport, args ...string) {
	php := "/usr/bin/php" // the panel's PHP (install.sh points the alternative at it)
	full := append([]string{"--reuid=panel", "--regid=panel", "--init-groups", "--reset-env", "--", php, "/srv/panel/current/artisan"}, args...)
	actx, cancel := context.WithTimeout(ctx, 10*time.Minute)
	defer cancel()
	if _, err := env.Exec.RunIO(actx, nil, nil, "setpriv", full...); err != nil {
		rep.problem("artisan %s: %v", args[0], err)
	}
}

// runHooks: the administrator's own scripts (a router's port forward, a
// load balancer): root's executables in hooks/<event>.d, run in name order.
func runHooks(ctx context.Context, env *Env, event string, rep *RestoreReport) {
	dir := filepath.Join(hookDir, event+".d")
	entries, err := os.ReadDir(dir)
	if err != nil {
		return
	}
	st := currentMirror(env)
	for _, e := range entries {
		path := filepath.Join(dir, e.Name())
		fi, err := os.Lstat(path)
		if err != nil || !fi.Mode().IsRegular() || fi.Mode()&0o111 == 0 || fi.Mode()&0o022 != 0 {
			continue
		}
		if sys, ok := fi.Sys().(*syscall.Stat_t); !ok || sys.Uid != 0 {
			continue
		}
		hctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
		_, err = env.Exec.RunIO(hctx, nil, nil, "mirror-hook", path, event, st.NodeID, st.LANAddress, st.PublicIPv4)
		cancel()
		if err != nil {
			rep.problem("hook %s: %v", e.Name(), err)
		} else {
			rep.ok("hook %s", e.Name())
		}
	}
}

// ---------------------------------------------------------------- the panel's .env

func readEnvKeys(path string, keys []string) map[string]string {
	out := map[string]string{}
	f, err := os.Open(path)
	if err != nil {
		return out
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		k, v, ok := strings.Cut(sc.Text(), "=")
		if ok && slices.Contains(keys, k) {
			out[k] = v
		}
	}
	return out
}

// writeEnvKeys puts the given keys back into the .env (owner panel, 0600).
func writeEnvKeys(env *Env, path string, values map[string]string) error {
	if len(values) == 0 {
		return nil
	}
	b, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	lines := strings.Split(strings.TrimRight(string(b), "\n"), "\n")
	seen := map[string]bool{}
	for i, l := range lines {
		k, _, ok := strings.Cut(l, "=")
		if v, has := values[k]; ok && has {
			lines[i], seen[k] = k+"="+v, true
		}
	}
	for _, k := range sortedKeys(values) {
		if !seen[k] && envKeyRe.MatchString(k) {
			lines = append(lines, k+"="+values[k])
		}
	}
	owner, err := lookupOwner(env, "panel")
	if err != nil {
		owner = fsx.Root
	}
	return env.FS.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o600, owner)
}

// ---------------------------------------------------------------- fencing

// peerFence: the other node took over (or asks for a switchover).
func peerFence(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Epoch     int64  `json:"epoch"`
		Reason    string `json:"reason"`
		Positions bool   `json:"positions"`
	}
	if err := json.Unmarshal(raw, &p); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	if st.Role != rolePrimary && st.Role != roleFenced {
		return map[string]any{"fenced": false}, nil
	}
	if p.Epoch <= st.Epoch {
		return nil, fmt.Errorf("epoch %d is not newer than this node's %d", p.Epoch, st.Epoch)
	}
	reason := "replaced"
	if p.Reason == "switchover" {
		reason = "switchover"
	}
	if err := fenceLocal(ctx, env, reason); err != nil {
		return nil, err
	}
	out := map[string]any{"fenced": true}
	if p.Positions {
		if slices.Contains(env.Cfg.DBEngines, "mariadb") {
			if v, err := (mariadb{}).sql(ctx, env, "SELECT @@gtid_binlog_pos"); err == nil {
				out["mariadb_gtid"] = strings.TrimSpace(v)
			}
		}
		if slices.Contains(env.Cfg.DBEngines, "postgresql") {
			if v, err := (postgres{}).psql(ctx, env, "postgres", "SELECT pg_current_wal_lsn()"); err == nil {
				out["postgres_lsn"] = strings.TrimSpace(v)
			}
		}
	}
	return out, nil
}

// fenceLocal: this node stops serving (role fenced).
func fenceLocal(ctx context.Context, env *Env, reason string) error {
	if _, err := updateMirror(env, func(s *mirrorState) error {
		if s.Role != rolePrimary && s.Role != roleFenced {
			return protocol.Conflict("only a primary is fenced")
		}
		s.Role, s.Fenced, s.Since = roleFenced, reason, time.Now().UTC()
		return nil
	}); err != nil {
		return err
	}
	mirrorRuntime.event("danger", "tento server přestal obsluhovat weby (%s)", reason)
	_ = writeMirrorFile(env, "fenced", []byte(reason+"\n"))
	enforceFence(ctx, env)
	return nil
}

// enforceFence: also at the agent's start (the units started with the system).
func enforceFence(ctx context.Context, env *Env) {
	if slices.Contains(env.Cfg.DBEngines, "mariadb") {
		// Read-only also when MariaDB starts again (a reboot).
		_, _ = writeFileIfChanged(env, filepath.Join(env.Cfg.MariaDBConfDir, mariadbMirrorCf), mariadbMirrorConfig(currentMirror(env).NodeID, true), 0o644, fsx.Root)
	}
	databasesReadOnly(ctx, env, true)
	if err := putEdgesAside(env); err != nil {
		mirrorRuntime.event("warning", "weby se nepodařilo stáhnout z edge: %v", err)
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "reload", env.Cfg.ProxyUnit)
	for _, s := range []string{"postfix", "dovecot"} {
		_, _ = env.Exec.Run(ctx, "systemctl", "stop", s+".service")
	}
	for _, u := range enabledUnits(env) {
		if !strings.HasPrefix(u, "wspanel-mirror-") && u != "wspanel-site-guard.service" {
			_, _ = env.Exec.Run(ctx, "systemctl", "stop", u)
		}
	}
}

// keepFenced: cheap checks every tick of a fenced node; acts only when
// something came back.
func keepFenced(ctx context.Context, env *Env) {
	keepSitesOff(ctx, env)
	if slices.Contains(env.Cfg.DBEngines, "mariadb") {
		if out, err := (mariadb{}).sql(ctx, env, "SELECT @@read_only"); err == nil && strings.TrimSpace(out) == "0" {
			databasesReadOnly(ctx, env, true)
		}
	}
	if slices.Contains(env.Cfg.DBEngines, "postgresql") {
		if out, err := (postgres{}).psql(ctx, env, "postgres", "SHOW default_transaction_read_only"); err == nil && strings.TrimSpace(out) == "off" {
			databasesReadOnly(ctx, env, true)
		}
	}
}

// keepSitesOff: a node that must not serve (fenced, standby) takes the
// sites' edge blocks off whenever they appear (rendered at its start).
func keepSitesOff(ctx context.Context, env *Env) {
	if files, _ := filepath.Glob(filepath.Join(env.Cfg.ProxySitesDir, "*.caddy")); len(files) > 0 {
		if err := putEdgesAside(env); err != nil {
			mirrorRuntime.event("warning", "weby se nepodařilo stáhnout z edge: %v", err)
		}
		_, _ = env.Exec.Run(ctx, "systemctl", "reload", env.Cfg.ProxyUnit)
	}
}

// putEdgesAside: the sites' edge blocks leave the edge (the panel stays).
func putEdgesAside(env *Env) error {
	files, err := filepath.Glob(filepath.Join(env.Cfg.ProxySitesDir, "*.caddy"))
	if err != nil || len(files) == 0 {
		return err
	}
	if err := env.FS.MkdirAll(fencedEdgeDir(env), 0o700, fsx.Root); err != nil {
		return err
	}
	for _, f := range files {
		if err := os.Rename(f, filepath.Join(fencedEdgeDir(env), filepath.Base(f))); err != nil {
			return err
		}
	}
	return nil
}

func restoreFencedEdges(env *Env) {
	files, _ := filepath.Glob(filepath.Join(fencedEdgeDir(env), "*.caddy"))
	for _, f := range files {
		dst := filepath.Join(env.Cfg.ProxySitesDir, filepath.Base(f))
		if fsx.Exists(dst) {
			_ = os.Remove(f) // a newer one is in place (the promotion wrote it)
			continue
		}
		_ = os.Rename(f, dst)
	}
}

// unfenceLocal: serving again (edge blocks, mail, writes).
func unfenceLocal(ctx context.Context, env *Env) error {
	_ = os.Remove(fenceMarker(env))
	if slices.Contains(env.Cfg.DBEngines, "mariadb") {
		_, _ = writeFileIfChanged(env, filepath.Join(env.Cfg.MariaDBConfDir, mariadbMirrorCf), mariadbMirrorConfig(currentMirror(env).NodeID, false), 0o644, fsx.Root)
	}
	restoreFencedEdges(env)
	databasesReadOnly(ctx, env, false)
	for _, u := range enabledUnits(env) {
		if !strings.HasPrefix(u, "wspanel-mirror-") {
			_, _ = env.Exec.Run(ctx, "systemctl", "start", u)
		}
	}
	for _, s := range []string{"postfix", "dovecot"} {
		if res, err := env.Exec.Run(ctx, "systemctl", "is-enabled", s+".service"); err == nil && strings.TrimSpace(res.Stdout) == "enabled" {
			_, _ = env.Exec.Run(ctx, "systemctl", "start", s+".service")
		}
	}
	_, err := env.Exec.Run(ctx, "systemctl", "reload", env.Cfg.ProxyUnit)
	return err
}

func unfenceToPrimary(ctx context.Context, env *Env) error {
	if err := unfenceLocal(ctx, env); err != nil {
		return err
	}
	_, err := updateMirror(env, func(s *mirrorState) error {
		s.Role, s.Fenced, s.Since = rolePrimary, "", time.Now().UTC()
		return nil
	})
	mirrorRuntime.event("info", "tento server znovu obsluhuje weby (zrcadlo provoz nepřevzalo)")
	return err
}

// mirrorUnfence (panel / CLI): a fenced node serves again, e.g. after a
// switchover that did not complete. Refused while the peer is primary.
func mirrorUnfence(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	if st.Role != roleFenced {
		return nil, protocol.Conflict("this node is not fenced")
	}
	if them, err := helloPeer(ctx, env); err == nil && them.Role == rolePrimary {
		return nil, protocol.Conflict("the other node is the primary now: rejoin as its standby instead")
	} else if err != nil {
		return nil, protocol.Conflict("the other node does not answer, so it cannot be told it is not primary: " + err.Error())
	}
	if err := unfenceToPrimary(ctx, env); err != nil {
		return nil, err
	}
	return map[string]any{"role": rolePrimary}, nil
}

// ---------------------------------------------------------------- rejoin and switchover

// mirrorRejoin: the old (fenced) primary becomes the standby of the new
// one. Its databases are seeded again (what it had is dumped aside first),
// its files follow the new primary (what only it had goes to a backup dir).
func mirrorRejoin(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	if st.Role != roleFenced {
		return nil, protocol.Conflict("only a fenced node rejoins")
	}
	them, err := helloPeer(ctx, env)
	if err != nil {
		return nil, protocol.Conflict("the other node does not answer: " + err.Error())
	}
	if them.Role != rolePrimary {
		return nil, protocol.Conflict("the other node is not the primary (" + orNone(them.Role) + ")")
	}
	// The new primary took this node's state with its recovery key when it
	// was promoted: its snapshots open with this node's own key.
	if !fsx.Exists(mirrorFile(env, "peer-recovery.key")) {
		key, err := signingKey(env)
		if err != nil {
			return nil, err
		}
		if err := writeMirrorFile(env, "peer-recovery.key", []byte(hex.EncodeToString(key)+"\n")); err != nil {
			return nil, err
		}
	}
	backup := filepath.Join(env.Cfg.BackupDir, "mirror", "rejoin-"+time.Now().UTC().Format("20060102T150405Z"))
	if err := env.FS.MkdirAll(backup, 0o700, fsx.Root); err != nil {
		return nil, err
	}
	if err := writeMirrorFile(env, "rejoin-backup", []byte(backup+"\n")); err != nil {
		return nil, err
	}
	// The sites' processes stay off on a standby, also after a reboot.
	for _, u := range enabledUnits(env) {
		if !strings.HasPrefix(u, "wspanel-mirror-") && u != "wspanel-site-guard.service" {
			_, _ = env.Exec.Run(ctx, "systemctl", "disable", "--now", u)
		}
	}
	for _, e := range []string{"mariadb", "postgresql"} {
		_ = os.Remove(seededMark(env, e))
	}
	if _, err := updateMirror(env, func(s *mirrorState) error {
		s.Role, s.Fenced, s.Since, s.Epoch = roleStandby, "", time.Now().UTC(), them.Epoch
		return nil
	}); err != nil {
		return nil, err
	}
	mirrorRuntime.event("info", "připojeno zpět jako zrcadlo serveru %s; co měl jen tento server, je uloženo v %s", them.Name, backup)
	mirrorWake()
	return map[string]any{"role": roleStandby, "backup": backup}, nil
}

// mirrorSwitchover (panel / CLI on the primary): the standby takes over
// without losing anything, this node becomes its standby.
func mirrorSwitchover(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	if st.Role != rolePrimary || st.Peer == nil {
		return nil, protocol.Conflict("a switchover starts on the primary of a paired mirror")
	}
	them, err := helloPeer(ctx, env)
	if err != nil || them.Role != roleStandby {
		return nil, protocol.Conflict("the standby does not answer or is not a standby")
	}
	var out map[string]any
	if err := peerCall(ctx, env, "promote", map[string]any{"reason": "planned switchover from " + st.Name}, &out); err != nil {
		return nil, fmt.Errorf("the standby did not take over: %w", err)
	}
	// This node was fenced during the handover; now it follows the new primary.
	if _, err := mirrorRejoin(ctx, env, nil); err != nil {
		return map[string]any{"switched": true, "rejoin_error": err.Error()}, nil
	}
	return map[string]any{"switched": true, "standby": out}, nil
}

// peerPromote: the primary asks this standby to take over (switchover).
func peerPromote(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Reason string `json:"reason"`
	}
	_ = json.Unmarshal(raw, &p)
	if p.Reason == "" {
		p.Reason = "planned switchover"
	}
	rep, err := promotePlanned(ctx, env, p.Reason)
	if err != nil {
		return nil, err
	}
	return rep, nil
}
