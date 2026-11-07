package ops

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/fileop"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Whole-site backups. One <domain>-<time>.tar.gz in <BackupDir>/sites/<domain>
// (root:<backup group> 0640, like database dumps) holds:
//
//	manifest.json        what is inside, from which release
//	db/<engine>-<name>…  dumps of the site's databases
//	files/releases/<r>/… the live release
//	files/shared/…       .env, storage, uploads
//
// The files are read by the agent binary running as the site user (fileop
// "tar"): root never opens a site's file. A restore unpacks them the same
// way (fileop "untar") into a new release, keeps the old shared folder beside
// it and takes a backup of the current state first — nothing is lost by it.

const siteBackupFormat = 1

type dbRef struct {
	Engine string `json:"engine"`
	Name   string `json:"name"`
}

// siteBackupConfig is the schedule kept in the site state.
type siteBackupConfig struct {
	Frequency string  `json:"frequency"` // off | daily | weekly
	Keep      int     `json:"keep"`
	Databases []dbRef `json:"databases,omitempty"`
}

type siteManifest struct {
	Format    int          `json:"format"`
	Domain    string       `json:"domain"`
	Created   time.Time    `json:"created"`
	Release   string       `json:"release"`
	Framework string       `json:"framework"`
	Mode      string       `json:"mode"`
	Shared    bool         `json:"shared"`
	Databases []manifestDB `json:"databases"`
	Snapshot  bool         `json:"pre_restore,omitempty"`
}

type manifestDB struct {
	Engine string `json:"engine"`
	Name   string `json:"name"`
	File   string `json:"file"`
}

var siteBackupNameRe = regexp.MustCompile(`^(.+)-(\d{8}T\d{6}Z)(-pre-restore)?\.tar\.gz$`)

func siteBackupsDir(env *Env, domain string) string {
	return filepath.Join(env.Cfg.BackupDir, "sites", domain)
}

func validDBRefs(env *Env, refs []dbRef) error {
	if len(refs) > 20 {
		return protocol.Invalid("databases: at most 20")
	}
	seen := map[dbRef]bool{}
	for _, r := range refs {
		if err := (dbParams{Engine: r.Engine, Name: r.Name}).validateOnly(env); err != nil {
			return err
		}
		if seen[r] {
			return protocol.Invalid("databases: " + r.Name + " twice")
		}
		seen[r] = true
	}
	return nil
}

// runSiteBackup writes one backup. The caller holds the site (claimSite) when
// it runs inside the daemon.
func runSiteBackup(ctx context.Context, env *Env, domain string, dbs []dbRef, suffix string) (backupInfo, error) {
	st, l, err := loadSite(env, domain)
	if err != nil {
		return backupInfo{}, err
	}
	current, err := currentRelease(l)
	if err != nil {
		return backupInfo{}, err
	}
	dir, owner, err := backupDir(env, "sites", domain)
	if err != nil {
		return backupInfo{}, err
	}
	now := time.Now().UTC()
	file := domain + "-" + now.Format("20060102T150405Z") + suffix + ".tar.gz"

	// Dumps first, to temporary files: a tar entry needs its size up front.
	man := siteManifest{Format: siteBackupFormat, Domain: domain, Created: now, Release: current, Framework: st.Framework, Mode: st.Mode, Snapshot: suffix != "", Databases: []manifestDB{}}
	var dumps []string
	defer func() {
		for _, d := range dumps {
			_ = os.Remove(d)
		}
	}()
	for _, ref := range dbs {
		e, err := engineFor(env, ref.Engine)
		if err != nil {
			return backupInfo{}, err
		}
		tmp, err := os.CreateTemp(dir, ".db-*")
		if err != nil {
			return backupInfo{}, err
		}
		dumps = append(dumps, tmp.Name())
		var w io.Writer = tmp
		var gz *gzip.Writer
		if strings.HasSuffix(e.ext(), ".gz") {
			gz = gzip.NewWriter(tmp)
			w = gz
		}
		err = e.dump(ctx, env, ref.Name, w)
		if err == nil && gz != nil {
			err = gz.Close()
		}
		if cerr := tmp.Close(); err == nil {
			err = cerr
		}
		if err != nil {
			return backupInfo{}, fmt.Errorf("dump of %s failed: %w", ref.Name, err)
		}
		man.Databases = append(man.Databases, manifestDB{Engine: ref.Engine, Name: ref.Name, File: "db/" + ref.Engine + "-" + ref.Name + e.ext()})
	}

	paths := []string{"releases/" + current}
	if fi, err := os.Lstat(filepath.Join(l.Home, "shared")); err == nil && fi.IsDir() {
		paths = append(paths, "shared")
		man.Shared = true
	}

	out, err := os.CreateTemp(dir, ".site-*")
	if err != nil {
		return backupInfo{}, err
	}
	defer os.Remove(out.Name())
	gz := gzip.NewWriter(out)
	tw := tar.NewWriter(gz)
	err = func() error {
		mb, _ := json.MarshalIndent(man, "", "  ")
		if err := tarBytes(tw, "manifest.json", mb, now); err != nil {
			return err
		}
		for i, d := range man.Databases {
			if err := tarFile(tw, d.File, dumps[i], now); err != nil {
				return err
			}
		}
		// The site's files, re-wrapped under files/ as they stream past.
		if err := siteFileOp(ctx, env, l, fileop.Request{Action: "tar", Paths: paths}, nil, func(r io.Reader) error {
			tr := tar.NewReader(r)
			for {
				hdr, err := tr.Next()
				if errors.Is(err, io.EOF) {
					return nil
				}
				if err != nil {
					return err
				}
				hdr.Name = "files/" + hdr.Name
				if err := tw.WriteHeader(hdr); err != nil {
					return err
				}
				if _, err := io.Copy(tw, tr); err != nil {
					return err
				}
			}
		}); err != nil {
			return fmt.Errorf("reading the site's files failed: %w", err)
		}
		if err := tw.Close(); err != nil {
			return err
		}
		return gz.Close()
	}()
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return backupInfo{}, err
	}
	if err := os.Chmod(out.Name(), 0o640); err != nil {
		return backupInfo{}, err
	}
	if !env.Cfg.DryRun {
		if err := os.Chown(out.Name(), owner.UID, owner.GID); err != nil {
			return backupInfo{}, err
		}
	}
	path := filepath.Join(dir, file)
	if err := os.Rename(out.Name(), path); err != nil {
		return backupInfo{}, err
	}
	fi, _ := os.Stat(path)
	return backupInfo{File: file, Size: fi.Size(), Created: now, Path: path}, nil
}

func tarBytes(tw *tar.Writer, name string, b []byte, t time.Time) error {
	if err := tw.WriteHeader(&tar.Header{Name: name, Mode: 0o640, Size: int64(len(b)), ModTime: t, Typeflag: tar.TypeReg}); err != nil {
		return err
	}
	_, err := tw.Write(b)
	return err
}

func tarFile(tw *tar.Writer, name, src string, t time.Time) error {
	f, err := os.Open(src)
	if err != nil {
		return err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return err
	}
	if err := tw.WriteHeader(&tar.Header{Name: name, Mode: 0o640, Size: fi.Size(), ModTime: t, Typeflag: tar.TypeReg}); err != nil {
		return err
	}
	_, err = io.Copy(tw, io.LimitReader(f, fi.Size()))
	return err
}

// siteFileOp runs one fileop request as the site user with a streamed
// payload in (stdin, may be nil) and/or out (consume, may be nil).
func siteFileOp(ctx context.Context, env *Env, l siteLayout, req fileop.Request, stdin io.Reader, consume func(io.Reader) error) error {
	line, _ := json.Marshal(req)
	var in io.Reader = bytes.NewReader(append(line, '\n'))
	if stdin != nil {
		in = io.MultiReader(in, stdin)
	}
	sink := &responseSplitter{}
	var pw *io.PipeWriter
	done := make(chan error, 1)
	if consume != nil {
		var pr *io.PipeReader
		pr, pw = io.Pipe()
		sink.rest = pw
		go func() {
			err := consume(pr)
			if err == nil {
				_, err = io.Copy(io.Discard, pr) // tar padding after the last entry
			}
			pr.CloseWithError(err) // a failed reader stops the worker (broken pipe)
			done <- err
		}()
	}
	args := []string{"--reuid=" + l.User, "--regid=" + l.User, "--init-groups", "--reset-env", "--", env.Cfg.AgentBinary, "-fileop", l.Home}
	_, runErr := env.Exec.RunIO(ctx, in, sink, "setpriv", args...)
	var consumeErr error
	if pw != nil {
		pw.CloseWithError(runErr)
		consumeErr = <-done
	}
	if err := fileOpAnswer(sink.head); err != nil {
		return err
	}
	if runErr != nil {
		return runErr
	}
	return consumeErr
}

func fileOpAnswer(head []byte) error {
	var resp fileop.Response
	if err := json.Unmarshal(bytes.TrimSpace(head), &resp); err != nil {
		return errors.New("file worker gave no answer")
	}
	if !resp.OK {
		return errors.New(resp.Error)
	}
	return nil
}

func listSiteBackups(env *Env, domain string) []backupInfo {
	dir := siteBackupsDir(env, domain)
	entries, _ := os.ReadDir(dir)
	out := []backupInfo{}
	for _, de := range entries {
		m := siteBackupNameRe.FindStringSubmatch(de.Name())
		if m == nil || m[1] != domain || !de.Type().IsRegular() {
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

func pruneSiteBackups(env *Env, domain string, keep int) int {
	removed := 0
	for i, b := range listSiteBackups(env, domain) {
		if i >= keep && os.Remove(b.Path) == nil {
			removed++
		}
	}
	return removed
}

func siteBackupFile(env *Env, domain, name string) (string, error) {
	m := siteBackupNameRe.FindStringSubmatch(name)
	if m == nil || m[1] != domain {
		return "", protocol.Invalid("backup: not a backup of " + domain)
	}
	path := filepath.Join(siteBackupsDir(env, domain), name)
	fi, err := os.Lstat(path)
	if err != nil || !fi.Mode().IsRegular() {
		return "", protocol.NotFound("backup " + name + " not found")
	}
	return path, nil
}

// ---------------------------------------------------------------- operations

type siteBackupParams struct {
	Domain    string  `json:"domain"`
	Databases []dbRef `json:"databases,omitempty"`
}

func siteBackup(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p siteBackupParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if _, _, err := loadSite(env, p.Domain); err != nil {
		return nil, err
	}
	if err := validDBRefs(env, p.Databases); err != nil {
		return nil, err
	}
	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()
	return runSiteBackup(ctx, env, p.Domain, p.Databases, "")
}

func siteBackups(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	schedule := siteBackupConfig{Frequency: "off", Keep: 7, Databases: []dbRef{}}
	if st.Backup != nil {
		schedule = *st.Backup
	}
	return map[string]any{"backups": listSiteBackups(env, p.Domain), "schedule": schedule}, nil
}

func siteBackupDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		Backup string `json:"backup"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	path, err := siteBackupFile(env, p.Domain, p.Backup)
	if err != nil {
		return nil, err
	}
	return map[string]any{"deleted": p.Backup}, os.Remove(path)
}

// readManifest reads manifest.json, the archive's first entry.
func readManifest(path string) (siteManifest, error) {
	var m siteManifest
	f, err := os.Open(path)
	if err != nil {
		return m, err
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		return m, protocol.Invalid("not a site backup")
	}
	tr := tar.NewReader(gz)
	hdr, err := tr.Next()
	if err != nil || hdr.Name != "manifest.json" || hdr.Size > 1<<20 {
		return m, protocol.Invalid("not a site backup")
	}
	if err := json.NewDecoder(tr).Decode(&m); err != nil || m.Format != siteBackupFormat {
		return m, protocol.Invalid("not a site backup this agent can read")
	}
	return m, nil
}

// siteRestore puts a backup back: files as a new release (+ shared), and the
// chosen databases. A backup of the current state is taken first.
func siteRestore(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain    string `json:"domain"`
		Backup    string `json:"backup"`
		Files     bool   `json:"files"`
		Databases bool   `json:"databases"`
		Confirm   string `json:"confirm"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Confirm != p.Domain {
		return nil, protocol.Invalid("confirm: must equal domain")
	}
	if !p.Files && !p.Databases {
		return nil, protocol.Invalid("choose files, databases or both")
	}
	path, err := siteBackupFile(env, p.Domain, p.Backup)
	if err != nil {
		return nil, err
	}
	man, err := readManifest(path)
	if err != nil {
		return nil, err
	}
	if man.Domain != p.Domain || !releaseNameRe.MatchString(man.Release) {
		return nil, protocol.Invalid("the backup belongs to another site")
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	// Databases: those of the backup that still exist (others are reported).
	var restoreDBs []manifestDB
	var skipped []string
	if p.Databases {
		for _, d := range man.Databases {
			if (dbParams{Engine: d.Engine, Name: d.Name}).validateOnly(env) != nil || !strings.HasPrefix(d.File, "db/") {
				skipped = append(skipped, d.Name)
				continue
			}
			restoreDBs = append(restoreDBs, d)
		}
	}

	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()

	var snapDBs []dbRef
	for _, d := range restoreDBs {
		snapDBs = append(snapDBs, dbRef{Engine: d.Engine, Name: d.Name})
	}
	snap, err := runSiteBackup(ctx, env, p.Domain, snapDBs, "-pre-restore")
	if err != nil {
		return nil, fmt.Errorf("backup before restore failed, nothing restored: %w", err)
	}

	stamp := time.Now().UTC().Format("20060102T150405Z")
	staging := ".wspanel-restore-" + stamp
	stagingPath := filepath.Join(l.Home, staging)
	cleanup := func() { _ = asUser(context.WithoutCancel(ctx), env, l.User, "/usr/bin/rm", "-rf", "--", stagingPath) }

	// One pass through the archive: dumps to temporary files (root's), the
	// files streamed to the site user's unpacker.
	dumps := map[string]string{}
	defer func() {
		for _, d := range dumps {
			_ = os.Remove(d)
		}
	}()
	want := map[string]bool{}
	for _, d := range restoreDBs {
		want[d.File] = true
	}
	unpacked := false
	err = func() error {
		f, err := os.Open(path)
		if err != nil {
			return err
		}
		defer f.Close()
		gz, err := gzip.NewReader(f)
		if err != nil {
			return err
		}
		tr := tar.NewReader(gz)
		for {
			hdr, err := tr.Next()
			if errors.Is(err, io.EOF) {
				return nil
			}
			if err != nil {
				return fmt.Errorf("broken backup: %w", err)
			}
			switch {
			case want[hdr.Name] && hdr.Typeflag == tar.TypeReg:
				tmp, err := os.CreateTemp(siteBackupsDir(env, p.Domain), ".restore-db-*")
				if err != nil {
					return err
				}
				dumps[hdr.Name] = tmp.Name()
				_, err = io.Copy(tmp, tr)
				if cerr := tmp.Close(); err == nil {
					err = cerr
				}
				if err != nil {
					return err
				}
			case strings.HasPrefix(hdr.Name, "files/") && p.Files:
				unpacked = true
				return streamToUnpacker(ctx, env, l, staging, tr, hdr)
			}
		}
	}()
	if err != nil {
		if unpacked {
			cleanup()
		}
		return nil, fmt.Errorf("restore failed, nothing changed (backup before restore: %s): %w", snap.File, err)
	}
	if p.Files && !unpacked {
		return nil, protocol.Invalid("the backup holds no files")
	}

	result := map[string]any{"restored": p.Backup, "snapshot": snap.File, "skipped_databases": skipped}

	// Databases.
	var restored []string
	for _, d := range restoreDBs {
		src, ok := dumps[d.File]
		if !ok {
			skipped = append(skipped, d.Name)
			continue
		}
		e, _ := engineFor(env, d.Engine)
		if err := restoreDump(ctx, env, e, d.Name, src); err != nil {
			if unpacked {
				cleanup()
			}
			return nil, fmt.Errorf("restore of database %s failed (backup before restore: %s): %w", d.Name, snap.File, err)
		}
		restored = append(restored, d.Name)
	}
	result["databases"] = restored
	result["skipped_databases"] = skipped

	// Files: the backup's release becomes a new release, its shared folder
	// replaces the current one (kept beside it).
	if p.Files {
		newRelease := stamp
		mv := func(from, to string) error {
			return asUser(ctx, env, l.User, "/usr/bin/mv", "-T", "--", from, to)
		}
		if err := mv(filepath.Join(stagingPath, "releases", man.Release), filepath.Join(l.Home, "releases", newRelease)); err != nil {
			cleanup()
			return nil, fmt.Errorf("restore failed (backup before restore: %s): %w", snap.File, err)
		}
		if man.Shared {
			keptShared := "shared.before-restore-" + stamp
			if fsx.Exists(filepath.Join(l.Home, "shared")) {
				if err := mv(filepath.Join(l.Home, "shared"), filepath.Join(l.Home, keptShared)); err != nil {
					cleanup()
					return nil, err
				}
				result["previous_shared"] = keptShared
			}
			if err := mv(filepath.Join(stagingPath, "shared"), filepath.Join(l.Home, "shared")); err != nil {
				cleanup()
				return nil, err
			}
		}
		cleanup()
		var logBuf strings.Builder
		d := &deployment{env: env, l: l, release: newRelease, log: &logBuf}
		if err := withMutate(env, func() error {
			if l.Mode == "classic" {
				if err := grantWebAccess(ctx, env, l, newRelease); err != nil {
					return err
				}
			}
			if err := d.switchTo(ctx, newRelease); err != nil {
				return err
			}
			d.restartServices(ctx)
			return nil
		}); err != nil {
			return nil, err
		}
		ok, why := d.healthy(ctx)
		result["release"], result["healthy"], result["health_error"] = newRelease, ok, why
	}
	return result, nil
}

// streamToUnpacker feeds the files/ entries (from first on) to fileop untar.
func streamToUnpacker(ctx context.Context, env *Env, l siteLayout, staging string, tr *tar.Reader, first *tar.Header) error {
	pr, pw := io.Pipe()
	go func() {
		tw := tar.NewWriter(pw)
		err := func() error {
			for hdr := first; hdr != nil; {
				if name, ok := strings.CutPrefix(hdr.Name, "files/"); ok && name != "" {
					hdr.Name = name
					if err := tw.WriteHeader(hdr); err != nil {
						return err
					}
					if _, err := io.Copy(tw, tr); err != nil {
						return err
					}
				}
				next, err := tr.Next()
				if errors.Is(err, io.EOF) {
					break
				}
				if err != nil {
					return err
				}
				hdr = next
			}
			return tw.Close()
		}()
		pw.CloseWithError(err)
	}()
	err := siteFileOp(ctx, env, l, fileop.Request{Action: "untar", To: staging}, pr, nil)
	pr.CloseWithError(errors.New("unpacker finished")) // unblocks the writer if the worker stopped early
	return err
}

func restoreDump(ctx context.Context, env *Env, e dbEngine, name, src string) error {
	f, err := os.OpenFile(src, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return err
	}
	defer f.Close()
	var r io.Reader = f
	if strings.HasSuffix(e.ext(), ".gz") {
		gz, err := gzip.NewReader(f)
		if err != nil {
			return err
		}
		defer gz.Close()
		r = gz
	}
	return e.restore(ctx, env, name, r)
}

// ---------------------------------------------------------------- schedule

func siteBackupUnit(user string) string { return "wspanel-sitebackup-" + user }

func siteBackupSchedule(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		siteBackupConfig
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	switch p.Frequency {
	case "off", "daily", "weekly":
	default:
		return nil, protocol.Invalid("frequency: off, daily or weekly")
	}
	if p.Keep < 1 || p.Keep > 90 {
		return nil, protocol.Invalid("keep: 1-90")
	}
	if err := validDBRefs(env, p.Databases); err != nil {
		return nil, err
	}
	if err := removeSiteBackupSchedule(ctx, env, l.User); err != nil {
		return nil, err
	}
	cfg := p.siteBackupConfig
	if cfg.Databases == nil {
		cfg.Databases = []dbRef{}
	}
	st.Backup = &cfg
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	if cfg.Frequency == "off" {
		return cfg, nil
	}
	unit := siteBackupUnit(l.User)
	calendar := "*-*-* 02:30:00"
	if cfg.Frequency == "weekly" {
		calendar = "Sun *-*-* 02:30:00"
	}
	service := fmt.Sprintf(`# Managed by WSPanel agent. Do not edit.
[Unit]
Description=WSPanel backup of the site %[1]s

[Service]
Type=oneshot
ExecStart=%[2]s -config /etc/panel-agent/agent.json -site-backup %[1]s
Nice=10
IOSchedulingClass=idle
`, p.Domain, env.Cfg.AgentBinary)
	timer := fmt.Sprintf(`# Managed by WSPanel agent. Do not edit.
[Unit]
Description=WSPanel %[2]s backup of the site %[1]s

[Timer]
OnCalendar=%[3]s
RandomizedDelaySec=45min
Persistent=true

[Install]
WantedBy=timers.target
`, p.Domain, cfg.Frequency, calendar)
	if err := env.FS.WriteFile(filepath.Join(env.Cfg.SystemdDir, unit+".service"), []byte(service), 0o644, fsx.Root); err != nil {
		return nil, err
	}
	if err := env.FS.WriteFile(filepath.Join(env.Cfg.SystemdDir, unit+".timer"), []byte(timer), 0o644, fsx.Root); err != nil {
		return nil, err
	}
	for _, args := range [][]string{{"daemon-reload"}, {"enable", "--now", unit + ".timer"}} {
		if _, err := env.Exec.Run(ctx, "systemctl", args...); err != nil {
			_ = removeSiteBackupSchedule(context.WithoutCancel(ctx), env, l.User)
			return nil, err
		}
	}
	return cfg, nil
}

func removeSiteBackupSchedule(ctx context.Context, env *Env, user string) error {
	unit := siteBackupUnit(user)
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

// RunScheduledSiteBackup is the agent's `-site-backup <domain>` mode: the
// databases and how many backups to keep come from the site state.
func RunScheduledSiteBackup(ctx context.Context, env *Env, domain string) (any, error) {
	st, _, err := loadSite(env, domain)
	if err != nil {
		return nil, err
	}
	if st.Backup == nil || st.Backup.Frequency == "off" {
		return nil, protocol.Invalid("no backup schedule for " + domain)
	}
	// Databases removed since the schedule was set are left out, not fatal.
	var dbs []dbRef
	for _, d := range st.Backup.Databases {
		if (dbParams{Engine: d.Engine, Name: d.Name}).validateOnly(env) == nil {
			dbs = append(dbs, d)
		}
	}
	b, err := runSiteBackup(ctx, env, domain, dbs, "")
	if err != nil {
		return nil, err
	}
	return map[string]any{"backup": b.File, "size": b.Size, "pruned": pruneSiteBackups(env, domain, st.Backup.Keep)}, nil
}
