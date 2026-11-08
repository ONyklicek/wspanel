package ops

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
)

// Worker units are systemd templates, one instance per release:
//
//	wspanel-<user>-<type>@.service           the template (the worker is configured)
//	wspanel-<user>-<type>@<release>.service  runs from releases/<release>, never via current
//
// After a deploy the new release's instance starts at once and the old one
// is told to stop: a queue worker or Horizon finishes the job it holds with
// the code it started with and takes no new job, while the new instance
// already takes them with the new code. Nothing waits for a long job, and no
// job runs half on old and half on new code. The scheduler and Reverb must
// not run twice, so they are swapped: old stops, then new starts.

// Workers whose old instance may finish its work beside the new one.
var drainingWorkers = []string{"queue", "horizon", "messenger", "pulsework"}

// workerDrainTimeout is how long an old consumer may finish its job.
const workerDrainTimeout = 3600

func workerTemplate(user, typ string) string { return "wspanel-" + user + "-" + typ + "@.service" }

func workerInstance(user, typ, rel string) string {
	return "wspanel-" + user + "-" + typ + "@" + rel + ".service"
}

// legacyWorkerUnit is the single unit of a worker from before releases had
// their own instances; converted on the next deploy or worker change.
func legacyWorkerUnit(user, typ string) string { return "wspanel-" + user + "-" + typ + ".service" }

// hasWorker: the site has this worker configured (either form).
func hasWorker(env *Env, user, typ string) bool {
	return fileIn(env.Cfg.SystemdDir, workerTemplate(user, typ)) || fileIn(env.Cfg.SystemdDir, legacyWorkerUnit(user, typ))
}

func fileIn(dir, name string) bool {
	_, err := os.Lstat(filepath.Join(dir, name))
	return err == nil
}

// siteWorkerTypes lists the worker types a site has.
func siteWorkerTypes(env *Env, user string) []string {
	var out []string
	for _, typ := range WorkerTypes {
		if hasWorker(env, user, typ) {
			out = append(out, typ)
		}
	}
	return out
}

// currentWorkerUnit is the unit that is the worker now: the instance of the
// current release, or a legacy unit.
func currentWorkerUnit(env *Env, l siteLayout, typ string) string {
	if fileIn(env.Cfg.SystemdDir, legacyWorkerUnit(l.User, typ)) {
		return legacyWorkerUnit(l.User, typ)
	}
	rel, err := currentRelease(l)
	if err != nil {
		rel = "initial"
	}
	return workerInstance(l.User, typ, rel)
}

// siteWorkerInstances: the current unit of every worker of the site.
func siteWorkerInstances(env *Env, l siteLayout) []string {
	var out []string
	for _, typ := range siteWorkerTypes(env, l.User) {
		out = append(out, currentWorkerUnit(env, l, typ))
	}
	return out
}

// loadedInstances lists the worker's instances systemd knows (running,
// stopping or failed), e.g. an old one still finishing its job.
func loadedInstances(ctx context.Context, env *Env, user, typ string) []string {
	res, err := env.Exec.Run(ctx, "systemctl", "list-units", "--all", "--plain", "--no-legend", "--no-pager", "wspanel-"+user+"-"+typ+"@*.service")
	if err != nil {
		return nil
	}
	var out []string
	for _, line := range strings.Split(res.Stdout, "\n") {
		f := strings.Fields(line)
		if len(f) >= 4 && strings.HasPrefix(f[0], "wspanel-"+user+"-"+typ+"@") && f[3] != "dead" && f[2] != "inactive" {
			out = append(out, f[0])
		}
	}
	return out
}

func workerUnitDataFor(env *Env, l siteLayout, typ string, spec workerSpec) unitData {
	d := workerUnitData(env, l, typ, spec)
	d.WorkDir = filepath.Join(l.Home, "releases", "%i")
	if slices.Contains(drainingWorkers, typ) {
		d.StopTimeout = workerDrainTimeout
	}
	return d
}

// writeWorkerTemplate writes (or rewrites) the worker's template, checked
// before systemd reads it. A legacy unit of the worker is removed first.
func writeWorkerTemplate(ctx context.Context, env *Env, l siteLayout, typ string, spec workerSpec, rb *undo) error {
	if fileIn(env.Cfg.SystemdDir, legacyWorkerUnit(l.User, typ)) {
		if err := removeUnit(ctx, env, filepath.Join(env.Cfg.SystemdDir, legacyWorkerUnit(l.User, typ))); err != nil {
			return err
		}
	}
	unit, err := render("unit.service.tmpl", workerUnitDataFor(env, l, typ, spec))
	if err != nil {
		return err
	}
	path := filepath.Join(env.Cfg.SystemdDir, workerTemplate(l.User, typ))
	old, readErr := os.ReadFile(path)
	if err := env.FS.WriteFile(path, unit, 0o644, fsx.Root); err != nil {
		return err
	}
	rb.add(func() {
		if readErr == nil {
			_ = env.FS.WriteFile(path, old, 0o644, fsx.Root)
		} else {
			_ = os.Remove(path)
		}
		_, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "daemon-reload")
	})
	if _, err := env.Exec.Run(ctx, "systemd-analyze", "verify", path); err != nil {
		return fmt.Errorf("unit %s invalid, not installing: %w", filepath.Base(path), err)
	}
	_, err = env.Exec.Run(ctx, "systemctl", "daemon-reload")
	return err
}

// startWorkerRelease rotates one worker to the current release (see the top
// of this file). Not started while the release lacks the worker's entry.
func startWorkerRelease(ctx context.Context, env *Env, l siteLayout, typ string, spec workerSpec, logf func(string, ...any)) (bool, error) {
	rel, err := currentRelease(l)
	if err != nil {
		return false, err
	}
	newUnit := workerInstance(l.User, typ, rel)
	var olds []string
	for _, u := range loadedInstances(ctx, env, l.User, typ) {
		if u != newUnit {
			olds = append(olds, u)
		}
	}
	stopOld := func(block bool) {
		for _, u := range olds {
			_, _ = env.Exec.Run(ctx, "systemctl", "disable", u)
			args := []string{"stop", u}
			if !block {
				args = []string{"stop", "--no-block", u}
			}
			if _, err := env.Exec.Run(ctx, "systemctl", args...); err != nil {
				logf("stop %s: %v\n", u, err)
			} else if block {
				logf("stopped %s\n", u)
			} else {
				logf("%s finishes its current job with the old code, takes no new one\n", u)
			}
		}
	}
	if l.Disabled || !fileIn(filepath.Join(l.Home, "releases", rel), spec.Entry) {
		stopNow(ctx, env, olds)
		return false, nil
	}
	draining := slices.Contains(drainingWorkers, typ)
	if !draining {
		stopOld(true) // never two schedulers / two Reverbs
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "reset-failed", newUnit)
	if _, err := env.Exec.Run(ctx, "systemctl", "enable", "--now", newUnit); err != nil {
		return false, err
	}
	logf("started %s\n", newUnit)
	if draining {
		stopOld(false)
	}
	return true, nil
}

// rotateWorkers brings every worker of the site to the current release.
func rotateWorkers(ctx context.Context, env *Env, l siteLayout, logf func(string, ...any)) {
	for _, typ := range siteWorkerTypes(env, l.User) {
		spec, ok := workerSpecs[l.Framework][typ]
		if !ok {
			continue
		}
		if fileIn(env.Cfg.SystemdDir, legacyWorkerUnit(l.User, typ)) {
			var rb undo
			if err := writeWorkerTemplate(ctx, env, l, typ, spec, &rb); err != nil {
				rb.run()
				logf("worker %s: %v\n", typ, err)
				continue
			}
		}
		if _, err := startWorkerRelease(ctx, env, l, typ, spec, logf); err != nil {
			logf("worker %s: %v\n", typ, err)
		}
	}
}

// removeWorker stops every instance of the worker and removes it.
func removeWorker(ctx context.Context, env *Env, l siteLayout, typ string) error {
	if fileIn(env.Cfg.SystemdDir, legacyWorkerUnit(l.User, typ)) {
		if err := removeUnit(ctx, env, filepath.Join(env.Cfg.SystemdDir, legacyWorkerUnit(l.User, typ))); err != nil {
			return err
		}
	}
	instances := loadedInstances(ctx, env, l.User, typ)
	stopNow(ctx, env, instances)
	for _, u := range instances {
		_, _ = env.Exec.Run(ctx, "systemctl", "reset-failed", u)
	}
	path := filepath.Join(env.Cfg.SystemdDir, workerTemplate(l.User, typ))
	if !fileIn(env.Cfg.SystemdDir, workerTemplate(l.User, typ)) {
		return nil
	}
	// Instances enabled for boot point at the template: their links go too.
	links, _ := filepath.Glob(filepath.Join(env.Cfg.SystemdDir, "*.wants", "wspanel-"+l.User+"-"+typ+"@*.service"))
	for _, link := range links {
		_ = os.Remove(link)
	}
	if err := os.Remove(path); err != nil {
		return err
	}
	_, err := env.Exec.Run(ctx, "systemctl", "daemon-reload")
	return err
}

// releaseInUse: an instance of some worker still runs from the release (an
// old consumer finishing its job); pruning must keep it.
func releaseInUse(ctx context.Context, env *Env, l siteLayout, rel string) bool {
	for _, typ := range siteWorkerTypes(env, l.User) {
		for _, u := range loadedInstances(ctx, env, l.User, typ) {
			if u == workerInstance(l.User, typ, rel) {
				return true
			}
		}
	}
	return false
}

// MigrateLegacyWorkers converts workers from before per-release instances
// (one unit per worker) when the agent starts: each becomes a template and
// the current release's instance takes over. Under the agent's lock, like an op.
func MigrateLegacyWorkers(ctx context.Context, env *Env, logf func(string, ...any)) {
	if env.Cfg.DryRun {
		return
	}
	for _, st := range allSiteStates(env.Cfg) {
		_, l, err := loadSite(env, st.Domain)
		if err != nil {
			continue
		}
		for _, typ := range WorkerTypes {
			if !fileIn(env.Cfg.SystemdDir, legacyWorkerUnit(l.User, typ)) {
				continue
			}
			spec, ok := workerSpecs[l.Framework][typ]
			if !ok {
				continue
			}
			_ = withMutate(env, func() error {
				var rb undo
				if err := writeWorkerTemplate(ctx, env, l, typ, spec, &rb); err != nil {
					rb.run()
					logf("migrating worker %s of %s: %v", typ, l.Domain, err)
					return err
				}
				if _, err := startWorkerRelease(ctx, env, l, typ, spec, func(string, ...any) {}); err != nil {
					logf("starting worker %s of %s: %v", typ, l.Domain, err)
				}
				logf("worker %s of %s now runs per release", typ, l.Domain)
				return nil
			})
		}
	}
}

// stopNow stops units at once (site switched off, suspended or deleted, a
// worker removed): SIGTERM and at most hardStopGrace to finish, then SIGKILL.
// The long drain timeout is for the old worker beside the new one after a
// deploy only; here nothing may wait an hour, least of all under the lock.
func stopNow(ctx context.Context, env *Env, units []string) {
	if len(units) == 0 {
		return
	}
	for _, u := range units {
		_, _ = env.Exec.Run(ctx, "systemctl", "disable", u)
		_, _ = env.Exec.Run(ctx, "systemctl", "stop", "--no-block", u)
	}
	if env.Cfg.DryRun {
		return
	}
	deadline := time.Now().Add(hardStopGrace)
	for time.Now().Before(deadline) {
		res, _ := env.Exec.Run(ctx, "systemctl", append([]string{"is-active"}, units...)...)
		running := false
		for _, state := range strings.Fields(res.Stdout) {
			if state == "active" || state == "activating" || state == "deactivating" || state == "reloading" {
				running = true
			}
		}
		if !running {
			return
		}
		time.Sleep(time.Second)
	}
	_, _ = env.Exec.Run(ctx, "systemctl", append([]string{"kill", "--signal=SIGKILL"}, units...)...)
}

const hardStopGrace = 30 * time.Second
