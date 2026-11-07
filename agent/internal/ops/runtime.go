package ops

import (
	"context"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// ---------------------------------------------------------------- Node (fnm)

var nodeDirRe = regexp.MustCompile(`^v(\d+)\.(\d+)\.(\d+)$`)

// nodeVersions maps installed Node majors to the newest installed binary,
// e.g. "22" -> /opt/fnm/node-versions/v22.12.0/installation/bin/node.
func nodeVersions(cfg config.Config) map[string]string {
	dirs, _ := filepath.Glob(filepath.Join(cfg.NodeDir, "v*"))
	best := map[string][3]int{}
	out := map[string]string{}
	for _, d := range dirs {
		m := nodeDirRe.FindStringSubmatch(filepath.Base(d))
		bin := filepath.Join(d, "installation", "bin", "node")
		if m == nil || !fsx.Exists(bin) {
			continue
		}
		var v [3]int
		for i := range v {
			v[i], _ = strconv.Atoi(m[i+1])
		}
		if cur, ok := best[m[1]]; !ok || v[1] > cur[1] || (v[1] == cur[1] && v[2] > cur[2]) {
			best[m[1]] = v
			out[m[1]] = bin
		}
	}
	return out
}

func nodeMajors(cfg config.Config) []string {
	vs := nodeVersions(cfg)
	out := make([]string, 0, len(vs))
	for k := range vs {
		out = append(out, k)
	}
	slices.SortFunc(out, func(a, b string) int {
		x, _ := strconv.Atoi(a)
		y, _ := strconv.Atoi(b)
		return x - y
	})
	return out
}

// nodeEntry is the whitelisted start command per Node framework: the file
// that must exist before the app can start, and the arguments after `node`.
func nodeEntry(framework string, port int) (entry string, args []string) {
	switch framework {
	case "nuxt":
		return ".output/server/index.mjs", []string{".output/server/index.mjs"}
	case "next":
		return "node_modules/next/dist/bin/next", []string{"node_modules/next/dist/bin/next", "start", "-p", strconv.Itoa(port), "-H", "127.0.0.1"}
	default: // node
		return "server.js", []string{"server.js"}
	}
}

// allocatePort picks the lowest free port in the configured range that no
// other site holds and nothing listens on.
func allocatePort(env *Env) (int, error) {
	used := map[int]bool{}
	for _, st := range allSiteStates(env.Cfg) {
		used[st.Port] = true
	}
	for p := env.Cfg.NodePortMin; p <= env.Cfg.NodePortMax; p++ {
		if used[p] {
			continue
		}
		ln, err := net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(p))
		if err != nil {
			continue
		}
		ln.Close()
		return p, nil
	}
	return 0, fmt.Errorf("no free port in %d-%d", env.Cfg.NodePortMin, env.Cfg.NodePortMax)
}

// ---------------------------------------------------------------- FrankenPHP

var frankenPHPVersionRe = regexp.MustCompile(`\bPHP (\d+\.\d+)\.\d+`)

// workerPHPVersion is the PHP version built into the FrankenPHP binary. One
// binary = one PHP version; worker sites cannot choose another.
func workerPHPVersion(ctx context.Context, env *Env) (string, error) {
	res, err := env.Exec.Run(ctx, "frankenphp", "version")
	if err != nil {
		return "", err
	}
	m := frankenPHPVersionRe.FindStringSubmatch(res.Stdout)
	if m == nil {
		return "", fmt.Errorf("cannot determine FrankenPHP's PHP version from %q", strings.TrimSpace(res.Stdout))
	}
	return m[1], nil
}

// workerEntry: the file that must exist before the instance can start, the
// worker script (absolute: FrankenPHP resolves relative paths against the
// process cwd, not the php_server root), and extra worker env, per framework.
func workerEntry(framework, home string) (entry, script string, envs []string) {
	switch framework {
	case "laravel": // Laravel Octane (php artisan octane:install --server=frankenphp); routed via workerIndex
		return "public/frankenphp-worker.php", home + "/current/public/frankenphp-worker.php", []string{
			"APP_BASE_PATH " + home + "/current",
			"APP_PUBLIC_PATH " + home + "/current/public",
			"LARAVEL_OCTANE 1",
		}
	default: // symfony >= 7.4: native FrankenPHP runtime
		return "vendor/autoload_runtime.php", home + "/current/public/index.php", nil
	}
}

// workerIndex is the front controller requests fall back to when they are
// not a static file. Symfony's index.php is the worker itself (default);
// Laravel routes everything to Octane's worker script, as Octane's own
// Caddyfile does.
func workerIndex(framework string) string {
	if framework == "laravel" {
		return "frankenphp-worker.php"
	}
	return ""
}

func workerThreads() int {
	n := runtime.NumCPU()
	return max(2, min(n, 8))
}

// ---------------------------------------------------------------- units

type unitData struct {
	Description string
	User        string
	Home        string
	RunDir      string
	ExecStart   string
	Environment []string
	StopTimeout int
}

// appUnitData renders the long-running app process of worker and node sites.
func appUnitData(env *Env, l siteLayout) (unitData, string, error) {
	d := unitData{User: l.User, Home: l.Home, StopTimeout: 30}
	switch l.Mode {
	case "worker":
		entry, _, _ := workerEntry(l.Framework, l.Home)
		d.Description = "WSPanel worker-mode (FrankenPHP) app for " + l.Domain
		d.RunDir = l.RunDir
		d.ExecStart = env.Cfg.FrankenPHPBin + " run --config " + l.InstanceConfig + " --adapter caddyfile"
		d.Environment = []string{"XDG_DATA_HOME=" + l.Home + "/tmp/xdg", "XDG_CONFIG_HOME=" + l.Home + "/tmp/xdg"}
		return d, entry, nil
	case "node":
		bin, ok := nodeVersions(env.Cfg)[l.NodeVersion]
		if !ok {
			return d, "", protocol.Invalid("node_version: Node " + l.NodeVersion + " is not installed")
		}
		entry, args := nodeEntry(l.Framework, l.Port)
		d.Description = "WSPanel Node SSR app for " + l.Domain
		d.ExecStart = bin + " " + strings.Join(args, " ")
		port := strconv.Itoa(l.Port)
		d.Environment = []string{
			"NODE_ENV=production", "PATH=" + filepath.Dir(bin) + ":/usr/bin:/bin",
			"HOST=127.0.0.1", "PORT=" + port, "HOSTNAME=127.0.0.1", "NITRO_HOST=127.0.0.1", "NITRO_PORT=" + port,
		}
		return d, entry, nil
	}
	return d, "", fmt.Errorf("mode %s has no app unit", l.Mode)
}

// installUnit writes a unit, verifies it, enables it and starts it when its
// entry file exists (before the first deploy it would only crash-loop).
func installUnit(ctx context.Context, env *Env, path string, data unitData, entry string, rb *undo) (started bool, err error) {
	unit, err := render("unit.service.tmpl", data)
	if err != nil {
		return false, err
	}
	name := filepath.Base(path)
	existed := fsx.Exists(path)
	var old []byte
	if existed {
		old, _ = os.ReadFile(path)
	}
	if err = env.FS.WriteFile(path, unit, 0o644, fsx.Root); err != nil {
		return false, err
	}
	rb.add(func() {
		if existed {
			_ = env.FS.WriteFile(path, old, 0o644, fsx.Root)
		} else {
			_, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "disable", "--now", name)
			_ = os.Remove(path)
		}
		_, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "daemon-reload")
	})
	if _, err = env.Exec.Run(ctx, "systemd-analyze", "verify", path); err != nil {
		return false, fmt.Errorf("unit %s invalid, not installing: %w", name, err)
	}
	if _, err = env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return false, err
	}
	if _, err = env.Exec.Run(ctx, "systemctl", "enable", name); err != nil {
		return false, err
	}
	if !fsx.Exists(filepath.Join(data.Home, "current", entry)) {
		if existed {
			_, _ = env.Exec.Run(ctx, "systemctl", "stop", name)
		}
		return false, nil
	}
	action := "start"
	if existed {
		action = "restart"
	}
	if _, err = env.Exec.Run(ctx, "systemctl", action, name); err != nil {
		return false, err
	}
	return true, nil
}
