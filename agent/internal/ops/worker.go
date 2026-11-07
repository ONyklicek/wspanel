package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strconv"
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
		// Reverb listens on an agent-allocated privileged port; the edge routes /app and /apps to it.
		"reverb":    {"artisan", []string{"artisan", "reverb:start", "--host=127.0.0.1", "--port={port}"}, 30},
		"pulse":     {"artisan", []string{"artisan", "pulse:check"}, 30},
		"pulsework": {"artisan", []string{"artisan", "pulse:work"}, 30}, // only with Redis ingest
	},
	"symfony": {
		"messenger": {"bin/console", []string{"bin/console", "messenger:consume", "async", "--time-limit=3600", "--memory-limit=256M"}, 60},
		"scheduler": {"bin/console", []string{"bin/console", "messenger:consume", "scheduler_default", "--time-limit=3600"}, 30},
	},
}

// WorkerTypes lists all known worker types (for validation messages / UI).
var WorkerTypes = []string{"horizon", "queue", "scheduler", "reverb", "pulse", "pulsework", "messenger"}

func workerUnit(user, typ string) string {
	return "wspanel-" + user + "-" + typ + ".service"
}

type workerParams struct {
	Domain string `json:"domain"`
	Worker string `json:"worker"`
	// HTTPS port browsers use for the site (Reverb's client settings); 0 = 443.
	PublicPort int `json:"public_port,omitempty"`
}

// workerUnitData builds the unit for a worker. The PHP binary follows the
// site's mode: FPM's CLI of the same version in classic mode, the PHP built
// into FrankenPHP in worker mode.
func workerUnitData(env *Env, l siteLayout, typ string, spec workerSpec) unitData {
	php := config.ForVersion(env.Cfg.PHPCLIBinary, l.PHPVersion)
	if l.Mode == "worker" {
		php = env.Cfg.FrankenPHPBin + " php-cli"
	}
	args := strings.Join(spec.Args, " ")
	extra := noBindExtra
	if typ == "reverb" {
		args = strings.ReplaceAll(args, "{port}", strconv.Itoa(l.ReverbPort))
		extra = portUnitExtra(l.ReverbPort)
	}
	return unitData{
		Description: fmt.Sprintf("WSPanel %s worker for %s", typ, l.Domain),
		User:        l.User,
		Home:        l.Home,
		ExecStart:   php + " " + args,
		Extra:       extra,
		StopTimeout: spec.StopTimeout,
	}
}

func resolveWorker(env *Env, p workerParams) (siteLayout, workerSpec, string, error) {
	if err := validate.OneOf("worker", p.Worker, WorkerTypes); err != nil {
		return siteLayout{}, workerSpec{}, "", err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return l, workerSpec{}, "", err
	}
	spec, ok := workerSpecs[st.Framework][p.Worker]
	if !ok {
		allowed := make([]string, 0)
		for t := range workerSpecs[st.Framework] {
			allowed = append(allowed, t)
		}
		slices.Sort(allowed)
		return l, spec, "", protocol.Invalid(fmt.Sprintf("worker: %q is not available for framework %s (allowed: %v)", p.Worker, st.Framework, allowed))
	}
	return l, spec, filepath.Join(env.Cfg.SystemdDir, workerUnit(l.User, p.Worker)), nil
}

func workerCreate(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p workerParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	l, spec, unitPath, err := resolveWorker(env, p)
	if err != nil {
		return nil, err
	}
	if fsx.Exists(unitPath) {
		return nil, protocol.Conflict("worker " + p.Worker + " already exists for " + p.Domain)
	}
	if p.PublicPort == 0 {
		p.PublicPort = 443
	}
	if p.PublicPort < 1 || p.PublicPort > 65535 {
		return nil, protocol.Invalid("public_port: 1-65535")
	}
	st0, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if err := checkValkeyWorker(st0, p.Worker); err != nil {
		return nil, err
	}
	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()
	out := map[string]any{"worker": p.Worker, "domain": p.Domain}
	if p.Worker == "reverb" {
		// Port + edge route first; the state change is rolled back with the edge.
		st, _, lerr := loadSite(env, p.Domain)
		if lerr != nil {
			return nil, lerr
		}
		if st.ReverbPort, err = allocatePort(env); err != nil {
			return nil, err
		}
		if l, err = layout(env.Cfg, st); err != nil {
			return nil, err
		}
		if err = writeEdge(ctx, env, l, &rollback); err != nil {
			return nil, err
		}
		prev := st
		prev.ReverbPort = 0
		if err = saveSiteState(env, st); err != nil {
			return nil, err
		}
		rollback.add(func() { _ = saveSiteState(env, prev) })
		out["port"] = st.ReverbPort
	}
	st1, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	// The app's settings before the process starts, so it starts configured.
	if err = workerAppEnv(ctx, env, l, st1, p.Worker, p.PublicPort); err != nil {
		return nil, err
	}
	started, err := installUnit(ctx, env, unitPath, workerUnitData(env, l, p.Worker, spec), spec.Entry, &rollback)
	if err != nil {
		return nil, err
	}
	out["unit"], out["started"] = filepath.Base(unitPath), started
	return out, nil
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
	if p.Worker == "reverb" { // drop the edge route and release the port
		st, _, err := loadSite(env, p.Domain)
		if err != nil {
			return nil, err
		}
		st.ReverbPort = 0
		l, err := layout(env.Cfg, st)
		if err != nil {
			return nil, err
		}
		var rb undo
		if err := writeEdge(ctx, env, l, &rb); err != nil {
			rb.run()
			return nil, err
		}
		if err := saveSiteState(env, st); err != nil {
			return nil, err
		}
	}
	return map[string]string{"unit": filepath.Base(unitPath), "removed": "true"}, nil
}

// refreshWorkers re-renders a site's worker units after a mode or version
// change (they embed the PHP binary). Workers the new framework does not
// support are removed.
func refreshWorkers(ctx context.Context, env *Env, l siteLayout) error {
	for _, typ := range WorkerTypes {
		path := filepath.Join(env.Cfg.SystemdDir, workerUnit(l.User, typ))
		if !fsx.Exists(path) {
			continue
		}
		spec, ok := workerSpecs[l.Framework][typ]
		if !ok {
			if err := removeUnit(ctx, env, path); err != nil {
				return err
			}
			continue
		}
		var rb undo
		if _, err := installUnit(ctx, env, path, workerUnitData(env, l, typ, spec), spec.Entry, &rb); err != nil {
			rb.run()
			return err
		}
	}
	return nil
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

// siteWorkerUnits lists installed worker unit files of a site. Exact names
// only: a prefix glob would also match another site whose user starts with
// this one (web_a_com vs web_a_com-x_com).
func siteWorkerUnits(cfg config.Config, user string) []string {
	var out []string
	for _, typ := range WorkerTypes {
		path := filepath.Join(cfg.SystemdDir, workerUnit(user, typ))
		if fsx.Exists(path) {
			out = append(out, path)
		}
	}
	return out
}

// allWorkerUnits lists every unit managed by WSPanel (workers and app units).
func allWorkerUnits(cfg config.Config) []string {
	matches, _ := filepath.Glob(filepath.Join(cfg.SystemdDir, "wspanel-*.service"))
	out := make([]string, 0, len(matches))
	for _, m := range matches {
		out = append(out, filepath.Base(m))
	}
	slices.Sort(out)
	return out
}
