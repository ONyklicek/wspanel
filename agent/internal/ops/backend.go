package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// setupBackend creates what serves the site in its mode. Every step registers
// its compensation in rb. Returns whether an app process was started.
func setupBackend(ctx context.Context, env *Env, l siteLayout, rb *undo) (bool, error) {
	switch l.Mode {
	case "classic":
		release, err := currentRelease(l)
		if err != nil {
			return false, err
		}
		if err := grantWebAccess(ctx, env, l, release); err != nil {
			return false, err
		}
		if err := writePool(ctx, env, l, rb); err != nil {
			return false, err
		}
		return true, writeCaddyBlock(ctx, env, "web-site.caddy.tmpl", l, l.WebFile, env.Cfg.WebUnit, validateWeb, rb)

	case "worker":
		// Socket dir: owned by the site user, group = edge proxy, setgid so the
		// socket inherits the group. Recreated on boot via tmpfiles.d.
		conf := fmt.Sprintf("d %s 2750 %s %s -\n", l.RunDir, l.User, env.Cfg.ProxyGroup)
		if err := env.FS.WriteFile(l.TmpfilesFile, []byte(conf), 0o644, fsx.Root); err != nil {
			return false, err
		}
		rb.add(func() { _ = os.Remove(l.TmpfilesFile); _ = os.RemoveAll(l.RunDir) })
		if _, err := env.Exec.Run(ctx, "systemd-tmpfiles", "--create", l.TmpfilesFile); err != nil {
			return false, err
		}
		cfg, err := render("instance.caddy.tmpl", l)
		if err != nil {
			return false, err
		}
		group, err := lookupGroupOwner(env, l.User)
		if err != nil {
			return false, err
		}
		if err := env.FS.MkdirAll(env.Cfg.SiteConfigDir, 0o755, fsx.Root); err != nil {
			return false, err
		}
		// Readable by the site's group (the instance runs as the site user), not writable.
		if err := env.FS.WriteFile(l.InstanceConfig, cfg, 0o640, group); err != nil {
			return false, err
		}
		rb.add(func() { _ = os.Remove(l.InstanceConfig) })
		if err := validateCaddy(ctx, env, "worker instance", l.InstanceConfig); err != nil {
			return false, err
		}
		fallthrough

	case "node", "python":
		if l.Mode == "python" {
			if err := ensurePythonVenv(ctx, env, l); err != nil {
				return false, err
			}
		}
		data, entry, err := appUnitData(env, l)
		if err != nil {
			return false, err
		}
		return installUnit(ctx, env, l.AppUnitFile, data, entry, rb)
	}
	return false, protocol.Invalid("mode " + l.Mode)
}

// teardownBackend removes what `mode` left behind for the site, keeping what
// `next` (the mode the site moves to, "" when deleting) still uses: worker
// and node share the app unit. Safe to call for a mode the site is not in.
func teardownBackend(ctx context.Context, env *Env, l siteLayout, mode, next string) error {
	switch mode {
	case "classic":
		if err := removeCaddyBlock(ctx, env, l.WebFile, env.Cfg.WebUnit, validateWeb); err != nil {
			return err
		}
		for _, v := range env.Cfg.PHPVersions { // pools of any version
			if err := removePool(ctx, env, filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, v), l.User+".conf"), v); err != nil {
				return err
			}
		}
		if _, err := lookupOwner(env, l.User); err == nil && fsx.Exists(l.Home) {
			// As the site user, like the grant (see grantWebAccess).
			if err := asUser(ctx, env, l.User, "/usr/bin/setfacl", "-R", "-P", "-x", "u:"+l.WebUser, l.Home); err != nil {
				return err
			}
			if err := asUser(ctx, env, l.User, "/usr/bin/setfacl", "-R", "-P", "-k", l.Home); err != nil {
				return err
			}
		}
	case "worker", "node", "python":
		if next != "worker" && !validate.AppMode(next) && fsx.Exists(l.AppUnitFile) {
			if err := removeUnit(ctx, env, l.AppUnitFile); err != nil {
				return err
			}
		}
		if !validate.AppMode(next) {
			removeNodeSlots(ctx, env, l)
		}
		if mode == "worker" {
			_ = os.Remove(l.InstanceConfig)
			_ = os.Remove(l.TmpfilesFile)
			_ = os.RemoveAll(l.RunDir)
		}
	}
	return nil
}

func writePool(ctx context.Context, env *Env, l siteLayout, rb *undo) error {
	pool, err := render("fpm-pool.conf.tmpl", l)
	if err != nil {
		return err
	}
	if err := env.FS.WriteFile(l.PoolFile, pool, 0o644, fsx.Root); err != nil {
		return err
	}
	unit := config.ForVersion(env.Cfg.FPMUnit, l.PHPVersion)
	rb.add(func() {
		_ = os.Remove(l.PoolFile)
		_, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "reload", unit)
	})
	if err := validateFPM(ctx, env, l.PHPVersion); err != nil {
		return err
	}
	_, err = env.Exec.Run(ctx, "systemctl", "reload", unit)
	return err
}

func removePool(ctx context.Context, env *Env, pool, version string) error {
	if !fsx.Exists(pool) {
		return nil
	}
	if err := os.Remove(pool); err != nil {
		return err
	}
	if err := validateFPM(ctx, env, version); err != nil {
		return err
	}
	_, err := env.Exec.Run(ctx, "systemctl", "reload", config.ForVersion(env.Cfg.FPMUnit, version))
	return err
}

// writeCaddyBlock writes (or replaces) a site block, validates, reloads. On
// rollback the previous content (or absence) is restored.
func writeCaddyBlock(ctx context.Context, env *Env, tmpl string, l siteLayout, file, unit string,
	validateFn func(context.Context, *Env) error, rb *undo) error {
	block, err := render(tmpl, l)
	if err != nil {
		return err
	}
	old, readErr := os.ReadFile(file)
	if err := env.FS.WriteFile(file, block, 0o644, fsx.Root); err != nil {
		return err
	}
	rb.add(func() {
		if readErr == nil {
			_ = env.FS.WriteFile(file, old, 0o644, fsx.Root)
		} else {
			_ = os.Remove(file)
		}
		_, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "reload", unit)
	})
	if err := validateFn(ctx, env); err != nil {
		return err
	}
	_, err = env.Exec.Run(ctx, "systemctl", "reload", unit)
	return err
}

func removeCaddyBlock(ctx context.Context, env *Env, file, unit string, validateFn func(context.Context, *Env) error) error {
	if !fsx.Exists(file) {
		return nil
	}
	if err := os.Remove(file); err != nil {
		return err
	}
	if err := validateFn(ctx, env); err != nil {
		return err
	}
	_, err := env.Exec.Run(ctx, "systemctl", "reload", unit)
	return err
}

func writeEdge(ctx context.Context, env *Env, l siteLayout, rb *undo) error {
	return writeCaddyBlock(ctx, env, "edge-site.caddy.tmpl", l, l.EdgeFile, env.Cfg.ProxyUnit, validateEdge, rb)
}

// lookupGroupOwner: root as owner, the user's primary group as group.
func lookupGroupOwner(env *Env, user string) (fsx.Owner, error) {
	o, err := lookupOwner(env, user)
	if err != nil {
		return o, err
	}
	if o.GID < 0 {
		return fsx.Owner{UID: -1, GID: -1}, nil
	}
	return fsx.Owner{UID: 0, GID: o.GID}, nil
}

// ---------------------------------------------------------------- switch mode

// siteSwitchMode moves a site between classic, worker and node. The new
// backend is built and the edge switched before the old backend is removed,
// so a failure leaves the site running as before.
func siteSwitchMode(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p siteParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Mode == "" {
		return nil, protocol.Invalid("mode: required")
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()
	old, oldL, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if p.Mode == old.Mode {
		return nil, protocol.Invalid("mode: site already runs in " + old.Mode + " mode (use site.set_version to change the version)")
	}
	st, err := p.resolve(ctx, env)
	if err != nil {
		return nil, err
	}
	st.SSHAccess, st.SSHPassword, st.Deploy, st.OpcacheProduction = old.SSHAccess, old.SSHPassword, old.Deploy, old.OpcacheProduction
	st.MailMode, st.TLS, st.Disabled = old.MailMode, old.TLS, old.Disabled
	st.PHPSettings = carryPHPSettings(old.PHPSettings, st.Mode)
	st.Edge = old.Edge
	if _, ok := workerSpecs[st.Framework]["reverb"]; ok {
		st.ReverbPort = old.ReverbPort // the Reverb route survives; otherwise refreshWorkers drops the worker
	}
	if _, ok := workerSpecs[st.Framework]["ssr"]; ok {
		st.SSRPort = old.SSRPort
	}
	if validate.AppMode(st.Mode) {
		if st.Port, err = allocatePort(env); err != nil {
			return nil, err
		}
	}
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
	if err = ensureShared(ctx, env, l); err != nil {
		return nil, err
	}
	started, err := setupBackend(ctx, env, l, &rollback)
	if err != nil {
		return nil, err
	}
	if err = writeEdge(ctx, env, l, &rollback); err != nil {
		return nil, err
	}
	if err = saveSiteState(env, st); err != nil {
		return nil, err
	}
	rollback = nil // the switch is committed; cleanup failures below are reported, not rolled back

	if err = teardownBackend(ctx, env, oldL, old.Mode, st.Mode); err != nil {
		return nil, fmt.Errorf("switched to %s, but cleaning up %s mode failed: %w", st.Mode, old.Mode, err)
	}
	if err = refreshWorkers(ctx, env, l); err != nil {
		return nil, fmt.Errorf("switched to %s, but updating workers failed: %w", st.Mode, err)
	}
	if err = refreshCrons(ctx, env, l); err != nil {
		return nil, fmt.Errorf("switched to %s, but updating tasks failed: %w", st.Mode, err)
	}
	if err = writeToolLinks(env, l); err != nil {
		return nil, err
	}
	return siteOutput(l, started), nil
}

// ---------------------------------------------------------------- set version

type setVersionParams struct {
	Domain  string `json:"domain"`
	Version string `json:"version"`
}

// siteSetVersion changes the PHP version (classic) or Node version (node).
// Worker mode has no choice: the version is the one built into FrankenPHP.
func siteSetVersion(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p setVersionParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()
	st, oldL, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()

	switch st.Mode {
	case "worker":
		return nil, protocol.Invalid("version: worker mode runs the PHP built into FrankenPHP; switch to classic mode to choose a version")

	case "classic":
		if err = validate.OneOf("version", p.Version, env.Cfg.PHPVersions); err != nil {
			return nil, err
		}
		if p.Version == st.PHPVersion {
			return nil, protocol.Invalid("version: site already runs PHP " + p.Version)
		}
		st.PHPVersion = p.Version
		l, lerr := layout(env.Cfg, st)
		if lerr != nil {
			return nil, lerr
		}
		// New pool next to the old one, then point the web tier at it, then drop the old pool.
		if err = writePool(ctx, env, l, &rollback); err != nil {
			return nil, err
		}
		if err = writeCaddyBlock(ctx, env, "web-site.caddy.tmpl", l, l.WebFile, env.Cfg.WebUnit, validateWeb, &rollback); err != nil {
			return nil, err
		}
		if err = saveSiteState(env, st); err != nil {
			return nil, err
		}
		rollback = nil
		if err = removePool(ctx, env, oldL.PoolFile, oldL.PHPVersion); err != nil {
			return nil, fmt.Errorf("switched to PHP %s, but removing the old pool failed: %w", p.Version, err)
		}
		if err = refreshWorkers(ctx, env, l); err != nil {
			return nil, fmt.Errorf("switched to PHP %s, but updating workers failed: %w", p.Version, err)
		}
		if err = refreshCrons(ctx, env, l); err != nil {
			return nil, fmt.Errorf("switched to PHP %s, but updating tasks failed: %w", p.Version, err)
		}
		if err = writeToolLinks(env, l); err != nil {
			return nil, err
		}
		return siteOutput(l, true), nil

	case "node":
		if err = validate.OneOf("version", p.Version, nodeMajors(env.Cfg)); err != nil {
			return nil, err
		}
		if p.Version == st.NodeVersion {
			return nil, protocol.Invalid("version: site already runs Node " + p.Version)
		}
		st.NodeVersion = p.Version
		fallthrough
	case "python":
		if st.Mode == "python" {
			if err = validate.OneOf("version", p.Version, pythonMinors(env.Cfg)); err != nil {
				return nil, err
			}
			if p.Version == st.PythonVersion {
				return nil, protocol.Invalid("version: site already runs Python " + p.Version)
			}
			st.PythonVersion = p.Version
		}
		l, lerr := layout(env.Cfg, st)
		if lerr != nil {
			return nil, lerr
		}
		data, entry, derr := appUnitData(env, l)
		if derr != nil {
			return nil, derr
		}
		started, ierr := installUnit(ctx, env, l.AppUnitFile, data, entry, &rollback)
		if ierr != nil {
			err = ierr
			return nil, err
		}
		if err = saveSiteState(env, st); err != nil {
			return nil, err
		}
		if err = writeToolLinks(env, l); err != nil {
			return nil, err
		}
		if st.Mode == "python" { // Celery's PATH names the interpreter
			if err = refreshWorkers(ctx, env, l); err != nil {
				return nil, err
			}
		}
		return siteOutput(l, started), nil
	}
	return nil, protocol.Invalid("mode " + st.Mode)
}
