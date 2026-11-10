package ops

import (
	"bytes"
	"context"
	"os"
	"sort"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
)

// RenderEdgesOnStart brings every site's generated configuration up to the
// agent's templates when the agent starts: the edge blocks (a new release
// adds a route to all sites, e.g. the deploy webhook path), the web tier's
// blocks and the PHP-FPM pools (new tuning). Only changed files are written;
// each service validates and reloads once, and on a refusal its files go back.
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
		if n, err := renderWebBlocks(ctx, env); err != nil {
			logf("refreshing the web tier's site blocks: %v", err)
		} else if n > 0 {
			logf("refreshed the web tier block of %d site(s)", n)
		}
		if n, err := renderPools(ctx, env); err != nil {
			logf("refreshing PHP-FPM pools: %v", err)
		} else if n > 0 {
			logf("refreshed the PHP-FPM pool of %d site(s)", n)
		}
		return err
	})
}

// renderedFile is one generated file as the current template renders it.
type renderedFile struct {
	path string
	data []byte
}

// applyRendered writes the files whose content changed, validates, and
// reloads unit once; a refused validation puts every file back. It returns
// how many files changed.
func applyRendered(ctx context.Context, env *Env, files []renderedFile, validate func(context.Context, *Env) error, unit string) (int, error) {
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
	for _, f := range files {
		old, readErr := os.ReadFile(f.path)
		if readErr == nil && bytes.Equal(old, f.data) {
			continue
		}
		if err := env.FS.WriteFile(f.path, f.data, 0o644, fsx.Root); err != nil {
			restore()
			return 0, err
		}
		done = append(done, written{f.path, old, readErr == nil})
	}
	if len(done) == 0 {
		return 0, nil
	}
	if err := validate(ctx, env); err != nil {
		restore()
		return 0, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "reload", unit); err != nil {
		return 0, err
	}
	return len(done), nil
}

// existingSites yields the layout of each site whose file at pick exists (a
// site being created or removed: its own op writes its files).
func existingSites(env *Env, pick func(siteLayout) string) []siteLayout {
	var out []siteLayout
	for _, st := range allSiteStates(env.Cfg) {
		_, l, err := loadSite(env, st.Domain)
		if err != nil || pick(l) == "" || !fsx.Exists(pick(l)) {
			continue
		}
		out = append(out, l)
	}
	return out
}

// renderEdges rewrites the edge blocks whose rendering changed; returns how many.
func renderEdges(ctx context.Context, env *Env) (int, error) {
	var files []renderedFile
	for _, l := range existingSites(env, func(l siteLayout) string { return l.EdgeFile }) {
		block, err := render("edge-site.caddy.tmpl", l)
		if err != nil {
			return 0, err
		}
		files = append(files, renderedFile{l.EdgeFile, block})
	}
	return applyRendered(ctx, env, files, validateEdge, env.Cfg.ProxyUnit)
}

// renderWebBlocks does the same for the web tier's blocks of classic sites.
func renderWebBlocks(ctx context.Context, env *Env) (int, error) {
	var files []renderedFile
	for _, l := range existingSites(env, func(l siteLayout) string { return l.WebFile }) {
		if l.Mode != "classic" {
			continue
		}
		block, err := render("web-site.caddy.tmpl", l)
		if err != nil {
			return 0, err
		}
		files = append(files, renderedFile{l.WebFile, block})
	}
	return applyRendered(ctx, env, files, validateWeb, env.Cfg.WebUnit)
}

// renderPools does the same for the PHP-FPM pools, one reload per version.
func renderPools(ctx context.Context, env *Env) (int, error) {
	byVersion := map[string][]renderedFile{}
	for _, l := range existingSites(env, func(l siteLayout) string { return l.PoolFile }) {
		if l.Mode != "classic" || l.PHPVersion == "" {
			continue
		}
		pool, err := render("fpm-pool.conf.tmpl", l)
		if err != nil {
			return 0, err
		}
		byVersion[l.PHPVersion] = append(byVersion[l.PHPVersion], renderedFile{l.PoolFile, pool})
	}
	versions := make([]string, 0, len(byVersion))
	for v := range byVersion {
		versions = append(versions, v)
	}
	sort.Strings(versions)
	total := 0
	for _, v := range versions {
		n, err := applyRendered(ctx, env, byVersion[v], func(ctx context.Context, env *Env) error { return validateFPM(ctx, env, v) },
			config.ForVersion(env.Cfg.FPMUnit, v))
		if err != nil {
			return total, err
		}
		total += n
	}
	return total, nil
}
