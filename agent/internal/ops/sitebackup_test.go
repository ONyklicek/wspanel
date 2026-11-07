package ops

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fileop"
	"wspanel/agent/internal/protocol"
)

// backupEnv: a Laravel site with a real home on disk. The dry-run executor
// runs the file worker for real and does what mv/rm/ln as the site user would.
func backupEnv(t *testing.T) (*Env, *executor.DryRun, string) {
	env, dr := testEnv(t)
	env.Cfg.BackupDir = filepath.Join(t.TempDir(), "backups")
	env.Cfg.AgentBinary = "/usr/local/sbin/panel-agent"
	env.Cfg.DBEngines = []string{"mariadb"}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(env.Cfg.SitesRoot, "app.example.com")
	for p, body := range map[string]string{
		"releases/20260101T000000Z/public/index.php": "<?php echo 'v1';",
		"shared/.env":                        "APP_KEY=one\n",
		"shared/storage/app/public/logo.png": "png",
	} {
		_ = os.MkdirAll(filepath.Join(home, filepath.Dir(p)), 0o755)
		if err := os.WriteFile(filepath.Join(home, p), []byte(body), 0o640); err != nil {
			t.Fatal(err)
		}
	}
	_ = os.Symlink(filepath.Join(home, "shared/.env"), filepath.Join(home, "releases/20260101T000000Z/.env"))
	_ = os.Remove(filepath.Join(home, "current"))
	if err := os.Symlink("releases/20260101T000000Z", filepath.Join(home, "current")); err != nil {
		t.Fatal(err)
	}
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch c.Name {
		case "mariadb-dump":
			return executor.Result{Stdout: "CREATE TABLE posts (id int);\n"}, nil
		case "setpriv":
		default:
			return executor.Result{}, nil
		}
		i := slices.Index(c.Args, "--")
		argv := c.Args[i+1:]
		switch argv[0] {
		case env.Cfg.AgentBinary:
			var out bytes.Buffer
			err := fileop.Serve(argv[2], strings.NewReader(c.Stdin), &out)
			return executor.Result{Stdout: out.String()}, err
		case "/usr/bin/mv":
			return executor.Result{}, os.Rename(argv[len(argv)-2], argv[len(argv)-1])
		case "/usr/bin/rm":
			return executor.Result{}, os.RemoveAll(argv[len(argv)-1])
		case "/usr/bin/ln":
			return executor.Result{}, os.Symlink(argv[len(argv)-2], argv[len(argv)-1])
		}
		return executor.Result{}, nil
	}
	return env, dr, home
}

func archiveEntries(t *testing.T, path string) map[string]string {
	f, err := os.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		t.Fatal(err)
	}
	tr := tar.NewReader(gz)
	out := map[string]string{}
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return out
		}
		if err != nil {
			t.Fatal(err)
		}
		b, _ := io.ReadAll(tr)
		if hdr.Typeflag == tar.TypeSymlink {
			b = []byte("-> " + hdr.Linkname)
		}
		out[hdr.Name] = string(b)
	}
}

func TestSiteBackupHoldsFilesAndDatabases(t *testing.T) {
	env, _, home := backupEnv(t)
	out, err := call(t, env, siteBackup, map[string]any{"domain": "app.example.com", "databases": []map[string]string{{"engine": "mariadb", "name": "app"}}})
	if err != nil {
		t.Fatal(err)
	}
	b := out.(backupInfo)
	if !strings.HasPrefix(b.File, "app.example.com-") || !strings.HasSuffix(b.File, ".tar.gz") {
		t.Fatal(b.File)
	}
	got := archiveEntries(t, b.Path)
	for name, want := range map[string]string{
		"files/releases/20260101T000000Z/public/index.php": "<?php echo 'v1';",
		"files/shared/.env":                        "APP_KEY=one\n",
		"files/shared/storage/app/public/logo.png": "png",
		"files/releases/20260101T000000Z/.env":     "-> " + filepath.Join(home, "shared/.env"),
	} {
		if got[name] != want {
			t.Errorf("%s = %q", name, got[name])
		}
	}
	if !strings.Contains(got["manifest.json"], `"release": "20260101T000000Z"`) || !strings.Contains(got["manifest.json"], `"file": "db/mariadb-app.sql.gz"`) {
		t.Fatal(got["manifest.json"])
	}
	if _, ok := got["db/mariadb-app.sql.gz"]; !ok {
		t.Fatal("no dump")
	}
	if fi, _ := os.Stat(b.Path); fi.Mode().Perm() != 0o640 {
		t.Fatal(fi.Mode())
	}
	// No temporary files left behind.
	if left, _ := filepath.Glob(filepath.Join(filepath.Dir(b.Path), ".*")); len(left) != 0 {
		t.Fatal(left)
	}

	list, _ := call(t, env, siteBackups, map[string]any{"domain": "app.example.com"})
	if bs := list.(map[string]any)["backups"].([]backupInfo); len(bs) != 1 || bs[0].File != b.File {
		t.Fatalf("%v", list)
	}
	if _, err := call(t, env, siteBackup, map[string]any{"domain": "app.example.com", "databases": []map[string]string{{"engine": "postgresql", "name": "app"}}}); err == nil {
		t.Fatal("an engine that is not installed was accepted")
	}
}

func TestSiteRestoreMakesANewReleaseAndKeepsTheOldState(t *testing.T) {
	env, dr, home := backupEnv(t)
	out, err := call(t, env, siteBackup, map[string]any{"domain": "app.example.com", "databases": []map[string]string{{"engine": "mariadb", "name": "app"}}})
	if err != nil {
		t.Fatal(err)
	}
	backup := out.(backupInfo).File
	// The site changes after the backup.
	_ = os.WriteFile(filepath.Join(home, "shared/.env"), []byte("APP_KEY=two\n"), 0o640)
	_ = os.WriteFile(filepath.Join(home, "releases/20260101T000000Z/public/index.php"), []byte("broken"), 0o640)

	if _, err := call(t, env, siteRestore, map[string]any{"domain": "app.example.com", "backup": backup, "files": true, "databases": []map[string]string{{"engine": "mariadb", "name": "app"}}, "confirm": "nope"}); code(err) != protocol.CodeInvalidParams {
		t.Fatal("restore without confirmation")
	}
	res, err := call(t, env, siteRestore, map[string]any{"domain": "app.example.com", "backup": backup, "files": true, "databases": []map[string]string{{"engine": "mariadb", "name": "app"}}, "confirm": "app.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	r := res.(map[string]any)
	rel := r["release"].(string)
	if link, _ := os.Readlink(filepath.Join(home, "current")); link != "releases/"+rel {
		t.Fatalf("current -> %s", link)
	}
	if b, _ := os.ReadFile(filepath.Join(home, "current/public/index.php")); string(b) != "<?php echo 'v1';" {
		t.Fatal(string(b))
	}
	if b, _ := os.ReadFile(filepath.Join(home, "shared/.env")); string(b) != "APP_KEY=one\n" {
		t.Fatal(string(b))
	}
	// The state before the restore is still there: old shared, old release, a snapshot.
	if b, _ := os.ReadFile(filepath.Join(home, r["previous_shared"].(string), ".env")); string(b) != "APP_KEY=two\n" {
		t.Fatal("previous shared lost")
	}
	if _, err := os.Stat(filepath.Join(home, "releases/20260101T000000Z")); err != nil {
		t.Fatal("old release removed")
	}
	if !strings.HasSuffix(r["snapshot"].(string), "-pre-restore.tar.gz") {
		t.Fatal(r["snapshot"])
	}
	if left, _ := filepath.Glob(filepath.Join(home, ".wspanel-restore-*")); len(left) != 0 {
		t.Fatal("staging left", left)
	}
	// The dump went to the mariadb client, sandboxed.
	var restored bool
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" && slices.Contains(c.Args, "--sandbox") && strings.Contains(c.Stdin, "CREATE TABLE posts") {
			restored = true
		}
	}
	if !restored || !slices.Equal(r["databases"].([]string), []string{"app"}) {
		t.Fatalf("database not restored: %v", r)
	}
}

func TestSiteRestoreRefusesForeignNamesAndArchives(t *testing.T) {
	env, _, _ := backupEnv(t)
	for _, name := range []string{"../../etc/shadow", "other.com-20260101T000000Z.tar.gz", "app.example.com-x.tar.gz"} {
		if _, err := call(t, env, siteRestore, map[string]any{"domain": "app.example.com", "backup": name, "files": true, "confirm": "app.example.com"}); err == nil {
			t.Errorf("%s accepted", name)
		}
	}
	// A file that is not a site backup.
	dir := filepath.Join(env.Cfg.BackupDir, "sites", "app.example.com")
	_ = os.MkdirAll(dir, 0o750)
	_ = os.WriteFile(filepath.Join(dir, "app.example.com-20260101T000000Z.tar.gz"), []byte("junk"), 0o640)
	if _, err := call(t, env, siteRestore, map[string]any{"domain": "app.example.com", "backup": "app.example.com-20260101T000000Z.tar.gz", "files": true, "confirm": "app.example.com"}); code(err) != protocol.CodeInvalidParams {
		t.Fatal(err)
	}
}

func TestSiteBackupScheduleAndPrune(t *testing.T) {
	env, dr, _ := backupEnv(t)
	if _, err := call(t, env, siteBackupSchedule, map[string]any{"domain": "app.example.com", "frequency": "daily", "keep": 2, "databases": []map[string]string{{"engine": "mariadb", "name": "app"}}}); err != nil {
		t.Fatal(err)
	}
	svc, err := os.ReadFile(filepath.Join(env.Cfg.SystemdDir, "wspanel-sitebackup-web_app_example_com.service"))
	if err != nil || !strings.Contains(string(svc), "-site-backup app.example.com") {
		t.Fatal(string(svc), err)
	}
	if !slices.ContainsFunc(dr.Calls(), func(c executor.Call) bool {
		return c.String() == "systemctl enable --now wspanel-sitebackup-web_app_example_com.timer"
	}) {
		t.Fatal("timer not enabled")
	}
	for range 3 {
		if _, err := RunScheduledSiteBackup(t.Context(), env, "app.example.com"); err != nil {
			t.Fatal(err)
		}
		// Names are per second: move the ones made so far a day back.
		for _, b := range listSiteBackups(env, "app.example.com") {
			_ = os.Rename(b.Path, strings.Replace(b.Path, b.Created.Format("20060102T150405Z"), b.Created.Add(-24*time.Hour).Format("20060102T150405Z"), 1))
		}
	}
	if n := len(listSiteBackups(env, "app.example.com")); n != 2 {
		t.Fatalf("kept %d", n)
	}
	if _, err := call(t, env, siteBackupSchedule, map[string]any{"domain": "app.example.com", "frequency": "off", "keep": 2}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(env.Cfg.SystemdDir, "wspanel-sitebackup-web_app_example_com.timer")); !os.IsNotExist(err) {
		t.Fatal("timer left")
	}
	if _, err := call(t, env, siteBackupSchedule, map[string]any{"domain": "app.example.com", "frequency": "hourly", "keep": 2}); code(err) != protocol.CodeInvalidParams {
		t.Fatal("hourly accepted")
	}
}
