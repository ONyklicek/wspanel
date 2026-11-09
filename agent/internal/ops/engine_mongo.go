package ops

import (
	"context"
	"fmt"
	"time"

	"wspanel/agent/internal/config"
)

// installMongo: MongoDB from its own repository with the PHP extension for
// every PHP version (mongodb.sh), then the panel can use it.
func installMongo(ctx context.Context, env *Env) (any, error) {
	ctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 20*time.Minute)
	defer cancel()
	args := append([]string{mongoAdminPath(env)}, env.Cfg.PHPVersions...)
	if _, err := env.Exec.RunIO(ctx, nil, nil, "mongodb-setup", args...); err != nil {
		return nil, err
	}
	for _, v := range env.Cfg.PHPVersions { // load the new PHP extension
		if err := validateFPM(ctx, env, v); err != nil {
			return nil, err
		}
		if _, err := env.Exec.Run(ctx, "systemctl", "reload", config.ForVersion(env.Cfg.FPMUnit, v)); err != nil {
			return nil, err
		}
	}
	env.Cfg.DBEngines = append(env.Cfg.DBEngines, "mongodb")
	if err := persistDBEngines(env); err != nil {
		return nil, fmt.Errorf("mongodb installed, but saving agent.json failed: %w", err)
	}
	return map[string]any{"engine": "mongodb", "installed": true, "changed": true}, nil
}
