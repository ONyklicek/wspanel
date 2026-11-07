package ops

import (
	"encoding/json"
	"os"
	"path/filepath"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// siteState is the agent's own record of a site, kept in its root-only state
// dir. The panel's database is not trusted for anything that decides which
// command runs; this is.
type siteState struct {
	Domain     string `json:"domain"`
	Framework  string `json:"framework"`
	PHPVersion string `json:"php_version"`
	Mode       string `json:"mode"`
}

func siteStatePath(cfg config.Config, domain string) string {
	return filepath.Join(cfg.StateDir, "sites", domain+".json")
}

func saveSiteState(env *Env, st siteState) error {
	dir := filepath.Join(env.Cfg.StateDir, "sites")
	if err := env.FS.MkdirAll(dir, 0o700, fsx.Root); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(st, "", "  ")
	return env.FS.WriteFile(siteStatePath(env.Cfg, st.Domain), b, 0o600, fsx.Root)
}

// loadSite returns the site's state and layout. Sites created before the
// state file existed are inferred from the files on disk.
func loadSite(env *Env, domain string) (siteState, siteLayout, error) {
	if err := validate.Domain(domain); err != nil {
		return siteState{}, siteLayout{}, err
	}
	var st siteState
	if b, err := os.ReadFile(siteStatePath(env.Cfg, domain)); err == nil {
		if err := json.Unmarshal(b, &st); err != nil {
			return st, siteLayout{}, err
		}
	} else {
		st = siteState{Domain: domain, Mode: "classic", Framework: "php"}
		home := filepath.Join(env.Cfg.SitesRoot, domain)
		if !fsx.Exists(home) {
			return st, siteLayout{}, protocol.NotFound("site " + domain + " does not exist")
		}
		switch {
		case fsx.Exists(filepath.Join(home, "shared", ".env")):
			st.Framework = "laravel"
		case fsx.Exists(filepath.Join(home, "shared", ".env.local")):
			st.Framework = "symfony"
		}
		user := validate.SiteUser(domain)
		for _, v := range env.Cfg.PHPVersions {
			if fsx.Exists(filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, v), user+".conf")) {
				st.PHPVersion = v
			}
		}
		if st.PHPVersion == "" {
			return st, siteLayout{}, protocol.NotFound("site " + domain + " has no PHP-FPM pool")
		}
	}
	l, err := layout(env.Cfg, st.Domain, st.Framework, st.PHPVersion)
	return st, l, err
}
