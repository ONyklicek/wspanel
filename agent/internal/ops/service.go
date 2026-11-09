package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"syscall"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Services the agent may touch. Anything else is unknown.
var serviceTypes = []string{"php-fpm", "proxy", "web", "mariadb", "postgresql", "mongodb", "worker", "app", "task", "site-valkey"}

// siteServices take a domain; "worker" also a worker type, "task" a task id.
var siteServices = []string{"worker", "app", "task", "site-valkey"}

type serviceParams struct {
	Service string `json:"service"`
	Version string `json:"version,omitempty"` // php-fpm only
	Domain  string `json:"domain,omitempty"`  // worker and app only
	Worker  string `json:"worker,omitempty"`  // worker: its type; task: its id
}

// unit maps validated params to a systemd unit name.
func (p serviceParams) unit(cfg config.Config) (string, error) {
	if err := validate.OneOf("service", p.Service, serviceTypes); err != nil {
		return "", err
	}
	if p.Service != "php-fpm" && p.Version != "" {
		return "", protocol.Invalid("version: only valid for php-fpm")
	}
	if p.Service != "worker" && p.Service != "task" && p.Worker != "" {
		return "", protocol.Invalid("worker: only valid for service worker or task")
	}
	if !slices.Contains(siteServices, p.Service) && p.Domain != "" {
		return "", protocol.Invalid("domain: only valid for a site's services")
	}
	switch p.Service {
	case "app": // the site's own process in worker or node mode
		if err := validate.Domain(p.Domain); err != nil {
			return "", err
		}
		name := appUnitName(validate.SiteUser(p.Domain))
		// Node: the slot that serves now (the other one is idle or finishing).
		if b, err := os.ReadFile(siteStatePath(cfg, p.Domain)); err == nil {
			var st siteState
			if json.Unmarshal(b, &st) == nil && st.Slot == "b" && st.PortB > 0 {
				name = appUnitNameB(validate.SiteUser(p.Domain))
			}
		}
		if !fsx.Exists(filepath.Join(cfg.SystemdDir, name)) {
			return "", protocol.NotFound(p.Domain + " has no app process (classic sites run in PHP-FPM)")
		}
		return name, nil
	case "worker":
		if err := validate.Domain(p.Domain); err != nil {
			return "", err
		}
		if err := validate.OneOf("worker", p.Worker, WorkerTypes); err != nil {
			return "", err
		}
		user := validate.SiteUser(p.Domain)
		if fileIn(cfg.SystemdDir, legacyWorkerUnit(user, p.Worker)) {
			return legacyWorkerUnit(user, p.Worker), nil
		}
		if !fileIn(cfg.SystemdDir, workerTemplate(user, p.Worker)) {
			return "", protocol.NotFound("worker " + p.Worker + " does not exist for " + p.Domain)
		}
		// The instance of the current release (an old one finishing its job is left alone).
		rel, err := currentRelease(siteLayout{Home: filepath.Join(cfg.SitesRoot, p.Domain)})
		if err != nil {
			return "", err
		}
		return workerInstance(user, p.Worker, rel), nil
	case "task": // a scheduled task's timer: stop = paused, start = scheduled again
		if err := validate.Domain(p.Domain); err != nil {
			return "", err
		}
		id, err := strconv.Atoi(p.Worker)
		if err != nil || id < 1 || id > 999999 {
			return "", protocol.Invalid("worker: task id")
		}
		name := cronUnitBase(validate.SiteUser(p.Domain), id) + ".timer"
		if !fsx.Exists(filepath.Join(cfg.SystemdDir, name)) {
			return "", protocol.NotFound("task " + p.Worker + " does not exist for " + p.Domain)
		}
		return name, nil
	case "site-valkey":
		if err := validate.Domain(p.Domain); err != nil {
			return "", err
		}
		name := "wspanel-" + validate.SiteUser(p.Domain) + "-valkey.service"
		if !fsx.Exists(filepath.Join(cfg.SystemdDir, name)) {
			return "", protocol.NotFound(p.Domain + " has no Valkey")
		}
		return name, nil
	case "php-fpm":
		if err := validate.OneOf("version", p.Version, cfg.PHPVersions); err != nil {
			return "", err
		}
		return config.ForVersion(cfg.FPMUnit, p.Version), nil
	case "proxy":
		return cfg.ProxyUnit, nil
	case "web":
		return cfg.WebUnit, nil
	case "mariadb", "postgresql", "mongodb":
		if !slices.Contains(cfg.DBEngines, p.Service) {
			return "", protocol.NotFound(p.Service + " is not installed on this server")
		}
		if p.Service == "mongodb" {
			return "mongod.service", nil
		}
		return p.Service + ".service", nil
	}
	return "", protocol.Invalid("service")
}

func serviceStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p serviceParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	unit, err := p.unit(env.Cfg)
	if err != nil {
		return nil, err
	}
	st, err := unitStates(ctx, env, []string{unit})
	if err != nil {
		return nil, err
	}
	return st[0], nil
}

func serviceStart(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return serviceAction(ctx, env, raw, "start")
}

func serviceStop(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return serviceAction(ctx, env, raw, "stop")
}

func serviceReload(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return serviceAction(ctx, env, raw, "reload")
}

func serviceRestart(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return serviceAction(ctx, env, raw, "restart")
}

// serviceReset clears a failed state (and the start rate limit) and restarts.
func serviceReset(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return serviceAction(ctx, env, raw, "reset")
}

func serviceAction(ctx context.Context, env *Env, raw json.RawMessage, action string) (any, error) {
	var p serviceParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	unit, err := p.unit(env.Cfg)
	if err != nil {
		return nil, err
	}
	if action == "stop" && slices.Contains(env.Cfg.PanelUnits(), unit) {
		return nil, protocol.Forbidden(unit + " runs the panel itself and cannot be stopped from it; use restart")
	}
	if action == "reload" && slices.Contains(siteServices, p.Service) {
		return nil, protocol.Invalid("reload: not supported for " + p.Service + ", use restart")
	}
	// A broken config is never (re)loaded.
	switch p.Service {
	case "php-fpm":
		if err := validateFPM(ctx, env, p.Version); err != nil {
			return nil, err
		}
	case "proxy":
		if err := validateEdge(ctx, env); err != nil {
			return nil, err
		}
	case "web":
		if err := validateWeb(ctx, env); err != nil {
			return nil, err
		}
	}
	if action == "reset" {
		if _, err := env.Exec.Run(ctx, "systemctl", "reset-failed", unit); err != nil {
			return nil, err
		}
		action = "restart"
	}
	if _, err := env.Exec.Run(ctx, "systemctl", action, unit); err != nil {
		return nil, err
	}
	return map[string]string{"unit": unit, "action": action}, nil
}

// UnitState is the subset of `systemctl show` the panel cares about.
type UnitState struct {
	Unit    string `json:"unit"`
	Load    string `json:"load"`
	Active  string `json:"active"`
	Sub     string `json:"sub"`
	Enabled string `json:"enabled"`
}

func unitStates(ctx context.Context, env *Env, units []string) ([]UnitState, error) {
	args := append([]string{"show", "--property=Id,LoadState,ActiveState,SubState,UnitFileState", "--"}, units...)
	res, err := env.Exec.Run(ctx, "systemctl", args...)
	if err != nil {
		return nil, err
	}
	out := make([]UnitState, len(units))
	for i, u := range units {
		out[i] = UnitState{Unit: u, Load: "unknown", Active: "unknown"}
	}
	// systemctl prints blocks in argument order, separated by blank lines.
	blocks := strings.Split(strings.TrimSpace(res.Stdout), "\n\n")
	for i, b := range blocks {
		if i >= len(out) || strings.TrimSpace(b) == "" {
			continue
		}
		for _, line := range strings.Split(b, "\n") {
			k, v, ok := strings.Cut(line, "=")
			if !ok {
				continue
			}
			switch k {
			case "LoadState":
				out[i].Load = v
			case "ActiveState":
				out[i].Active = v
			case "SubState":
				out[i].Sub = v
			case "UnitFileState":
				out[i].Enabled = v
			}
		}
	}
	return out, nil
}

func validateFPM(ctx context.Context, env *Env, version string) error {
	if _, err := env.Exec.Run(ctx, "php-fpm"+version, "-t"); err != nil {
		return fmt.Errorf("php-fpm %s config invalid, not reloading: %w", version, err)
	}
	return nil
}

func validateCaddy(ctx context.Context, env *Env, name, path string) error {
	if _, err := env.Exec.Run(ctx, "frankenphp", "validate", "--config", path, "--adapter", "caddyfile"); err != nil {
		return fmt.Errorf("%s config invalid, not reloading: %w", name, err)
	}
	return nil
}

func validateEdge(ctx context.Context, env *Env) error {
	ensureAccessLogs(env)
	return validateCaddy(ctx, env, "proxy", env.Cfg.ProxyConfig)
}

// ensureAccessLogs creates the sites' access logs as the edge's (validation
// runs as root and would otherwise create them as root, which the edge then
// cannot open), and gives back any that root created.
func ensureAccessLogs(env *Env) {
	if env.Cfg.DryRun {
		return
	}
	owner, err := lookupOwner(env, env.Cfg.ProxyGroup)
	if err != nil || owner.UID < 0 {
		return
	}
	// Every site block on disk (a new site's block exists before its state).
	blocks, _ := filepath.Glob(filepath.Join(env.Cfg.ProxySitesDir, "*.caddy"))
	for _, b := range blocks {
		domain := strings.TrimSuffix(filepath.Base(b), ".caddy")
		if validate.Domain(domain) != nil {
			continue
		}
		path := filepath.Join(env.Cfg.AccessLogDir, domain+".log")
		f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND|syscall.O_NOFOLLOW, 0o640)
		if err != nil {
			continue
		}
		_ = f.Chown(owner.UID, owner.GID)
		_ = f.Close()
	}
}

func validateWeb(ctx context.Context, env *Env) error {
	return validateCaddy(ctx, env, "web", env.Cfg.WebConfig)
}

type configValidateParams struct {
	Type    string `json:"type"`
	Version string `json:"version,omitempty"`
}

func configValidate(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p configValidateParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("type", p.Type, []string{"php-fpm", "proxy", "web"}); err != nil {
		return nil, err
	}
	var err error
	if p.Type == "php-fpm" {
		if err = validate.OneOf("version", p.Version, env.Cfg.PHPVersions); err != nil {
			return nil, err
		}
		err = validateFPM(ctx, env, p.Version)
	} else if p.Type == "proxy" {
		err = validateEdge(ctx, env)
	} else {
		err = validateWeb(ctx, env)
	}
	if err != nil {
		return nil, err
	}
	return map[string]bool{"valid": true}, nil
}
