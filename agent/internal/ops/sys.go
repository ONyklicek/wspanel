package ops

import (
	"context"
	"encoding/json"
	"os"
	"strings"

	"wspanel/agent/internal/config"
)

// Version is set at build time via -ldflags.
var Version = "dev"

func managedUnits(cfg config.Config) []string {
	units := []string{cfg.ProxyUnit}
	for _, v := range cfg.PHPVersions {
		units = append(units, config.ForVersion(cfg.FPMUnit, v))
	}
	for _, e := range cfg.DBEngines {
		units = append(units, e+".service")
	}
	return append(units, "valkey-server.service")
}

func sysServices(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	return unitStates(ctx, env, managedUnits(env.Cfg))
}

type phpInfo struct {
	Version   string `json:"version"`
	Installed bool   `json:"installed"`
}

func sysVersions(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	php := make([]phpInfo, 0, len(env.Cfg.PHPVersions))
	for _, v := range env.Cfg.PHPVersions {
		_, err := os.Stat(config.ForVersion(env.Cfg.FPMBinary, v))
		php = append(php, phpInfo{Version: v, Installed: err == nil || env.Cfg.DryRun})
	}
	franken := "unknown"
	if res, err := env.Exec.Run(ctx, "frankenphp", "version"); err == nil && res.Stdout != "" {
		franken = strings.TrimSpace(res.Stdout)
	}
	return map[string]any{
		"agent":       Version,
		"php":         php,
		"php_default": env.Cfg.DefaultPHPVer,
		"frankenphp":  franken,
		"db_engines":  env.Cfg.DBEngines,
		"frameworks":  []string{"laravel", "symfony", "php"},
		"site_modes":  []string{"classic"},
		"dry_run":     env.Cfg.DryRun,
	}, nil
}
