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
