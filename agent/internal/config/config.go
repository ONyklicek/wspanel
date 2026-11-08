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
	WebUser     string `json:"web_user"`
	WebUnit     string `json:"web_unit"`
	WebConfig   string `json:"web_config"`
	WebSitesDir string `json:"web_sites_dir"`
	// XHProf + XHGui (optional, xhprof.sh): the shared, read-only code.
	XhguiDir      string `json:"xhgui_dir"`
	WebSocket     string `json:"web_socket"`
	FrankenPHPBin string `json:"frankenphp_bin"`
	DefaultPHPVer string `json:"default_php_version"`

	// Worker mode (one FrankenPHP instance per site) and Node SSR.
	SiteConfigDir string `json:"site_config_dir"` // per-site FrankenPHP Caddyfiles
	SiteRunDir    string `json:"site_run_dir"`    // per-site socket dirs (group = proxy group)
	// Per-site Valkey: runtime dir parent (systemd RuntimeDirectory) and binary.
	ValkeyRunDir    string `json:"valkey_run_dir"`
	ValkeyServerBin string `json:"valkey_server_bin"`
	ValkeyCLIBin    string `json:"valkey_cli_bin"`
	TmpfilesDir     string `json:"tmpfiles_dir"`
	ProxyGroup      string `json:"proxy_group"`   // group of the edge proxy, owns site socket dirs
	NodeDir         string `json:"node_dir"`      // fnm node-versions dir
	NodePortMin     int    `json:"node_port_min"` // must stay below net.ipv4.ip_unprivileged_port_start
	NodePortMax     int    `json:"node_port_max"`
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

	// phpMyAdmin / Adminer: one-time sign-on tokens for the tools' FPM pool.
	DBToolsDir   string `json:"dbtools_dir"`  // contains pma/ and adminer/
	DBToolsUser  string `json:"dbtools_user"` // the tools' pool user
	DBTokenDir   string `json:"dbtools_token_dir"`
	DBAccessTTLm int    `json:"dbtools_access_minutes"`

	// Database servers reachable from the internet (db.remote): engine config
	// drop-ins, the TLS certificate MariaDB serves, and fail2ban jails.
	MariaDBConfDir string `json:"mariadb_conf_dir"`
	PostgresEtcDir string `json:"postgres_etc_dir"` // contains <version>/main
	DBTLSDir       string `json:"db_tls_dir"`
	Fail2banDir    string `json:"fail2ban_dir"`

	ProxyStorage string `json:"proxy_storage"`  // Caddy data dir of the edge (certificates)
	AccessLogDir string `json:"access_log_dir"` // the edge's per-site JSON access logs (traffic stats)
	AutoUpgrades string `json:"auto_upgrades"`  // apt's periodic config (automatic security updates)
	ProxyCertDir string `json:"proxy_cert_dir"` // uploaded certificates (custom TLS)
	DeployLogDir string `json:"deploy_log_dir"`
	PanelDomain  string `json:"panel_domain"`
	PanelPort    int    `json:"panel_port,omitempty"` // 0/443: the panel has its domain to itself

	SystemdDir     string `json:"systemd_dir"`    // where worker units are written
	PHPCLIBinary   string `json:"php_cli_binary"` // may contain {version}
	UFWBinary      string `json:"ufw_binary"`
	ProtectedPorts []int  `json:"protected_ports"` // never denied or deleted (lockout protection)

	// Mail: sites send through the local Postfix (DKIM via OpenDKIM) or, in
	// "capture" mode, into Mailpit, where nothing leaves the server.
	MailDefault  string `json:"mail_default"` // send | capture, for new sites
	MailpitBin   string `json:"mailpit_bin"`
	MailpitSMTP  string `json:"mailpit_smtp"`
	PostfixSASL  string `json:"postfix_sasl"`
	DKIMDir      string `json:"dkim_dir"`
	DKIMSelector string `json:"dkim_selector"`
	SASLDB       string `json:"sasl_db"`       // per-site SMTP logins (Cyrus sasldb)
	SenderLogins string `json:"sender_logins"` // Postfix: sender domain -> SMTP login allowed to use it
	// Mail hosting (mail-hosting.sh): where mail.json is rendered to.
	MailHost       string `json:"mail_host,omitempty"` // default: the panel domain
	MailDir        string `json:"mail_dir"`            // TLS pair, the "hosting" marker
	VDomains       string `json:"vdomains"`
	VMailboxes     string `json:"vmailboxes"`
	VAliases       string `json:"valiases"`
	MailboxSenders string `json:"mailbox_senders"`
	// SRSExclude: Postfix canonical map of the domains that send from here
	// (sites, mail domains); their senders are never SRS-rewritten.
	SRSExclude string `json:"srs_exclude"`
	// RspamdRatelimit: Rspamd's ratelimit settings, how much a mailbox and
	// a site's SMTP login may send per hour (from mail.json).
	RspamdRatelimit string `json:"rspamd_ratelimit"`
	// Rspamd: greylisting (an include of actions.conf) and the ClamAV scan.
	RspamdActions   string `json:"rspamd_actions"`
	RspamdAntivirus string `json:"rspamd_antivirus"`
	DovecotUsers    string `json:"dovecot_users"`
	VmailRoot       string `json:"vmail_root"`
	DovecotBlocked  string `json:"dovecot_blocked"` // deny passdb: blocked mailboxes
	MailEdgeFile    string `json:"mail_edge_file"`  // edge: webmail.<domain>, autoconfig/autodiscover
	WebmailUser     string `json:"webmail_user"`
	WebmailTokens   string `json:"webmail_tokens"`   // one-time sign-in tokens for Roundcube
	DomainSieveDir  string `json:"domain_sieve_dir"` // per mail domain: sender lists, spam into Junk
	LoginSenders    string `json:"login_senders"`    // Postfix: Unix login -> senders allowed with sendmail -f

	// Local authoritative DNS (PowerDNS API on loopback); empty = not installed.
	PowerDNSAPI string `json:"powerdns_api"`
	PowerDNSKey string `json:"powerdns_key"`
	// PowerDNSConf: the agent's own PowerDNS settings (DNSSEC, zone
	// transfers and NOTIFY to the secondary servers).
	PowerDNSConf string `json:"powerdns_conf"`

	// Build artifacts uploaded by CI (through the panel) for deploys that skip
	// the build on the server. Root-owned dir the panel user may write into.
	ArtifactDir string `json:"artifact_dir"`
	PanelUser   string `json:"panel_user"`

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
		XhguiDir:      "/usr/share/wspanel-xhgui",
		WebSocket:     "/run/panel-web/web.sock",
		FrankenPHPBin: "/usr/local/bin/frankenphp",
		DefaultPHPVer: "8.4",

		SiteConfigDir:   "/etc/panel-sites",
		SiteRunDir:      "/run/wspanel-sites",
		ValkeyRunDir:    "/run/wspanel-valkey",
		ValkeyServerBin: "/usr/bin/valkey-server",
		ValkeyCLIBin:    "/usr/bin/valkey-cli",
		TmpfilesDir:     "/etc/tmpfiles.d",
		ProxyGroup:      "panel-proxy",
		NodeDir:         "/opt/fnm/node-versions",
		// Privileged ports: only units granted CAP_NET_BIND_SERVICE (and allowed
		// exactly their own port via SocketBindAllow) can bind them, so no
		// other site user can squat a port while its owner restarts.
		NodePortMin:       600,
		NodePortMax:       999,
		AgentBinary:       "/usr/sbin/panel-agent",
		AuthorizedKeysDir: "/etc/ssh/authorized_keys",
		SSHGroup:          "wspanel-ssh",
		SFTPGroup:         "wspanel-sftp",
		ToolsDir:          "/etc/panel-sites/bin",
		BackupDir:         "/var/backups/wspanel",
		DBToolsDir:        "/usr/share/wspanel-dbtools/dbtools",
		DBToolsUser:       "dbtools",
		DBTokenDir:        "/run/wspanel-dbtools",
		DBAccessTTLm:      30,
		MariaDBConfDir:    "/etc/mysql/mariadb.conf.d",
		PostgresEtcDir:    "/etc/postgresql",
		DBTLSDir:          "/etc/wspanel-db-tls",
		Fail2banDir:       "/etc/fail2ban",
		BackupGroup:       "panel",
		ProxyStorage:      "/var/lib/panel-proxy/caddy",
		AccessLogDir:      "/var/log/panel-proxy/access",
		AutoUpgrades:      "/etc/apt/apt.conf.d/20auto-upgrades",
		ProxyCertDir:      "/etc/panel-proxy/certs",
		DeployLogDir:      "/var/log/panel-agent/deploys",
		SystemdDir:        "/etc/systemd/system",
		PHPCLIBinary:      "/usr/bin/php{version}",
		UFWBinary:         "/usr/sbin/ufw",
		ProtectedPorts:    []int{22, 80, 443},
		MailDefault:       "send",
		MailpitBin:        "/usr/local/bin/mailpit",
		MailpitSMTP:       "127.0.0.1:1025",
		PostfixSASL:       "/etc/postfix/sasl_passwd",
		DKIMDir:           "/etc/opendkim",
		DKIMSelector:      "wspanel",
		ArtifactDir:       "/var/lib/wspanel-artifacts",
		PanelUser:         "panel",
		SASLDB:            "/etc/sasldb2",
		SenderLogins:      "/etc/postfix/wspanel_sender_logins",
		MailDir:           "/etc/wspanel/mail",
		VDomains:          "/etc/postfix/wspanel_vdomains",
		VMailboxes:        "/etc/postfix/wspanel_vmailboxes",
		VAliases:          "/etc/postfix/wspanel_valiases",
		MailboxSenders:    "/etc/postfix/wspanel_mailbox_senders",
		SRSExclude:        "/etc/postfix/wspanel_srs_exclude",
		RspamdRatelimit:   "/etc/rspamd/local.d/ratelimit.conf",
		RspamdActions:     "/etc/rspamd/local.d/wspanel-actions.inc",
		RspamdAntivirus:   "/etc/rspamd/local.d/antivirus.conf",
		PowerDNSConf:      "/etc/powerdns/pdns.d/wspanel-agent.conf",
		DovecotUsers:      "/etc/dovecot/wspanel/users",
		VmailRoot:         "/var/mail/vmail",
		DovecotBlocked:    "/etc/dovecot/wspanel/blocked",
		MailEdgeFile:      "/etc/panel-proxy/mail-sites.caddy",
		WebmailUser:       "webmail",
		WebmailTokens:     "/run/wspanel-webmail",
		DomainSieveDir:    "/var/mail/vmail/.wspanel-domains",
		LoginSenders:      "/etc/postfix/wspanel_login_senders",
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

// AllowUIDs replaces who may connect: for tests, whose user is not in a
// config file (Defaults() resolves nobody, so a Linux agent refuses all).
func (c *Config) AllowUIDs(uids ...uint32) { c.allowedUIDs = uids }

// PanelUnits are units the panel itself depends on: they may be restarted or
// reloaded, but never stopped from the panel (it would cut off its own access).
func (c *Config) PanelUnits() []string {
	return []string{c.ProxyUnit, c.WebUnit, ForVersion(c.FPMUnit, c.DefaultPHPVer)}
}

// ForVersion substitutes {version} in a path template.
func ForVersion(tmpl, version string) string {
	return strings.ReplaceAll(tmpl, "{version}", version)
}
