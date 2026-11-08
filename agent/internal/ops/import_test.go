package ops

import (
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestImportScanFindsACpanelBackupsDumps(t *testing.T) {
	env, dr := testEnv(t)
	_, _ = call(t, env, siteCreate, map[string]string{"domain": "old.example.com", "framework": "php", "php_version": "8.4"})
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if strings.Contains(c.String(), "/usr/bin/tar -tzf") {
			return executor.Result{Stdout: "cpmove-jan/\ncpmove-jan/homedir/public_html/index.php\ncpmove-jan/mysql/jan_wp.sql\ncpmove-jan/mysql/jan_shop.sql\ncpmove-jan/mysql.sql\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, siteImportScan, map[string]string{"domain": "old.example.com", "file": "cpmove-jan.tar.gz"})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	if m["cpanel"] != true || strings.Join(m["dumps"].([]string), ",") != "jan_shop,jan_wp" {
		t.Errorf("scan: %v", m)
	}
	for _, bad := range []string{"../../etc/x.tar.gz", "x.zip", "-rf.tar.gz"} {
		if _, err := call(t, env, siteImportScan, map[string]string{"domain": "old.example.com", "file": bad}); code(err) != "invalid_params" {
			t.Errorf("%q: %v", bad, err)
		}
	}
	for _, c := range dr.Calls() {
		if c.Name == "setpriv" && !strings.Contains(c.String(), "--reuid=web_old_example_com") {
			t.Errorf("read as the site user: %s", c)
		}
	}
}

func TestImportedWordPressGetsTheSitesDatabase(t *testing.T) {
	cfg := "<?php\ndefine( 'DB_NAME', 'jan_wp' );\ndefine(\"DB_USER\", \"jan_wp\");\ndefine('DB_PASSWORD', 'o\\'ld');\ndefine('DB_HOST', 'localhost');\n$table_prefix = 'wp_';\n"
	got := rewriteWPConfigDB(cfg, map[string]string{"DB_HOST": "127.0.0.1", "DB_DATABASE": "old_example_com", "DB_USERNAME": "old_example_com", "DB_PASSWORD": "n'ew"})
	for _, want := range []string{"define('DB_NAME', 'old_example_com')", "define('DB_USER', 'old_example_com')", `define('DB_PASSWORD', 'n\'ew')`, "define('DB_HOST', '127.0.0.1')", "$table_prefix = 'wp_';"} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in:\n%s", want, got)
		}
	}
}

func TestImportedSQLRunsAsAnAccountOfThatDatabaseOnly(t *testing.T) {
	env, dr := testEnv(t)
	env.Cfg.ArtifactDir = t.TempDir()
	if err := restoreUntrusted(t.Context(), env, "old_example_com", strings.NewReader(
		"CREATE DEFINER=`root`@`localhost` TRIGGER t BEFORE INSERT ON x FOR EACH ROW SET @a = 1;\n/*!50013 DEFINER=`jan`@`%` SQL SECURITY DEFINER */\n")); err != nil {
		t.Fatal(err)
	}
	var grant, restore executor.Call
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" && strings.Contains(c.Stdin, "GRANT ALL PRIVILEGES ON `old\\_example\\_com`.* TO 'old_example_com-def'@'localhost'") {
			grant = c
		}
		if c.Name == "mariadb" && len(c.Args) > 0 && strings.HasPrefix(c.Args[0], "--defaults-extra-file=") {
			restore = c
		}
	}
	if grant.Name == "" {
		t.Fatal("a temporary user with rights on the one database")
	}
	if restore.Name == "" || strings.Contains(strings.Join(restore.Args, " "), "password") {
		t.Fatalf("restored as that user, the password not in argv: %v", restore)
	}
	if strings.Contains(restore.Stdin, "DEFINER=") || !strings.Contains(restore.Stdin, "CREATE  TRIGGER t") {
		t.Errorf("definers dropped, the rest kept:\n%s", restore.Stdin)
	}
	locked := false
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" && strings.Contains(c.Stdin, "ALTER USER 'old_example_com-def'@'localhost' ACCOUNT LOCK") {
			locked = true
		}
	}
	if !locked {
		t.Error("the account is locked afterwards (its objects keep working, nobody signs in)")
	}
}
