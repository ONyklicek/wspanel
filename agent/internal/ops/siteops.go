package ops

import (
	"context"
	"encoding/json"
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

// Switching a site off and on, restarting it, and what runs for it.

type domainParams struct {
	Domain string `json:"domain"`
}

// siteUnits are the long-running units of a site: the app process (worker
// and node mode) and its workers.
func siteUnits(env *Env, l siteLayout) []string {
	var units []string
	if fsx.Exists(l.AppUnitFile) {
		units = append(units, l.AppUnit)
	}
	units = append(units, siteWorkerInstances(env, l)...)
	return units
}

func siteTimers(env *Env, l siteLayout) []string {
	var timers []string
	for _, id := range siteCronIDs(env.Cfg, l.User) {
		timers = append(timers, cronUnitBase(l.User, id)+".timer")
	}
	return timers
}

// unitEntry is the file a unit needs in the current release to start.
func unitEntry(env *Env, l siteLayout, unit string) string {
	if unit == l.AppUnit {
		_, entry, _ := appUnitData(env, l)
		return entry
	}
	for typ, spec := range workerSpecs[l.Framework] {
		if unit == currentWorkerUnit(env, l, typ) {
			return spec.Entry
		}
	}
	return ""
}

func siteDisable(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return setSiteEnabled(ctx, env, raw, false)
}

func siteEnable(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return setSiteEnabled(ctx, env, raw, true)
}

// setSiteEnabled: off = the edge answers 503 and the app, workers and
// scheduled tasks are stopped and disabled (they stay off across reboots).
// On = the reverse; units start only when the release has their entry file.
// Classic sites keep their FPM pool: it is on-demand and gets no traffic.
func setSiteEnabled(ctx context.Context, env *Env, raw json.RawMessage, enable bool) (_ any, err error) {
	var p domainParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.Disabled == !enable {
		return map[string]any{"domain": p.Domain, "enabled": enable, "changed": false}, nil
	}
	prev := st
	st.Disabled = !enable
	l, err := layout(env.Cfg, st)
	if err != nil {
		return nil, err
	}
	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()
	if err = writeEdge(ctx, env, l, &rollback); err != nil {
		return nil, err
	}
	if err = saveSiteState(env, st); err != nil {
		return nil, err
	}
	rollback.add(func() { _ = saveSiteState(env, prev) })

	units := append(siteUnits(env, l), siteTimers(env, l)...)
	started := []string{}
	if !enable {
		// Also an old worker instance still finishing its job.
		for _, typ := range siteWorkerTypes(env, l.User) {
			for _, u := range loadedInstances(ctx, env, l.User, typ) {
				if !slices.Contains(units, u) {
					units = append(units, u)
				}
			}
		}
	}
	for _, u := range units {
		if !enable {
			if _, err = env.Exec.Run(ctx, "systemctl", "disable", "--now", u); err != nil {
				return nil, err
			}
			continue
		}
		if _, err = env.Exec.Run(ctx, "systemctl", "enable", u); err != nil {
			return nil, err
		}
		if strings.HasSuffix(u, ".service") && !fsx.Exists(filepath.Join(l.Home, "current", unitEntry(env, l, u))) {
			continue // not deployed yet
		}
		if _, err = env.Exec.Run(ctx, "systemctl", "start", u); err != nil {
			return nil, err
		}
		started = append(started, u)
	}
	return map[string]any{"domain": p.Domain, "enabled": enable, "changed": true, "units": units, "started": started}, nil
}

type siteRestartParams struct {
	Domain  string `json:"domain"`
	Workers bool   `json:"workers,omitempty"` // also restart the site's workers
}

// siteRestart restarts what serves the site: a graceful reload of the PHP-FPM
// version (classic; the pool's workers finish their requests), or a restart
// of the app process (worker / node mode). Optionally the workers too.
func siteRestart(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p siteRestartParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.Disabled {
		return nil, protocol.Conflict(p.Domain + " is disabled; enable it instead")
	}
	done := []string{}
	if l.Mode == "classic" {
		if err := validateFPM(ctx, env, l.PHPVersion); err != nil {
			return nil, err
		}
		unit := config.ForVersion(env.Cfg.FPMUnit, l.PHPVersion)
		if _, err := env.Exec.Run(ctx, "systemctl", "reload", unit); err != nil {
			return nil, err
		}
		done = append(done, unit+" (reload)")
	}
	for _, u := range siteUnits(env, l) {
		if u != l.AppUnit && !p.Workers {
			continue
		}
		if !fsx.Exists(filepath.Join(l.Home, "current", unitEntry(env, l, u))) {
			continue
		}
		_, _ = env.Exec.Run(ctx, "systemctl", "reset-failed", u)
		if _, err := env.Exec.Run(ctx, "systemctl", "restart", u); err != nil {
			return nil, err
		}
		done = append(done, u)
	}
	return map[string]any{"domain": p.Domain, "restarted": done}, nil
}

// Process is a unit of a site with what an operator wants to see.
type Process struct {
	Unit     string `json:"unit"`
	Kind     string `json:"kind"`             // app | worker | task
	Name     string `json:"name"`             // worker type / task id / "app"
	Active   string `json:"active"`           // active, inactive, failed, activating…
	Sub      string `json:"sub"`              // running, dead, exited, auto-restart…
	Enabled  string `json:"enabled"`          // enabled, disabled
	Since    string `json:"since,omitempty"`  // when it entered the current state
	PID      int    `json:"pid,omitempty"`    // main process
	Memory   int64  `json:"memory,omitempty"` // bytes
	Restarts int    `json:"restarts"`         // automatic restarts since the unit was started
	Result   string `json:"result,omitempty"` // success, exit-code, signal…
}

// siteProcesses lists the app process, workers and scheduled tasks of a site.
func siteProcesses(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p domainParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	units := append(siteUnits(env, l), siteTimers(env, l)...)
	valkey := valkeyPaths(env, l)
	if fsx.Exists(valkey.UnitFile) {
		units = append(units, valkey.Unit)
	}
	procs, err := processStates(ctx, env, units)
	if err != nil {
		return nil, err
	}
	for i := range procs {
		u := procs[i].Unit
		switch {
		case u == l.AppUnit:
			procs[i].Kind, procs[i].Name = "app", "app"
		case u == valkey.Unit:
			procs[i].Kind, procs[i].Name = "valkey", "valkey"
		case strings.HasSuffix(u, ".timer"):
			procs[i].Kind = "task"
			procs[i].Name = strings.TrimSuffix(strings.TrimPrefix(u, "wspanel-"+l.User+"-cron-"), ".timer")
		default:
			procs[i].Kind = "worker"
			name := strings.TrimSuffix(strings.TrimPrefix(u, "wspanel-"+l.User+"-"), ".service")
			name, _, _ = strings.Cut(name, "@") // the instance is the release it runs from
			procs[i].Name = name
		}
	}
	return map[string]any{"domain": p.Domain, "mode": l.Mode, "enabled": !st.Disabled, "processes": procs}, nil
}

func processStates(ctx context.Context, env *Env, units []string) ([]Process, error) {
	out := make([]Process, len(units))
	if len(units) == 0 {
		return out, nil
	}
	args := append([]string{"show", "--property=Id,ActiveState,SubState,UnitFileState,StateChangeTimestamp,MainPID,MemoryCurrent,NRestarts,Result", "--"}, units...)
	res, err := env.Exec.Run(ctx, "systemctl", args...)
	if err != nil {
		return nil, err
	}
	for i, u := range units {
		out[i] = Process{Unit: u, Active: "unknown"}
	}
	blocks := strings.Split(strings.TrimSpace(res.Stdout), "\n\n")
	for i, b := range blocks {
		if i >= len(out) {
			break
		}
		for _, line := range strings.Split(b, "\n") {
			k, v, ok := strings.Cut(line, "=")
			if !ok {
				continue
			}
			switch k {
			case "ActiveState":
				out[i].Active = v
			case "SubState":
				out[i].Sub = v
			case "UnitFileState":
				out[i].Enabled = v
			case "StateChangeTimestamp":
				out[i].Since = v
			case "MainPID":
				out[i].PID, _ = strconv.Atoi(v)
			case "MemoryCurrent":
				if n, err := strconv.ParseInt(v, 10, 64); err == nil {
					out[i].Memory = n
				}
			case "NRestarts":
				out[i].Restarts, _ = strconv.Atoi(v)
			case "Result":
				out[i].Result = v
			}
		}
	}
	return out, nil
}

// Log sources of a site. Files inside the site home are read as the site
// user (the user controls that tree: a planted symlink must not make root
// read /etc/shadow for it).
var siteLogSources = []string{"app", "worker", "task", "php", "framework"}

type siteLogsParams struct {
	Domain string `json:"domain"`
	Source string `json:"source"`
	Name   string `json:"name,omitempty"` // worker type or task id
	Lines  int    `json:"lines,omitempty"`
}

func siteLogs(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p siteLogsParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("source", p.Source, siteLogSources); err != nil {
		return nil, err
	}
	if p.Lines <= 0 {
		p.Lines = 200
	}
	if p.Lines > 2000 {
		return nil, protocol.Invalid("lines: at most 2000")
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	n := strconv.Itoa(p.Lines)
	journal := func(unit string) (any, error) {
		if !fsx.Exists(filepath.Join(env.Cfg.SystemdDir, unit)) {
			return nil, protocol.NotFound(unit + " does not exist")
		}
		res, err := env.Exec.Run(ctx, "journalctl", "--unit", unit, "--lines", n, "--no-pager", "--output", "short-iso", "--quiet")
		if err != nil {
			return nil, err
		}
		return map[string]any{"source": p.Source, "unit": unit, "text": res.Stdout}, nil
	}
	switch p.Source {
	case "app":
		return journal(l.AppUnit)
	case "worker":
		if err := validate.OneOf("name", p.Name, WorkerTypes); err != nil {
			return nil, err
		}
		// Every instance: an old one finishing its job writes there too.
		return journal("wspanel-" + l.User + "-" + p.Name + "@*.service")
	case "task":
		id, err := strconv.Atoi(p.Name)
		if err != nil || id <= 0 || id > 999999 {
			return nil, protocol.Invalid("name: task id")
		}
		return journal(cronUnitBase(l.User, id) + ".service")
	}
	// Files in the site home.
	var file string
	switch {
	case p.Source == "php":
		file = filepath.Join(l.Home, "logs", "php-error.log")
	case l.Framework == "laravel":
		file = filepath.Join(l.Home, "shared", "storage", "logs", "laravel.log")
	case l.Framework == "symfony":
		file = filepath.Join(l.Home, "shared", "var", "log", "prod.log")
	default:
		return nil, protocol.Invalid("source: framework log exists only for Laravel and Symfony")
	}
	out := map[string]any{"source": p.Source, "file": file, "text": ""}
	if _, err := os.Lstat(file); os.IsNotExist(err) {
		out["missing"] = true
		return out, nil
	}
	res, err := env.Exec.Run(ctx, "setpriv", "--reuid="+l.User, "--regid="+primaryGroup(l.User), "--init-groups", "--reset-env",
		"--", "/usr/bin/tail", "--lines", n, "--", file)
	if err != nil {
		return nil, err
	}
	out["text"] = res.Stdout
	return out, nil
}
