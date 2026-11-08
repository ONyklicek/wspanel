package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// siteLayout is everything derived from a validated site state. No path in
// here is ever built from unvalidated input.
type siteLayout struct {
	Domain      string
	Framework   string
	Mode        string
	PHPVersion  string
	NodeVersion string
	Port        int
	ReverbPort  int
	User        string

	OpcacheProduction bool
	Disabled          bool
	FPMChildren       int    // classic: pm.max_children
	TLS               string // effective: auto | internal | custom
	TLSDirective      string // edge "tls …" line, empty for auto
	MailMode          string // effective: send | capture
	SendmailPath      string // PHP mail() for this mode
	Home              string

	// classic
	Socket           string // FPM socket
	PoolFile         string
	WebFile          string // web tier site block
	XhguiDir         string
	Xhprof           bool
	XhprofRatio      int
	WebUser          string
	WebSocket        string
	DisableFunctions string

	// worker / node
	AppUnit        string
	AppUnitFile    string
	InstanceConfig string // worker: FrankenPHP Caddyfile
	RunDir         string // worker: socket dir, group = edge proxy
	AppSocket      string
	TmpfilesFile   string
	WorkerFile     string
	WorkerIndex    string
	WorkerThreads  int
	WorkerMaxReq   int
	WorkerEnv      []string

	// php.ini values from the panel (pool or FrankenPHP php_ini), and whether
	// errors are shown (off unless the site asked).
	PHPValues     []phpLine
	DisplayErrors bool

	// Edge settings, ready for the template.
	Edge         edgeSettings
	Directives   string // custom rules, indented for the edge route
	Maintenance  string // bypass secret while a maintenance page is up
	EdgeHosts    string // the site block's addresses: domain + aliases
	AuthMatcher  string // basic_auth matcher ("*" = whole site)
	AllowMatcher string // path the IP allowlist guards
	TLSInternal  bool   // redirect-only hosts get the local CA too

	EdgeFile string // TLS edge site block
	Upstream string // where the edge forwards to
}

// DisableFunctions is the single list applied to every PHP-FPM pool (sites
// and, via provision/files/panel-pool.conf, the panel; a test keeps them in
// sync). putenv is included because putenv(LD_PRELOAD) + mail() is a classic
// way around the rest of the list.
const DisableFunctions = "exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,putenv,dl"

func appUnitName(user string) string { return "wspanel-" + user + "-app.service" }

// appUnitNameB is a Node site's second slot (zero-downtime deploys).
func appUnitNameB(user string) string { return "wspanel-" + user + "-appb.service" }

func layout(cfg config.Config, st siteState) (siteLayout, error) {
	user := validate.SiteUser(st.Domain)
	l := siteLayout{
		Domain:      st.Domain,
		Framework:   st.Framework,
		Mode:        st.Mode,
		PHPVersion:  st.PHPVersion,
		NodeVersion: st.NodeVersion,
		Port:        st.Port,
		ReverbPort:  st.ReverbPort,

		OpcacheProduction: st.OpcacheProduction,
		Disabled:          st.Disabled,
		Xhprof:            st.Xhprof != nil && st.Mode == "classic",
		XhprofRatio:       xhprofRatio(st),
		FPMChildren:       defaultFPMChildren,
		User:              user,
		Home:              filepath.Join(cfg.SitesRoot, st.Domain),

		WebFile:          filepath.Join(cfg.WebSitesDir, st.Domain+".caddy"),
		XhguiDir:         cfg.XhguiDir,
		WebUser:          cfg.WebUser,
		WebSocket:        cfg.WebSocket,
		DisableFunctions: DisableFunctions,

		AppUnit:        appUnitName(user),
		AppUnitFile:    filepath.Join(cfg.SystemdDir, appUnitName(user)),
		InstanceConfig: filepath.Join(cfg.SiteConfigDir, user+".Caddyfile"),
		RunDir:         filepath.Join(cfg.SiteRunDir, user),
		TmpfilesFile:   filepath.Join(cfg.TmpfilesDir, "wspanel-"+user+".conf"),
		WorkerThreads:  workerThreads(),
		WorkerMaxReq:   500,
		PHPValues:      phpLines(st.PHPSettings),
		DisplayErrors:  st.PHPSettings["display_errors"] == "on",

		EdgeFile: filepath.Join(cfg.ProxySitesDir, st.Domain+".caddy"),
	}
	l.Maintenance = st.Maintenance
	if st.Directives != "" {
		l.Directives = "\t\t" + strings.ReplaceAll(st.Directives, "\n", "\n\t\t")
	}
	if st.FPMChildren > 0 {
		l.FPMChildren = st.FPMChildren
	}
	l.TLS = st.TLS
	if l.TLS == "" {
		l.TLS = "auto"
	}
	switch l.TLS {
	case "internal":
		l.TLSDirective = "tls internal"
	case "custom":
		l.TLSDirective = "tls " + filepath.Join(cfg.ProxyCertDir, st.Domain+".crt") + " " + filepath.Join(cfg.ProxyCertDir, st.Domain+".key")
	}
	l.MailMode = st.MailMode
	if l.MailMode == "" {
		l.MailMode = cfg.MailDefault
	}
	l.SendmailPath = sendmailPath(cfg, l)
	l.AppSocket = filepath.Join(l.RunDir, "http.sock")
	if st.Mode == "classic" && st.PHPVersion != "" {
		l.Socket = filepath.Join(cfg.FPMSocketDir, "php"+st.PHPVersion+"-"+user+".sock")
		l.PoolFile = filepath.Join(config.ForVersion(cfg.FPMPoolDir, st.PHPVersion), user+".conf")
	}
	switch st.Mode {
	case "classic":
		l.Upstream = "unix/" + cfg.WebSocket
	case "worker":
		l.Upstream = "unix/" + l.AppSocket
		_, l.WorkerFile, l.WorkerEnv = workerEntry(st.Framework, l.Home)
		l.WorkerIndex = workerIndex(st.Framework)
	case "node":
		// The slot that serves now: its port, its unit.
		if st.Slot == "b" && st.PortB > 0 {
			l.Port = st.PortB
			l.AppUnit = appUnitNameB(user)
			l.AppUnitFile = filepath.Join(cfg.SystemdDir, appUnitNameB(user))
		}
		l.Upstream = "127.0.0.1:" + strconv.Itoa(l.Port)
	}
	if !fsx.Within(cfg.SitesRoot, l.Home) {
		return l, protocol.Invalid("domain resolves outside sites root")
	}
	if n, err := strconv.Atoi(st.PHPSettings["worker_threads"]); err == nil && n > 0 {
		l.WorkerThreads = n
	}
	if n, err := strconv.Atoi(st.PHPSettings["max_requests"]); err == nil && st.PHPSettings["max_requests"] != "" {
		l.WorkerMaxReq = n
	}
	l.EdgeHosts = st.Domain
	if st.Edge != nil {
		l.Edge = *st.Edge
		if len(st.Edge.Aliases) > 0 {
			l.EdgeHosts += ", " + strings.Join(st.Edge.Aliases, ", ")
		}
		l.AuthMatcher = edgePathMatcher(st.Edge.AuthPath)
		l.AllowMatcher = edgePathMatcher(st.Edge.AllowPath)
	}
	l.TLSInternal = st.TLS == "internal"
	return l, nil
}

// sharedDirs are per-framework directories that survive deploys.
func sharedDirs(framework string) []string {
	switch framework {
	case "laravel":
		return []string{
			"storage", "storage/app", "storage/app/public", "storage/app/private",
			"storage/framework", "storage/framework/cache", "storage/framework/cache/data",
			"storage/framework/sessions", "storage/framework/views", "storage/logs",
		}
	case "symfony":
		return []string{"var", "var/log"}
	}
	return nil
}

// sharedEnvFile is where DB credentials etc. go for each framework.
func sharedEnvFile(framework string) string {
	switch framework {
	case "laravel", "nuxt", "next", "node", "php":
		// php: plain sites read DB credentials from ~/shared/.env (outside the docroot).
		return ".env"
	case "symfony":
		return ".env.local"
	}
	return ""
}

// siteParams is the common, validated description of how a site runs.
type siteParams struct {
	Domain      string `json:"domain"`
	Framework   string `json:"framework"`
	Mode        string `json:"mode"`
	PHPVersion  string `json:"php_version,omitempty"`
	NodeVersion string `json:"node_version,omitempty"`
	TLS         string `json:"tls,omitempty"` // create only: auto | internal (default by domain)
	// create only: the client's group (wsc_<id>) and the FPM process limit
	Group       string `json:"group,omitempty"`
	FPMChildren int    `json:"fpm_children,omitempty"`
}

// resolve validates params against the mode and the server, and returns the
// state to store. Ports are not allocated here.
func (p *siteParams) resolve(ctx context.Context, env *Env) (siteState, error) {
	if p.Mode == "" {
		p.Mode = "classic"
	}
	if err := validate.Domain(p.Domain); err != nil {
		return siteState{}, err
	}
	if err := validate.OneOf("mode", p.Mode, validate.SiteModes); err != nil {
		return siteState{}, err
	}
	if err := validate.OneOf("framework", p.Framework, validate.FrameworksForMode(p.Mode)); err != nil {
		return siteState{}, err
	}
	st := siteState{Domain: p.Domain, Framework: p.Framework, Mode: p.Mode}
	if p.TLS != "" {
		if err := validate.OneOf("tls", p.TLS, []string{"auto", "internal"}); err != nil {
			return st, err
		}
	}
	switch p.Mode {
	case "classic":
		if p.NodeVersion != "" {
			return st, protocol.Invalid("node_version: only valid for node mode")
		}
		if err := validate.OneOf("php_version", p.PHPVersion, env.Cfg.PHPVersions); err != nil {
			return st, err
		}
		st.PHPVersion = p.PHPVersion
	case "worker":
		if p.NodeVersion != "" {
			return st, protocol.Invalid("node_version: only valid for node mode")
		}
		v, err := workerPHPVersion(ctx, env)
		if err != nil {
			return st, err
		}
		if p.PHPVersion != "" && p.PHPVersion != v {
			return st, protocol.Invalid(fmt.Sprintf("php_version: worker mode runs PHP %s (built into FrankenPHP)", v))
		}
		st.PHPVersion = v
	case "node":
		if p.PHPVersion != "" {
			return st, protocol.Invalid("php_version: not valid for node mode")
		}
		if err := validate.OneOf("node_version", p.NodeVersion, nodeMajors(env.Cfg)); err != nil {
			return st, err
		}
		st.NodeVersion = p.NodeVersion
	}
	return st, nil
}

func siteCreate(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p siteParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, err := p.resolve(ctx, env)
	if err != nil {
		return nil, err
	}
	if err := checkClientGroup(env, p.Group); err != nil {
		return nil, err
	}
	if p.FPMChildren < 0 || p.FPMChildren > maxFPMChildren {
		return nil, protocol.Invalid(fmt.Sprintf("fpm_children: must be 1-%d", maxFPMChildren))
	}
	st.Group, st.FPMChildren = p.Group, p.FPMChildren
	st.TLS = p.TLS
	if st.TLS == "" && isInternalDomain(p.Domain) {
		st.TLS = "internal" // no public CA can issue for these
	}
	if st.Mode == "node" {
		if st.Port, err = allocatePort(env); err != nil {
			return nil, err
		}
	}
	l, err := layout(env.Cfg, st)
	if err != nil {
		return nil, err
	}
	for _, path := range []string{l.Home, l.EdgeFile, l.WebFile, l.AppUnitFile, l.InstanceConfig, siteStatePath(env.Cfg, p.Domain)} {
		if fsx.Exists(path) {
			return nil, protocol.Conflict(fmt.Sprintf("site %s already exists (%s)", p.Domain, path))
		}
	}
	if _, err := lookupOwner(env, l.User); err == nil && !env.Cfg.DryRun {
		return nil, protocol.Conflict("system user " + l.User + " already exists")
	}

	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()

	// 1. System user (no login shell; SSH access is a separate, later step).
	// A client's site has the client's group as its primary group (quota).
	groupArgs := []string{"--user-group"}
	if st.Group != "" {
		groupArgs = []string{"--gid", st.Group}
	}
	if _, err = env.Exec.Run(ctx, "useradd", append(append([]string{"--system"}, groupArgs...), "--no-create-home",
		"--home-dir", l.Home, "--shell", "/usr/sbin/nologin", "--comment", "WSPanel site "+l.Domain, l.User)...); err != nil {
		return nil, err
	}
	rollback.add(func() { _, _ = env.Exec.Run(context.WithoutCancel(ctx), "userdel", l.User) })
	owner, err := lookupOwner(env, l.User)
	if err != nil {
		return nil, err
	}

	// 2. Directory tree.
	if err = env.FS.MkdirAll(env.Cfg.SitesRoot, 0o751, fsx.Root); err != nil {
		return nil, err
	}
	if err = env.FS.Mkdir(l.Home, 0o750, owner); err != nil {
		return nil, err
	}
	rollback.add(func() { _ = os.RemoveAll(l.Home) })
	smtpPassword := ""
	if l.MailMode == "send" {
		if smtpPassword, err = setSMTPLogin(ctx, env, l); err != nil {
			return nil, err
		}
		rollback.add(func() { removeSMTPLogin(context.WithoutCancel(ctx), env, l.User) })
	}
	if err = createSiteTree(env, l, owner, smtpPassword); err != nil {
		return nil, err
	}

	// 3. Backend for the mode, 4. edge, 5. state.
	started, err := setupBackend(ctx, env, l, &rollback)
	if err != nil {
		return nil, err
	}
	if err = writeEdge(ctx, env, l, &rollback); err != nil {
		return nil, err
	}
	if err = saveSiteState(env, st); err != nil {
		return nil, err
	}
	if err = writeSenderMaps(ctx, env); err != nil {
		return nil, err
	}
	if err = writeToolLinks(env, l); err != nil {
		return nil, err
	}
	if st.Group != "" {
		if err = isolateHome(ctx, env, l, true); err != nil {
			return nil, err
		}
	}
	return siteOutput(l, started), nil
}

func siteOutput(l siteLayout, started bool) map[string]any {
	out := map[string]any{
		"domain":      l.Domain,
		"framework":   l.Framework,
		"mode":        l.Mode,
		"php_version": l.PHPVersion,
		"system_user": l.User,
		"home":        l.Home,
		"env_file":    envFilePath(l),
		"started":     started,
	}
	switch l.Mode {
	case "node":
		out["node_version"] = l.NodeVersion
		out["port"] = l.Port
		out["doc_root"] = filepath.Join(l.Home, "current")
	default:
		out["doc_root"] = filepath.Join(l.Home, "current", "public")
	}
	return out
}

func envFilePath(l siteLayout) string {
	if f := sharedEnvFile(l.Framework); f != "" {
		return filepath.Join(l.Home, "shared", f)
	}
	return ""
}

// createSiteTree builds the zero-downtime layout:
//
//	releases/initial/           placeholder until the first deploy
//	current -> releases/initial
//	shared/                     per-framework persistent dirs + env file
//	tmp/ logs/
func createSiteTree(env *Env, l siteLayout, o fsx.Owner, smtpPassword string) error {
	dirs := []string{"releases", "releases/initial", "shared", "logs"}
	if l.Mode != "node" {
		dirs = append(dirs, "releases/initial/public")
	}
	for _, d := range dirs {
		if err := env.FS.Mkdir(filepath.Join(l.Home, d), 0o750, o); err != nil {
			return err
		}
	}
	if err := env.FS.Mkdir(filepath.Join(l.Home, "tmp"), 0o700, o); err != nil {
		return err
	}
	// The tree was created by root a moment ago and the user has no process
	// yet, so nothing in it can be a planted symlink.
	for _, d := range sharedDirs(l.Framework) {
		if err := env.FS.MkdirAll(filepath.Join(l.Home, "shared", d), 0o750, o); err != nil {
			return err
		}
	}
	if f := sharedEnvFile(l.Framework); f != "" {
		keys, values := mailEnv(env.Cfg, l, smtpPassword)
		if err := env.FS.WriteFile(filepath.Join(l.Home, "shared", f), []byte(mergeEnv("", keys, values)), 0o600, o); err != nil {
			return err
		}
	}
	if l.Mode == "node" {
		js, err := render("placeholder-server.js.tmpl", l)
		if err != nil {
			return err
		}
		if err := env.FS.WriteFile(filepath.Join(l.Home, "releases/initial/server.js"), js, 0o640, o); err != nil {
			return err
		}
	} else {
		index, err := render("placeholder-index.php.tmpl", l)
		if err != nil {
			return err
		}
		if err := env.FS.WriteFile(filepath.Join(l.Home, "releases/initial/public/index.php"), index, 0o640, o); err != nil {
			return err
		}
	}
	if err := env.FS.Symlink("releases/initial", filepath.Join(l.Home, "current"), o); err != nil {
		return err
	}
	// Plain PHP sites are usually uploaded over SFTP: public_html is the
	// familiar name for the document root (it follows current/ after deploys).
	if l.Framework == "php" {
		return env.FS.Symlink("current/public", filepath.Join(l.Home, "public_html"), o)
	}
	return nil
}

// asUser runs a fixed binary as the site user (setpriv drops to its uid/gid
// and groups). Used for every change inside an existing site home: the user
// controls that tree and may have planted symlinks, which then lead only
// where the user could write or chmod anyway — never where root could.
func asUser(ctx context.Context, env *Env, user, bin string, args ...string) error {
	full := append(append(userCreds(user), bin), args...)
	_, err := env.Exec.Run(ctx, "setpriv", full...)
	return err
}

// ensureShared creates missing per-framework shared dirs and the env file
// when a site switches framework — as the site user (see asUser).
func ensureShared(ctx context.Context, env *Env, l siteLayout) error {
	var dirs []string
	for _, d := range sharedDirs(l.Framework) {
		dirs = append(dirs, filepath.Join(l.Home, "shared", d))
	}
	if len(dirs) > 0 {
		if err := asUser(ctx, env, l.User, "/usr/bin/install", append([]string{"-d", "-m", "0750"}, dirs...)...); err != nil {
			return err
		}
	}
	if f := sharedEnvFile(l.Framework); f != "" {
		path := filepath.Join(l.Home, "shared", f)
		if _, err := os.Lstat(path); os.IsNotExist(err) {
			if err := asUser(ctx, env, l.User, "/usr/bin/install", "-m", "0600", "/dev/null", path); err != nil {
				return err
			}
		}
	}
	return nil
}

var releaseNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`)

// currentRelease is the release dir `current` points to. It must be a plain
// name of a real directory (not a symlink) directly under releases/.
func currentRelease(l siteLayout) (string, error) {
	target, err := os.Readlink(filepath.Join(l.Home, "current"))
	if err != nil {
		return "", fmt.Errorf("read current release: %w", err)
	}
	name := filepath.Base(target)
	if filepath.Clean(target) != filepath.Join("releases", name) && filepath.Clean(target) != filepath.Join(l.Home, "releases", name) {
		return "", protocol.Invalid("current must point to releases/<name>, got " + target)
	}
	if !releaseNameRe.MatchString(name) || name == ".." {
		return "", protocol.Invalid("invalid release name " + name)
	}
	fi, err := os.Lstat(filepath.Join(l.Home, "releases", name))
	if err != nil || !fi.IsDir() {
		return "", protocol.Invalid("release " + name + " is not a directory")
	}
	return name, nil
}

// grantWebAccess gives the web tier exactly what it must serve: traverse
// (x, no listing) on the path down to public/, and read on public/ itself.
// Caddy follows symlinks, so a symlink planted by the site can only ever
// reach files the web user may read — i.e. public files.
// Laravel's public/storage -> shared/storage/app/public is the one other path.
//
// setfacl runs as the site user: an owner may set ACLs on its own files, and a
// planted symlink then cannot steer the grant onto anything the user does not own.
func grantWebAccess(ctx context.Context, env *Env, l siteLayout, release string) error {
	acl := "u:" + l.WebUser
	traverse := []string{l.Home, filepath.Join(l.Home, "releases"), filepath.Join(l.Home, "releases", release)}
	readable := []string{filepath.Join(l.Home, "releases", release, "public")}
	if l.Framework == "laravel" {
		for _, d := range []string{"shared", "shared/storage", "shared/storage/app"} {
			traverse = append(traverse, filepath.Join(l.Home, d))
		}
		readable = append(readable, filepath.Join(l.Home, "shared/storage/app/public"))
	}
	if err := asUser(ctx, env, l.User, "/usr/bin/setfacl", append([]string{"-m", acl + ":x"}, traverse...)...); err != nil {
		return err
	}
	read := acl + ":rX,d:" + acl + ":rX"
	for _, d := range readable {
		if !fsx.Exists(d) {
			continue
		}
		if err := asUser(ctx, env, l.User, "/usr/bin/setfacl", "-R", "-P", "-m", read, d); err != nil {
			return err
		}
	}
	return nil
}

type siteDeleteParams struct {
	Domain  string `json:"domain"`
	Confirm string `json:"confirm"` // must repeat the domain
}

// siteDelete removes everything the site has in any mode and its system user.
// The home directory is not deleted but moved to the root-only trash.
func siteDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p siteDeleteParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if p.Confirm != p.Domain {
		return nil, protocol.Invalid("confirm: must equal domain")
	}
	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()
	l, err := layout(env.Cfg, siteState{Domain: p.Domain})
	if err != nil {
		return nil, err
	}
	if !fsx.Exists(l.Home) && !fsx.Exists(l.EdgeFile) && !fsx.Exists(siteStatePath(env.Cfg, p.Domain)) {
		return nil, protocol.NotFound("site " + p.Domain + " does not exist")
	}

	// Edge first, so the site stops receiving traffic.
	if err := removeCaddyBlock(ctx, env, l.EdgeFile, env.Cfg.ProxyUnit, validateEdge); err != nil {
		return nil, err
	}
	// Workers and tasks must stop before their user disappears. (Backups of
	// the site stay; only their schedule goes.)
	if err := removeSiteBackupSchedule(ctx, env, l.User); err != nil {
		return nil, err
	}
	removeNodeSlots(ctx, env, l)
	for _, typ := range siteWorkerTypes(env, l.User) {
		if err := removeWorker(ctx, env, l, typ); err != nil {
			return nil, err
		}
	}
	if err := removeSiteValkey(ctx, env, l); err != nil {
		return nil, err
	}
	for _, id := range siteCronIDs(env.Cfg, l.User) {
		if err := removeCron(ctx, env, l.User, id); err != nil {
			return nil, err
		}
	}
	for _, mode := range validate.SiteModes {
		if err := teardownBackend(ctx, env, l, mode, ""); err != nil {
			return nil, err
		}
	}
	if _, err := lookupOwner(env, l.User); err == nil {
		// Units are gone by now: end everything left (SSH sessions, finishing
		// FPM workers); userdel refuses otherwise. Then make sure the freed uid
		// runs nothing before it can be reused.
		uid, _ := lookupUID(l.User)
		if err := killAllProcesses(ctx, env, l.User); err != nil {
			return nil, err
		}
		if _, err := env.Exec.Run(ctx, "userdel", l.User); err != nil {
			return nil, err
		}
		if err := noProcessesLeft(env, uid); err != nil {
			return nil, err
		}
	}
	_ = os.Remove(siteStatePath(env.Cfg, p.Domain))
	_ = os.Remove(historyPath(env, p.Domain))
	removeDeployLogs(env, p.Domain)
	_ = os.Remove(filepath.Join(env.Cfg.AuthorizedKeysDir, l.User))
	removeToolLinks(env, l.User)
	_ = os.Remove(filepath.Join(env.Cfg.ProxyCertDir, p.Domain+".crt"))
	_ = os.Remove(filepath.Join(env.Cfg.ProxyCertDir, p.Domain+".key"))
	removeSMTPLogin(ctx, env, l.User)
	if err := writeSenderMaps(ctx, env); err != nil {
		return nil, fmt.Errorf("site removed, but updating the mail sender maps failed: %w", err)
	}
	trashed := ""
	if fsx.Exists(l.Home) {
		if err := env.FS.MkdirAll(env.Cfg.TrashDir, 0o700, fsx.Root); err != nil {
			return nil, err
		}
		trashed = filepath.Join(env.Cfg.TrashDir, p.Domain+"-"+time.Now().UTC().Format("20060102T150405Z"))
		if err := os.Rename(l.Home, trashed); err != nil {
			return nil, err
		}
	}
	return map[string]string{"domain": p.Domain, "trashed_to": trashed}, nil
}
