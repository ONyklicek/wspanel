package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// A site's own Valkey (Redis-compatible) server: a process under the site
// user, reachable only through a unix socket in a runtime dir only that user
// can enter. No TCP port, so no other site and no client of the server's
// shared instance can reach it; FLUSHALL in one site never touches another.

type valkeyConfig struct {
	MaxMemoryMB int    `json:"maxmemory_mb"`
	Policy      string `json:"policy"`  // maxmemory-policy
	Persist     bool   `json:"persist"` // RDB snapshots in <home>/valkey
}

var valkeyPolicies = []string{"allkeys-lru", "allkeys-lfu", "volatile-lru", "noeviction"}

const valkeyMaxMemoryMB = 65536

type valkeyLayout struct {
	Unit, UnitFile, Config, RunDir, Socket, DataDir string
	MaxMemoryMB                                     int
	Policy                                          string
	Persist                                         bool
}

func valkeyPaths(env *Env, l siteLayout) valkeyLayout {
	run := filepath.Join(env.Cfg.ValkeyRunDir, l.User)
	return valkeyLayout{
		Unit:     "wspanel-" + l.User + "-valkey.service",
		UnitFile: filepath.Join(env.Cfg.SystemdDir, "wspanel-"+l.User+"-valkey.service"),
		Config:   filepath.Join(env.Cfg.SiteConfigDir, l.User+".valkey.conf"),
		RunDir:   run,
		Socket:   filepath.Join(run, "valkey.sock"),
		DataDir:  filepath.Join(l.Home, "valkey"),
	}
}

// valkeyEnv: how the framework finds its server (phpredis, Predis, Symfony
// DSN, Node clients).
func valkeyEnv(framework, socket string) ([]string, map[string]string) {
	switch framework {
	case "symfony":
		return []string{"REDIS_URL"}, map[string]string{"REDIS_URL": "redis://" + socket}
	case "nuxt", "next", "node":
		return []string{"REDIS_URL", "REDIS_SOCKET"}, map[string]string{"REDIS_URL": "unix://" + socket, "REDIS_SOCKET": socket}
	default: // laravel, plain PHP
		return []string{"REDIS_CLIENT", "REDIS_HOST", "REDIS_PORT"}, map[string]string{"REDIS_CLIENT": "phpredis", "REDIS_HOST": socket, "REDIS_PORT": "0"}
	}
}

// valkeySet turns the site's Valkey on (or reconfigures it) or off.
func valkeySet(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p struct {
		Domain      string `json:"domain"`
		Enabled     bool   `json:"enabled"`
		MaxMemoryMB int    `json:"maxmemory_mb"`
		Policy      string `json:"policy"`
		Persist     bool   `json:"persist"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	v := valkeyPaths(env, l)
	if !p.Enabled {
		if fsx.Exists(v.UnitFile) {
			if err := removeUnit(ctx, env, v.UnitFile); err != nil {
				return nil, err
			}
		}
		_ = os.Remove(v.Config)
		st.Valkey = nil
		if err := saveSiteState(env, st); err != nil {
			return nil, err
		}
		return map[string]any{"domain": p.Domain, "enabled": false}, nil
	}
	if p.MaxMemoryMB < 8 || p.MaxMemoryMB > valkeyMaxMemoryMB {
		return nil, protocol.Invalid(fmt.Sprintf("maxmemory_mb: must be 8-%d", valkeyMaxMemoryMB))
	}
	if p.Policy == "" {
		p.Policy = "allkeys-lru"
	}
	if err := validate.OneOf("policy", p.Policy, valkeyPolicies); err != nil {
		return nil, err
	}
	v.MaxMemoryMB, v.Policy, v.Persist = p.MaxMemoryMB, p.Policy, p.Persist

	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()
	// Data dir as the site user: the home is the user's tree.
	if err = asUser(ctx, env, l.User, "/usr/bin/install", "-d", "-m", "0700", v.DataDir); err != nil {
		return nil, err
	}
	conf, err := render("valkey.conf.tmpl", v)
	if err != nil {
		return nil, err
	}
	group, err := lookupGroupOwner(env, l.User)
	if err != nil {
		return nil, err
	}
	if err = env.FS.MkdirAll(env.Cfg.SiteConfigDir, 0o755, fsx.Root); err != nil {
		return nil, err
	}
	old, _ := os.ReadFile(v.Config)
	// Root's file, readable by the site's group: the site cannot rewrite its limits.
	if err = env.FS.WriteFile(v.Config, conf, 0o640, group); err != nil {
		return nil, err
	}
	rollback.add(func() {
		if old != nil {
			_ = env.FS.WriteFile(v.Config, old, 0o640, group)
		} else {
			_ = os.Remove(v.Config)
		}
	})
	data := unitData{
		Description: "WSPanel Valkey for " + l.Domain,
		User:        l.User,
		Home:        l.Home,
		ExecStart:   env.Cfg.ValkeyServerBin + " " + v.Config,
		StopTimeout: 30,
		Extra: []string{
			"RuntimeDirectory=" + strings.TrimPrefix(v.RunDir, "/run/"),
			"RuntimeDirectoryMode=0700",
			// The data set may grow past maxmemory by fragmentation and buffers; this is the hard stop.
			fmt.Sprintf("MemoryMax=%dM", p.MaxMemoryMB*3/2+64),
			"LimitNOFILE=4096",
		},
	}
	if err = installService(ctx, env, v.UnitFile, data, &rollback); err != nil {
		return nil, err
	}
	if f := envFilePath(l); f != "" {
		content, err := readSiteFile(ctx, env, l, f)
		if err != nil {
			return nil, err
		}
		keys, values := valkeyEnv(l.Framework, v.Socket)
		if err = writeSiteFile(ctx, env, l, f, mergeEnvSection(content, keys, values, "Valkey (written by WSPanel)")); err != nil {
			return nil, err
		}
	}
	st.Valkey = &valkeyConfig{MaxMemoryMB: p.MaxMemoryMB, Policy: p.Policy, Persist: p.Persist}
	if err = saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "enabled": true, "socket": v.Socket, "unit": v.Unit}, nil
}

// installService writes, checks, enables and (re)starts a unit that does
// not depend on a deployed release.
func installService(ctx context.Context, env *Env, path string, data unitData, rb *undo) error {
	unit, err := render("unit.service.tmpl", data)
	if err != nil {
		return err
	}
	name := filepath.Base(path)
	existed := fsx.Exists(path)
	old, _ := os.ReadFile(path)
	if err := env.FS.WriteFile(path, unit, 0o644, fsx.Root); err != nil {
		return err
	}
	rb.add(func() {
		bg := context.WithoutCancel(ctx)
		if existed {
			_ = env.FS.WriteFile(path, old, 0o644, fsx.Root)
		} else {
			_, _ = env.Exec.Run(bg, "systemctl", "disable", "--now", name)
			_ = os.Remove(path)
		}
		_, _ = env.Exec.Run(bg, "systemctl", "daemon-reload")
	})
	if _, err := env.Exec.Run(ctx, "systemd-analyze", "verify", path); err != nil {
		return fmt.Errorf("unit %s invalid, not installing: %w", name, err)
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "enable", name); err != nil {
		return err
	}
	_, err = env.Exec.Run(ctx, "systemctl", "restart", name)
	return err
}

var valkeyInfoRe = regexp.MustCompile(`(?m)^(used_memory|maxmemory|uptime_in_seconds|connected_clients|evicted_keys):(\d+)\r?$`)
var valkeyKeysRe = regexp.MustCompile(`(?m)^db\d+:keys=(\d+)`)

func valkeyStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.Valkey == nil {
		return map[string]any{"domain": p.Domain, "enabled": false}, nil
	}
	v := valkeyPaths(env, l)
	out := map[string]any{"domain": p.Domain, "enabled": true, "socket": v.Socket, "unit": v.Unit,
		"maxmemory_mb": st.Valkey.MaxMemoryMB, "policy": st.Valkey.Policy, "persist": st.Valkey.Persist}
	if states, err := unitStates(ctx, env, []string{v.Unit}); err == nil && len(states) == 1 {
		out["active"] = states[0].Active
	}
	if res, err := valkeyCLI(ctx, env, l, v.Socket, "INFO"); err == nil {
		for _, m := range valkeyInfoRe.FindAllStringSubmatch(res.Stdout, -1) {
			n, _ := strconv.ParseInt(m[2], 10, 64)
			out[m[1]] = n
		}
		var keys int64
		for _, m := range valkeyKeysRe.FindAllStringSubmatch(res.Stdout, -1) {
			n, _ := strconv.ParseInt(m[1], 10, 64)
			keys += n
		}
		out["keys"] = keys
	}
	return out, nil
}

func valkeyFlush(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	st, l, err := valkeySite(env, raw)
	if err != nil {
		return nil, err
	}
	if _, err := valkeyCLI(ctx, env, l, valkeyPaths(env, l).Socket, "FLUSHALL"); err != nil {
		return nil, err
	}
	return map[string]any{"domain": st.Domain, "flushed": true}, nil
}

func valkeyRestart(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	st, l, err := valkeySite(env, raw)
	if err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "restart", valkeyPaths(env, l).Unit); err != nil {
		return nil, err
	}
	return map[string]any{"domain": st.Domain, "restarted": true}, nil
}

func valkeySite(env *Env, raw json.RawMessage) (siteState, siteLayout, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return siteState{}, siteLayout{}, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return st, l, err
	}
	if st.Valkey == nil {
		return st, l, protocol.Conflict("site " + p.Domain + " has no Valkey (valkey.set first)")
	}
	return st, l, nil
}

// valkeyCLI talks to the site's server as the site user: the socket's
// directory belongs to that user, who could put a link to another site's
// socket in its place; as root the panel would then act on the other site.
func valkeyCLI(ctx context.Context, env *Env, l siteLayout, socket string, args ...string) (executor.Result, error) {
	return env.Exec.Run(ctx, "setpriv", append(append(userCreds(l.User), env.Cfg.ValkeyCLIBin, "-s", socket), args...)...)
}

// removeSiteValkey is part of deleting a site.
func removeSiteValkey(ctx context.Context, env *Env, l siteLayout) error {
	v := valkeyPaths(env, l)
	if fsx.Exists(v.UnitFile) {
		if err := removeUnit(ctx, env, v.UnitFile); err != nil {
			return err
		}
	}
	_ = os.Remove(v.Config)
	return nil
}
