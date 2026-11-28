package ops

import (
	"archive/tar"
	"context"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"time"

	"wspanel/agent/internal/fsx"
)

// The standby's sync cycle. Order matters: accounts before files (rsync maps
// owners by name), files before the key-value replicas (their data dir is in
// the site's home), the shared databases replicate continuously, the rest
// is dumped every few minutes.

const mirrorDumpEvery = 5 * time.Minute

var lastDumps time.Time

// makeMirrorSnapshot: a server backup's archive for the standby (encrypted
// with this server's recovery key, which the standby got at pairing).
func makeMirrorSnapshot(ctx context.Context, env *Env) (string, int64, error) {
	dir := mirrorFile(env, "out")
	if err := env.FS.MkdirAll(dir, 0o700, fsx.Root); err != nil {
		return "", 0, err
	}
	b, err := makeServerArchive(ctx, env, dir, "snapshot-", true)
	if err != nil {
		return "", 0, err
	}
	return b.Path, b.Size, nil
}

func peerRecoveryKey(env *Env) ([]byte, error) {
	b, err := os.ReadFile(mirrorFile(env, "peer-recovery.key"))
	if err != nil {
		return nil, errors.New("this standby holds no key of the primary (pair again)")
	}
	k, err := hex.DecodeString(strings.TrimSpace(string(b)))
	if err != nil || len(k) != 32 {
		return nil, errors.New("the primary's key is damaged")
	}
	return k, nil
}

// fetchSnapshot replaces the standby's copy of the primary's state; the
// old copy stays until the new one is complete and opens.
func fetchSnapshot(ctx context.Context, env *Env) (serverManifest, error) {
	key, err := peerRecoveryKey(env)
	if err != nil {
		return serverManifest{}, err
	}
	r, closer, err := peerStream(ctx, env, "snapshot", map[string]any{}, nil)
	if err != nil {
		return serverManifest{}, err
	}
	defer closer.Close()
	tmp, err := os.CreateTemp(mirrorDir(env), ".snapshot-*")
	if err != nil {
		return serverManifest{}, err
	}
	defer os.Remove(tmp.Name())
	_, err = io.Copy(tmp, io.LimitReader(r, 4<<30))
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return serverManifest{}, err
	}
	man, err := readServerManifest(tmp.Name(), key)
	if err != nil {
		return serverManifest{}, fmt.Errorf("the snapshot does not open: %w", err)
	}
	if err := os.Chmod(tmp.Name(), 0o600); err != nil {
		return serverManifest{}, err
	}
	if err := os.Rename(tmp.Name(), mirrorFile(env, "snapshot.wspbak")); err != nil {
		return serverManifest{}, err
	}
	return man, nil
}

// snapshotFiles reads files of the primary's state out of the snapshot:
// match gets each path (as on the primary) and says whether to keep it.
func snapshotFiles(env *Env, match func(string) bool) (map[string][]byte, error) {
	key, err := peerRecoveryKey(env)
	if err != nil {
		return nil, err
	}
	out := map[string][]byte{}
	err = openServerArchive(mirrorFile(env, "snapshot.wspbak"), key, func(tr *tar.Reader, hdr *tar.Header) (bool, error) {
		target, ok := configPath(hdr.Name)
		if !ok || hdr.Typeflag != tar.TypeReg || !match(target) || hdr.Size > 4<<20 {
			return false, nil
		}
		b, err := io.ReadAll(io.LimitReader(tr, 4<<20))
		if err != nil {
			return true, err
		}
		out[target] = b
		return false, nil
	})
	return out, err
}

// snapshotSites: the primary's sites, from its state.
func snapshotSites(env *Env) ([]siteState, error) {
	dir := filepath.Join(env.Cfg.StateDir, "sites") + "/"
	files, err := snapshotFiles(env, func(p string) bool {
		return strings.HasPrefix(p, dir) && strings.HasSuffix(p, ".json") && !strings.Contains(strings.TrimPrefix(p, dir), "/")
	})
	if err != nil {
		return nil, err
	}
	var out []siteState
	for _, b := range files {
		var st siteState
		if json.Unmarshal(b, &st) == nil && st.Domain != "" {
			out = append(out, st)
		}
	}
	slices.SortFunc(out, func(a, b siteState) int { return strings.Compare(a.Domain, b.Domain) })
	return out, nil
}

var (
	syncRunning = make(chan struct{}, 1)
	syncCancel  struct {
		sync.Mutex
		fn context.CancelFunc
	}
)

// stopSyncCycle cancels a running cycle and waits for it: a promotion must
// not have a cycle that still takes this node for a standby (it would make
// its databases read-only, or seed them from the old primary).
func stopSyncCycle() {
	syncCancel.Lock()
	if syncCancel.fn != nil {
		syncCancel.fn()
	}
	syncCancel.Unlock()
	select {
	case syncRunning <- struct{}{}: // none running, or it ended
		<-syncRunning
	case <-time.After(2 * time.Minute):
	}
}

// stillStandby: every step of a cycle checks the node is still the standby.
func stillStandby(env *Env) error {
	if currentMirror(env).Role != roleStandby || mirrorRuntime.busy() {
		return errors.New("this node is no longer the standby")
	}
	return nil
}

func startSyncCycle(ctx context.Context, env *Env, logf func(string, ...any)) {
	select {
	case syncRunning <- struct{}{}:
	default:
		return // one at a time
	}
	go func() {
		defer func() { <-syncRunning }()
		mirrorRuntime.mu.Lock()
		mirrorRuntime.Syncing = true
		mirrorRuntime.mu.Unlock()
		defer func() {
			mirrorRuntime.mu.Lock()
			mirrorRuntime.Syncing, mirrorRuntime.LastSync = false, time.Now().UTC()
			mirrorRuntime.mu.Unlock()
		}()
		cctx, cancel := context.WithTimeout(ctx, 6*time.Hour)
		defer cancel()
		syncCancel.Lock()
		syncCancel.fn = cancel
		syncCancel.Unlock()
		defer func() {
			syncCancel.Lock()
			syncCancel.fn = nil
			syncCancel.Unlock()
		}()
		if err := syncCycle(cctx, env); err != nil {
			logf("mirror: sync: %v", err)
		}
	}()
}

func syncCycle(ctx context.Context, env *Env) error {
	st := currentMirror(env)
	if st.Role != roleStandby || mirrorRuntime.busy() {
		return nil
	}
	peer, err := helloPeer(ctx, env)
	if err != nil {
		return err
	}
	if peer.Version != Version {
		mirrorRuntime.layer("version", fmt.Errorf("the primary runs WSPanel %s, this server %s: upgrade the older one", peer.Version, Version), "", nil)
	} else {
		mirrorRuntime.layer("version", nil, Version, nil)
	}

	// 1. State and accounts.
	man, err := fetchSnapshot(ctx, env)
	mirrorRuntime.layer("state", err, fmt.Sprintf("%d sites, %d mail domains, %d databases", len(man.Sites), len(man.MailDomains), len(man.Databases)), nil)
	if err != nil {
		return err
	}
	var rep RestoreReport
	err = withMutate(env, func() error {
		_, _, err := restoreAccounts(ctx, env, man, &rep)
		return err
	})
	mirrorRuntime.layer("accounts", errOrProblems(err, rep), fmt.Sprintf("%d users, %d groups", len(man.Users), len(man.Groups)), nil)
	if err != nil {
		return err
	}

	// 2. What the primary runs must be installed here too.
	if err := stillStandby(env); err != nil {
		return err
	}
	prepareStandby(ctx, env, peer)

	// 3. Files and mail.
	sitesErr := sourceLooksWhole(ctx, env, "sites/", env.Cfg.SitesRoot, man.Sites)
	if sitesErr == nil {
		sitesErr = rsyncFrom(ctx, env, "sites/", env.Cfg.SitesRoot+"/", siteSyncExcludes)
	}
	mirrorRuntime.layer("sites", sitesErr, fmt.Sprintf("%d sites", len(man.Sites)), nil)
	var mailErr error
	if peer.Mail && env.Cfg.VmailRoot != "" {
		mailErr = env.FS.MkdirAll(env.Cfg.VmailRoot, 0o750, fsx.Root)
		if mailErr == nil {
			mailErr = sourceLooksWhole(ctx, env, "mail/", env.Cfg.VmailRoot, man.MailDomains)
		}
		if mailErr == nil {
			mailErr = rsyncFrom(ctx, env, "mail/", env.Cfg.VmailRoot+"/", nil)
		}
		mirrorRuntime.layer("mail", mailErr, fmt.Sprintf("%d domains", len(man.MailDomains)), nil)
	}

	// What only a rejoined node had is set aside by these copies: the
	// marker goes only when every one of them succeeded.
	if sitesErr == nil && mailErr == nil && rejoinBackupDir(env) != "" {
		_ = os.Remove(mirrorFile(env, "rejoin-backup")) // one full cycle done
	}

	// 4. Databases: the shared servers replicate; MongoDB and the dedicated
	// servers are dumped.
	if err := stillStandby(env); err != nil {
		return err
	}
	tunnelsForEngines(ctx, env, peer)
	ensureReplication(ctx, env, st, peer)
	if time.Since(lastDumps) >= mirrorDumpEvery {
		lastDumps = time.Now()
		dumpOthers(ctx, env, man)
	}

	// 5. Each site's Valkey/Redis.
	if err := stillStandby(env); err != nil {
		return err
	}
	if sites, err := snapshotSites(env); err == nil {
		syncKVReplicas(ctx, env, sites)
	} else {
		mirrorRuntime.layer("kv", err, "", nil)
	}
	return nil
}

func errOrProblems(err error, rep RestoreReport) error {
	if err != nil {
		return err
	}
	if len(rep.Problems) > 0 {
		return errors.New(strings.Join(rep.Problems, "; "))
	}
	return nil
}

// rsyncFrom pulls a module of the primary into dst. Owners map by name
// (the accounts exist here first), ACLs and hard links stay; deleted on the
// primary = deleted here.
func rsyncFrom(ctx context.Context, env *Env, module, dst string, exclude []string) error {
	if err := env.FS.MkdirAll(dst, 0o751, fsx.Root); err != nil {
		return err
	}
	args := []string{"-aHA", "--delete", "--delete-delay", "--partial", "--timeout=300",
		"-e", env.Cfg.AgentBinary + " -mirror-pipe"}
	for _, e := range exclude {
		args = append(args, "--exclude="+e)
	}
	if b := rejoinBackupDir(env); b != "" {
		// The first cycle after an old primary rejoins: what it alone had
		// goes aside, not away.
		args = append(args, "--backup", "--backup-dir="+filepath.Join(b, strings.TrimSuffix(module, "/")))
	}
	args = append(args, "peer:"+module, dst)
	ctx, cancel := context.WithTimeout(ctx, 6*time.Hour)
	defer cancel()
	_, err := env.Exec.RunIO(ctx, nil, nil, "rsync", args...)
	if err != nil {
		// 24: files vanished while copying (a deploy on the primary): fine.
		if strings.Contains(err.Error(), "exit status 24") {
			return nil
		}
	}
	return err
}

// siteSyncExcludes: what the copy of the sites leaves out. A site's tmp/
// itself is copied (with its owner and mode: PHP-FPM's TMPDIR, uploads and
// sessions live there, so a promoted node must have it), its contents are
// not; valkey/ is the site's own server's data (the standby keeps a
// replica of its own).
var siteSyncExcludes = []string{"/.trash/", "/*/tmp/*", "/*/valkey/"}

// sourceLooksWhole: the primary's folder of a module still holds (at least
// half of) what its state lists and this node already has a copy of (the
// sites' domains, the mail domains). A folder that lost them (a mount that
// failed, a wipe) would make the copy delete everything here within a
// cycle: that copy is refused instead and the standby keeps what it has.
func sourceLooksWhole(ctx context.Context, env *Env, module, local string, listed []string) error {
	var want []string
	for _, n := range listed {
		if fi, err := os.Stat(filepath.Join(local, n)); err == nil && fi.IsDir() {
			want = append(want, n)
		}
	}
	if len(want) == 0 {
		return nil
	}
	lctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	res, err := env.Exec.RunIO(lctx, nil, nil, "rsync", "--list-only", "-d", "-e", env.Cfg.AgentBinary+" -mirror-pipe", "peer:"+module)
	if err != nil {
		// A primary older than this check (an upgrade in progress, the
		// standby first) refuses the listing: copy as before.
		if strings.Contains(err.Error(), `"--list-only" is not allowed`) {
			return nil
		}
		return fmt.Errorf("listing the primary's %s: %w", strings.TrimSuffix(module, "/"), err)
	}
	have := map[string]bool{}
	for _, line := range strings.Split(res.Stdout, "\n") {
		f := strings.Fields(line)
		if len(f) >= 5 {
			have[f[len(f)-1]] = true
		}
	}
	missing := 0
	for _, w := range want {
		if !have[w] {
			missing++
		}
	}
	if env.Cfg.DryRun || missing*2 <= len(want) {
		return nil
	}
	return fmt.Errorf("the primary's %s folder lacks %d of %d (unmounted or wiped?): not copied, nothing deleted here", strings.TrimSuffix(module, "/"), missing, len(want))
}

// rejoinBackupDir: set by a rejoin until the first full cycle is done.
func rejoinBackupDir(env *Env) string {
	b, err := os.ReadFile(mirrorFile(env, "rejoin-backup"))
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}

// prepareStandby installs here what the primary runs and this node lacks:
// database engines, PHP and Python versions, Valkey and Redis, mail hosting. Each is
// the same install the panel would start.
func prepareStandby(ctx context.Context, env *Env, peer *helloInfo) {
	var missing, failed []string
	try := func(what string, fn func() error) {
		missing = append(missing, what)
		if err := withMutate(env, fn); err != nil {
			failed = append(failed, what+": "+err.Error())
		}
	}
	for _, e := range peer.DBEngines {
		if !slices.Contains(env.Cfg.DBEngines, e) {
			try(e, func() error { _, err := engineInstall(ctx, env, mustJSON(map[string]any{"engine": e})); return err })
		}
	}
	for _, v := range peer.PHP {
		if !slices.Contains(env.Cfg.PHPVersions, v) {
			try("PHP "+v, func() error { _, err := phpInstall(ctx, env, mustJSON(map[string]any{"version": v})); return err })
		}
	}
	have := pythonMinors(env.Cfg)
	for _, v := range peer.Python {
		if !slices.Contains(have, v) {
			try("Python "+v, func() error { _, err := pythonInstall(ctx, env, mustJSON(map[string]any{"version": v})); return err })
		}
	}
	if (peer.Valkey == nil || *peer.Valkey) && !valkeyInstalled(env) {
		try("Valkey", func() error { _, err := kvInstallServer(ctx, env, kvValkey); return err })
	}
	if peer.Redis && !redisInstalled(env) {
		try("Redis", func() error { _, err := redisInstall(ctx, env, mustJSON(map[string]any{})); return err })
	}
	if peer.Mail && !mailHostingOn(env) {
		try("mail hosting", func() error { _, err := mailInstall(ctx, env, mustJSON(map[string]any{})); return err })
	}
	var err error
	if len(failed) > 0 {
		err = errors.New(strings.Join(failed, "; "))
	}
	detail := "the same software as the primary"
	if len(missing) > 0 {
		detail = "installed: " + strings.Join(missing, ", ")
		mirrorRuntime.event("info", "doinstalováno, co používá primár: %s", strings.Join(missing, ", "))
	}
	mirrorRuntime.layer("software", err, detail, nil)
}

func mustJSON(v any) json.RawMessage {
	b, _ := json.Marshal(v)
	return b
}

func tunnelsForEngines(ctx context.Context, env *Env, peer *helloInfo) {
	if slices.Contains(peer.DBEngines, "mariadb") && slices.Contains(env.Cfg.DBEngines, "mariadb") {
		if err := ensureTunnel(ctx, env, tunnelPortMariaDB, "mariadb", replicaUIDs("mysql")); err != nil {
			mirrorRuntime.layer("mariadb", err, "", nil)
		}
	}
	if slices.Contains(peer.DBEngines, "postgresql") && slices.Contains(env.Cfg.DBEngines, "postgresql") {
		if err := ensureTunnel(ctx, env, tunnelPortPostgres, "postgresql", replicaUIDs("postgres")); err != nil {
			mirrorRuntime.layer("postgresql", err, "", nil)
		}
	}
}

func dumpsDir(env *Env) string { return mirrorFile(env, "dumps") }

// dumpOthers: MongoDB's databases and those of the dedicated servers, one
// file per database (replaced when the new one is complete).
func dumpOthers(ctx context.Context, env *Env, man serverManifest) {
	dedicated := map[string]bool{}
	for _, d := range man.Dedicated {
		dedicated[d] = true
	}
	var want []dbRef
	for _, d := range man.Databases {
		if d.Engine == "mongodb" || dedicated[d.Engine+"/"+d.Name] {
			want = append(want, d)
		}
	}
	if len(want) == 0 {
		mirrorRuntime.dropLayers(func(k string) bool { return k != "dumps" })
		return
	}
	var failed []string
	keep := map[string]bool{}
	for _, d := range want {
		name, err := dumpOne(ctx, env, d)
		if err != nil {
			failed = append(failed, d.Engine+"/"+d.Name+": "+err.Error())
			continue
		}
		keep[name] = true
	}
	// Databases gone on the primary go here too.
	entries, _ := os.ReadDir(dumpsDir(env))
	for _, e := range entries {
		if !keep[e.Name()] && !strings.HasPrefix(e.Name(), ".") {
			_ = os.Remove(filepath.Join(dumpsDir(env), e.Name()))
		}
	}
	var err error
	if len(failed) > 0 {
		err = errors.New(strings.Join(failed, "; "))
	}
	mirrorRuntime.layer("dumps", err, fmt.Sprintf("%d databases (MongoDB, dedicated servers) every %s", len(want), mirrorDumpEvery), nil)
}

func dumpOne(ctx context.Context, env *Env, d dbRef) (string, error) {
	if err := env.FS.MkdirAll(dumpsDir(env), 0o700, fsx.Root); err != nil {
		return "", err
	}
	var ans struct {
		Ext string `json:"ext"`
	}
	r, closer, err := peerStream(ctx, env, "dump", map[string]any{"kind": "database", "engine": d.Engine, "name": d.Name}, &ans)
	if err != nil {
		return "", err
	}
	defer closer.Close()
	if !slices.Contains([]string{".sql.gz", ".pgdump", ".archive.gz"}, ans.Ext) {
		return "", fmt.Errorf("unexpected dump format %q", ans.Ext)
	}
	name := d.Engine + "--" + d.Name + ans.Ext
	tmp, err := os.CreateTemp(dumpsDir(env), ".dump-*")
	if err != nil {
		return "", err
	}
	defer os.Remove(tmp.Name())
	_, err = io.Copy(tmp, r)
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return "", err
	}
	return name, os.Rename(tmp.Name(), filepath.Join(dumpsDir(env), name))
}

func (m *mirrorRuntimeState) busy() bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.Action != ""
}
