package ops

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strings"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// PHP on the server: versions (packages.sury.org), their extensions, and the
// PHP built into FrankenPHP (worker mode), which has a fixed set of extensions.

// InstallablePHP are the versions sury publishes for Debian 13 that the agent
// knows how to set up (the binary allowlist covers each of them).
var InstallablePHP = []string{"8.1", "8.2", "8.3", "8.4", "8.5"}

// Packages a version gets when installed from the panel (as install.sh does).
var basePHPPackages = []string{"fpm", "cli", "common", "opcache", "intl", "bcmath", "mbstring", "xml", "curl", "zip", "gd", "sqlite3", "redis", "apcu"}

// Packages that are PHP itself, not an extension: never listed as extensions,
// never removed from the panel.
var phpCorePackages = []string{"fpm", "cli", "common", "cgi", "dev", "phpdbg", "opcache", "readline"}

// opcacheBuiltIn: from PHP 8.5 OPcache is part of PHP itself, and sury
// publishes no phpX.Y-opcache package for it.
func opcacheBuiltIn(version string) bool {
	var major, minor int
	if _, err := fmt.Sscanf(version, "%d.%d", &major, &minor); err != nil {
		return false
	}
	return major > 8 || (major == 8 && minor >= 5)
}

// phpInstallPackages: what installing a PHP version brings (as install.sh).
func phpInstallPackages(version string, engines []string) []string {
	pkgs := make([]string, 0, len(basePHPPackages)+2)
	for _, ext := range basePHPPackages {
		if ext == "opcache" && opcacheBuiltIn(version) {
			continue
		}
		pkgs = append(pkgs, "php"+version+"-"+ext)
	}
	if slices.Contains(engines, "mariadb") {
		pkgs = append(pkgs, "php"+version+"-mysql")
	}
	if slices.Contains(engines, "postgresql") {
		pkgs = append(pkgs, "php"+version+"-pgsql")
	}
	return pkgs
}

var phpExtRe = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,39}$`)

type phpVersionInfo struct {
	Version    string   `json:"version"`
	Installed  bool     `json:"installed"`
	Default    bool     `json:"default"`
	Extensions []string `json:"extensions"` // installed extension packages (without the php<v>- prefix)
	Available  []string `json:"available"`  // installable from the repository
}

// phpPackages: installed and available php<v>-* packages, by extension name.
func phpPackages(ctx context.Context, env *Env, version string) (installed, available []string) {
	prefix := "php" + version + "-"
	if res, err := env.Exec.Run(ctx, "dpkg-query", "-W", "-f", "${Package}\t${db:Status-Status}\n", prefix+"*"); err == nil {
		for _, line := range strings.Split(res.Stdout, "\n") {
			pkg, status, ok := strings.Cut(strings.TrimSpace(line), "\t")
			if ok && status == "installed" && strings.HasPrefix(pkg, prefix) {
				installed = append(installed, strings.TrimPrefix(pkg, prefix))
			}
		}
	}
	if res, err := env.Exec.Run(ctx, "apt-cache", "pkgnames", prefix); err == nil {
		for _, pkg := range strings.Fields(res.Stdout) {
			if ext := strings.TrimPrefix(pkg, prefix); phpExtRe.MatchString(ext) && !slices.Contains(phpCorePackages, ext) && !strings.Contains(ext, "dbgsym") {
				available = append(available, ext)
			}
		}
	}
	installed = slices.DeleteFunc(installed, func(e string) bool { return slices.Contains(phpCorePackages, e) })
	sort.Strings(installed)
	sort.Strings(available)
	return slices.Compact(installed), slices.Compact(available)
}

func phpStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	versions := make([]phpVersionInfo, 0, len(env.Cfg.PHPVersions))
	for _, v := range env.Cfg.PHPVersions {
		_, err := os.Stat(config.ForVersion(env.Cfg.FPMBinary, v))
		installed, available := phpPackages(ctx, env, v)
		versions = append(versions, phpVersionInfo{Version: v, Installed: err == nil || env.Cfg.DryRun, Default: v == env.Cfg.DefaultPHPVer,
			Extensions: nonNil(installed), Available: nonNil(available)})
	}
	var installable []string
	for _, v := range InstallablePHP {
		if !slices.Contains(env.Cfg.PHPVersions, v) {
			installable = append(installable, v)
		}
	}
	franken := map[string]any{}
	if res, err := env.Exec.Run(ctx, "frankenphp", "version"); err == nil {
		franken["version"] = strings.TrimSpace(res.Stdout)
	}
	if v, err := workerPHPVersion(ctx, env); err == nil {
		franken["php"] = v
	}
	// `php-cli -m` needs PHP 8.6+; a one-line script works with every version.
	script := filepath.Join(env.Cfg.StateDir, "frankenphp-extensions.php")
	if !fsx.Exists(script) {
		_ = env.FS.WriteFile(script, []byte("<?php echo implode(\"\\n\", get_loaded_extensions()), \"\\n\";\n"), 0o644, fsx.Root)
	}
	if res, err := env.Exec.Run(ctx, "frankenphp", "php-cli", script); err == nil {
		var mods []string
		for _, l := range strings.Split(res.Stdout, "\n") {
			l = strings.TrimSpace(l)
			if l != "" && !strings.HasPrefix(l, "[") {
				mods = append(mods, strings.ToLower(l))
			}
		}
		sort.Strings(mods)
		franken["extensions"] = slices.Compact(mods)
	}
	return map[string]any{"versions": versions, "installable": nonNil(installable), "frankenphp": franken}, nil
}

func nonNil(s []string) []string {
	if s == nil {
		return []string{}
	}
	return s
}

// phpExtension installs or removes one extension package of one version (async: apt).
func phpExtension(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Version   string `json:"version"`
		Extension string `json:"extension"`
		Action    string `json:"action"` // install | remove
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !slices.Contains(env.Cfg.PHPVersions, p.Version) {
		return nil, protocol.NotFound("PHP " + p.Version + " is not installed on this server")
	}
	if err := validate.OneOf("action", p.Action, []string{"install", "remove"}); err != nil {
		return nil, err
	}
	if !phpExtRe.MatchString(p.Extension) || slices.Contains(phpCorePackages, p.Extension) {
		return nil, protocol.Invalid("extension: not an extension package")
	}
	installed, available := phpPackages(ctx, env, p.Version)
	pkg := "php" + p.Version + "-" + p.Extension
	switch p.Action {
	case "install":
		if !slices.Contains(available, p.Extension) && !env.Cfg.DryRun {
			return nil, protocol.NotFound("extension " + p.Extension + " is not available for PHP " + p.Version)
		}
		if slices.Contains(installed, p.Extension) {
			return map[string]any{"version": p.Version, "extension": p.Extension, "changed": false}, nil
		}
		if _, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", "install", "-y", "-q", "--no-install-recommends", pkg); err != nil {
			return nil, err
		}
	case "remove":
		if !slices.Contains(installed, p.Extension) {
			return map[string]any{"version": p.Version, "extension": p.Extension, "changed": false}, nil
		}
		// apt also removes what depends on the package (php-redis needs
		// igbinary): ask first, and refuse rather than take more than asked.
		sim, err := env.Exec.Run(ctx, "apt-get", "remove", "--simulate", "-q", pkg)
		if err != nil {
			return nil, err
		}
		if others := aptRemovals(sim.Stdout, pkg); len(others) > 0 {
			return nil, protocol.Conflict("extension " + p.Extension + " is needed by " + strings.Join(others, ", ") + "; remove those first")
		}
		if _, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", "remove", "-y", "-q", pkg); err != nil {
			return nil, err
		}
	}
	if err := validateFPM(ctx, env, p.Version); err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "reload", config.ForVersion(env.Cfg.FPMUnit, p.Version)); err != nil {
		return nil, err
	}
	return map[string]any{"version": p.Version, "extension": p.Extension, "changed": true, "action": p.Action}, nil
}

// phpInstall installs another PHP version like install.sh does (async: apt).
func phpInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Version string `json:"version"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("version", p.Version, InstallablePHP); err != nil {
		return nil, err
	}
	if slices.Contains(env.Cfg.PHPVersions, p.Version) {
		return map[string]any{"version": p.Version, "changed": false}, nil
	}
	pkgs := phpInstallPackages(p.Version, env.Cfg.DBEngines)
	if _, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", "update", "-q"); err != nil {
		return nil, err
	}
	if _, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", append([]string{"install", "-y", "-q", "--no-install-recommends"}, pkgs...)...); err != nil {
		return nil, err
	}
	// The same php.ini additions and idle pool as the versions install.sh set up.
	if err := copyPHPSetup(env, env.Cfg.DefaultPHPVer, p.Version); err != nil {
		return nil, err
	}
	if err := validateFPM(ctx, env, p.Version); err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "enable", "--now", config.ForVersion(env.Cfg.FPMUnit, p.Version)); err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "restart", config.ForVersion(env.Cfg.FPMUnit, p.Version)); err != nil {
		return nil, err
	}
	env.Cfg.PHPVersions = append(env.Cfg.PHPVersions, p.Version)
	sort.Strings(env.Cfg.PHPVersions)
	if err := persistConfigKey(env, "php_versions", env.Cfg.PHPVersions); err != nil {
		return nil, fmt.Errorf("PHP %s installed, but saving agent.json failed: %w", p.Version, err)
	}
	return map[string]any{"version": p.Version, "changed": true, "packages": pkgs}, nil
}

// copyPHPSetup copies the panel's php.ini additions and the idle pool from an
// installed version, and drops Debian's default www pool.
func copyPHPSetup(env *Env, from, to string) error {
	etc := func(v string) string { return filepath.Dir(filepath.Dir(config.ForVersion(env.Cfg.FPMPoolDir, v))) } // /etc/php/<v>
	for _, sapi := range []string{"fpm", "cli"} {
		src := filepath.Join(etc(from), sapi, "conf.d", "90-wspanel.ini")
		b, err := os.ReadFile(src)
		if err != nil {
			if env.Cfg.DryRun {
				continue
			}
			return err
		}
		if err := env.FS.MkdirAll(filepath.Join(etc(to), sapi, "conf.d"), 0o755, fsx.Root); err != nil {
			return err
		}
		if err := env.FS.WriteFile(filepath.Join(etc(to), sapi, "conf.d", "90-wspanel.ini"), b, 0o644, fsx.Root); err != nil {
			return err
		}
	}
	idle, err := os.ReadFile(filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, from), "00-wspanel-idle.conf"))
	if err != nil && !env.Cfg.DryRun {
		return err
	}
	poolDir := config.ForVersion(env.Cfg.FPMPoolDir, to)
	if err := env.FS.MkdirAll(poolDir, 0o755, fsx.Root); err != nil {
		return err
	}
	idle = bytes.ReplaceAll(idle, []byte(from), []byte(to))
	if err := env.FS.WriteFile(filepath.Join(poolDir, "00-wspanel-idle.conf"), idle, 0o644, fsx.Root); err != nil {
		return err
	}
	_ = os.Remove(filepath.Join(poolDir, "www.conf"))
	return nil
}

// persistConfigKey rewrites one key of agent.json, keeping every other key.
func persistConfigKey(env *Env, key string, value any) error {
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
	m[key] = value
	var out bytes.Buffer
	enc := json.NewEncoder(&out)
	enc.SetIndent("", "  ")
	if err := enc.Encode(m); err != nil {
		return err
	}
	return env.FS.WriteFile(env.ConfigPath, out.Bytes(), 0o600, fsx.Root)
}

// aptRemovals lists the packages a simulated removal would take besides pkg.
func aptRemovals(simulated, pkg string) []string {
	var out []string
	for _, line := range strings.Split(simulated, "\n") {
		f := strings.Fields(line)
		if len(f) >= 2 && f[0] == "Remv" && f[1] != pkg {
			out = append(out, f[1])
		}
	}
	return out
}
