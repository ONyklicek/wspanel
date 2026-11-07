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
// command runs or which port is used; this is.
type siteState struct {
	Domain      string        `json:"domain"`
	Framework   string        `json:"framework"`
	Mode        string        `json:"mode"`
	PHPVersion  string        `json:"php_version,omitempty"`  // classic: FPM version; worker: FrankenPHP's PHP (informational)
	NodeVersion string        `json:"node_version,omitempty"` // node: major version
	Port        int           `json:"port,omitempty"`         // node: 127.0.0.1 port allocated by the agent
	ReverbPort  int           `json:"reverb_port,omitempty"`  // laravel: Reverb WebSocket server port
	SSHAccess   string        `json:"ssh_access,omitempty"`   // off | sftp | shell
	SSHPassword bool          `json:"ssh_password,omitempty"` // password login allowed (besides keys)
	Deploy      *deployConfig `json:"deploy,omitempty"`
	// classic: OPcache does not revalidate files (deploys reload FPM)
	OpcacheProduction bool `json:"opcache_production,omitempty"`
	// Disabled: the edge answers 503, app/workers/tasks are stopped.
	Disabled bool `json:"disabled,omitempty"`
	// MailMode: send (Postfix) | capture (Mailpit); empty = server default.
	MailMode string `json:"mail_mode,omitempty"`
	// TLS: auto (Let's Encrypt / ZeroSSL) | internal (local CA, for internal
	// domains) | custom (uploaded certificate). Empty = auto.
	TLS string `json:"tls,omitempty"`
	// PHPSettings: php.ini values set from the panel (see phpDirectives), plus
	// worker_threads / max_requests for FrankenPHP worker mode.
	PHPSettings map[string]string `json:"php_settings,omitempty"`
	// Edge: more domains, redirects, password/IP protection, headers.
	Edge *edgeSettings `json:"edge,omitempty"`
	// Backup: the whole-site backup schedule.
	Backup *siteBackupConfig `json:"backup,omitempty"`
	// Group: the client's group (wsc_<id>), the site user's primary group, so
	// the client's disk quota counts the site's files. Empty = the site's own group.
	Group string `json:"group,omitempty"`
	// FPMChildren: classic mode, pm.max_children (0 = default).
	FPMChildren int `json:"fpm_children,omitempty"`
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

// allSiteStates reads every state file (used for port allocation).
func allSiteStates(cfg config.Config) []siteState {
	files, _ := filepath.Glob(filepath.Join(cfg.StateDir, "sites", "*.json"))
	out := make([]siteState, 0, len(files))
	for _, f := range files {
		var st siteState
		if b, err := os.ReadFile(f); err == nil && json.Unmarshal(b, &st) == nil {
			out = append(out, st)
		}
	}
	return out
}

// loadSite returns the site's state and layout. Sites created before the
// state file existed (phase 1, always classic) are inferred from disk.
func loadSite(env *Env, domain string) (siteState, siteLayout, error) {
	if err := validate.Domain(domain); err != nil {
		return siteState{}, siteLayout{}, err
	}
	var st siteState
	if b, err := os.ReadFile(siteStatePath(env.Cfg, domain)); err == nil {
		if err := json.Unmarshal(b, &st); err != nil {
			return st, siteLayout{}, err
		}
		if st.Domain != domain {
			return st, siteLayout{}, protocol.Invalid("state file does not match domain")
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
	l, err := layout(env.Cfg, st)
	return st, l, err
}
