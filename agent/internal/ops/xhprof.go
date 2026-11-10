package ops

import (
	"bytes"
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
// requests into a spool in the site's home. The viewer (XHGui) runs in the
// same pool on the site's own address (/_wsp_xhgui/), never the panel's: the
// site's user controls its pool, so nothing it serves may share the panel's
// origin. The edge lets in only a sign-in link from the panel.

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
	res, err := runModuleScript(ctx, env, "xhprof", env.Cfg.PHPVersions...)
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
	// The viewer lives in the site's own blocks (web tier and edge).
	var rb undo
	if err := writeCaddyBlock(ctx, env, "web-site.caddy.tmpl", l, l.WebFile, env.Cfg.WebUnit, validateWeb, &rb); err != nil {
		rb.run()
		return nil, err
	}
	if err := writeEdge(ctx, env, l, &rb); err != nil {
		rb.run()
		return nil, err
	}
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "enabled": p.Enabled, "ratio": l.XhprofRatio, "viewer": "/_wsp_xhgui/"}, nil
}

// rewritePool renders a classic site's pool, checks it with php-fpm -t (the
// old one comes back on failure) and reloads FPM. An unchanged pool is left
// alone: one FPM master runs every site's pool, and a reload empties OPcache
// and APCu for all of them and replaces their idle workers.
func rewritePool(ctx context.Context, env *Env, l siteLayout) error {
	pool, err := render("fpm-pool.conf.tmpl", l)
	if err != nil {
		return err
	}
	old, err := os.ReadFile(l.PoolFile)
	if err != nil {
		return err
	}
	if bytes.Equal(old, pool) {
		return nil
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
