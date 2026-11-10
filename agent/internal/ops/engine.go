package ops

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"slices"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/validate"
)

// engineInstall adds a database engine after installation (add-only: removing
// one from the panel would destroy data). Installs the server and the PHP
// drivers for every PHP version, starts it, and records it in agent.json.
// Idempotent: an installed engine is a no-op.
func engineInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Engine string `json:"engine"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("engine", p.Engine, []string{"mariadb", "postgresql", "mongodb"}); err != nil {
		return nil, err
	}
	if slices.Contains(env.Cfg.DBEngines, p.Engine) {
		return map[string]any{"engine": p.Engine, "installed": true, "changed": false}, nil
	}
	if p.Engine == "mongodb" {
		return installMongo(ctx, env)
	}
	pkgs := []string{"mariadb-server"}
	ext := "mysql"
	if p.Engine == "postgresql" {
		pkgs, ext = []string{"postgresql"}, "pgsql"
	}
	for _, v := range env.Cfg.PHPVersions {
		pkgs = append(pkgs, "php"+v+"-"+ext)
	}
	if _, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", "update", "-q"); err != nil {
		return nil, err
	}
	if _, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", append([]string{"install", "-y", "-q", "--no-install-recommends"}, pkgs...)...); err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "enable", "--now", p.Engine+".service"); err != nil {
		return nil, err
	}
	for _, v := range env.Cfg.PHPVersions { // load the new PHP driver
		if err := validateFPM(ctx, env, v); err != nil {
			return nil, err
		}
		if _, err := env.Exec.Run(ctx, "systemctl", "reload", config.ForVersion(env.Cfg.FPMUnit, v)); err != nil {
			return nil, err
		}
	}
	env.Cfg.DBEngines = append(env.Cfg.DBEngines, p.Engine)
	if err := persistDBEngines(env); err != nil {
		return nil, fmt.Errorf("%s installed, but saving agent.json failed: %w", p.Engine, err)
	}
	return map[string]any{"engine": p.Engine, "installed": true, "changed": true, "packages": pkgs}, nil
}

// persistDBEngines rewrites db_engines in agent.json, keeping every other key.
func persistDBEngines(env *Env) error {
	return persistConfig(env, map[string]any{"db_engines": env.Cfg.DBEngines})
}

// persistConfig rewrites these keys of agent.json (nil removes one), keeping
// every other key: what the agent changed in its own configuration at run
// time survives its restart.
func persistConfig(env *Env, keys map[string]any) error {
	if env.ConfigPath == "" {
		return nil
	}
	b, err := os.ReadFile(env.ConfigPath)
	if err != nil {
		return err
	}
	var m map[string]any
	if err := json.Unmarshal(b, &m); err != nil {
		return err
	}
	for k, v := range keys {
		if v == nil {
			delete(m, k)
		} else {
			m[k] = v
		}
	}
	var out bytes.Buffer
	enc := json.NewEncoder(&out)
	enc.SetIndent("", "  ")
	if err := enc.Encode(m); err != nil {
		return err
	}
	return env.FS.WriteFile(env.ConfigPath, out.Bytes(), 0o600, fsx.Root)
}
