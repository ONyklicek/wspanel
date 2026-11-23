package ops

import (
	"context"
	"encoding/json"
	"path/filepath"
	"slices"
	"strings"

	"wspanel/agent/internal/protocol"
)

// Redis 8 (optional module, Server → Moduly): the official server from
// packages.redis.io, installed for its binaries only. A site picks Valkey or
// Redis for its own key-value server (valkey.set "server"); both run the same
// way (own process under the site user, own port and socket, password) and
// speak the same protocol, so the site's .env does not change between them.

// Key-value servers a site can run.
const (
	kvValkey = "valkey"
	kvRedis  = "redis"
)

var kvServers = []string{kvValkey, kvRedis}

// redisInstalled: the module's binary is there and it is Redis itself, not
// Valkey's compatibility link (valkey-redis-compat).
func redisInstalled(env *Env) bool {
	if env.Cfg.DryRun {
		return true
	}
	target, err := filepath.EvalSymlinks(env.Cfg.RedisServerBin)
	if err != nil {
		return false
	}
	return !strings.Contains(filepath.Base(target), "valkey")
}

// kvServerBin: the binary a site's server runs.
func kvServerBin(env *Env, server string) string {
	if server == kvRedis {
		return env.Cfg.RedisServerBin
	}
	return env.Cfg.ValkeyServerBin
}

// kvLabel: the server's name in descriptions and messages.
func kvLabel(server string) string {
	if server == kvRedis {
		return "Redis"
	}
	return "Valkey"
}

// redisModules: the Redis 8 modules the package ships (JSON, Search,
// TimeSeries, Bloom), loaded into every site's Redis.
func redisModules(env *Env) []string {
	mods, _ := filepath.Glob(filepath.Join(env.Cfg.RedisModulesDir, "*.so"))
	slices.Sort(mods)
	return mods
}

func redisSites(env *Env) []string {
	var on []string
	for _, st := range allSiteStates(env.Cfg) {
		if st.Valkey != nil && st.Valkey.Server == kvRedis {
			on = append(on, st.Domain)
		}
	}
	slices.Sort(on)
	return on
}

func redisInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	if env.Cfg.DryRun {
		return map[string]any{"installed": true, "dry_run": true}, nil
	}
	res, err := runModuleScript(ctx, env, "redis-setup", "install")
	if err != nil {
		return nil, err
	}
	return map[string]any{"installed": true, "log": tail(res.Stdout, 2000)}, nil
}

// redisUninstall takes Redis away, once no site runs it.
func redisUninstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	if on := redisSites(env); len(on) > 0 {
		return nil, protocol.Conflict("Redis runs for " + strings.Join(on, ", ") + ": switch them to Valkey or off first")
	}
	if env.Cfg.DryRun {
		return map[string]any{"installed": false, "dry_run": true}, nil
	}
	if !redisInstalled(env) {
		return map[string]any{"installed": false, "changed": false}, nil
	}
	res, err := runModuleScript(ctx, env, "redis-setup", "--remove")
	if err != nil {
		return nil, err
	}
	if err := dropInstallFlag(env, "--redis=", ""); err != nil {
		return nil, err
	}
	return map[string]any{"installed": false, "changed": true, "log": tail(res.Stdout, 2000)}, nil
}

func redisStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	out := map[string]any{"installed": redisInstalled(env), "sites": redisSites(env)}
	if !env.Cfg.DryRun && redisInstalled(env) {
		if res, err := env.Exec.Run(ctx, "redis-server", "--version"); err == nil {
			out["version"] = strings.TrimSpace(res.Stdout)
		}
		var mods []string
		for _, m := range redisModules(env) {
			mods = append(mods, strings.TrimSuffix(filepath.Base(m), ".so"))
		}
		out["modules"] = mods
	}
	return out, nil
}

// removeKVData drops a site's snapshot when it changes servers: Valkey and
// Redis write different RDB versions and neither has to read the other's.
// As the site user: the data dir is in the site's home.
func removeKVData(ctx context.Context, env *Env, user string, v valkeyLayout) error {
	return asUser(ctx, env, user, "/usr/bin/rm", "-f", "--", filepath.Join(v.DataDir, "dump.rdb"))
}
