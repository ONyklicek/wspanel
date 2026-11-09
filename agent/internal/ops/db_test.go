package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

func dbEnv(t *testing.T) (*Env, *executor.DryRun) {
	env, dr := testEnv(t)
	env.Cfg.DBEngines = []string{"mariadb", "postgresql"}
	env.Cfg.BackupDir = filepath.Join(t.TempDir(), "backups")
	return env, dr
}

func TestDBNamesAndEngines(t *testing.T) {
	env, dr := dbEnv(t)
	for _, bad := range []string{"Shop", "1shop", "shop-db", "shop;drop", "mysql", "postgres", "pg_x", "a", strings.Repeat("a", 49), "x' OR '1"} {
		if _, err := call(t, env, dbCreate, map[string]string{"engine": "mariadb", "name": bad}); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%q: %v", bad, err)
		}
	}
	if _, err := call(t, env, dbCreate, map[string]string{"engine": "oracle", "name": "shop"}); code(err) != protocol.CodeInvalidParams {
		t.Error("unknown engine accepted")
	}
	env.Cfg.DBEngines = []string{"mariadb"}
	if _, err := call(t, env, dbCreate, map[string]string{"engine": "postgresql", "name": "shop"}); code(err) != protocol.CodeNotFound {
		t.Error("engine not installed accepted")
	}
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" || c.Name == "setpriv" {
			t.Fatalf("no SQL may run on invalid input: %s", c)
		}
	}
}

func TestDBCreateMariaDBReturnsPasswordOnce(t *testing.T) {
	env, dr := dbEnv(t)
	out, err := call(t, env, dbCreate, map[string]string{"engine": "mariadb", "name": "shop"})
	if err != nil {
		t.Fatal(err)
	}
	pw := out.(map[string]any)["password"].(string)
	if len(pw) != 32 {
		t.Fatalf("password %q", pw)
	}
	var sql string
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" && strings.Contains(c.Stdin, "CREATE DATABASE") {
			sql = c.Stdin
		}
		if strings.Contains(strings.Join(c.Args, " "), "IDENTIFIED BY") || strings.Contains(strings.Join(c.Args, " "), "PASSWORD") {
			t.Fatalf("a password reached argv: %s", c)
		}
	}
	for _, want := range []string{"CREATE DATABASE `shop` CHARACTER SET utf8mb4", "CREATE USER 'shop'@'localhost' IDENTIFIED BY '" + pw + "'",
		"CREATE USER 'shop'@'127.0.0.1'", "GRANT ALL PRIVILEGES ON `shop`.* TO 'shop'@'localhost'", "GRANT ALL PRIVILEGES ON `shop`.* TO 'shop'@'127.0.0.1'"} {
		if !strings.Contains(sql, want) {
			t.Errorf("SQL missing %q:\n%s", want, sql)
		}
	}
}

func TestDBCreatePostgresIsolatesDatabase(t *testing.T) {
	env, dr := dbEnv(t)
	if _, err := call(t, env, dbCreate, map[string]string{"engine": "postgresql", "name": "shop"}); err != nil {
		t.Fatal(err)
	}
	var all []string
	for _, c := range dr.Calls() {
		if c.Name == "setpriv" && c.Args[0] == "--reuid=postgres" {
			all = append(all, c.Stdin)
			if strings.Contains(strings.Join(c.Args, " "), "PASSWORD") {
				t.Fatalf("a password reached argv: %s", c)
			}
		}
	}
	joined := strings.Join(all, "\n")
	for _, want := range []string{`CREATE ROLE "shop" LOGIN PASSWORD`, `CREATE DATABASE "shop" OWNER "shop"`, `REVOKE ALL ON DATABASE "shop" FROM PUBLIC`} {
		if !strings.Contains(joined, want) {
			t.Errorf("missing %q in:\n%s", want, joined)
		}
	}
}

func TestDBCreateWritesSiteEnvAsSiteUser(t *testing.T) {
	env, dr := dbEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(strings.Join(c.Args, " "), "/usr/bin/cat") {
			return executor.Result{Stdout: "APP_NAME=Shop\nDB_CONNECTION=sqlite\n# DB_HOST=old\n"}, nil
		}
		return frankenVersion(c)
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, dbCreate, map[string]string{"engine": "mariadb", "name": "shop", "domain": "shop.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	if _, leaked := out.(map[string]any)["password"]; leaked {
		t.Fatal("password must not be returned when it goes into the site env")
	}
	var written string
	for _, c := range dr.Calls() {
		if c.Name == "setpriv" && strings.Contains(strings.Join(c.Args, " "), "/usr/bin/tee ") {
			if c.Args[0] != "--reuid=web_shop_example_com" {
				t.Fatalf("env not written as the site user: %s", c)
			}
			written = c.Stdin
		}
	}
	for _, want := range []string{"APP_NAME=Shop\n", `DB_CONNECTION="mariadb"`, `DB_HOST="127.0.0.1"`, `DB_DATABASE="shop"`, `DB_USERNAME="shop"`, "# DB_HOST=old"} {
		if !strings.Contains(written, want) {
			t.Errorf("env missing %q:\n%s", want, written)
		}
	}
	if strings.Count(written, "DB_CONNECTION") != 1 {
		t.Errorf("DB_CONNECTION must be replaced in place:\n%s", written)
	}
}

func TestMergeEnvForSymfonyURL(t *testing.T) {
	got := mergeEnv("", []string{"DATABASE_URL"}, map[string]string{"DATABASE_URL": `postgresql://a:b@127.0.0.1:5432/a?serverVersion=17&charset=utf8`})
	if !strings.Contains(got, `DATABASE_URL="postgresql://a:b@127.0.0.1:5432/a?serverVersion=17&charset=utf8"`) {
		t.Fatal(got)
	}
	if got := mergeEnv("", []string{"X"}, map[string]string{"X": "a\"b$c\nd"}); !strings.Contains(got, `X="a\"b\$cd"`) {
		t.Fatalf("escaping: %q", got)
	}
}

func TestBackupsAndRestoreValidation(t *testing.T) {
	env, dr := dbEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "mariadb-dump" {
			return executor.Result{Stdout: "-- dump of shop\nCREATE TABLE t (id int);\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, dbBackup, map[string]string{"engine": "mariadb", "name": "shop"})
	if err != nil {
		t.Fatal(err)
	}
	b := out.(backupInfo)
	if !backupNameRe.MatchString(b.File) || b.Size == 0 {
		t.Fatalf("backup: %+v", b)
	}
	if fi, _ := os.Stat(b.Path); fi.Mode().Perm() != 0o640 {
		t.Errorf("backup mode %v", fi.Mode().Perm())
	}
	list, _ := call(t, env, dbBackups, map[string]string{"engine": "mariadb", "name": "shop"})
	if n := len(list.(map[string]any)["backups"].([]backupInfo)); n != 1 {
		t.Fatalf("listed %d backups", n)
	}
	// Restore: confirm required, foreign / traversal names rejected.
	for _, p := range []map[string]string{
		{"engine": "mariadb", "name": "shop", "backup": b.File, "confirm": "nope"},
		{"engine": "mariadb", "name": "shop", "backup": "../../etc/shadow", "confirm": "shop"},
		{"engine": "mariadb", "name": "shop", "backup": strings.Replace(b.File, "shop-", "other-", 1), "confirm": "shop"},
	} {
		if _, err := call(t, env, dbRestore, p); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: %v", p, err)
		}
	}
	out, err = call(t, env, dbRestore, map[string]string{"engine": "mariadb", "name": "shop", "backup": b.File, "confirm": "shop"})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out.(map[string]any)["snapshot"].(string), "-pre-restore.sql.gz") {
		t.Errorf("no pre-restore snapshot: %v", out)
	}
	var restored string
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" && len(c.Args) > 0 && c.Args[len(c.Args)-1] == "shop" {
			restored = c.Stdin
		}
	}
	if !strings.Contains(restored, "CREATE TABLE t") {
		t.Errorf("restore did not stream the (gunzipped) dump: %q", restored)
	}
	if n := pruneBackups(env, "mariadb", "shop", 1); n != 1 {
		t.Errorf("pruned %d", n)
	}
}

func TestBackupSchedule(t *testing.T) {
	env, _ := dbEnv(t)
	if _, err := call(t, env, dbSchedule, map[string]any{"engine": "postgresql", "name": "shop", "frequency": "hourly", "keep": 7}); code(err) != protocol.CodeInvalidParams {
		t.Error("hourly accepted")
	}
	if _, err := call(t, env, dbSchedule, map[string]any{"engine": "postgresql", "name": "shop", "frequency": "daily", "keep": 14}); err != nil {
		t.Fatal(err)
	}
	svc := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-backup-postgresql-shop.service"))
	if !strings.Contains(svc, "ExecStart=/usr/sbin/panel-agent -config /etc/panel-agent/agent.json -backup postgresql/shop -keep 14") {
		t.Errorf("service:\n%s", svc)
	}
	if s := readBackupSchedule(env, "postgresql", "shop"); s["frequency"] != "daily" || s["keep"] != 14 {
		t.Errorf("schedule: %v", s)
	}
	if _, err := call(t, env, dbDrop, map[string]string{"engine": "postgresql", "name": "shop", "confirm": "shop"}); err != nil {
		t.Fatal(err)
	}
	if s := readBackupSchedule(env, "postgresql", "shop"); s["frequency"] != "off" {
		t.Error("schedule not removed with the database")
	}
}

func TestGrantEscapesWildcards(t *testing.T) {
	env, dr := dbEnv(t)
	if _, err := call(t, env, dbCreate, map[string]string{"engine": "mariadb", "name": "shop_db"}); err != nil {
		t.Fatal(err)
	}
	var sql string
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" && strings.Contains(c.Stdin, "GRANT") {
			sql = c.Stdin
		}
	}
	// `shop_db`.* would also grant `shopXdb` (another site's database).
	if !strings.Contains(sql, "GRANT ALL PRIVILEGES ON `shop\\_db`.*") || !strings.Contains(sql, "CREATE DATABASE `shop_db`") {
		t.Fatalf("grant not escaped:\n%s", sql)
	}
}

func TestRestoreRunsWithoutSuperuserPowers(t *testing.T) {
	env, dr := dbEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(strings.Join(c.Args, " "), "pg_dump") {
			return executor.Result{Stdout: "PGDMP"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, dbBackup, map[string]string{"engine": "postgresql", "name": "shop"})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, dbRestore, map[string]string{"engine": "postgresql", "name": "shop", "backup": out.(backupInfo).File, "confirm": "shop"}); err != nil {
		t.Fatal(err)
	}
	var restore string
	for _, c := range dr.Calls() {
		if strings.Contains(strings.Join(c.Args, " "), "pg_restore") {
			restore = strings.Join(c.Args, " ")
		}
	}
	for _, want := range []string{"--role=shop", "--no-owner", "--no-privileges"} {
		if !strings.Contains(restore, want) {
			t.Errorf("pg_restore missing %s: %s", want, restore)
		}
	}
}

func TestEngineInstallIsAddOnlyAndPersisted(t *testing.T) {
	env, dr := dbEnv(t)
	env.Cfg.DBEngines = []string{"mariadb"}
	env.ConfigPath = filepath.Join(t.TempDir(), "agent.json")
	if err := os.WriteFile(env.ConfigPath, []byte(`{"socket":"/run/x.sock","db_engines":["mariadb"]}`), 0o600); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, engineInstall, map[string]string{"engine": "mariadb"})
	if err != nil || out.(map[string]any)["changed"] != false || len(dr.Calls()) != 0 {
		t.Fatalf("installed engine must be a no-op: %v %v %v", out, err, dr.Calls())
	}
	if _, err := call(t, env, engineInstall, map[string]string{"engine": "postgresql"}); err != nil {
		t.Fatal(err)
	}
	var install string
	for _, c := range dr.Calls() {
		if c.Name == "apt-get" && c.Args[0] == "install" {
			install = strings.Join(c.Args, " ")
		}
	}
	if !strings.Contains(install, "postgresql php8.3-pgsql php8.4-pgsql") {
		t.Errorf("apt-get: %s", install)
	}
	cfg := read(t, env.ConfigPath)
	if !strings.Contains(cfg, `"mariadb"`) || !strings.Contains(cfg, `"postgresql"`) || !strings.Contains(cfg, `"socket": "/run/x.sock"`) {
		t.Errorf("agent.json:\n%s", cfg)
	}
	if _, err := call(t, env, engineInstall, map[string]string{"engine": "mysql"}); code(err) != protocol.CodeInvalidParams {
		t.Error("unknown engine accepted")
	}
}
