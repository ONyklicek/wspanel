package ops

import (
	"context"
	"os"
	"path/filepath"
	"strings"

	"wspanel/agent/internal/fsx"
)

// RenderSiteOrderOnStart brings the After= line of existing sites' process
// units (the app, its workers) to the current one after an upgrade: they
// stop before the site's Valkey and the database servers at shutdown. Only
// that line changes, nothing restarts; systemd reads it at the reload.
func RenderSiteOrderOnStart(ctx context.Context, env *Env, logf func(string, ...any)) {
	if env.Cfg.DryRun {
		return
	}
	n := 0
	_ = withMutate(env, func() error {
		n = renderSiteOrder(env)
		if n > 0 {
			_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
		}
		return nil
	})
	if n > 0 {
		logf("ordered %d site unit(s) after their Valkey and databases", n)
	}
}

// renderSiteOrder rewrites the After= line of each site's process units
// whose line differs; it returns how many changed.
func renderSiteOrder(env *Env) int {
	n := 0
	for _, st := range allSiteStates(env.Cfg) {
		_, l, err := loadSite(env, st.Domain)
		if err != nil {
			continue
		}
		want := "After=network.target " + strings.Join(siteUnitDeps(env, l), " ")
		files, _ := filepath.Glob(filepath.Join(env.Cfg.SystemdDir, "wspanel-"+l.User+"-*@.service"))
		files = append(files, l.AppUnitFile, filepath.Join(env.Cfg.SystemdDir, appUnitNameB(l.User)))
		for _, f := range files {
			if strings.HasSuffix(f, "-valkey.service") {
				continue
			}
			b, err := os.ReadFile(f)
			if err != nil || !strings.HasPrefix(string(b), "# Managed by WSPanel agent") || !strings.Contains(string(b), "\nUser="+l.User+"\n") {
				continue
			}
			lines := strings.Split(string(b), "\n")
			changed := false
			for i, line := range lines {
				if strings.HasPrefix(line, "[Service]") {
					break
				}
				if strings.HasPrefix(line, "After=") && line != want {
					lines[i], changed = want, true
				}
			}
			if changed && env.FS.WriteFile(f, []byte(strings.Join(lines, "\n")), 0o644, fsx.Root) == nil {
				n++
			}
		}
	}
	return n
}
