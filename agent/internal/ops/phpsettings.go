package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Per-site PHP settings (php.ini values). Only the directives below can be
// set, each with its own check: they end up in an FPM pool or a FrankenPHP
// Caddyfile, so a value is never passed through as written.

type phpDirective struct {
	flag  bool                         // on/off
	check func(string) (string, error) // returns the normalized value
	modes []string                     // empty = classic and worker
}

func sizeRange(min, max int64) func(string) (string, error) {
	re := regexp.MustCompile(`^([0-9]{1,6})([KMG]?)$`)
	return func(v string) (string, error) {
		v = strings.ToUpper(strings.TrimSpace(v))
		if v == "-1" {
			return v, nil
		}
		m := re.FindStringSubmatch(v)
		if m == nil {
			return "", fmt.Errorf("a size such as 256M or 1G")
		}
		n, _ := strconv.ParseInt(m[1], 10, 64)
		n <<= map[string]uint{"": 0, "K": 10, "M": 20, "G": 30}[m[2]]
		if n < min || n > max {
			return "", fmt.Errorf("between %dM and %dG", min>>20, max>>30)
		}
		return v, nil
	}
}

func intRange(min, max int) func(string) (string, error) {
	return func(v string) (string, error) {
		n, err := strconv.Atoi(strings.TrimSpace(v))
		if err != nil || n < min || n > max {
			return "", fmt.Errorf("a whole number %d-%d", min, max)
		}
		return strconv.Itoa(n), nil
	}
}

func onOff(v string) (string, error) {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "on", "1", "true":
		return "on", nil
	case "off", "0", "false":
		return "off", nil
	}
	return "", fmt.Errorf("on or off")
}

func timezone(v string) (string, error) {
	v = strings.TrimSpace(v)
	if !regexp.MustCompile(`^[A-Za-z][A-Za-z0-9_+\-/]{0,63}$`).MatchString(v) {
		return "", fmt.Errorf("a time zone such as Europe/Prague")
	}
	if _, err := time.LoadLocation(v); err != nil {
		return "", fmt.Errorf("unknown time zone")
	}
	return v, nil
}

// error_reporting by name: FPM pools do not evaluate E_* constants.
func errorLevel(v string) (string, error) {
	levels := map[string]string{"all": "32767", "production": "22527", "errors": "1"} // E_ALL; E_ALL & ~E_DEPRECATED & ~E_STRICT; E_ERROR
	if n, ok := levels[strings.TrimSpace(v)]; ok {
		return n, nil
	}
	return "", fmt.Errorf("all, production or errors")
}

var phpDirectives = map[string]phpDirective{
	"memory_limit":           {check: sizeRange(16<<20, 8<<30)},
	"upload_max_filesize":    {check: sizeRange(1<<20, 10<<30)},
	"post_max_size":          {check: sizeRange(1<<20, 10<<30)},
	"max_execution_time":     {check: intRange(0, 3600)},
	"max_input_time":         {check: intRange(-1, 3600)},
	"max_input_vars":         {check: intRange(100, 100000)},
	"date.timezone":          {check: timezone},
	"display_errors":         {flag: true, check: onOff},
	"error_reporting":        {check: errorLevel},
	"session.gc_maxlifetime": {check: intRange(60, 2592000)},
	"allow_url_fopen":        {flag: true, check: onOff},
	"short_open_tag":         {flag: true, check: onOff},
	// FrankenPHP worker mode: workers per site and requests before a worker is recycled.
	"worker_threads": {check: intRange(1, 64), modes: []string{"worker"}},
	"max_requests":   {check: intRange(0, 100000), modes: []string{"worker"}},
}

// phpLine is one rendered setting.
type phpLine struct {
	Key   string
	Value string
	Flag  bool
}

// phpLines: the site's settings that go into php.ini (not the worker knobs), sorted.
func phpLines(settings map[string]string) []phpLine {
	var out []phpLine
	for k, v := range settings {
		d, ok := phpDirectives[k]
		if !ok || len(d.modes) > 0 || k == "display_errors" {
			continue
		}
		out = append(out, phpLine{Key: k, Value: v, Flag: d.flag})
	}
	slices.SortFunc(out, func(a, b phpLine) int { return strings.Compare(a.Key, b.Key) })
	return out
}

// carryPHPSettings keeps what still applies after a mode switch.
func carryPHPSettings(settings map[string]string, mode string) map[string]string {
	if mode == "node" {
		return nil
	}
	out := map[string]string{}
	for k, v := range settings {
		if d, ok := phpDirectives[k]; ok && (len(d.modes) == 0 || slices.Contains(d.modes, mode)) {
			out[k] = v
		}
	}
	if len(out) == 0 {
		return nil
	}
	return out
}

// sitePHP sets the site's PHP settings: the given map replaces the previous one
// (an empty value = back to the default).
func sitePHP(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain   string            `json:"domain"`
		Settings map[string]string `json:"settings"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.Mode == "node" {
		return nil, protocol.Invalid("settings: a Node site runs no PHP")
	}
	clean := map[string]string{}
	for k, v := range p.Settings {
		d, ok := phpDirectives[k]
		if !ok {
			return nil, protocol.Invalid("settings: " + k + " cannot be set from the panel")
		}
		if strings.TrimSpace(v) == "" {
			continue
		}
		if len(d.modes) > 0 && !slices.Contains(d.modes, st.Mode) {
			return nil, protocol.Invalid("settings: " + k + " applies to " + strings.Join(d.modes, ", ") + " mode only")
		}
		n, err := d.check(v)
		if err != nil {
			return nil, protocol.Invalid("settings: " + k + ": " + err.Error())
		}
		clean[k] = n
	}
	st.PHPSettings = clean
	if len(clean) == 0 {
		st.PHPSettings = nil
	}
	l, err := layout(env.Cfg, st)
	if err != nil {
		return nil, err
	}
	if err := applyPHPRuntime(ctx, env, l); err != nil {
		return nil, err
	}
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "settings": clean}, nil
}

// applyPHPRuntime re-renders the file that carries the settings, checks it and
// reloads; on a failed check the previous file is put back.
func applyPHPRuntime(ctx context.Context, env *Env, l siteLayout) error {
	switch l.Mode {
	case "classic":
		pool, err := render("fpm-pool.conf.tmpl", l)
		if err != nil {
			return err
		}
		old, err := os.ReadFile(l.PoolFile)
		if err != nil {
			return err
		}
		if err := env.FS.WriteFile(l.PoolFile, pool, 0o644, fsx.Root); err != nil {
			return err
		}
		if err := validateFPM(ctx, env, l.PHPVersion); err != nil {
			_ = env.FS.WriteFile(l.PoolFile, old, 0o644, fsx.Root)
			return err
		}
		_, err = env.Exec.Run(ctx, "systemctl", "reload", strings.ReplaceAll(env.Cfg.FPMUnit, "{version}", l.PHPVersion))
		return err
	case "worker":
		cfg, err := render("instance.caddy.tmpl", l)
		if err != nil {
			return err
		}
		old, err := os.ReadFile(l.InstanceConfig)
		if err != nil {
			return err
		}
		group, err := lookupGroupOwner(env, l.User)
		if err != nil {
			return err
		}
		if err := env.FS.WriteFile(l.InstanceConfig, cfg, 0o640, group); err != nil {
			return err
		}
		if err := validateCaddy(ctx, env, "worker instance", l.InstanceConfig); err != nil {
			_ = env.FS.WriteFile(l.InstanceConfig, old, 0o640, group)
			return err
		}
		if l.Disabled {
			return nil
		}
		_, err = env.Exec.Run(ctx, "systemctl", "restart", l.AppUnit)
		return err
	}
	return protocol.Invalid("mode " + l.Mode)
}
