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

	ProxyUser     string `json:"proxy_user"`
	ProxyUnit     string `json:"proxy_unit"`
	ProxyConfig   string `json:"proxy_config"`
	ProxySitesDir string `json:"proxy_sites_dir"`
	FrankenPHPBin string `json:"frankenphp_bin"`
	DefaultPHPVer string `json:"default_php_version"`

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
		ProxyUser:     "panel-proxy",
		ProxyUnit:     "panel-proxy.service",
		ProxyConfig:   "/etc/panel-proxy/Caddyfile",
		ProxySitesDir: "/etc/panel-proxy/sites",
		FrankenPHPBin: "/usr/local/bin/frankenphp",
		DefaultPHPVer: "8.4",
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

// ForVersion substitutes {version} in a path template.
func ForVersion(tmpl, version string) string {
	return strings.ReplaceAll(tmpl, "{version}", version)
}
