package ops

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

func testEnv(t *testing.T) (*Env, *executor.DryRun) {
	t.Helper()
	root := t.TempDir()
	cfg := config.Defaults()
	cfg.DryRun = true
	cfg.PHPVersions = []string{"8.3", "8.4"}
	cfg.SitesRoot = filepath.Join(root, "srv/sites")
	cfg.TrashDir = filepath.Join(cfg.SitesRoot, ".trash")
	cfg.FPMPoolDir = filepath.Join(root, "etc/php/{version}/fpm/pool.d")
	cfg.ProxySitesDir = filepath.Join(root, "etc/panel-proxy/sites")
	for _, d := range []string{config.ForVersion(cfg.FPMPoolDir, "8.3"), config.ForVersion(cfg.FPMPoolDir, "8.4"), cfg.ProxySitesDir} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	dr := &executor.DryRun{}
	return &Env{Cfg: cfg, Exec: dr, FS: &fsx.FS{DryRun: true}}, dr
}

func call(t *testing.T, env *Env, h Handler, params any) (any, error) {
	t.Helper()
	raw, _ := json.Marshal(params)
	return h(context.Background(), env, raw)
}

func code(err error) string {
	var oe *protocol.OpError
	if errors.As(err, &oe) {
		return oe.Code
	}
	return ""
}

func TestSiteCreateLaravel(t *testing.T) {
	env, dr := testEnv(t)
	out, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.3"})
	if err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(env.Cfg.SitesRoot, "shop.example.com")
	if out.(map[string]string)["system_user"] != "web_shop_example_com" {
		t.Fatalf("output: %v", out)
	}
	for _, p := range []string{"releases/initial/public/index.php", "shared/storage/framework/views", "shared/.env", "tmp", "logs"} {
		if !fsx.Exists(filepath.Join(home, p)) {
			t.Errorf("missing %s", p)
		}
	}
	if target, _ := os.Readlink(filepath.Join(home, "current")); target != "releases/initial" {
		t.Errorf("current -> %q", target)
	}
	if fi, _ := os.Stat(filepath.Join(home, "shared/.env")); fi.Mode().Perm() != 0o600 {
		t.Errorf(".env mode %v", fi.Mode().Perm())
	}
	pool, err := os.ReadFile(filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, "8.3"), "web_shop_example_com.conf"))
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"[web_shop_example_com]", "user = web_shop_example_com", "open_basedir] = " + home + "/", "listen = /run/php/php8.3-web_shop_example_com.sock"} {
		if !strings.Contains(string(pool), want) {
			t.Errorf("pool missing %q", want)
		}
	}
	site, _ := os.ReadFile(filepath.Join(env.Cfg.ProxySitesDir, "shop.example.com.caddy"))
	if !strings.Contains(string(site), "root * "+home+"/current/public") || !strings.Contains(string(site), "resolve_root_symlink") {
		t.Errorf("proxy config:\n%s", site)
	}

	// Validation must precede every reload.
	var seq []string
	for _, c := range dr.Calls() {
		seq = append(seq, c.String())
	}
	joined := strings.Join(seq, "\n")
	iv := strings.Index(joined, "php-fpm8.3 -t")
	ir := strings.Index(joined, "systemctl reload php8.3-fpm.service")
	pv := strings.Index(joined, "frankenphp validate")
	pr := strings.Index(joined, "systemctl reload panel-proxy.service")
	if iv < 0 || ir < iv || pv < 0 || pr < pv {
		t.Errorf("bad order:\n%s", joined)
	}
	if !strings.Contains(joined, "setfacl -R -P -m u:panel-proxy:rX,d:u:panel-proxy:rX "+home+"/shared/storage/app/public") {
		t.Errorf("laravel public storage ACL missing:\n%s", joined)
	}
	if strings.Contains(joined, "-R -P -m u:panel-proxy:rX,d:u:panel-proxy:rX "+home+"/shared\n") {
		t.Error("proxy must not get read access to shared/")
	}

	// Second create conflicts.
	_, err = call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.3"})
	if code(err) != protocol.CodeConflict {
		t.Fatalf("want conflict, got %v", err)
	}
}

func TestSiteCreateSymfonyEnv(t *testing.T) {
	env, _ := testEnv(t)
	out, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.cz", "framework": "symfony", "php_version": "8.4"})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasSuffix(out.(map[string]string)["env_file"], "shared/.env.local") {
		t.Fatalf("env_file: %v", out)
	}
	if !fsx.Exists(filepath.Join(env.Cfg.SitesRoot, "app.example.cz/shared/var/log")) {
		t.Fatal("missing var/log")
	}
}

func TestSiteCreateRejectsBadParams(t *testing.T) {
	env, dr := testEnv(t)
	cases := []map[string]any{
		{"domain": "../etc.com", "framework": "laravel", "php_version": "8.3"},
		{"domain": "a.com", "framework": "rails", "php_version": "8.3"},
		{"domain": "a.com", "framework": "laravel", "php_version": "7.4"},
		{"domain": "a.com", "framework": "laravel", "php_version": "8.3", "mode": "worker"},
		{"domain": "a.com", "framework": "laravel", "php_version": "8.3", "cmd": "rm -rf /"},
	}
	for _, c := range cases {
		if _, err := call(t, env, siteCreate, c); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: want invalid_params, got %v", c, err)
		}
	}
	if len(dr.Calls()) != 0 {
		t.Fatalf("no command may run on invalid input, got %v", dr.Calls())
	}
}

func TestSiteCreateRollsBackOnInvalidProxyConfig(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "frankenphp" && c.Args[0] == "validate" {
			return executor.Result{ExitCode: 1}, errors.New("broken")
		}
		return executor.Result{}, nil
	}
	_, err := call(t, env, siteCreate, map[string]string{"domain": "a.example.com", "framework": "php", "php_version": "8.4"})
	if err == nil {
		t.Fatal("want error")
	}
	for _, p := range []string{
		filepath.Join(env.Cfg.SitesRoot, "a.example.com"),
		filepath.Join(env.Cfg.ProxySitesDir, "a.example.com.caddy"),
		filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, "8.4"), "web_a_example_com.conf"),
	} {
		if fsx.Exists(p) {
			t.Errorf("not rolled back: %s", p)
		}
	}
	var calls []string
	for _, c := range dr.Calls() {
		calls = append(calls, c.String())
	}
	joined := strings.Join(calls, "\n")
	wantTail := "frankenphp validate --config /etc/panel-proxy/Caddyfile --adapter caddyfile\n" +
		"systemctl reload panel-proxy.service\nsystemctl reload php8.4-fpm.service\nuserdel web_a_example_com"
	if !strings.HasSuffix(joined, wantTail) {
		t.Errorf("rollback calls:\n%s", joined)
	}
}

func TestSiteDelete(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "del.example.com", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "del.example.com", "confirm": "nope"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("want invalid confirm, got %v", err)
	}
	out, err := call(t, env, siteDelete, map[string]string{"domain": "del.example.com", "confirm": "del.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	if fsx.Exists(filepath.Join(env.Cfg.SitesRoot, "del.example.com")) || fsx.Exists(filepath.Join(env.Cfg.ProxySitesDir, "del.example.com.caddy")) {
		t.Fatal("site not removed")
	}
	if !fsx.Exists(out.(map[string]string)["trashed_to"]) {
		t.Fatal("home not moved to trash")
	}
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "del.example.com", "confirm": "del.example.com"}); code(err) != protocol.CodeNotFound {
		t.Fatalf("want not_found, got %v", err)
	}
}

func TestServiceParams(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, serviceRestart, map[string]string{"service": "php-fpm", "version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	calls := dr.Calls()
	if calls[0].String() != "php-fpm8.3 -t" || calls[1].String() != "systemctl restart php8.3-fpm.service" {
		t.Fatalf("calls: %v", calls)
	}
	bad := []map[string]string{
		{"service": "sshd"},
		{"service": "php-fpm", "version": "8.3; reboot"},
		{"service": "proxy", "version": "8.3"},
	}
	for _, b := range bad {
		if _, err := call(t, env, serviceRestart, b); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: got %v", b, err)
		}
	}
	if _, err := call(t, env, serviceRestart, map[string]string{"service": "mariadb"}); code(err) != protocol.CodeNotFound {
		t.Errorf("mariadb not installed: got %v", err)
	}
}
