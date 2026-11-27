package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
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

// valkeyInstalled: Valkey's own server binary is there (a server may run
// Redis only: install.sh --kv=redis).
func valkeyInstalled(env *Env) bool {
	if env.Cfg.DryRun {
		return !env.Cfg.NoValkey
	}
	target, err := filepath.EvalSymlinks(env.Cfg.ValkeyServerBin)
	return err == nil && strings.Contains(filepath.Base(target), "valkey")
}

func kvInstalled(env *Env, server string) bool {
	if server == kvRedis {
		return redisInstalled(env)
	}
	return valkeyInstalled(env)
}

// kvDefault: what a site gets when it does not choose, Valkey where it is.
func kvDefault(env *Env) string {
	if !valkeyInstalled(env) && redisInstalled(env) {
		return kvRedis
	}
	return kvValkey
}

// kvCLIBin: the command-line client, either server's (they speak the same
// protocol, so one is enough for both).
func kvCLIBin(env *Env) string {
	if !env.Cfg.DryRun && !fsx.Exists(env.Cfg.ValkeyCLIBin) && fsx.Exists(env.Cfg.RedisCLIBin) {
		return env.Cfg.RedisCLIBin
	}
	return env.Cfg.ValkeyCLIBin
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

func redisSites(env *Env) []string { return kvSites(env, kvRedis) }

// kvSites: the sites whose own server is that one.
func kvSites(env *Env, server string) []string {
	on := []string{}
	for _, st := range allSiteStates(env.Cfg) {
		if st.Valkey != nil && st.Valkey.server() == server {
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
	return kvInstallServer(ctx, env, kvRedis)
}

// redisUninstall takes Redis away, once no site runs it.
func redisUninstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	return kvUninstallServer(ctx, env, kvRedis)
}

// kvInstall adds Valkey or Redis (Server → Moduly).
func kvInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Server string `json:"server"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("server", p.Server, kvServers); err != nil {
		return nil, err
	}
	return kvInstallServer(ctx, env, p.Server)
}

// kvUninstall takes Valkey or Redis away: once no site runs it, never the
// last one, and the mail filter's store moves to the other first.
func kvUninstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Server string `json:"server"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("server", p.Server, kvServers); err != nil {
		return nil, err
	}
	return kvUninstallServer(ctx, env, p.Server)
}

func kvScript(server string) string {
	if server == kvRedis {
		return "redis-setup"
	}
	return "valkey-setup"
}

func kvOther(server string) string {
	if server == kvRedis {
		return kvValkey
	}
	return kvRedis
}

func kvInstallServer(ctx context.Context, env *Env, server string) (any, error) {
	if env.Cfg.DryRun {
		return map[string]any{"server": server, "installed": true, "dry_run": true}, nil
	}
	if kvInstalled(env, server) {
		return map[string]any{"server": server, "installed": true, "changed": false}, nil
	}
	res, err := runModuleScript(ctx, env, kvScript(server), "install")
	if err != nil {
		return nil, err
	}
	if err := saveKVFlag(env); err != nil {
		return nil, err
	}
	return map[string]any{"server": server, "installed": true, "changed": true, "log": tail(res.Stdout, 2000)}, nil
}

func kvUninstallServer(ctx context.Context, env *Env, server string) (any, error) {
	if on := kvSites(env, server); len(on) > 0 {
		return nil, protocol.Conflict(kvLabel(server) + " runs for " + strings.Join(on, ", ") + ": switch them to " + kvLabel(kvOther(server)) + " or off first")
	}
	if !env.Cfg.DryRun && !kvInstalled(env, server) {
		return map[string]any{"server": server, "installed": false, "changed": false}, nil
	}
	other := kvOther(server)
	if !kvInstalled(env, other) {
		return nil, protocol.Conflict(kvLabel(server) + " is the only key-value server here: install " + kvLabel(other) + " first")
	}
	// The mail filter keeps its statistics in one of them: moved to the other.
	if mailHostingOn(env) && rspamdKV(env) == server {
		if err := switchRspamdKV(ctx, env, other); err != nil {
			return nil, fmt.Errorf("moving the mail filter's store to %s: %w", kvLabel(other), err)
		}
	}
	if env.Cfg.DryRun {
		return map[string]any{"server": server, "installed": false, "dry_run": true}, nil
	}
	res, err := runModuleScript(ctx, env, kvScript(server), "--remove")
	if err != nil {
		return nil, err
	}
	if err := saveKVFlag(env); err != nil {
		return nil, err
	}
	return map[string]any{"server": server, "installed": false, "changed": true, "log": tail(res.Stdout, 2000)}, nil
}

// kvStatus: both servers, which sites run each, and the mail filter's.
func kvStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	servers := map[string]any{}
	for _, s := range kvServers {
		servers[s] = map[string]any{"installed": kvInstalled(env, s), "sites": kvSites(env, s)}
	}
	out := map[string]any{"servers": servers, "default": kvDefault(env)}
	if mailHostingOn(env) {
		out["mail"] = rspamdKV(env)
	}
	return out, nil
}

// saveKVFlag records which servers this one has for the next upgrade
// (--kv=valkey|redis|both), in place of the older --redis=yes.
func saveKVFlag(env *Env) error {
	v := kvValkey
	switch {
	case valkeyInstalled(env) && redisInstalled(env):
		v = "both"
	case redisInstalled(env):
		v = kvRedis
	}
	if err := dropInstallFlag(env, "--redis=", ""); err != nil {
		return err
	}
	if err := dropInstallFlag(env, "--kv=", ""); err != nil {
		return err
	}
	return addInstallFlag(env, "--kv="+v)
}

// addInstallFlag appends one argument to the installer's saved choices.
func addInstallFlag(env *Env, arg string) error {
	if env.Cfg.InstallFlags == "" {
		return nil
	}
	b, err := os.ReadFile(env.Cfg.InstallFlags)
	if err != nil && !os.IsNotExist(err) {
		return err
	}
	data := strings.TrimRight(string(b), "\n")
	if data != "" {
		data += "\n"
	}
	return env.FS.WriteFile(env.Cfg.InstallFlags, []byte(data+arg+"\n"), 0o600, fsx.Root)
}

// rspamdKV: which server keeps the mail filter's statistics (Bayes, reply
// tracking, rate limits): mail-hosting.sh writes it.
func rspamdKV(env *Env) string {
	b, _ := os.ReadFile(filepath.Join(env.Cfg.MailDir, "rspamd-kv"))
	if strings.TrimSpace(string(b)) == kvRedis {
		return kvRedis
	}
	return kvValkey
}

var rspamdExecRe = regexp.MustCompile(`(?m)^ExecStart=\S+ `)

// switchRspamdKV runs the mail filter's store on the other server. The
// statistics start again: the two write different snapshot formats.
func switchRspamdKV(ctx context.Context, env *Env, to string) error {
	if env.Cfg.DryRun {
		return env.FS.WriteFile(filepath.Join(env.Cfg.MailDir, "rspamd-kv"), []byte(to+"\n"), 0o644, fsx.Root)
	}
	b, err := os.ReadFile(env.Cfg.RspamdKVUnit)
	if err != nil {
		return err
	}
	unit := rspamdExecRe.ReplaceAllString(string(b), "ExecStart="+kvServerBin(env, to)+" ")
	if err := env.FS.WriteFile(env.Cfg.RspamdKVUnit, []byte(unit), 0o644, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(filepath.Join(env.Cfg.MailDir, "rspamd-kv"), []byte(to+"\n"), 0o644, fsx.Root); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "stop", "rspamd-valkey.service"); err != nil {
		return err
	}
	_ = os.Remove(filepath.Join(env.Cfg.RspamdKVData, "dump.rdb"))
	for _, args := range [][]string{{"daemon-reload"}, {"start", "rspamd-valkey.service"}, {"restart", "rspamd.service"}} {
		if _, err := env.Exec.Run(ctx, "systemctl", args...); err != nil {
			return err
		}
	}
	return nil
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

func boolPtr(b bool) *bool { return &b }
