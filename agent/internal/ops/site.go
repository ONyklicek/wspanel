package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// siteLayout is everything derived from a validated domain. No path in here
// is ever built from unvalidated input.
type siteLayout struct {
	Domain     string
	Framework  string
	PHPVersion string
	User       string
	Home       string
	Socket     string
	PoolFile   string
	ProxyFile  string
	ProxyUser  string
}

func layout(cfg config.Config, domain, framework, version string) (siteLayout, error) {
	user := validate.SiteUser(domain)
	l := siteLayout{
		Domain:     domain,
		Framework:  framework,
		PHPVersion: version,
		User:       user,
		Home:       filepath.Join(cfg.SitesRoot, domain),
		Socket:     filepath.Join(cfg.FPMSocketDir, "php"+version+"-"+user+".sock"),
		PoolFile:   filepath.Join(config.ForVersion(cfg.FPMPoolDir, version), user+".conf"),
		ProxyFile:  filepath.Join(cfg.ProxySitesDir, domain+".caddy"),
		ProxyUser:  cfg.ProxyUser,
	}
	if !fsx.Within(cfg.SitesRoot, l.Home) {
		return l, protocol.Invalid("domain resolves outside sites root")
	}
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
	case "laravel":
		return ".env"
	case "symfony":
		return ".env.local"
	}
	return ""
}

type siteCreateParams struct {
	Domain     string `json:"domain"`
	Framework  string `json:"framework"`
	PHPVersion string `json:"php_version"`
	Mode       string `json:"mode"`
}

func (p *siteCreateParams) validate(cfg config.Config) error {
	if p.Mode == "" {
		p.Mode = "classic"
	}
	if err := validate.Domain(p.Domain); err != nil {
		return err
	}
	if err := validate.OneOf("framework", p.Framework, validate.Frameworks); err != nil {
		return err
	}
	if err := validate.OneOf("mode", p.Mode, validate.SiteModes); err != nil {
		return err
	}
	return validate.OneOf("php_version", p.PHPVersion, cfg.PHPVersions)
}

func siteCreate(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p siteCreateParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := p.validate(env.Cfg); err != nil {
		return nil, err
	}
	l, err := layout(env.Cfg, p.Domain, p.Framework, p.PHPVersion)
	if err != nil {
		return nil, err
	}
	for _, path := range []string{l.Home, l.PoolFile, l.ProxyFile} {
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
	if _, err = env.Exec.Run(ctx, "useradd", "--system", "--user-group", "--no-create-home",
		"--home-dir", l.Home, "--shell", "/usr/sbin/nologin", "--comment", "WSPanel site "+l.Domain, l.User); err != nil {
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
	if err = createSiteTree(env, l, owner); err != nil {
		return nil, err
	}
	if err = grantProxyAccess(ctx, env, l); err != nil {
		return nil, err
	}

	// 3. PHP-FPM pool: write, validate, reload.
	pool, err := render("fpm-pool.conf.tmpl", l)
	if err != nil {
		return nil, err
	}
	if err = env.FS.WriteFile(l.PoolFile, pool, 0o644, fsx.Root); err != nil {
		return nil, err
	}
	fpmUnit := config.ForVersion(env.Cfg.FPMUnit, l.PHPVersion)
	rollback.add(func() {
		_ = os.Remove(l.PoolFile)
		_, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "reload", fpmUnit)
	})
	if err = validateFPM(ctx, env, l.PHPVersion); err != nil {
		return nil, err
	}
	if _, err = env.Exec.Run(ctx, "systemctl", "reload", fpmUnit); err != nil {
		return nil, err
	}

	// 4. Proxy: write, validate, reload.
	site, err := render("proxy-site.caddy.tmpl", l)
	if err != nil {
		return nil, err
	}
	if err = env.FS.WriteFile(l.ProxyFile, site, 0o644, fsx.Root); err != nil {
		return nil, err
	}
	rollback.add(func() {
		_ = os.Remove(l.ProxyFile)
		_, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "reload", env.Cfg.ProxyUnit)
	})
	if err = validateProxy(ctx, env); err != nil {
		return nil, err
	}
	if _, err = env.Exec.Run(ctx, "systemctl", "reload", env.Cfg.ProxyUnit); err != nil {
		return nil, err
	}

	return map[string]string{
		"domain":      l.Domain,
		"framework":   l.Framework,
		"mode":        p.Mode,
		"php_version": l.PHPVersion,
		"system_user": l.User,
		"home":        l.Home,
		"doc_root":    filepath.Join(l.Home, "current", "public"),
		"fpm_socket":  l.Socket,
		"env_file":    envFilePath(l),
	}, nil
}

func envFilePath(l siteLayout) string {
	if f := sharedEnvFile(l.Framework); f != "" {
		return filepath.Join(l.Home, "shared", f)
	}
	return ""
}

// createSiteTree builds the zero-downtime layout:
//
//	releases/initial/public/index.php   placeholder until the first deploy
//	current -> releases/initial
//	shared/                             per-framework persistent dirs + env file
//	tmp/ logs/
func createSiteTree(env *Env, l siteLayout, o fsx.Owner) error {
	dirs := []struct {
		rel  string
		mode os.FileMode
	}{
		{"releases", 0o750}, {"releases/initial", 0o750}, {"releases/initial/public", 0o750},
		{"shared", 0o750}, {"tmp", 0o700}, {"logs", 0o750},
	}
	for _, d := range sharedDirs(l.Framework) {
		dirs = append(dirs, struct {
			rel  string
			mode os.FileMode
		}{"shared/" + d, 0o750})
	}
	for _, d := range dirs {
		if err := env.FS.Mkdir(filepath.Join(l.Home, d.rel), d.mode, o); err != nil {
			return err
		}
	}
	index, err := render("placeholder-index.php.tmpl", l)
	if err != nil {
		return err
	}
	if err := env.FS.WriteFile(filepath.Join(l.Home, "releases/initial/public/index.php"), index, 0o640, o); err != nil {
		return err
	}
	if f := sharedEnvFile(l.Framework); f != "" {
		if err := env.FS.WriteFile(filepath.Join(l.Home, "shared", f), nil, 0o600, o); err != nil {
			return err
		}
	}
	return env.FS.Symlink("releases/initial", filepath.Join(l.Home, "current"), o)
}

// grantProxyAccess lets the proxy user traverse the site home and read
// releases (static files) via ACLs — but not shared/, where the env file
// with credentials lives. Laravel's public storage is the one exception.
func grantProxyAccess(ctx context.Context, env *Env, l siteLayout) error {
	acl := "u:" + l.ProxyUser
	if _, err := env.Exec.Run(ctx, "setfacl", "-m", acl+":x", l.Home); err != nil {
		return err
	}
	read := acl + ":rX,d:" + acl + ":rX"
	if _, err := env.Exec.Run(ctx, "setfacl", "-R", "-P", "-m", read, filepath.Join(l.Home, "releases")); err != nil {
		return err
	}
	if l.Framework == "laravel" {
		for _, d := range []string{"shared", "shared/storage", "shared/storage/app"} {
			if _, err := env.Exec.Run(ctx, "setfacl", "-m", acl+":x", filepath.Join(l.Home, d)); err != nil {
				return err
			}
		}
		if _, err := env.Exec.Run(ctx, "setfacl", "-R", "-P", "-m", read, filepath.Join(l.Home, "shared/storage/app/public")); err != nil {
			return err
		}
	}
	return nil
}

type siteDeleteParams struct {
	Domain  string `json:"domain"`
	Confirm string `json:"confirm"` // must repeat the domain
}

// siteDelete removes the site's pool, proxy config and system user. The home
// directory is not deleted but moved to the root-only trash.
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
	l, err := layout(env.Cfg, p.Domain, "php", env.Cfg.DefaultPHPVer)
	if err != nil {
		return nil, err
	}
	if !fsx.Exists(l.Home) && !fsx.Exists(l.ProxyFile) {
		return nil, protocol.NotFound("site " + p.Domain + " does not exist")
	}

	// Proxy first, so the site stops receiving traffic.
	if fsx.Exists(l.ProxyFile) {
		if err := os.Remove(l.ProxyFile); err != nil {
			return nil, err
		}
		if err := validateProxy(ctx, env); err != nil {
			return nil, err
		}
		if _, err := env.Exec.Run(ctx, "systemctl", "reload", env.Cfg.ProxyUnit); err != nil {
			return nil, err
		}
	}
	// Pools of any version (handles a site that switched versions).
	for _, v := range env.Cfg.PHPVersions {
		pool := filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, v), l.User+".conf")
		if !fsx.Exists(pool) {
			continue
		}
		if err := os.Remove(pool); err != nil {
			return nil, err
		}
		if err := validateFPM(ctx, env, v); err != nil {
			return nil, err
		}
		if _, err := env.Exec.Run(ctx, "systemctl", "reload", config.ForVersion(env.Cfg.FPMUnit, v)); err != nil {
			return nil, err
		}
	}
	if _, err := lookupOwner(env, l.User); err == nil {
		if _, err := env.Exec.Run(ctx, "userdel", l.User); err != nil {
			return nil, err
		}
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
