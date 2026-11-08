package ops

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// XHProf + XHGui, per site and switchable. Classic (PHP-FPM) sites only: the
// pool loads the extension and a prepend file that profiles a share of the
// requests into SQLite in the site's home. The viewer (XHGui) runs in the
// same pool under the panel's address, /xhgui/<domain>/, so it reads only
// that site's profiles, as that site's user.

func xhprofRatio(st siteState) int {
	if st.Xhprof == nil {
		return 0
	}
	return st.Xhprof.Ratio
}

func xhprofInstalled(env *Env) bool {
	return env.Cfg.DryRun || fileIn(env.Cfg.XhguiDir, "prepend.php")
}

// xhprofInstall installs the extension (every PHP version) and XHGui.
func xhprofInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	res, err := env.Exec.Run(ctx, "xhprof", env.Cfg.PHPVersions...)
	if err != nil {
		return nil, err
	}
	return map[string]any{"installed": true, "log": tail(res.Stdout, 2000)}, nil
}

func xhprofStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	return map[string]any{"installed": !env.Cfg.DryRun && fileIn(env.Cfg.XhguiDir, "prepend.php")}, nil
}

func siteXhprof(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain  string `json:"domain"`
		Enabled bool   `json:"enabled"`
		Ratio   int    `json:"ratio,omitempty"` // percent, default 100
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Ratio == 0 {
		p.Ratio = 100
	}
	if p.Ratio < 1 || p.Ratio > 100 {
		return nil, protocol.Invalid("ratio: 1-100 (percent of requests)")
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if p.Enabled {
		if st.Mode != "classic" {
			return nil, protocol.Invalid("xhprof: only classic (PHP-FPM) sites")
		}
		if !xhprofInstalled(env) {
			return nil, protocol.Conflict("XHProf is not installed (Server → Moduly)")
		}
		st.Xhprof = &xhprofConfig{Ratio: p.Ratio}
	} else {
		st.Xhprof = nil
	}
	if l, err = layout(env.Cfg, st); err != nil {
		return nil, err
	}
	if p.Enabled {
		owner, err := lookupOwner(env, l.User)
		if err != nil {
			return nil, err
		}
		if err := env.FS.MkdirAll(filepath.Join(l.Home, "xhgui"), 0o700, owner); err != nil {
			return nil, err
		}
	}
	if err := rewritePool(ctx, env, l); err != nil {
		return nil, err
	}
	var rb undo
	if p.Enabled {
		if err := env.FS.MkdirAll(env.Cfg.XhguiWebDir, 0o755, fsx.Root); err != nil {
			return nil, err
		}
		if err := writeCaddyBlock(ctx, env, "xhgui-web.caddy.tmpl", l, l.XhguiFile, env.Cfg.WebUnit, validateWeb, &rb); err != nil {
			rb.run()
			return nil, err
		}
	} else if err := removeCaddyBlock(ctx, env, l.XhguiFile, env.Cfg.WebUnit, validateWeb); err != nil {
		return nil, err
	}
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "enabled": p.Enabled, "ratio": l.XhprofRatio, "viewer": "/xhgui/" + p.Domain + "/"}, nil
}

// rewritePool renders a classic site's pool, checks it with php-fpm -t (the
// old one comes back on failure) and reloads FPM.
func rewritePool(ctx context.Context, env *Env, l siteLayout) error {
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
	_, err = env.Exec.Run(ctx, "systemctl", "reload", config.ForVersion(env.Cfg.FPMUnit, l.PHPVersion))
	return err
}
