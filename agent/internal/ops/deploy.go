package ops

import (
	"compress/gzip"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
	"unicode/utf8"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Deploy pipeline. Everything that runs code from the repository (git hooks,
// composer scripts, npm scripts, artisan, bin/console) runs as the site user
// in a sandboxed transient systemd unit, never as root. The agent itself only
// does the privileged part: switching the release, reloading/restarting the
// site's services, health check and rollback.
//
//	releases/<UTC timestamp>  new release (git clone)
//	shared/                   linked into it (.env, storage / var/log)
//	current -> releases/...   switched atomically after a successful build

type deployConfig struct {
	Repo         string `json:"repo"`
	Branch       string `json:"branch"`
	Migrate      bool   `json:"migrate"`       // run database migrations
	Assets       bool   `json:"assets"`        // npm ci + npm run build when package.json exists
	AutoRollback bool   `json:"auto_rollback"` // switch back when the new release does not answer
	Keep         int    `json:"keep"`          // releases to keep
	// Commands: the app's own console commands after the built-in steps
	// (artisan / bin/console, e.g. "install:permissions"); no shell.
	Commands []string `json:"commands,omitempty"`
	// EnvFromDeploy: the release's env file wins over the panel, except for
	// what binds the app to this server (Reverb's listening port).
	EnvFromDeploy bool `json:"env_from_deploy,omitempty"`
	// Maintenance: visitors see a maintenance page while migrations run and
	// until the new release answers (for migrations the old code cannot run on).
	Maintenance bool `json:"maintenance,omitempty"`
}

var deployCommandRe = regexp.MustCompile(`^[a-z][a-z0-9:_-]{0,63}( [A-Za-z0-9=:_.,/@-]{1,100}){0,8}$`)

var (
	repoHTTPSRe = regexp.MustCompile(`^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?/[A-Za-z0-9._~/-]+$`)
	repoSCPRe   = regexp.MustCompile(`^git@[A-Za-z0-9.-]+:[A-Za-z0-9._~/-]+$`)
	repoSSHRe   = regexp.MustCompile(`^ssh://git@[A-Za-z0-9.-]+(:[0-9]{1,5})?/[A-Za-z0-9._~/-]+$`)
	branchRe    = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$`)
)

func (c *deployConfig) validate() error {
	// No repository: the site deploys only artifacts built in CI.
	if c.Repo != "" && !(repoHTTPSRe.MatchString(c.Repo) || repoSCPRe.MatchString(c.Repo) || repoSSHRe.MatchString(c.Repo)) || strings.Contains(c.Repo, "..") {
		return protocol.Invalid("repo: https://host/path, git@host:path or ssh://git@host/path (no credentials in the URL; use the deploy key)")
	}
	if !branchRe.MatchString(c.Branch) || strings.Contains(c.Branch, "..") || strings.HasSuffix(c.Branch, ".lock") {
		return protocol.Invalid("branch: invalid name")
	}
	if c.Keep < 2 || c.Keep > 20 {
		return protocol.Invalid("keep: 2-20")
	}
	if len(c.Commands) > 10 {
		return protocol.Invalid("commands: at most 10")
	}
	for _, cmd := range c.Commands {
		if !deployCommandRe.MatchString(cmd) {
			return protocol.Invalid("commands: " + cmd + " is not a console command with plain arguments")
		}
	}
	return nil
}

// ---------------------------------------------------------------- per-site guard

var deploying = struct {
	sync.Mutex
	sites map[string]bool
}{sites: map[string]bool{}}

func beginDeploy(domain string) bool {
	deploying.Lock()
	defer deploying.Unlock()
	if deploying.sites[domain] {
		return false
	}
	deploying.sites[domain] = true
	return true
}

func endDeploy(domain string) {
	deploying.Lock()
	delete(deploying.sites, domain)
	deploying.Unlock()
}

// deployInProgress reports a running deploy (shown in the history).
func deployInProgress(domain string) bool {
	deploying.Lock()
	defer deploying.Unlock()
	return deploying.sites[domain]
}

// claimSite gives an operation exclusive use of a site against deploys:
// delete, mode/version switch and rollback claim it like a deploy does, so a
// deploy can never start halfway through them (and vice versa).
func claimSite(domain string) (func(), error) {
	if !beginDeploy(domain) {
		return nil, protocol.Conflict("a deploy of " + domain + " is running")
	}
	return func() { endDeploy(domain) }, nil
}

func withMutate(env *Env, f func() error) error {
	if env.Mutate != nil {
		env.Mutate.Lock()
		defer env.Mutate.Unlock()
	}
	return f()
}

// ---------------------------------------------------------------- operations

func deployConfigure(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		deployConfig
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := p.deployConfig.validate(); err != nil {
		return nil, err
	}
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	cfg := p.deployConfig
	st.Deploy = &cfg
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	return cfg, nil
}

// deployKey returns the site's deploy key (created on first use, as the site
// user, in ~/.ssh): add the public half to the repository as a read-only key.
func deployKey(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	key := filepath.Join(l.Home, ".ssh", "deploy_key")
	pub, err := readSiteFile(ctx, env, l, key+".pub")
	if err != nil {
		return nil, err
	}
	if pub == "" {
		if err := asUser(ctx, env, l.User, "/usr/bin/install", "-d", "-m", "0700", filepath.Join(l.Home, ".ssh")); err != nil {
			return nil, err
		}
		if err := asUser(ctx, env, l.User, "/usr/bin/ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-C", "wspanel-deploy@"+l.Domain, "-f", key); err != nil {
			return nil, err
		}
		if pub, err = readSiteFile(ctx, env, l, key+".pub"); err != nil {
			return nil, err
		}
	}
	return map[string]string{"public_key": strings.TrimSpace(pub)}, nil
}

type deployRecord struct {
	ID       string    `json:"id"` // = release name
	Started  time.Time `json:"started"`
	Finished time.Time `json:"finished,omitzero"`
	Status   string    `json:"status"` // running | success | failed | rolled_back
	Commit   string    `json:"commit,omitempty"`
	Error    string    `json:"error,omitempty"`
	Ref      string    `json:"ref,omitempty"`    // tag, branch or commit deployed (default: the configured branch)
	Source   string    `json:"source,omitempty"` // git | artifact
}

func historyPath(env *Env, domain string) string {
	return filepath.Join(env.Cfg.StateDir, "deploys", domain+".json")
}

func loadHistory(env *Env, domain string) []deployRecord {
	var h []deployRecord
	if b, err := os.ReadFile(historyPath(env, domain)); err == nil {
		_ = json.Unmarshal(b, &h)
	}
	return h
}

func saveRecord(env *Env, domain string, r deployRecord) {
	h := loadHistory(env, domain)
	i := slices.IndexFunc(h, func(x deployRecord) bool { return x.ID == r.ID })
	if i >= 0 {
		h[i] = r
	} else {
		h = append(h, r)
	}
	if len(h) > 50 {
		h = h[len(h)-50:]
	}
	b, _ := json.MarshalIndent(h, "", "  ")
	_ = env.FS.MkdirAll(filepath.Dir(historyPath(env, domain)), 0o700, fsx.Root)
	_ = env.FS.WriteFile(historyPath(env, domain), b, 0o600, fsx.Root)
}

// removeDeployLogs deletes exactly this site's logs: <domain>-<release>.log.
// A prefix glob would also match another site (a.com vs a.com-x.com).
func removeDeployLogs(env *Env, domain string) {
	re := regexp.MustCompile(`^` + regexp.QuoteMeta(domain) + `-\d{8}T\d{6}Z\.log$`)
	entries, _ := os.ReadDir(env.Cfg.DeployLogDir)
	for _, e := range entries {
		if re.MatchString(e.Name()) {
			_ = os.Remove(filepath.Join(env.Cfg.DeployLogDir, e.Name()))
		}
	}
}

func deployLogPath(env *Env, domain, id string) string {
	return filepath.Join(env.Cfg.DeployLogDir, domain+"-"+id+".log")
}

func deployHistory(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	h := loadHistory(env, p.Domain)
	if h == nil {
		h = []deployRecord{}
	}
	slices.Reverse(h)
	current, _ := currentRelease(l)
	return map[string]any{"deploys": h, "releases": listReleases(l), "current": current, "config": st.Deploy,
		"running": deployInProgress(p.Domain), "opcache_production": st.OpcacheProduction}, nil
}

// deployLog returns the build log from `offset` (the panel polls it live).
func deployLog(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		ID     string `json:"id"`
		Offset int64  `json:"offset"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if !releaseNameRe.MatchString(p.ID) || p.Offset < 0 {
		return nil, protocol.Invalid("id/offset")
	}
	f, err := os.Open(deployLogPath(env, p.Domain, p.ID))
	if err != nil {
		return nil, protocol.NotFound("no log for deploy " + p.ID)
	}
	defer f.Close()
	if _, err := f.Seek(p.Offset, io.SeekStart); err != nil {
		return nil, err
	}
	b, _ := io.ReadAll(io.LimitReader(f, 256<<10))
	h := loadHistory(env, p.Domain)
	status := "unknown"
	if i := slices.IndexFunc(h, func(x deployRecord) bool { return x.ID == p.ID }); i >= 0 {
		status = h[i].Status
	}
	return map[string]any{"content": strings.ToValidUTF8(string(b), "?"), "offset": p.Offset + int64(len(b)), "status": status}, nil
}

// listReleases: "initial" (the placeholder) first, then timestamps in order.
func listReleases(l siteLayout) []string {
	entries, _ := os.ReadDir(filepath.Join(l.Home, "releases"))
	var out []string
	for _, e := range entries {
		if e.IsDir() && releaseNameRe.MatchString(e.Name()) {
			out = append(out, e.Name())
		}
	}
	slices.SortFunc(out, func(a, b string) int {
		if a == "initial" {
			return -1
		}
		if b == "initial" {
			return 1
		}
		return strings.Compare(a, b)
	})
	if out == nil {
		out = []string{}
	}
	return out
}

// deployRun is an async job. Build steps run unprivileged and without the
// global lock; activation takes it.
func deployRun(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain   string `json:"domain"`
		Ref      string `json:"ref,omitempty"`      // tag / branch / commit; default: configured branch
		Artifact string `json:"artifact,omitempty"` // pre-built tar.gz uploaded by CI (file name in ArtifactDir)
		Commit   string `json:"commit,omitempty"`   // artifact: the commit it was built from (informational)
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Ref != "" && (!branchRe.MatchString(p.Ref) || strings.Contains(p.Ref, "..")) {
		return nil, protocol.Invalid("ref: tag, branch or commit")
	}
	if p.Artifact != "" && !artifactNameRe.MatchString(p.Artifact) {
		return nil, protocol.Invalid("artifact: file name of an uploaded .tar.gz")
	}
	if p.Commit != "" && !commitRe.MatchString(p.Commit) {
		return nil, protocol.Invalid("commit: hex commit id")
	}
	// Load and claim together under the global lock: a mutating operation on
	// the site (delete, switch) either finished before or sees the claim.
	var st siteState
	var l siteLayout
	if err := withMutate(env, func() error {
		var err error
		if st, l, err = loadSite(env, p.Domain); err != nil {
			return err
		}
		if st.Deploy == nil {
			if p.Artifact == "" {
				return protocol.Invalid("deploy is not configured for " + p.Domain + " (deploy.config)")
			}
			// A ready release (a copy from another site, a CI build): nothing to
			// fetch or build, the defaults do.
			st.Deploy = &deployConfig{Keep: 5, AutoRollback: true, Migrate: st.Framework == "laravel" || st.Framework == "symfony" || st.Framework == "django", Assets: st.Framework == "django"}
		}
		if p.Artifact == "" && st.Deploy.Repo == "" {
			return protocol.Invalid("no repository is set for " + p.Domain + "; it deploys only artifacts from CI")
		}
		if st.Disabled {
			return protocol.Conflict(p.Domain + " is disabled; enable it before deploying")
		}
		if !beginDeploy(p.Domain) {
			return protocol.Conflict("a deploy of " + p.Domain + " is already running")
		}
		return nil
	}); err != nil {
		return nil, err
	}
	defer endDeploy(p.Domain)

	release := time.Now().UTC().Format("20060102T150405Z")
	rec := deployRecord{ID: release, Started: time.Now().UTC(), Status: "running", Ref: p.Ref, Source: "git"}
	if p.Artifact != "" {
		rec.Source = "artifact"
	}
	saveRecord(env, p.Domain, rec)
	if err := env.FS.MkdirAll(env.Cfg.DeployLogDir, 0o700, fsx.Root); err != nil {
		return nil, err
	}
	logf, err := os.OpenFile(deployLogPath(env, p.Domain, release), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return nil, err
	}
	defer logf.Close()
	d := &deployment{env: env, l: l, cfg: *st.Deploy, release: release, log: logf, ref: p.Ref, artifact: p.Artifact, commit: p.Commit}
	if p.Artifact != "" {
		defer os.Remove(filepath.Join(env.Cfg.ArtifactDir, p.Artifact))
	}

	result, err := d.run(ctx)
	rec.Finished = time.Now().UTC()
	rec.Commit = d.commit
	switch {
	case err != nil:
		rec.Status, rec.Error = "failed", err.Error()
		d.logf("\n✗ deploy failed: %v\n", err)
	case result == "rolled_back":
		rec.Status, rec.Error = "rolled_back", "the new release did not answer; switched back"
		d.logf("\n↩ rolled back to the previous release\n")
	default:
		rec.Status = "success"
		d.logf("\n✓ deployed %s (%s)\n", release, shortCommit(d.commit))
	}
	saveRecord(env, p.Domain, rec)
	if err != nil {
		return nil, err
	}
	return rec, nil
}

func shortCommit(c string) string {
	if len(c) > 12 {
		return c[:12]
	}
	return c
}

type deployment struct {
	env      *Env
	l        siteLayout
	cfg      deployConfig
	release  string
	log      io.Writer
	commit   string
	ref      string // what to fetch instead of the configured branch
	artifact string // pre-built release from CI: no git, no composer/npm
	// maintenance: this deploy put the maintenance page up
	maintenance bool
	// newFailed: the new release's process never answered (Node: visitors stayed on the old one)
	newFailed bool
}

func (d *deployment) logf(format string, a ...any) { fmt.Fprintf(d.log, format, a...) }

func (d *deployment) dir() string { return filepath.Join(d.l.Home, "releases", d.release) }

// step runs one command as the site user in a sandboxed transient unit,
// streaming its output into the log.
func (d *deployment) step(ctx context.Context, title, workdir string, env []string, argv ...string) error {
	d.logf("\n==> %s\n$ %s\n", title, strings.Join(argv, " "))
	args := append(sandboxRunArgs(d.env, d.l, workdir, env), argv...)
	_, err := d.env.Exec.RunLog(ctx, d.log, "systemd-run", args...)
	if err != nil {
		return fmt.Errorf("%s failed", title)
	}
	return nil
}

// sandboxRunArgs: systemd-run arguments (up to and including "--") that run a
// command as the site user in a sandboxed transient unit, with the site's
// tools on PATH and its deploy key for git.
func sandboxRunArgs(e *Env, l siteLayout, workdir string, env []string) []string {
	tools := filepath.Join(e.Cfg.ToolsDir, l.User)
	args := []string{
		"--wait", "--pipe", "--quiet", "--collect", "--service-type=exec",
		"--unit=wspanel-" + l.User + "-deploy-" + strconv.FormatInt(time.Now().UnixNano(), 36),
		"--uid=" + l.User, "--gid=" + l.User,
		"--working-directory=" + workdir,
		"-p", "UMask=0027", "-p", "NoNewPrivileges=yes", "-p", "PrivateTmp=yes", "-p", "PrivateDevices=yes",
		"-p", "ProtectSystem=strict", "-p", "ProtectHome=yes", "-p", "ReadWritePaths=" + l.Home,
		"-p", "ProtectKernelTunables=yes", "-p", "ProtectKernelModules=yes", "-p", "ProtectControlGroups=yes",
		"-p", "RestrictSUIDSGID=yes", "-p", "RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6",
		"-p", "SocketBindDeny=any", "-p", "CapabilityBoundingSet=",
		"-p", "InaccessiblePaths=-/etc/panel-agent -/var/lib/panel-agent -/var/log/panel-agent -/var/lib/panel-proxy -/etc/panel-proxy",
		"-p", "RuntimeMaxSec=1800",
		"--setenv=HOME=" + l.Home, "--setenv=PATH=" + tools + ":/usr/local/bin:/usr/bin:/bin",
		"--setenv=COMPOSER_HOME=" + l.Home + "/tmp/composer", "--setenv=npm_config_cache=" + l.Home + "/tmp/npm",
		"--setenv=COMPOSER_NO_INTERACTION=1", "--setenv=CI=1",
		"--setenv=GIT_SSH_COMMAND=ssh -i " + l.Home + "/.ssh/deploy_key -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=" + l.Home + "/.ssh/known_hosts",
	}
	for _, v := range env {
		args = append(args, "--setenv="+v)
	}
	return append(args, "--")
}

func (d *deployment) exists(rel string) bool {
	return fsx.Exists(filepath.Join(d.dir(), rel))
}

func (d *deployment) php() string { return filepath.Join(d.env.Cfg.ToolsDir, d.l.User, "php") }

func (d *deployment) npm() string { return filepath.Join(d.env.Cfg.ToolsDir, d.l.User, "npm") }

func (d *deployment) run(ctx context.Context) (string, error) {
	l, dir := d.l, d.dir()
	cleanup := func() { _ = asUser(context.WithoutCancel(ctx), d.env, l.User, "/usr/bin/rm", "-rf", "--", dir) }

	// 1. Source.
	switch {
	case d.artifact != "":
		d.logf("Deploy of %s (%s, %s mode)\nbuild artifact from CI%s\n", l.Domain, l.Framework, l.Mode, map[bool]string{true: ", ref " + d.ref, false: ""}[d.ref != ""])
		if err := d.unpackArtifact(ctx); err != nil {
			cleanup()
			return "", err
		}
		d.logf("commit %s\n", or(d.commit, "(not given)"))
	default:
		ref := or(d.ref, d.cfg.Branch)
		d.logf("Deploy of %s (%s, %s mode)\nrepository %s, %s %s\n", l.Domain, l.Framework, l.Mode, d.cfg.Repo, map[bool]string{true: "ref", false: "branch"}[d.ref != ""], ref)
		if err := d.fetch(ctx, ref); err != nil {
			cleanup()
			return "", err
		}
		var commit strings.Builder
		_, _ = d.env.Exec.RunIO(ctx, nil, &commit, "setpriv", "--reuid="+l.User, "--regid="+primaryGroup(l.User), "--init-groups", "--reset-env", "--",
			"/usr/bin/git", "-c", "safe.directory="+dir, "-C", dir, "rev-parse", "HEAD")
		d.commit = strings.TrimSpace(commit.String())
		d.logf("commit %s\n", d.commit)
	}

	// 2. Shared files and directories.
	if err := d.mergeReleaseEnv(ctx); err != nil {
		cleanup()
		return "", err
	}
	if err := d.ensureAppSecrets(ctx); err != nil {
		cleanup()
		return "", err
	}
	if err := d.linkShared(ctx); err != nil {
		cleanup()
		return "", err
	}

	// Whatever happens below, a maintenance page this deploy put up comes down.
	defer func() {
		if d.maintenance {
			d.setMaintenance(context.WithoutCancel(ctx), false)
		}
	}()

	// 3. Build.
	if err := d.build(ctx); err != nil {
		cleanup()
		return "", err
	}
	// Build scripts may replace the env link (Symfony Demo renames its own
	// .env.local into place): the shared file is the configuration, always.
	if f := sharedEnvFile(l.Framework); f != "" {
		if target, err := os.Readlink(filepath.Join(d.dir(), f)); err != nil || target != filepath.Join(l.Home, "shared", f) {
			d.logf("\n%s was replaced during the build; linking the shared one again\n", f)
			if err := d.linkShared(ctx); err != nil {
				cleanup()
				return "", err
			}
		}
	}

	// 4. Activate (privileged part, under the global lock).
	previous, _ := currentRelease(l)
	result := "success"
	err := withMutate(d.env, func() error {
		if l.Mode == "classic" {
			if err := grantWebAccess(ctx, d.env, l, d.release); err != nil {
				return err
			}
		}
		if err := d.switchTo(ctx, d.release); err != nil {
			return err
		}
		d.restartServices(ctx)
		if ok, why := d.healthy(ctx); !ok {
			d.logf("\nthe new release does not answer: %s\n", why)
			if d.cfg.AutoRollback && previous != "" && previous != d.release {
				if err := d.switchTo(ctx, previous); err != nil {
					return fmt.Errorf("rollback failed: %w", err)
				}
				d.restartServices(ctx)
				result = "rolled_back"
			}
		}
		return nil
	})
	if err != nil {
		return "", err
	}

	// 5. Old releases.
	d.prune(ctx)
	return result, nil
}

// linkShared links the persistent parts of shared/ into the release.
func (d *deployment) linkShared(ctx context.Context) error {
	l, dir := d.l, d.dir()
	d.logf("\n==> Link shared files\n")
	link := func(target, name string) error {
		_ = asUser(ctx, d.env, l.User, "/usr/bin/rm", "-rf", "--", filepath.Join(dir, name))
		if err := asUser(ctx, d.env, l.User, "/usr/bin/install", "-d", "-m", "0750", filepath.Dir(filepath.Join(dir, name))); err != nil {
			return err
		}
		return asUser(ctx, d.env, l.User, "/usr/bin/ln", "-s", "--", target, filepath.Join(dir, name))
	}
	shared := filepath.Join(l.Home, "shared")
	var links [][2]string
	if f := sharedEnvFile(l.Framework); f != "" {
		links = append(links, [2]string{filepath.Join(shared, f), f})
	}
	switch l.Framework {
	case "laravel":
		links = append(links, [2]string{filepath.Join(shared, "storage"), "storage"})
	case "symfony":
		links = append(links, [2]string{filepath.Join(shared, "var", "log"), "var/log"})
	}
	for _, lk := range links {
		d.logf("%s -> %s\n", lk[1], lk[0])
		if err := link(lk[0], lk[1]); err != nil {
			return fmt.Errorf("linking %s failed: %w", lk[1], err)
		}
	}
	return nil
}

func (d *deployment) build(ctx context.Context) error {
	l, dir := d.l, d.dir()
	php := d.php()
	if d.artifact != "" {
		d.logf("\n==> Build\nskipped: the artifact was built in CI (vendor/ and assets included)\n")
	}
	if d.artifact == "" && (l.Mode == "classic" || l.Mode == "worker") && d.exists("composer.json") {
		// Symfony's composer scripts (cache:clear) boot the kernel: in prod, per the Symfony deployment docs.
		var composerEnv []string
		if l.Framework == "symfony" {
			composerEnv = []string{"APP_ENV=prod", "APP_DEBUG=0"}
		}
		if err := d.step(ctx, "Composer", dir, composerEnv, php, "/usr/bin/composer", "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--no-progress", "--prefer-dist"); err != nil {
			return err
		}
	}
	if d.artifact == "" && (d.cfg.Assets || l.Mode == "node") && d.exists("package.json") {
		install := []string{d.npm(), "install", "--no-audit", "--no-fund"}
		if d.exists("package-lock.json") {
			install = []string{d.npm(), "ci", "--no-audit", "--no-fund"}
		}
		if err := d.step(ctx, "npm install", dir, nil, install...); err != nil {
			return err
		}
		if err := d.step(ctx, "npm run build", dir, []string{"NODE_ENV=production"}, d.npm(), "run", "build", "--if-present"); err != nil {
			return err
		}
		// Inertia SSR: the server bundle too (the starter kits' build:ssr).
		if l.Framework == "laravel" && hasWorker(d.env, l.User, "ssr") {
			if err := d.step(ctx, "npm run build:ssr", dir, []string{"NODE_ENV=production"}, d.npm(), "run", "build:ssr", "--if-present"); err != nil {
				return err
			}
		}
	}
	if l.Mode == "python" {
		return d.buildPython(ctx)
	}
	switch l.Framework {
	case "laravel":
		d.maintenanceForMigrations(ctx)
		if d.artifact != "" {
			// Caches built on the CI runner name its paths and its packages;
			// the first artisan run here would boot with them.
			if err := asUser(ctx, d.env, l.User, "/usr/bin/find", filepath.Join(dir, "bootstrap", "cache"), "-maxdepth", "1", "-type", "f", "-name", "*.php", "-delete"); err != nil {
				d.logf("clearing bootstrap/cache: %v\n", err)
			}
		}
		if d.cfg.Migrate {
			if err := d.step(ctx, "Migrations", dir, nil, php, "artisan", "migrate", "--force"); err != nil {
				return err
			}
		}
		// A repository that commits public/storage/.gitignore ships a real folder
		// where the link belongs, and storage:link refuses to replace it.
		if placeholderDir(filepath.Join(dir, "public", "storage")) {
			if err := d.step(ctx, "Storage link: public/storage from the repository holds only a placeholder", dir, nil, "/bin/rm", "-r", "--", "public/storage"); err != nil {
				return err
			}
		}
		if err := d.step(ctx, "Storage link", dir, nil, php, "artisan", "storage:link", "--force"); err != nil {
			return err
		}
		if err := d.step(ctx, "Optimize", dir, nil, php, "artisan", "optimize"); err != nil {
			return err
		}
		for _, cmd := range d.cfg.Commands {
			if err := d.step(ctx, "artisan "+cmd, dir, nil, append([]string{php, "artisan"}, strings.Fields(cmd)...)...); err != nil {
				return err
			}
		}
	case "symfony":
		d.maintenanceForMigrations(ctx)
		prod := []string{"APP_ENV=prod", "APP_DEBUG=0"}
		if d.cfg.Migrate && d.exists("config/packages/doctrine_migrations.yaml") {
			if err := d.step(ctx, "Migrations", dir, prod, php, "bin/console", "doctrine:migrations:migrate", "--no-interaction", "--allow-no-migration"); err != nil {
				return err
			}
		}
		if d.exists("importmap.php") {
			if err := d.step(ctx, "Asset map", dir, prod, php, "bin/console", "asset-map:compile"); err != nil {
				return err
			}
		}
		if err := d.step(ctx, "Cache warmup", dir, prod, php, "bin/console", "cache:warmup"); err != nil {
			return err
		}
		for _, cmd := range d.cfg.Commands {
			if err := d.step(ctx, "bin/console "+cmd, dir, prod, append([]string{php, "bin/console"}, strings.Fields(cmd)...)...); err != nil {
				return err
			}
		}
	}
	return nil
}

// maintenanceForMigrations puts the maintenance page up before migrations
// when the site asked for it.
func (d *deployment) maintenanceForMigrations(ctx context.Context) {
	if d.cfg.Migrate && d.cfg.Maintenance && !d.maintenance {
		d.setMaintenance(ctx, true)
	}
}

// setMaintenance switches the edge's maintenance page for the site. The
// operator gets in with a bypass link (a cookie), printed in the deploy log;
// the health check talks to the backend directly and is not affected.
func (d *deployment) setMaintenance(ctx context.Context, on bool) {
	err := withMutate(d.env, func() error {
		st, _, err := loadSite(d.env, d.l.Domain)
		if err != nil {
			return err
		}
		st.Maintenance = ""
		if on {
			st.Maintenance = randomToken(24)
		}
		l, err := layout(d.env.Cfg, st)
		if err != nil {
			return err
		}
		var rb undo
		if err := writeEdge(ctx, d.env, l, &rb); err != nil {
			rb.run()
			return err
		}
		if err := saveSiteState(d.env, st); err != nil {
			return err
		}
		if on {
			d.logf("\n==> Maintenance page up (migrations). You get in with https://%s/?wsp_maintenance=%s\n", d.l.Domain, st.Maintenance)
		} else {
			d.logf("\n==> Maintenance page down\n")
		}
		return nil
	})
	if err != nil {
		d.logf("maintenance page: %v\n", err)
		return
	}
	d.maintenance = on
}

// switchTo points current at a release atomically (as the site user: the
// link lives in its home).
func (d *deployment) switchTo(ctx context.Context, release string) error {
	l := d.l
	tmp := filepath.Join(l.Home, "current.wspanel-new")
	_ = asUser(ctx, d.env, l.User, "/usr/bin/rm", "-f", "--", tmp)
	if err := asUser(ctx, d.env, l.User, "/usr/bin/ln", "-s", "--", "releases/"+release, tmp); err != nil {
		return err
	}
	if err := asUser(ctx, d.env, l.User, "/usr/bin/mv", "-T", "-f", "--", tmp, filepath.Join(l.Home, "current")); err != nil {
		return err
	}
	d.logf("\n==> current -> releases/%s\n", release)
	return nil
}

// restartServices makes the site pick up the release: graceful FPM reload
// (OPcache, realpath cache), restart of the app (worker / Node keep code in
// memory) and of the site's workers. Errors are logged, not fatal: the
// health check decides.
func (d *deployment) restartServices(ctx context.Context) {
	l := d.l
	var units []string
	switch l.Mode {
	case "classic":
		unit := strings.ReplaceAll(d.env.Cfg.FPMUnit, "{version}", l.PHPVersion)
		if _, err := d.env.Exec.Run(ctx, "systemctl", "reload", unit); err != nil {
			d.logf("reload %s: %v\n", unit, err)
		}
	case "worker":
		// Without dropping connections: the server stays, its PHP workers restart.
		if ok, err := gracefulWorkerRestart(ctx, d.env, l); ok {
			d.logf("PHP workers restarted in place (no connection dropped)\n")
		} else {
			if err != nil {
				d.logf("graceful worker restart: %v; restarting the process\n", err)
			}
			restarted := false
			if fresh, rerr := render("instance.caddy.tmpl", l); rerr == nil {
				if cur, _ := os.ReadFile(l.InstanceConfig); string(cur) != string(fresh) {
					// Brings the admin socket (next deploys restart in place); restarts the unit.
					if err := applyPHPRuntime(ctx, d.env, l); err != nil {
						d.logf("instance config: %v\n", err)
					} else {
						restarted = true
					}
				}
			}
			if !restarted {
				units = append(units, l.AppUnit)
			}
		}
	case "node", "python":
		if l.Disabled {
			units = append(units, l.AppUnit)
			break
		}
		switched, err := d.blueGreenNode(ctx)
		if err != nil {
			d.logf("zero-downtime switch: %v; restarting the process\n", err)
			units = append(units, d.l.AppUnit)
		} else if !switched {
			d.newFailed = true
		}
	default:
		units = append(units, l.AppUnit)
	}
	for _, u := range units {
		if _, err := d.env.Exec.Run(ctx, "systemctl", "restart", u); err != nil {
			d.logf("restart %s: %v\n", u, err)
		} else {
			d.logf("restarted %s\n", u)
		}
	}
	// Workers: the new release's instances start, old ones finish their jobs.
	rotateWorkers(ctx, d.env, l, d.logf)
}

func (d *deployment) healthy(ctx context.Context) (bool, string) {
	if d.newFailed {
		d.newFailed = false
		return false, "the new release's process did not start answering"
	}
	var last string
	for i := 0; i < 10; i++ { // apps need a moment to start after a restart
		res := probeSite(ctx, d.env, d.l)
		if res["ok"] == true {
			d.logf("health: HTTP %v\n", res["status"])
			return true, ""
		}
		last = fmt.Sprintf("%v %v", res["status"], res["error"])
		select {
		case <-ctx.Done():
			return false, ctx.Err().Error()
		case <-time.After(time.Second):
		}
	}
	return false, strings.TrimSpace(last)
}

// prune keeps the newest cfg.Keep releases; never the current one.
func (d *deployment) prune(ctx context.Context) {
	l := d.l
	current, _ := currentRelease(l)
	rels := listReleases(l)
	for i := 0; i < len(rels)-d.cfg.Keep; i++ {
		if rels[i] == current {
			continue
		}
		if releaseInUse(ctx, d.env, l, rels[i]) {
			d.logf("keeping release %s: a worker still finishes its job from it\n", rels[i])
			continue
		}
		if err := asUser(ctx, d.env, l.User, "/usr/bin/rm", "-rf", "--", filepath.Join(l.Home, "releases", rels[i])); err == nil {
			d.logf("removed old release %s\n", rels[i])
		}
	}
}

func deployRollback(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain  string `json:"domain"`
		Release string `json:"release,omitempty"` // default: the one before current
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	current, err := currentRelease(l)
	if err != nil {
		return nil, err
	}
	rels := listReleases(l)
	target := p.Release
	if target == "" {
		i := slices.Index(rels, current)
		if i <= 0 {
			return nil, protocol.Invalid("no release before " + current)
		}
		target = rels[i-1]
	}
	if !slices.Contains(rels, target) || target == current {
		return nil, protocol.Invalid("release: not an available release other than the current one")
	}
	cfg := deployConfig{Keep: 20}
	if st.Deploy != nil {
		cfg = *st.Deploy
	}
	var logBuf strings.Builder
	d := &deployment{env: env, l: l, cfg: cfg, release: target, log: &logBuf}
	if l.Mode == "classic" {
		if err := grantWebAccess(ctx, env, l, target); err != nil {
			return nil, err
		}
	}
	if err := d.switchTo(ctx, target); err != nil {
		return nil, err
	}
	d.restartServices(ctx)
	ok, why := d.healthy(ctx)
	return map[string]any{"current": target, "previous": current, "healthy": ok, "health_error": why, "log": logBuf.String()}, nil
}

// ---------------------------------------------------------------- env file

func envGet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	path := envFilePath(l)
	if path == "" {
		return nil, protocol.NotFound(p.Domain + " has no env file")
	}
	content, err := readSiteFile(ctx, env, l, path)
	if err != nil {
		return nil, err
	}
	return map[string]string{"path": path, "content": content}, nil
}

func envSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain  string `json:"domain"`
		Content string `json:"content"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if len(p.Content) > 64<<10 || !utf8.ValidString(p.Content) || strings.ContainsRune(p.Content, 0) {
		return nil, protocol.Invalid("content: UTF-8 text up to 64 KiB")
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	path := envFilePath(l)
	if path == "" {
		return nil, protocol.NotFound(p.Domain + " has no env file")
	}
	if !strings.HasSuffix(p.Content, "\n") && p.Content != "" {
		p.Content += "\n"
	}
	if err := writeSiteFile(ctx, env, l, path, p.Content); err != nil {
		return nil, err
	}
	return map[string]any{"path": path, "bytes": len(p.Content)}, nil
}

// siteOpcache switches production OPcache (no revalidation) for a classic site.
func siteOpcache(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain     string `json:"domain"`
		Production bool   `json:"production"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.Mode != "classic" {
		return nil, protocol.Invalid("opcache: only classic (PHP-FPM) sites; worker mode keeps the app in memory anyway")
	}
	st.OpcacheProduction = p.Production
	l, err := layout(env.Cfg, st)
	if err != nil {
		return nil, err
	}
	pool, err := render("fpm-pool.conf.tmpl", l)
	if err != nil {
		return nil, err
	}
	old, err := os.ReadFile(l.PoolFile)
	if err != nil {
		return nil, err
	}
	if err := env.FS.WriteFile(l.PoolFile, pool, 0o644, fsx.Root); err != nil {
		return nil, err
	}
	if err := validateFPM(ctx, env, l.PHPVersion); err != nil {
		_ = env.FS.WriteFile(l.PoolFile, old, 0o644, fsx.Root)
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "reload", strings.ReplaceAll(env.Cfg.FPMUnit, "{version}", l.PHPVersion)); err != nil {
		return nil, err
	}
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "opcache_production": p.Production}, nil
}

var (
	artifactNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.tar\.gz$`)
	commitRe       = regexp.MustCompile(`^[0-9a-f]{7,64}$`)
	fullCommitRe   = regexp.MustCompile(`^[0-9a-f]{40}([0-9a-f]{24})?$`)
)

func or(a, b string) string {
	if a != "" {
		return a
	}
	return b
}

// fetch gets one ref: a branch or tag with a shallow clone, a full commit id
// with a shallow fetch of exactly that commit.
func (d *deployment) fetch(ctx context.Context, ref string) error {
	releases := filepath.Join(d.l.Home, "releases")
	dir := d.dir()
	if !fullCommitRe.MatchString(ref) {
		return d.step(ctx, "Fetch source", releases, nil,
			"/usr/bin/git", "clone", "--depth=1", "--single-branch", "--branch", ref, "--", d.cfg.Repo, dir)
	}
	git := []string{"/usr/bin/git", "-c", "safe.directory=" + dir, "-C", dir}
	for _, s := range []struct {
		title string
		args  []string
	}{
		{"Fetch source", []string{"/usr/bin/git", "init", "--quiet", "--", dir}},
		{"Fetch source", append(append([]string{}, git...), "remote", "add", "origin", "--", d.cfg.Repo)},
		{"Fetch source", append(append([]string{}, git...), "fetch", "--depth=1", "origin", ref)},
		{"Fetch source", append(append([]string{}, git...), "checkout", "--quiet", "--detach", "FETCH_HEAD")},
	} {
		if err := d.step(ctx, s.title, releases, nil, s.args...); err != nil {
			return err
		}
	}
	return nil
}

// unpackArtifact extracts a tar.gz uploaded by CI into the release dir. The
// panel wrote the file, so the agent trusts nothing about it: it must be a
// regular file (no symlink) owned by the panel user directly in the artifact
// dir; root only opens it, and tar runs as the site user reading stdin, so
// the archive can only ever write where the site user could.
func (d *deployment) unpackArtifact(ctx context.Context) error {
	l, dir := d.l, d.dir()
	path := filepath.Join(d.env.Cfg.ArtifactDir, d.artifact)
	d.logf("\n==> Unpack artifact\n%s\n", d.artifact)
	f, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return fmt.Errorf("artifact: %w", err)
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil || !fi.Mode().IsRegular() {
		return fmt.Errorf("artifact is not a regular file")
	}
	if owner, err := lookupOwner(d.env, d.env.Cfg.PanelUser); err == nil && !d.env.Cfg.DryRun {
		if st, ok := fi.Sys().(*syscall.Stat_t); !ok || int(st.Uid) != owner.UID || st.Nlink != 1 {
			return fmt.Errorf("artifact must be a file written by the panel")
		}
	}
	if fi.Size() > 1<<30 {
		return fmt.Errorf("artifact is larger than 1 GB")
	}
	if err := asUser(ctx, d.env, l.User, "/usr/bin/install", "-d", "-m", "0750", "--", dir); err != nil {
		return err
	}
	// Decompress here, capped: a small archive must not expand into a full disk.
	zr, err := gzip.NewReader(f)
	if err != nil {
		return fmt.Errorf("artifact is not gzip: %w", err)
	}
	capped := &capReader{r: zr, left: maxArtifactExpanded}
	var out strings.Builder
	_, err = d.env.Exec.RunIO(ctx, capped, &out, "setpriv", "--reuid="+l.User, "--regid="+primaryGroup(l.User), "--init-groups", "--reset-env", "--",
		"/usr/bin/tar", "--extract", "--file=-", "--directory="+dir, "--no-same-owner", "--no-same-permissions", "--no-overwrite-dir")
	d.logf("%s", out.String())
	if capped.exceeded {
		return fmt.Errorf("artifact expands to more than %d GB", maxArtifactExpanded>>30)
	}
	if err != nil {
		return fmt.Errorf("unpacking the artifact failed: %w", err)
	}
	return nil
}

const maxArtifactExpanded = 4 << 30

// capReader fails once more than left bytes were read.
type capReader struct {
	r        io.Reader
	left     int64
	exceeded bool
}

func (c *capReader) Read(p []byte) (int, error) {
	if c.left <= 0 { // at the limit: fine only if the stream ends here
		var probe [1]byte
		n, err := c.r.Read(probe[:])
		if n == 0 && err != nil {
			return 0, err
		}
		c.exceeded = true
		return 0, fmt.Errorf("size limit exceeded")
	}
	if int64(len(p)) > c.left {
		p = p[:c.left+1] // one byte over tells "exceeded" from "exactly at the limit"
	}
	n, err := c.r.Read(p)
	c.left -= int64(n)
	if c.left < 0 {
		c.exceeded = true
		return n, fmt.Errorf("size limit exceeded")
	}
	return n, err
}

// placeholderDir reports a real folder (not a link) holding nothing but
// dotfiles such as .gitignore: a placeholder a repository keeps for a link.
func placeholderDir(path string) bool {
	if fi, err := os.Lstat(path); err != nil || !fi.IsDir() {
		return false
	}
	entries, err := os.ReadDir(path)
	if err != nil {
		return false
	}
	for _, e := range entries {
		if !e.Type().IsRegular() || !strings.HasPrefix(e.Name(), ".") {
			return false
		}
	}
	return true
}
