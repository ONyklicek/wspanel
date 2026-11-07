// Package config loads /etc/panel-agent/agent.json.
package config

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"os/user"
	"strconv"
	"strings"
)

type Config struct {
	Socket       string   `json:"socket"`
	SocketGroup  string   `json:"socket_group"`  // group allowed to connect (panel)
	AllowedUsers []string `json:"allowed_users"` // peer users accepted (checked via SO_PEERCRED)
	AuditLog     string   `json:"audit_log"`
	StateDir     string   `json:"state_dir"`
	DryRun       bool     `json:"dry_run"`

	SitesRoot string `json:"sites_root"`
	TrashDir  string `json:"trash_dir"`

	PHPVersions []string `json:"php_versions"`
	DBEngines   []string `json:"db_engines"`

	FPMPoolDir   string `json:"fpm_pool_dir"` // may contain {version}
	FPMBinary    string `json:"fpm_binary"`   // may contain {version}
	FPMUnit      string `json:"fpm_unit"`     // may contain {version}
	FPMSocketDir string `json:"fpm_socket_dir"`

	// Edge proxy: terminates TLS and holds the certificates. It has no access
	// to site files; it forwards to the web tier over a Unix socket.
	ProxyUnit     string `json:"proxy_unit"`
	ProxyConfig   string `json:"proxy_config"`
	ProxySitesDir string `json:"proxy_sites_dir"`

	// Web tier: serves static files and routes PHP to FPM. Holds no secrets
	// and may read only the public/ directories of sites (Caddy follows
	// symlinks, so whatever this user can read, a site could expose).
	WebUser       string `json:"web_user"`
	WebUnit       string `json:"web_unit"`
	WebConfig     string `json:"web_config"`
	WebSitesDir   string `json:"web_sites_dir"`
	WebSocket     string `json:"web_socket"`
	FrankenPHPBin string `json:"frankenphp_bin"`
	DefaultPHPVer string `json:"default_php_version"`

	// Worker mode (one FrankenPHP instance per site) and Node SSR.
	SiteConfigDir string `json:"site_config_dir"` // per-site FrankenPHP Caddyfiles
	SiteRunDir    string `json:"site_run_dir"`    // per-site socket dirs (group = proxy group)
	TmpfilesDir   string `json:"tmpfiles_dir"`
	ProxyGroup    string `json:"proxy_group"`   // group of the edge proxy, owns site socket dirs
	NodeDir       string `json:"node_dir"`      // fnm node-versions dir
	NodePortMin   int    `json:"node_port_min"` // must stay below net.ipv4.ip_unprivileged_port_start
	NodePortMax   int    `json:"node_port_max"`
	// Dev containers only: allow app ports although SocketBindAllow/Deny is not
	// enforced by the kernel (then an app could bind other privileged ports).
	AllowUnenforcedSocketBind bool   `json:"allow_unenforced_socket_bind"`
	AgentBinary               string `json:"agent_binary"` // used as the bind probe

	// SSH / SFTP for site users. Keys live in root-owned files the site user
	// cannot edit; sshd reads them via AuthorizedKeysFile (see provisioning).
	AuthorizedKeysDir string `json:"authorized_keys_dir"`
	SSHGroup          string `json:"ssh_group"`  // shell + SFTP
	SFTPGroup         string `json:"sftp_group"` // SFTP only
	ToolsDir          string `json:"tools_dir"`  // per-user php/node links put first on PATH

	// Databases: dumps live here, readable by the panel group (download).
	BackupDir   string `json:"backup_dir"`
	BackupGroup string `json:"backup_group"`

	SystemdDir     string `json:"systemd_dir"`    // where worker units are written
	PHPCLIBinary   string `json:"php_cli_binary"` // may contain {version}
	UFWBinary      string `json:"ufw_binary"`
	ProtectedPorts []int  `json:"protected_ports"` // never denied or deleted (lockout protection)

	allowedUIDs []uint32
}

func Defaults() Config {
	return Config{
		Socket:        "/run/panel-agent.sock",
		SocketGroup:   "panel",
		AllowedUsers:  []string{"panel"},
		AuditLog:      "/var/log/panel-agent/audit.log",
		StateDir:      "/var/lib/panel-agent",
		SitesRoot:     "/srv/sites",
		TrashDir:      "/srv/sites/.trash",
		PHPVersions:   []string{"8.4"},
		DBEngines:     []string{},
		FPMPoolDir:    "/etc/php/{version}/fpm/pool.d",
		FPMBinary:     "/usr/sbin/php-fpm{version}",
		FPMUnit:       "php{version}-fpm.service",
		FPMSocketDir:  "/run/php",
		ProxyUnit:     "panel-proxy.service",
		ProxyConfig:   "/etc/panel-proxy/Caddyfile",
		ProxySitesDir: "/etc/panel-proxy/sites",
		WebUser:       "panel-web",
		WebUnit:       "panel-web.service",
		WebConfig:     "/etc/panel-web/Caddyfile",
		WebSitesDir:   "/etc/panel-web/sites",
		WebSocket:     "/run/panel-web/web.sock",
		FrankenPHPBin: "/usr/local/bin/frankenphp",
		DefaultPHPVer: "8.4",

		SiteConfigDir: "/etc/panel-sites",
		SiteRunDir:    "/run/wspanel-sites",
		TmpfilesDir:   "/etc/tmpfiles.d",
		ProxyGroup:    "panel-proxy",
		NodeDir:       "/opt/fnm/node-versions",
		// Privileged ports: only units granted CAP_NET_BIND_SERVICE (and allowed
		// exactly their own port via SocketBindAllow) can bind them, so no
		// other site user can squat a port while its owner restarts.
		NodePortMin:       600,
		NodePortMax:       999,
		AgentBinary:       "/usr/local/sbin/panel-agent",
		AuthorizedKeysDir: "/etc/ssh/authorized_keys",
		SSHGroup:          "wspanel-ssh",
		SFTPGroup:         "wspanel-sftp",
		ToolsDir:          "/etc/panel-sites/bin",
		BackupDir:         "/var/backups/wspanel",
		BackupGroup:       "panel",
		SystemdDir:        "/etc/systemd/system",
		PHPCLIBinary:      "/usr/bin/php{version}",
		UFWBinary:         "/usr/sbin/ufw",
		ProtectedPorts:    []int{22, 80, 443},
	}
}

// Load reads a JSON config on top of the defaults. Unknown keys are an error.
func Load(path string) (Config, error) {
	cfg := Defaults()
	data, err := os.ReadFile(path)
	if err != nil {
		return cfg, err
	}
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&cfg); err != nil {
		return cfg, fmt.Errorf("parse %s: %w", path, err)
	}
	return cfg, cfg.resolve()
}

func (c *Config) resolve() error {
	if c.DryRun {
		return nil // dev mode: users may not exist on the host
	}
	c.allowedUIDs = []uint32{0}
	for _, name := range c.AllowedUsers {
		u, err := user.Lookup(name)
		if err != nil {
			return fmt.Errorf("allowed user %q: %w", name, err)
		}
		uid, _ := strconv.ParseUint(u.Uid, 10, 32)
		c.allowedUIDs = append(c.allowedUIDs, uint32(uid))
	}
	return nil
}

func (c *Config) AllowedUIDs() []uint32 { return c.allowedUIDs }

// PanelUnits are units the panel itself depends on: they may be restarted or
// reloaded, but never stopped from the panel (it would cut off its own access).
func (c *Config) PanelUnits() []string {
	return []string{c.ProxyUnit, c.WebUnit, ForVersion(c.FPMUnit, c.DefaultPHPVer)}
}

// ForVersion substitutes {version} in a path template.
func ForVersion(tmpl, version string) string {
	return strings.ReplaceAll(tmpl, "{version}", version)
}
