package ops

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

func phpEnv(t *testing.T) (*Env, *executor.DryRun) {
	env, dr := testEnv(t)
	env.Cfg.DryRun = false // the package lists below are what apt answers
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch c.Name {
		case "dpkg-query":
			return executor.Result{Stdout: "php8.4-fpm\tinstalled\nphp8.4-cli\tinstalled\nphp8.4-redis\tinstalled\nphp8.4-intl\tinstalled\nphp8.4-imagick\tnot-installed\n"}, nil
		case "apt-cache":
			return executor.Result{Stdout: "php8.4-fpm\nphp8.4-redis\nphp8.4-intl\nphp8.4-imagick\nphp8.4-swoole\nphp8.4-dev\nphp8.4-redis-dbgsym\n"}, nil
		case "frankenphp":
			if len(c.Args) > 0 && c.Args[0] == "version" {
				return executor.Result{Stdout: "FrankenPHP v1.13.5 PHP 8.5.12 Caddy v2.11.9\n"}, nil
			}
			return executor.Result{Stdout: "Core\nredis\nIntl\n"}, nil
		}
		return executor.Result{}, nil
	}
	return env, dr
}

func TestPHPStatusListsExtensions(t *testing.T) {
	env, _ := phpEnv(t)
	out, err := call(t, env, phpStatus, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(out)
	var st struct {
		Versions []phpVersionInfo `json:"versions"`
		Install  []string         `json:"installable"`
		Franken  struct {
			PHP        string   `json:"php"`
			Extensions []string `json:"extensions"`
		} `json:"frankenphp"`
	}
	_ = json.Unmarshal(b, &st)
	v84 := st.Versions[1]
	if strings.Join(v84.Extensions, ",") != "intl,redis" {
		t.Fatalf("installed: %v (core packages are not extensions)", v84.Extensions)
	}
	if strings.Join(v84.Available, ",") != "imagick,intl,redis,swoole" {
		t.Fatalf("available: %v", v84.Available)
	}
	if strings.Join(st.Install, ",") != "8.1,8.2,8.5" {
		t.Fatalf("installable: %v", st.Install)
	}
	if st.Franken.PHP != "8.5" || strings.Join(st.Franken.Extensions, ",") != "core,intl,redis" {
		t.Fatalf("frankenphp: %+v", st.Franken)
	}
}

func TestPHPExtensionInstallAndRemove(t *testing.T) {
	env, dr := phpEnv(t)
	for _, bad := range []map[string]string{
		{"version": "7.4", "extension": "redis", "action": "install"}, // version not on the server
		{"version": "8.4", "extension": "fpm", "action": "remove"},    // PHP itself
		{"version": "8.4", "extension": "x; rm -rf /", "action": "install"},
		{"version": "8.4", "extension": "redis", "action": "purge"},
	} {
		if _, err := call(t, env, phpExtension, bad); err == nil {
			t.Errorf("%v accepted", bad)
		}
	}
	if _, err := call(t, env, phpExtension, map[string]string{"version": "8.4", "extension": "mongodb", "action": "install"}); code(err) != protocol.CodeNotFound {
		t.Fatal("an extension the repository does not have")
	}
	if _, err := call(t, env, phpExtension, map[string]string{"version": "8.4", "extension": "imagick", "action": "install"}); err != nil {
		t.Fatal(err)
	}
	if !ran(dr, "apt-get install -y -q --no-install-recommends php8.4-imagick") || !ran(dr, "php-fpm8.4 -t") || !ran(dr, "systemctl reload php8.4-fpm.service") {
		t.Fatal("not installed / reloaded")
	}
	if _, err := call(t, env, phpExtension, map[string]string{"version": "8.4", "extension": "redis", "action": "remove"}); err != nil {
		t.Fatal(err)
	}
	if !ran(dr, "apt-get remove -y -q php8.4-redis") {
		t.Fatal("not removed")
	}
}

func TestPHPInstallAddsAVersion(t *testing.T) {
	env, dr := phpEnv(t)
	env.Cfg.DBEngines = []string{"mariadb"}
	env.ConfigPath = filepath.Join(t.TempDir(), "agent.json")
	if err := os.WriteFile(env.ConfigPath, []byte(`{"php_versions":["8.3","8.4"],"panel_domain":"p.example.com"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	etc := filepath.Dir(filepath.Dir(strings.ReplaceAll(env.Cfg.FPMPoolDir, "{version}", "8.4")))
	for path, body := range map[string]string{
		"fpm/conf.d/90-wspanel.ini":       "expose_php = Off\n",
		"cli/conf.d/90-wspanel.ini":       "expose_php = Off\n",
		"fpm/pool.d/00-wspanel-idle.conf": "[idle-8.4]\nlisten = /run/php/idle-8.4.sock\n",
	} {
		_ = os.MkdirAll(filepath.Dir(filepath.Join(etc, path)), 0o755)
		if err := os.WriteFile(filepath.Join(etc, path), []byte(body), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := call(t, env, phpInstall, map[string]string{"version": "7.4"}); code(err) != protocol.CodeInvalidParams {
		t.Fatal("an unsupported version accepted")
	}
	if _, err := call(t, env, phpInstall, map[string]string{"version": "8.2"}); err != nil {
		t.Fatal(err)
	}
	var install string
	for _, c := range dr.Calls() {
		if c.Name == "apt-get" && len(c.Args) > 0 && c.Args[0] == "install" {
			install = c.String()
		}
	}
	for _, want := range []string{"php8.2-fpm", "php8.2-cli", "php8.2-intl", "php8.2-mysql"} {
		if !strings.Contains(install, want) {
			t.Errorf("missing %s in %s", want, install)
		}
	}
	if strings.Contains(install, "pgsql") {
		t.Fatal("pgsql driver without PostgreSQL")
	}
	if !ran(dr, "systemctl enable --now php8.2-fpm.service") {
		t.Fatal("FPM not enabled")
	}
	etc82 := filepath.Join(filepath.Dir(etc), "8.2")
	if b, _ := os.ReadFile(filepath.Join(etc82, "fpm/conf.d/90-wspanel.ini")); string(b) != "expose_php = Off\n" {
		t.Fatal("php.ini additions not copied")
	}
	if b, _ := os.ReadFile(filepath.Join(etc82, "fpm/pool.d/00-wspanel-idle.conf")); !strings.Contains(string(b), "idle-8.2") {
		t.Fatalf("idle pool: %s", b)
	}
	b, _ := os.ReadFile(env.ConfigPath)
	if !strings.Contains(string(b), `"8.2"`) || !strings.Contains(string(b), `"panel_domain": "p.example.com"`) {
		t.Fatalf("agent.json: %s", b)
	}
	// Installing it again changes nothing.
	out, err := call(t, env, phpInstall, map[string]string{"version": "8.2"})
	if err != nil || out.(map[string]any)["changed"] != false {
		t.Fatalf("second install: %v %v", out, err)
	}
}
