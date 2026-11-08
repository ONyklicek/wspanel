package ops

import (
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestWordPressInstallsAsTheSiteUserWithConfigOutsideTheDocroot(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "wp.example.com", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(c.String(), "/usr/bin/cat") && strings.HasSuffix(c.String(), "shared/.env") {
			return executor.Result{Stdout: "DB_HOST=\"127.0.0.1\"\nDB_PORT=\"3306\"\nDB_DATABASE=\"wp_example_com\"\nDB_USERNAME=\"wp_example_com\"\nDB_PASSWORD=\"it's \\\"secret\\\"\"\n"}, nil
		}
		return executor.Result{}, nil
	}
	if _, err := call(t, env, siteAppInstall, map[string]string{"domain": "wp.example.com", "app": "wordpress"}); err != nil {
		t.Fatal(err)
	}
	var config string
	for _, c := range dr.Calls() {
		if c.Name == "setpriv" && !strings.Contains(c.String(), "--reuid=web_wp_example_com") {
			t.Errorf("as the site user: %s", c)
		}
		if strings.Contains(c.String(), "/usr/bin/tee") && strings.Contains(c.String(), "wp-config.php") {
			config = c.Stdin
			if strings.Contains(c.String(), "/public/") {
				t.Errorf("wp-config.php stays outside the docroot: %s", c)
			}
		}
	}
	for _, want := range []string{`define('DB_NAME', 'wp_example_com');`, `define('DB_PASSWORD', 'it\'s "secret"');`, `define('DB_HOST', '127.0.0.1');`, "define('ABSPATH', __DIR__.'/public/');"} {
		if !strings.Contains(config, want) {
			t.Errorf("wp-config lacks %q:\n%s", want, config)
		}
	}
	if strings.Count(config, "_KEY'") != 4 || strings.Count(config, "_SALT'") != 4 {
		t.Errorf("eight salts:\n%s", config)
	}
}

func TestWordPressNeedsAPlainPHPSite(t *testing.T) {
	env, _ := testEnv(t)
	_, _ = call(t, env, siteCreate, map[string]string{"domain": "l.example.com", "framework": "laravel", "php_version": "8.4"})
	if _, err := call(t, env, siteAppInstall, map[string]string{"domain": "l.example.com", "app": "wordpress"}); code(err) != "invalid_params" {
		t.Errorf("a Laravel site: %v", err)
	}
}
