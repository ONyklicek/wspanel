package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// workerSpec is a whitelisted long-running command for a framework.
type workerSpec struct {
	Entry       string   // file relative to the release that must exist to start
	Args        []string // arguments after the PHP binary
	StopTimeout int      // seconds to finish the current job on stop
}

// workerSpecs: framework -> worker type -> command. Nothing else can run.
var workerSpecs = map[string]map[string]workerSpec{
	"laravel": {
		"horizon":   {"artisan", []string{"artisan", "horizon"}, 60},
		"queue":     {"artisan", []string{"artisan", "queue:work", "--sleep=3", "--tries=3", "--max-time=3600", "--memory=256"}, 60},
		"scheduler": {"artisan", []string{"artisan", "schedule:work"}, 30},
	},
	"symfony": {
		"messenger": {"bin/console", []string{"bin/console", "messenger:consume", "async", "--time-limit=3600", "--memory-limit=256M"}, 60},
		"scheduler": {"bin/console", []string{"bin/console", "messenger:consume", "scheduler_default", "--time-limit=3600"}, 30},
	},
}

// WorkerTypes lists all known worker types (for validation messages / UI).
var WorkerTypes = []string{"horizon", "queue", "scheduler", "messenger"}

func workerUnit(user, typ string) string {
	return "wspanel-" + user + "-" + typ + ".service"
}

type workerParams struct {
	Domain string `json:"domain"`
	Worker string `json:"worker"`
}

type workerTemplate struct {
	siteLayout
	Type        string
	Command     string
	StopTimeout int
}

func resolveWorker(env *Env, p workerParams) (siteState, siteLayout, workerSpec, string, error) {
	if err := validate.OneOf("worker", p.Worker, WorkerTypes); err != nil {
		return siteState{}, siteLayout{}, workerSpec{}, "", err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return st, l, workerSpec{}, "", err
	}
	spec, ok := workerSpecs[st.Framework][p.Worker]
	if !ok {
		allowed := make([]string, 0)
		for t := range workerSpecs[st.Framework] {
			allowed = append(allowed, t)
		}
		slices.Sort(allowed)
		return st, l, spec, "", protocol.Invalid(fmt.Sprintf("worker: %q is not available for framework %s (allowed: %v)", p.Worker, st.Framework, allowed))
	}
	return st, l, spec, filepath.Join(env.Cfg.SystemdDir, workerUnit(l.User, p.Worker)), nil
}

func workerCreate(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p workerParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, spec, unitPath, err := resolveWorker(env, p)
	if err != nil {
		return nil, err
	}
	if fsx.Exists(unitPath) {
		return nil, protocol.Conflict("worker " + p.Worker + " already exists for " + p.Domain)
	}
	php := config.ForVersion(env.Cfg.PHPCLIBinary, st.PHPVersion)
	unit, err := render("worker.service.tmpl", workerTemplate{
		siteLayout:  l,
		Type:        p.Worker,
		Command:     php + " " + strings.Join(spec.Args, " "),
		StopTimeout: spec.StopTimeout,
	})
	if err != nil {
		return nil, err
	}
	name := filepath.Base(unitPath)

	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()
	if err = env.FS.WriteFile(unitPath, unit, 0o644, fsx.Root); err != nil {
		return nil, err
	}
	rollback.add(func() {
		_ = os.Remove(unitPath)
		_, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "daemon-reload")
	})
	if _, err = env.Exec.Run(ctx, "systemd-analyze", "verify", unitPath); err != nil {
		return nil, fmt.Errorf("worker unit invalid, not installing: %w", err)
	}
	if _, err = env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return nil, err
	}
	if _, err = env.Exec.Run(ctx, "systemctl", "enable", name); err != nil {
		return nil, err
	}
	rollback.add(func() { _, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "disable", name) })

	// Before the first deploy there is no artisan / bin/console: enable the
	// unit but do not start it into a crash loop.
	started := false
	if fsx.Exists(filepath.Join(l.Home, "current", spec.Entry)) {
		if _, err = env.Exec.Run(ctx, "systemctl", "start", name); err != nil {
			return nil, err
		}
		started = true
	}
	return map[string]any{"unit": name, "worker": p.Worker, "domain": p.Domain, "started": started}, nil
}

func workerDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p workerParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if err := validate.OneOf("worker", p.Worker, WorkerTypes); err != nil {
		return nil, err
	}
	unitPath := filepath.Join(env.Cfg.SystemdDir, workerUnit(validate.SiteUser(p.Domain), p.Worker))
	if !fsx.Exists(unitPath) {
		return nil, protocol.NotFound("worker " + p.Worker + " does not exist for " + p.Domain)
	}
	if err := removeUnit(ctx, env, unitPath); err != nil {
		return nil, err
	}
	return map[string]string{"unit": filepath.Base(unitPath), "removed": "true"}, nil
}

func removeUnit(ctx context.Context, env *Env, unitPath string) error {
	name := filepath.Base(unitPath)
	if _, err := env.Exec.Run(ctx, "systemctl", "disable", "--now", name); err != nil {
		return err
	}
	if err := os.Remove(unitPath); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return err
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "reset-failed", name)
	return nil
}

// siteWorkerUnits lists installed worker unit files of a site.
func siteWorkerUnits(cfg config.Config, user string) []string {
	matches, _ := filepath.Glob(filepath.Join(cfg.SystemdDir, "wspanel-"+user+"-*.service"))
	return matches
}

// allWorkerUnits lists every worker unit name managed by WSPanel.
func allWorkerUnits(cfg config.Config) []string {
	matches, _ := filepath.Glob(filepath.Join(cfg.SystemdDir, "wspanel-*.service"))
	out := make([]string, 0, len(matches))
	for _, m := range matches {
		out = append(out, filepath.Base(m))
	}
	slices.Sort(out)
	return out
}
