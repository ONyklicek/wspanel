package ops

import (
	"context"
	"encoding/json"
	"os"
	"slices"
	"strings"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/validate"
)

// Version is set at build time via -ldflags.
var Version = "dev"

func managedUnits(cfg config.Config) []string {
	units := []string{cfg.ProxyUnit, cfg.WebUnit}
	for _, v := range cfg.PHPVersions {
		units = append(units, config.ForVersion(cfg.FPMUnit, v))
	}
	for _, e := range cfg.DBEngines {
		if e == "mongodb" {
			e = "mongod" // MongoDB's own unit name
		}
		units = append(units, e+".service")
	}
	return append(units, allWorkerUnits(cfg)...)
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
	workerPHP, _ := workerPHPVersion(ctx, env)
	frameworks := map[string][]string{}
	for _, m := range validate.SiteModes {
		frameworks[m] = validate.FrameworksForMode(m)
	}
	return map[string]any{
		"agent":              Version,
		"php":                php,
		"php_default":        env.Cfg.DefaultPHPVer,
		"frankenphp":         franken,
		"db_engines":         env.Cfg.DBEngines,
		"frameworks":         frameworks,
		"site_modes":         validate.SiteModes,
		"worker_php":         workerPHP,
		"node":               nodeMajors(env.Cfg),
		"python":             pythonMinors(env.Cfg),
		"python_installable": InstallablePython,
		"python_patches":     pythonPatches(env.Cfg),
		"db_tools":           installedDBTools(env),
		"dns_local":          env.Cfg.PowerDNSAPI != "" && env.Cfg.PowerDNSKey != "",
		"workers":            workerTypesByFramework(),
		"panel_units":        env.Cfg.PanelUnits(),
		"dry_run":            env.Cfg.DryRun,
	}, nil
}

func workerTypesByFramework() map[string][]string {
	out := map[string][]string{}
	for fw, specs := range workerSpecs {
		for t := range specs {
			out[fw] = append(out[fw], t)
		}
		slices.Sort(out[fw])
	}
	return out
}
