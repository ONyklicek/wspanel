package ops

import (
	"context"
	"encoding/json"
	"os"
	"regexp"
	"strings"
	"syscall"

	"wspanel/agent/internal/protocol"
)

// Upgrading WSPanel from the panel: /usr/lib/wspanel/panel-upgrade.sh in a
// systemd unit of its own (wspanel-upgrade), started through PID 1, so that
// it outlives the restarts of the panel and of this agent it causes.

const upgradeUnit = "wspanel-upgrade.service"

// upgradeScript: the installer puts it there (tests point it elsewhere).
var upgradeScript = "/usr/lib/wspanel/panel-upgrade.sh"

var releaseVersionRe = regexp.MustCompile(`^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$`)

func upgradeState(ctx context.Context, env *Env) map[string]string {
	out := map[string]string{}
	res, err := env.Exec.Run(ctx, "systemctl", "show", "-p", "ActiveState,SubState,Result,InvocationID,ExecMainStartTimestamp,ExecMainExitTimestamp", upgradeUnit)
	if err != nil {
		return out
	}
	for _, line := range strings.Split(res.Stdout, "\n") {
		if k, v, ok := strings.Cut(line, "="); ok {
			out[k] = v
		}
	}
	return out
}

// panelUpgrade starts the upgrade to version (one at a time).
func panelUpgrade(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Version string `json:"version"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	p.Version = strings.TrimPrefix(p.Version, "v")
	if !releaseVersionRe.MatchString(p.Version) {
		return nil, protocol.Invalid("version: e.g. 1.2.0")
	}
	if st := upgradeState(ctx, env)["ActiveState"]; st == "activating" || st == "deactivating" {
		return nil, protocol.Conflict("an upgrade is already running")
	}
	if !env.Cfg.DryRun {
		// Root's file, writable by root only: what runs as root here.
		fi, err := os.Lstat(upgradeScript)
		var st *syscall.Stat_t
		if err == nil {
			st, _ = fi.Sys().(*syscall.Stat_t)
		}
		if err != nil || st == nil || !fi.Mode().IsRegular() || fi.Mode().Perm()&0o022 != 0 || st.Uid != 0 {
			return nil, protocol.Conflict("the upgrade script is missing (" + upgradeScript + "): upgrade once from the command line")
		}
	}
	// The record of the previous run goes, this one keeps its own until the next.
	_, _ = env.Exec.Run(ctx, "systemctl", "stop", upgradeUnit)
	_, _ = env.Exec.Run(ctx, "systemctl", "reset-failed", upgradeUnit)
	if _, err := env.Exec.Run(ctx, "systemd-run", "--unit="+upgradeUnit, "--description=WSPanel upgrade to "+p.Version,
		"--property=Type=oneshot", "--property=RemainAfterExit=yes", "--property=TimeoutStartSec=3600",
		"--no-block", "--", upgradeScript, p.Version); err != nil {
		return nil, err
	}
	return map[string]any{"started": true, "version": p.Version}, nil
}

var upgradeMarkRe = regexp.MustCompile(`WSPANEL-UPGRADE (start|done) (\S+)`)

// panelUpgradeStatus: running, done or failed, with the run's log.
func panelUpgradeStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	st := upgradeState(ctx, env)
	state := "none"
	switch {
	case st["ActiveState"] == "activating":
		state = "running"
	case st["ActiveState"] == "active":
		state = "done"
	case st["ActiveState"] == "failed" || st["Result"] != "" && st["Result"] != "success":
		state = "failed"
	}
	out := map[string]any{"state": state, "started_at": st["ExecMainStartTimestamp"], "finished_at": st["ExecMainExitTimestamp"], "log": []string{}}
	if id := st["InvocationID"]; regexp.MustCompile(`^[0-9a-f]{32}$`).MatchString(id) && state != "none" {
		if res, err := env.Exec.Run(ctx, "journalctl", "_SYSTEMD_INVOCATION_ID="+id, "-o", "cat", "-n", "400", "--no-pager"); err == nil {
			lines := strings.Split(strings.TrimRight(res.Stdout, "\n"), "\n")
			for _, l := range lines {
				if m := upgradeMarkRe.FindStringSubmatch(l); m != nil {
					out["version"] = m[2]
				}
			}
			if len(lines) > 60 {
				lines = lines[len(lines)-60:]
			}
			out["log"] = lines
		}
	}
	return out, nil
}
