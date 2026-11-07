package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
	"strings"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Services the agent may touch. Anything else is unknown.
var serviceTypes = []string{"php-fpm", "proxy", "mariadb", "postgresql", "valkey"}

type serviceParams struct {
	Service string `json:"service"`
	Version string `json:"version,omitempty"` // php-fpm only
}

// unit maps validated params to a systemd unit name.
func (p serviceParams) unit(cfg config.Config) (string, error) {
	if err := validate.OneOf("service", p.Service, serviceTypes); err != nil {
		return "", err
	}
	if p.Service != "php-fpm" && p.Version != "" {
		return "", protocol.Invalid("version: only valid for php-fpm")
	}
	switch p.Service {
	case "php-fpm":
		if err := validate.OneOf("version", p.Version, cfg.PHPVersions); err != nil {
			return "", err
		}
		return config.ForVersion(cfg.FPMUnit, p.Version), nil
	case "proxy":
		return cfg.ProxyUnit, nil
	case "mariadb", "postgresql":
		if !slices.Contains(cfg.DBEngines, p.Service) {
			return "", protocol.NotFound(p.Service + " is not installed on this server")
		}
		return p.Service + ".service", nil
	case "valkey":
		return "valkey-server.service", nil
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

func serviceReload(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return serviceAction(ctx, env, raw, "reload")
}

func serviceRestart(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return serviceAction(ctx, env, raw, "restart")
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
	// A broken config is never (re)loaded.
	switch p.Service {
	case "php-fpm":
		if err := validateFPM(ctx, env, p.Version); err != nil {
			return nil, err
		}
	case "proxy":
		if err := validateProxy(ctx, env); err != nil {
			return nil, err
		}
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

func validateProxy(ctx context.Context, env *Env) error {
	if _, err := env.Exec.Run(ctx, "frankenphp", "validate", "--config", env.Cfg.ProxyConfig, "--adapter", "caddyfile"); err != nil {
		return fmt.Errorf("proxy config invalid, not reloading: %w", err)
	}
	return nil
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
	if err := validate.OneOf("type", p.Type, []string{"php-fpm", "proxy"}); err != nil {
		return nil, err
	}
	var err error
	if p.Type == "php-fpm" {
		if err = validate.OneOf("version", p.Version, env.Cfg.PHPVersions); err != nil {
			return nil, err
		}
		err = validateFPM(ctx, env, p.Version)
	} else {
		err = validateProxy(ctx, env)
	}
	if err != nil {
		return nil, err
	}
	return map[string]bool{"valid": true}, nil
}
