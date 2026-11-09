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

type workerParams struct {
	Domain string `json:"domain"`
	Worker string `json:"worker"`
	// HTTPS port browsers use for the site (Reverb's client settings); 0 = 443.
	PublicPort int `json:"public_port,omitempty"`
}

// workerUnitData builds the unit for a worker, run by workerPHP.
func workerUnitData(env *Env, l siteLayout, typ string, spec workerSpec) unitData {
	php := workerPHP(env, l)
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
	return l, spec, filepath.Join(env.Cfg.SystemdDir, workerTemplate(l.User, p.Worker)), nil
}

func workerCreate(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p workerParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	l, spec, _, err := resolveWorker(env, p)
	if err != nil {
		return nil, err
	}
	if hasWorker(env, l.User, p.Worker) {
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
	if err = writeWorkerTemplate(ctx, env, l, p.Worker, spec, &rollback); err != nil {
		return nil, err
	}
	rollback.add(func() { _ = removeWorker(context.WithoutCancel(ctx), env, l, p.Worker) })
	started, err := startWorkerRelease(ctx, env, l, p.Worker, spec, func(string, ...any) {})
	if err != nil {
		return nil, err
	}
	out["unit"], out["started"] = currentWorkerUnit(env, l, p.Worker), started
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
	_, wl, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if !hasWorker(env, wl.User, p.Worker) {
		return nil, protocol.NotFound("worker " + p.Worker + " does not exist for " + p.Domain)
	}
	unitName := currentWorkerUnit(env, wl, p.Worker)
	if err := removeWorker(ctx, env, wl, p.Worker); err != nil {
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
	return map[string]string{"unit": unitName, "removed": "true"}, nil
}

// refreshWorkers re-renders a site's worker units after a mode or version
// change (they embed the PHP binary). Workers the new framework does not
// support are removed.
func refreshWorkers(ctx context.Context, env *Env, l siteLayout) error {
	for _, typ := range WorkerTypes {
		if !hasWorker(env, l.User, typ) {
			continue
		}
		spec, ok := workerSpecs[l.Framework][typ]
		if !ok {
			if err := removeWorker(ctx, env, l, typ); err != nil {
				return err
			}
			continue
		}
		var rb undo
		if err := writeWorkerTemplate(ctx, env, l, typ, spec, &rb); err != nil {
			rb.run()
			return err
		}
		unit := currentWorkerUnit(env, l, typ)
		if _, err := env.Exec.Run(ctx, "systemctl", "try-restart", unit); err != nil {
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
		for _, name := range []string{workerTemplate(user, typ), legacyWorkerUnit(user, typ)} {
			if path := filepath.Join(cfg.SystemdDir, name); fsx.Exists(path) {
				out = append(out, path)
			}
		}
	}
	return out
}

// allWorkerUnits lists every unit managed by WSPanel (workers and app units).
func allWorkerUnits(cfg config.Config) []string {
	matches, _ := filepath.Glob(filepath.Join(cfg.SystemdDir, "wspanel-*.service"))
	homes := map[string]string{}
	for _, st := range allSiteStates(cfg) {
		homes[validate.SiteUser(st.Domain)] = filepath.Join(cfg.SitesRoot, st.Domain)
	}
	out := make([]string, 0, len(matches))
	for _, m := range matches {
		name := filepath.Base(m)
		base, ok := strings.CutSuffix(name, "@.service")
		if !ok {
			out = append(out, name)
			continue
		}
		// A worker template: the instance of the site's current release.
		rest := strings.TrimPrefix(base, "wspanel-")
		i := strings.LastIndex(rest, "-")
		if i <= 0 || homes[rest[:i]] == "" {
			continue
		}
		rel, err := currentRelease(siteLayout{Home: homes[rest[:i]]})
		if err != nil {
			rel = "initial"
		}
		out = append(out, workerInstance(rest[:i], rest[i+1:], rel))
	}
	slices.Sort(out)
	return out
}

// frankenCLI is the PHP CLI of a FrankenPHP (worker-mode) site: FrankenPHP's
// own PHP from its packages (php-zts: the same version, extensions and
// php.ini as the site), or "" where there is none (a server from before
// FrankenPHP came from its packages).
func frankenCLI(env *Env) string {
	if env.Cfg.FrankenPHPCLI != "" && fsx.Exists(env.Cfg.FrankenPHPCLI) {
		return env.Cfg.FrankenPHPCLI
	}
	return ""
}

// workerPHP is the PHP that runs a site's long-running workers: the CLI of
// the site's version. In worker mode FrankenPHP's own CLI (php-zts). Never
// `frankenphp php-cli`, which crashes (SIGSEGV) as soon as a PHP signal
// handler runs: Horizon, queue:work, Reverb and the scheduler would never
// stop gracefully (a job cut off mid-way, the unit left failed after every
// deploy). Without php-zts: a system CLI, the site's version when
// installed, else the newest; php-cli only on a server with no PHP at all.
func workerPHP(env *Env, l siteLayout) string {
	if l.Mode != "worker" {
		return config.ForVersion(env.Cfg.PHPCLIBinary, l.PHPVersion)
	}
	if cli := frankenCLI(env); cli != "" {
		return cli
	}
	version := ""
	for _, v := range env.Cfg.PHPVersions {
		if v == l.PHPVersion {
			version = v
			break
		}
		if version == "" || newerVersion(v, version) {
			version = v
		}
	}
	if version == "" {
		return env.Cfg.FrankenPHPBin + " php-cli"
	}
	return config.ForVersion(env.Cfg.PHPCLIBinary, version)
}

// newerVersion compares "major.minor" versions numerically (8.10 > 8.9).
func newerVersion(a, b string) bool {
	pa, pb := strings.SplitN(a, ".", 2), strings.SplitN(b, ".", 2)
	for i := 0; i < 2; i++ {
		var x, y int
		if i < len(pa) {
			x, _ = strconv.Atoi(pa[i])
		}
		if i < len(pb) {
			y, _ = strconv.Atoi(pb[i])
		}
		if x != y {
			return x > y
		}
	}
	return false
}
