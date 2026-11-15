package ops

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"

	"wspanel/agent/internal/fsx"
)

// RenderWorkerStopOnStart brings the stop timeout of existing sites'
// draining workers (Horizon, queue, Pulse, Messenger, Celery) from the hour
// they used to have to workerStopTimeout after an upgrade; the hour is now
// the old instance's alone, at a deploy. Only that line changes, nothing
// restarts. Without it a reboot waited for those units up to an hour, and
// so did a database's restart that systemd ordered after their stop.
func RenderWorkerStopOnStart(ctx context.Context, env *Env, logf func(string, ...any)) {
	if env.Cfg.DryRun {
		return
	}
	n := 0
	_ = withMutate(env, func() error {
		n = renderWorkerStop(env)
		if n > 0 {
			_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
		}
		return nil
	})
	if n > 0 {
		logf("gave %d worker template(s) a %ds stop timeout", n, workerStopTimeout)
	}
}

func renderWorkerStop(env *Env) int {
	old := fmt.Sprintf("TimeoutStopSec=%d", workerDrainTimeout)
	want := fmt.Sprintf("TimeoutStopSec=%d", workerStopTimeout)
	n := 0
	files, _ := filepath.Glob(filepath.Join(env.Cfg.SystemdDir, "wspanel-*-*@.service"))
	for _, f := range files {
		typ := strings.TrimSuffix(f[strings.LastIndex(f, "-")+1:], "@.service")
		if !slices.Contains(drainingWorkers, typ) {
			continue
		}
		b, err := os.ReadFile(f)
		if err != nil || !strings.HasPrefix(string(b), "# Managed by WSPanel agent") {
			continue
		}
		lines := strings.Split(string(b), "\n")
		changed := false
		for i, line := range lines {
			if line == old {
				lines[i], changed = want, true
			}
		}
		if changed && env.FS.WriteFile(f, []byte(strings.Join(lines, "\n")), 0o644, fsx.Root) == nil {
			n++
		}
	}
	return n
}
