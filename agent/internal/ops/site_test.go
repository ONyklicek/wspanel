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
	cfg.WebSitesDir = filepath.Join(root, "etc/panel-web/sites")
	cfg.StateDir = filepath.Join(root, "var/lib/panel-agent")
	cfg.SystemdDir = filepath.Join(root, "etc/systemd/system")
	cfg.ArtifactDir = filepath.Join(root, "var/lib/wspanel-artifacts")
	cfg.SiteConfigDir = filepath.Join(root, "etc/panel-sites")
	cfg.SiteRunDir = filepath.Join(root, "run/wspanel-sites")
	cfg.TmpfilesDir = filepath.Join(root, "etc/tmpfiles.d")
	cfg.DBInstancesRoot = filepath.Join(root, "var/lib/wspanel-db")
	cfg.DBInstancesRun = filepath.Join(root, "run/wspanel-db")
	cfg.DBInstancesConf = filepath.Join(root, "etc/wspanel/db")
	cfg.PostgresConfDir = filepath.Join(root, "etc/postgresql")
	cfg.NodeDir = filepath.Join(root, "opt/fnm/node-versions")
	cfg.AuthorizedKeysDir = filepath.Join(root, "etc/ssh/authorized_keys")
	cfg.ToolsDir = filepath.Join(root, "etc/panel-sites/bin")
	cfg.SenderLogins = filepath.Join(root, "etc/postfix/wspanel_sender_logins")
	cfg.LoginSenders = filepath.Join(root, "etc/postfix/wspanel_login_senders")
	cfg.SRSExclude = filepath.Join(root, "etc/postfix/wspanel_srs_exclude")
	cfg.RspamdRatelimit = filepath.Join(root, "etc/rspamd/local.d/ratelimit.conf")
	cfg.PowerDNSConf = filepath.Join(root, "etc/powerdns/pdns.d/wspanel-agent.conf")
	cfg.RspamdActions = filepath.Join(root, "etc/rspamd/local.d/wspanel-actions.inc")
	cfg.RspamdAntivirus = filepath.Join(root, "etc/rspamd/local.d/antivirus.conf")
	cfg.DKIMDir = filepath.Join(root, "etc/opendkim")
	cfg.ProxyCertDir = filepath.Join(root, "etc/panel-proxy/certs")
	cfg.ProxyStorage = filepath.Join(root, "var/lib/panel-proxy/caddy")
	cfg.PostfixSASL = filepath.Join(root, "etc/postfix/sasl_passwd")
	cfg.AutoUpgrades = filepath.Join(root, "etc/apt/apt.conf.d/20auto-upgrades")
	cfg.HostsFile = filepath.Join(root, "etc/hosts")
	cfg.Fstab = filepath.Join(root, "etc/fstab")
	cfg.SwapFile = filepath.Join(root, "swapfile")
	cfg.SysctlDir = filepath.Join(root, "etc/sysctl.d")
	cfg.CloudCfgDir = filepath.Join(root, "etc/cloud/cloud.cfg.d")
	// Ports below 1024 need root on the dev machine; the privileged range is a
	// production property (checked by checkPrivilegedPorts, skipped in dry-run).
	cfg.NodePortMin, cfg.NodePortMax = 30600, 30999
	for _, d := range []string{config.ForVersion(cfg.FPMPoolDir, "8.3"), config.ForVersion(cfg.FPMPoolDir, "8.4"), cfg.ProxySitesDir, cfg.WebSitesDir, cfg.SystemdDir, cfg.TmpfilesDir, filepath.Join(root, "etc/postfix"), cfg.DKIMDir, filepath.Dir(cfg.AutoUpgrades), cfg.SysctlDir} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	// Fake fnm installs: two 22.x (newest must win) and one 24.x.
	for _, v := range []string{"v22.9.0", "v22.12.0", "v24.1.0"} {
		bin := filepath.Join(cfg.NodeDir, v, "installation/bin")
		if err := os.MkdirAll(bin, 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(bin, "node"), nil, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	dr := &executor.DryRun{Responder: frankenVersion}
	return &Env{Cfg: cfg, Exec: dr, FS: &fsx.FS{DryRun: true}}, dr
}

// frankenVersion fakes `frankenphp version` (worker mode reads the PHP version from it).
func frankenVersion(c executor.Call) (executor.Result, error) {
	if c.Name == "frankenphp" && len(c.Args) > 0 && c.Args[0] == "version" {
		return executor.Result{Stdout: "FrankenPHP v1.13.5 PHP 8.5.12 Caddy v2.11.9"}, nil
	}
	return executor.Result{}, nil
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
	if out.(map[string]any)["system_user"] != "web_shop_example_com" {
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
	web, _ := os.ReadFile(filepath.Join(env.Cfg.WebSitesDir, "shop.example.com.caddy"))
	if !strings.Contains(string(web), "root * "+home+"/current/public") || !strings.Contains(string(web), "resolve_root_symlink") {
		t.Errorf("web config:\n%s", web)
	}
	edge, _ := os.ReadFile(filepath.Join(env.Cfg.ProxySitesDir, "shop.example.com.caddy"))
	if !strings.Contains(string(edge), "reverse_proxy unix//run/panel-web/web.sock") || strings.Contains(string(edge), "/srv") || strings.Contains(string(edge), home) {
		t.Errorf("edge must only forward, never serve files:\n%s", edge)
	}
	if !strings.Contains(string(pool), "proc_open") || !strings.Contains(string(pool), "putenv") {
		t.Error("pool must disable proc_open and putenv")
	}

	// Validation must precede every reload.
	var seq []string
	for _, c := range dr.Calls() {
		seq = append(seq, c.String())
	}
	joined := strings.Join(seq, "\n")
	iv := strings.Index(joined, "php-fpm8.3 -t")
	ir := strings.Index(joined, "systemctl reload php8.3-fpm.service")
	wv := strings.Index(joined, "frankenphp validate --config /etc/panel-web/Caddyfile")
	wr := strings.Index(joined, "systemctl reload panel-web.service")
	pv := strings.Index(joined, "frankenphp validate --config /etc/panel-proxy/Caddyfile")
	pr := strings.Index(joined, "systemctl reload panel-proxy.service")
	if iv < 0 || ir < iv || wv < ir || wr < wv || pv < wr || pr < pv {
		t.Errorf("bad order:\n%s", joined)
	}
	// The web user may read only public dirs; everything else is traverse-only.
	var readGrants []string
	for _, c := range dr.Calls() {
		if c.Name == "setfacl" {
			t.Fatalf("setfacl must never run as root: %s", c)
		}
		if c.Name == "setpriv" && strings.Contains(strings.Join(c.Args, " "), "/usr/bin/setfacl") {
			if c.Args[0] != "--reuid=web_shop_example_com" || c.Args[1] != "--regid=web_shop_example_com" {
				t.Fatalf("setfacl not run as the site user: %s", c)
			}
			if strings.Contains(strings.Join(c.Args, " "), ":rX") {
				readGrants = append(readGrants, c.Args[len(c.Args)-1])
			}
		}
	}
	want := []string{home + "/releases/initial/public", home + "/shared/storage/app/public"}
	if strings.Join(readGrants, ",") != strings.Join(want, ",") {
		t.Errorf("read ACLs on %v, want exactly %v", readGrants, want)
	}
	if strings.Contains(joined, "panel-proxy:") {
		t.Error("edge proxy user must not get any ACL on site files")
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
	if !strings.HasSuffix(out.(map[string]any)["env_file"].(string), "shared/.env.local") {
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
	for _, c := range dr.Calls() {
		if c.String() != "frankenphp version" { // read-only lookup of worker mode's PHP version
			t.Fatalf("no command may run on invalid input, got %v", dr.Calls())
		}
	}
}

func TestSiteCreateRollsBackOnInvalidProxyConfig(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "frankenphp" && c.Args[0] == "validate" && strings.Contains(c.Args[2], "panel-proxy") {
			return executor.Result{ExitCode: 1}, errors.New("broken")
		}
		return frankenVersion(c)
	}
	_, err := call(t, env, siteCreate, map[string]string{"domain": "a.example.com", "framework": "php", "php_version": "8.4"})
	if err == nil {
		t.Fatal("want error")
	}
	for _, p := range []string{
		filepath.Join(env.Cfg.SitesRoot, "a.example.com"),
		filepath.Join(env.Cfg.ProxySitesDir, "a.example.com.caddy"),
		filepath.Join(env.Cfg.WebSitesDir, "a.example.com.caddy"),
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
		"systemctl reload panel-proxy.service\nsystemctl reload panel-web.service\n" +
		"systemctl reload php8.4-fpm.service\nsaslpasswd2 -d -f /etc/sasldb2 -u localhost web_a_example_com\nuserdel web_a_example_com"
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
	if fsx.Exists(filepath.Join(env.Cfg.SitesRoot, "del.example.com")) || fsx.Exists(filepath.Join(env.Cfg.ProxySitesDir, "del.example.com.caddy")) ||
		fsx.Exists(filepath.Join(env.Cfg.WebSitesDir, "del.example.com.caddy")) || fsx.Exists(siteStatePath(env.Cfg, "del.example.com")) {
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
