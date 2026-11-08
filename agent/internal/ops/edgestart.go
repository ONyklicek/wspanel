package ops

import (
	"bytes"
	"context"
	"os"

	"wspanel/agent/internal/fsx"
)

// RenderEdgesOnStart brings every site's edge block up to the agent's
// template when the agent starts (a new release adds a route to all sites,
// e.g. the deploy webhook path). Only changed files are written; the proxy
// validates and reloads once, and on a refusal everything goes back.
func RenderEdgesOnStart(ctx context.Context, env *Env, logf func(string, ...any)) {
	if env.Cfg.DryRun {
		return
	}
	_ = withMutate(env, func() error {
		changed, err := renderEdges(ctx, env)
		if err != nil {
			logf("refreshing site edges: %v", err)
		} else if changed > 0 {
			logf("refreshed the edge of %d site(s)", changed)
		}
		return err
	})
}

// renderEdges rewrites the edge blocks whose rendering changed; returns how many.
func renderEdges(ctx context.Context, env *Env) (int, error) {
	type written struct {
		file string
		old  []byte
		had  bool
	}
	var done []written
	restore := func() {
		for _, w := range done {
			if w.had {
				_ = env.FS.WriteFile(w.file, w.old, 0o644, fsx.Root)
			} else {
				_ = os.Remove(w.file)
			}
		}
	}
	for _, st := range allSiteStates(env.Cfg) {
		_, l, err := loadSite(env, st.Domain)
		if err != nil || !fsx.Exists(l.EdgeFile) {
			continue // a site being created or removed: its own op writes the edge
		}
		block, err := render("edge-site.caddy.tmpl", l)
		if err != nil {
			restore()
			return 0, err
		}
		old, readErr := os.ReadFile(l.EdgeFile)
		if readErr == nil && bytes.Equal(old, block) {
			continue
		}
		if err := env.FS.WriteFile(l.EdgeFile, block, 0o644, fsx.Root); err != nil {
			restore()
			return 0, err
		}
		done = append(done, written{l.EdgeFile, old, readErr == nil})
	}
	if len(done) == 0 {
		return 0, nil
	}
	if err := validateEdge(ctx, env); err != nil {
		restore()
		return 0, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "reload", env.Cfg.ProxyUnit); err != nil {
		return 0, err
	}
	return len(done), nil
}
