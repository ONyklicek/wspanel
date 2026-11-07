package ops

import (
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

var backupNameRe = regexp.MustCompile(`^([a-z][a-z0-9_]{1,47})-(\d{8}T\d{6}Z)(-pre-restore)?(\.sql\.gz|\.pgdump)$`)

// backupDir is <BackupDir>/<engine>/<name>: root:<panel> 0750, files 0640, so
// the panel can offer downloads (it can ask for a dump anyway).
func backupDir(env *Env, engine, name string) (string, fsx.Owner, error) {
	o := fsx.Owner{UID: 0, GID: 0}
	if !env.Cfg.DryRun {
		g, err := user.LookupGroup(env.Cfg.BackupGroup)
		if err != nil {
			return "", o, err
		}
		o.GID, _ = strconv.Atoi(g.Gid)
	} else {
		o = fsx.Owner{UID: -1, GID: -1}
	}
	dir := filepath.Join(env.Cfg.BackupDir, engine, name)
	if err := env.FS.MkdirAll(dir, 0o750, o); err != nil {
		return "", o, err
	}
	return dir, o, nil
}

type backupInfo struct {
	File    string    `json:"file"`
	Size    int64     `json:"size"`
	Created time.Time `json:"created"`
	Path    string    `json:"path"`
	// Offsite: key of the copy in off-site storage; OffsiteError when the
	// copy failed (the backup itself is fine).
	Offsite      string `json:"offsite,omitempty"`
	OffsiteError string `json:"offsite_error,omitempty"`
}

func runBackup(ctx context.Context, env *Env, engine, name, suffix string) (backupInfo, error) {
	e, err := engineFor(env, engine)
	if err != nil {
		return backupInfo{}, err
	}
	dir, owner, err := backupDir(env, engine, name)
	if err != nil {
		return backupInfo{}, err
	}
	now := time.Now().UTC()
	file := name + "-" + now.Format("20060102T150405Z") + suffix + e.ext()
	path := filepath.Join(dir, file)
	tmp, err := os.CreateTemp(dir, ".dump-*")
	if err != nil {
		return backupInfo{}, err
	}
	defer os.Remove(tmp.Name())
	var w io.Writer = tmp
	var gz *gzip.Writer
	if strings.HasSuffix(e.ext(), ".gz") {
		gz = gzip.NewWriter(tmp)
		w = gz
	}
	if err := e.dump(ctx, env, name, w); err != nil {
		tmp.Close()
		return backupInfo{}, fmt.Errorf("dump %s failed: %w", name, err)
	}
	if gz != nil {
		if err := gz.Close(); err != nil {
			tmp.Close()
			return backupInfo{}, err
		}
	}
	if err := tmp.Close(); err != nil {
		return backupInfo{}, err
	}
	if err := os.Chmod(tmp.Name(), 0o640); err != nil {
		return backupInfo{}, err
	}
	if !env.Cfg.DryRun {
		if err := os.Chown(tmp.Name(), owner.UID, owner.GID); err != nil {
			return backupInfo{}, err
		}
	}
	if err := os.Rename(tmp.Name(), path); err != nil {
		return backupInfo{}, err
	}
	fi, _ := os.Stat(path)
	return backupInfo{File: file, Size: fi.Size(), Created: now, Path: path}, nil
}

func listBackups(env *Env, engine, name string) []backupInfo {
	dir := filepath.Join(env.Cfg.BackupDir, engine, name)
	entries, _ := os.ReadDir(dir)
	out := []backupInfo{}
	for _, de := range entries {
		m := backupNameRe.FindStringSubmatch(de.Name())
		if m == nil || m[1] != name || !de.Type().IsRegular() {
			continue
		}
		fi, err := de.Info()
		if err != nil {
			continue
		}
		created, _ := time.Parse("20060102T150405Z", m[2])
		out = append(out, backupInfo{File: de.Name(), Size: fi.Size(), Created: created, Path: filepath.Join(dir, de.Name())})
	}
	slices.SortFunc(out, func(a, b backupInfo) int { return b.Created.Compare(a.Created) })
	return out
}

// pruneBackups keeps the newest `keep` backups (pre-restore snapshots count too).
func pruneBackups(env *Env, engine, name string, keep int) int {
	removed := 0
	for i, b := range listBackups(env, engine, name) {
		if i >= keep && os.Remove(b.Path) == nil {
			removed++
		}
	}
	return removed
}

type backupParams struct {
	Engine  string `json:"engine"`
	Name    string `json:"name"`
	Backup  string `json:"backup,omitempty"`
	Confirm string `json:"confirm,omitempty"`
}

func (p backupParams) validate(env *Env) error {
	return dbParams{Engine: p.Engine, Name: p.Name}.validateOnly(env)
}

func (p dbParams) validateOnly(env *Env) error {
	_, err := p.resolve(env)
	return err
}

// backupFile resolves a backup name to a regular file of that database.
func (p backupParams) backupFile(env *Env) (string, error) {
	m := backupNameRe.FindStringSubmatch(p.Backup)
	if m == nil || m[1] != p.Name {
		return "", protocol.Invalid("backup: not a backup of " + p.Name)
	}
	path := filepath.Join(env.Cfg.BackupDir, p.Engine, p.Name, p.Backup)
	fi, err := os.Lstat(path)
	if err != nil || !fi.Mode().IsRegular() {
		return "", protocol.NotFound("backup " + p.Backup + " not found")
	}
	return path, nil
}

func dbBackup(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p backupParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := p.validate(env); err != nil {
		return nil, err
	}
	b, err := runBackup(ctx, env, p.Engine, p.Name, "")
	if err != nil {
		return nil, err
	}
	return withOffsite(ctx, env, dbTarget(env, p.Engine, p.Name), b), nil
}

func dbBackups(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p backupParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := p.validate(env); err != nil {
		return nil, err
	}
	return map[string]any{"backups": listBackups(env, p.Engine, p.Name), "schedule": readBackupSchedule(env, p.Engine, p.Name)}, nil
}

func dbBackupDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p backupParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := p.validate(env); err != nil {
		return nil, err
	}
	path, err := p.backupFile(env)
	if err != nil {
		return nil, err
	}
	return map[string]any{"deleted": p.Backup}, os.Remove(path)
}

// dbRestore restores a backup over the database, after taking a pre-restore
// snapshot (so a wrong restore can itself be undone).
func dbRestore(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p backupParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := p.validate(env); err != nil {
		return nil, err
	}
	if p.Confirm != p.Name {
		return nil, protocol.Invalid("confirm: must equal name")
	}
	path, err := p.backupFile(env)
	if err != nil {
		return nil, err
	}
	e, _ := engineFor(env, p.Engine)
	snap, err := runBackup(ctx, env, p.Engine, p.Name, "-pre-restore")
	if err != nil {
		return nil, fmt.Errorf("pre-restore snapshot failed, nothing restored: %w", err)
	}
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	var r io.Reader = f
	if strings.HasSuffix(path, ".gz") {
		gz, err := gzip.NewReader(f)
		if err != nil {
			return nil, err
		}
		defer gz.Close()
		r = gz
	}
	if err := e.restore(ctx, env, p.Name, r); err != nil {
		return nil, fmt.Errorf("restore failed (snapshot before restore: %s): %w", snap.File, err)
	}
	return map[string]any{"restored": p.Backup, "snapshot": snap.File}, nil
}

// ---------------------------------------------------------------- schedule

type scheduleParams struct {
	Engine    string `json:"engine"`
	Name      string `json:"name"`
	Frequency string `json:"frequency"` // off | daily | weekly
	Keep      int    `json:"keep"`
}

func scheduleUnit(engine, name string) string { return "wspanel-backup-" + engine + "-" + name }

var scheduleMetaRe = regexp.MustCompile(`(?m)^# wspanel: frequency=(daily|weekly) keep=(\d+)$`)

func readBackupSchedule(env *Env, engine, name string) map[string]any {
	b, err := os.ReadFile(filepath.Join(env.Cfg.SystemdDir, scheduleUnit(engine, name)+".timer"))
	if err != nil {
		return map[string]any{"frequency": "off"}
	}
	m := scheduleMetaRe.FindStringSubmatch(string(b))
	if m == nil {
		return map[string]any{"frequency": "unknown"}
	}
	keep, _ := strconv.Atoi(m[2])
	return map[string]any{"frequency": m[1], "keep": keep}
}

// dbSchedule installs a systemd timer that runs the agent binary in backup
// mode (as root, oneshot) and prunes to `keep` backups.
func dbSchedule(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p scheduleParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := (dbParams{Engine: p.Engine, Name: p.Name}).validateOnly(env); err != nil {
		return nil, err
	}
	if err := removeBackupSchedule(ctx, env, p.Engine, p.Name); err != nil {
		return nil, err
	}
	switch p.Frequency {
	case "off":
		return map[string]any{"frequency": "off"}, nil
	case "daily", "weekly":
	default:
		return nil, protocol.Invalid("frequency: off, daily or weekly")
	}
	if p.Keep < 1 || p.Keep > 90 {
		return nil, protocol.Invalid("keep: 1-90")
	}
	unit := scheduleUnit(p.Engine, p.Name)
	calendar := "*-*-* 03:00:00"
	if p.Frequency == "weekly" {
		calendar = "Sun *-*-* 03:00:00"
	}
	service := fmt.Sprintf(`# Managed by WSPanel agent. Do not edit.
[Unit]
Description=WSPanel backup of %[1]s database %[2]s

[Service]
Type=oneshot
ExecStart=%[3]s -config /etc/panel-agent/agent.json -backup %[1]s/%[2]s -keep %[4]d
Nice=10
IOSchedulingClass=idle
`, p.Engine, p.Name, env.Cfg.AgentBinary, p.Keep)
	timer := fmt.Sprintf(`# Managed by WSPanel agent. Do not edit.
# wspanel: frequency=%[2]s keep=%[3]d
[Unit]
Description=WSPanel %[2]s backup of %[1]s

[Timer]
OnCalendar=%[4]s
RandomizedDelaySec=45min
Persistent=true

[Install]
WantedBy=timers.target
`, p.Name, p.Frequency, p.Keep, calendar)
	svcPath := filepath.Join(env.Cfg.SystemdDir, unit+".service")
	timerPath := filepath.Join(env.Cfg.SystemdDir, unit+".timer")
	if err := env.FS.WriteFile(svcPath, []byte(service), 0o644, fsx.Root); err != nil {
		return nil, err
	}
	if err := env.FS.WriteFile(timerPath, []byte(timer), 0o644, fsx.Root); err != nil {
		return nil, err
	}
	for _, args := range [][]string{{"daemon-reload"}, {"enable", "--now", unit + ".timer"}} {
		if _, err := env.Exec.Run(ctx, "systemctl", args...); err != nil {
			_ = removeBackupSchedule(context.WithoutCancel(ctx), env, p.Engine, p.Name)
			return nil, err
		}
	}
	return map[string]any{"frequency": p.Frequency, "keep": p.Keep, "unit": unit + ".timer"}, nil
}

func removeBackupSchedule(ctx context.Context, env *Env, engine, name string) error {
	unit := scheduleUnit(engine, name)
	timerPath := filepath.Join(env.Cfg.SystemdDir, unit+".timer")
	if !fsx.Exists(timerPath) {
		return nil
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "disable", "--now", unit+".timer"); err != nil {
		return err
	}
	_ = os.Remove(timerPath)
	_ = os.Remove(filepath.Join(env.Cfg.SystemdDir, unit+".service"))
	_, err := env.Exec.Run(ctx, "systemctl", "daemon-reload")
	return err
}

// RunScheduledBackup is the agent's `-backup engine/name -keep N` mode.
func RunScheduledBackup(ctx context.Context, env *Env, spec string, keep int) (any, error) {
	engine, name, ok := strings.Cut(spec, "/")
	if !ok {
		return nil, protocol.Invalid("backup: expected engine/name")
	}
	if err := (dbParams{Engine: engine, Name: name}).validateOnly(env); err != nil {
		return nil, err
	}
	b, err := runBackup(ctx, env, engine, name, "")
	if err != nil {
		return nil, err
	}
	b = withOffsite(ctx, env, dbTarget(env, engine, name), b)
	out := map[string]any{"backup": b.File, "size": b.Size, "pruned": pruneBackups(env, engine, name, keep), "offsite": b.Offsite}
	if b.OffsiteError != "" {
		return out, errors.New(b.OffsiteError) // the unit fails, so it shows
	}
	return out, nil
}
