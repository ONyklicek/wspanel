package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

func read(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func TestNodeVersions(t *testing.T) {
	env, _ := testEnv(t)
	vs := nodeVersions(env.Cfg)
	if !strings.Contains(vs["22"], "v22.12.0") || !strings.Contains(vs["24"], "v24.1.0") || len(vs) != 2 {
		t.Fatalf("versions: %v", vs)
	}
	if got := strings.Join(nodeMajors(env.Cfg), ","); got != "22,24" {
		t.Fatalf("majors: %s", got)
	}
}

func TestWorkerModeSite(t *testing.T) {
	env, dr := testEnv(t)
	// Worker mode needs a worker entry point: plain PHP is not allowed.
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "w.example.com", "framework": "php", "mode": "worker"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("php in worker mode: %v", err)
	}
	// The PHP version is FrankenPHP's, not selectable.
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "w.example.com", "framework": "laravel", "mode": "worker", "php_version": "8.3"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("explicit php_version in worker mode: %v", err)
	}
	out, err := call(t, env, siteCreate, map[string]string{"domain": "w.example.com", "framework": "laravel", "mode": "worker"})
	if err != nil {
		t.Fatal(err)
	}
	o := out.(map[string]any)
	if o["php_version"] != "8.5" || o["started"] != false {
		t.Fatalf("output: %v", o)
	}
	home := filepath.Join(env.Cfg.SitesRoot, "w.example.com")
	user := "web_w_example_com"
	inst := read(t, filepath.Join(env.Cfg.SiteConfigDir, user+".Caddyfile"))
	for _, want := range []string{
		"bind unix/" + env.Cfg.SiteRunDir + "/" + user + "/http.sock|0220",
		"root * " + home + "/current/public",
		"file " + home + "/current/public/frankenphp-worker.php", "try_files {path} frankenphp-worker.php", "env APP_BASE_PATH " + home + "/current", "env LARAVEL_OCTANE 1", "admin off",
	} {
		if !strings.Contains(inst, want) {
			t.Errorf("instance config missing %q:\n%s", want, inst)
		}
	}
	unit := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-app.service"))
	if !strings.Contains(unit, "User="+user) || !strings.Contains(unit, "/usr/local/bin/frankenphp run --config "+env.Cfg.SiteConfigDir+"/"+user+".Caddyfile") ||
		!strings.Contains(unit, "ReadWritePaths="+home+" "+env.Cfg.SiteRunDir+"/"+user) {
		t.Errorf("app unit:\n%s", unit)
	}
	if tf := read(t, filepath.Join(env.Cfg.TmpfilesDir, "wspanel-"+user+".conf")); tf != "d "+env.Cfg.SiteRunDir+"/"+user+" 2750 "+user+" panel-proxy -\n" {
		t.Errorf("tmpfiles: %q", tf)
	}
	edge := read(t, filepath.Join(env.Cfg.ProxySitesDir, "w.example.com.caddy"))
	if !strings.Contains(edge, "reverse_proxy unix/"+env.Cfg.SiteRunDir+"/"+user+"/http.sock") {
		t.Errorf("edge:\n%s", edge)
	}
	// No classic leftovers, and the web tier gets no access at all.
	if fsx.Exists(filepath.Join(env.Cfg.WebSitesDir, "w.example.com.caddy")) {
		t.Error("worker site must not have a web tier block")
	}
	for _, c := range dr.Calls() {
		if c.Name == "setfacl" || (c.Name == "php-fpm8.3" || c.Name == "php-fpm8.4") {
			t.Errorf("unexpected call for worker site: %s", c)
		}
	}
	// Workers of a worker-mode site use FrankenPHP's PHP.
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "w.example.com", "worker": "horizon"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-horizon.service")); !strings.Contains(u, "ExecStart=/usr/local/bin/frankenphp php-cli artisan horizon") {
		t.Errorf("worker unit:\n%s", u)
	}
	// Version is fixed in worker mode.
	if _, err := call(t, env, siteSetVersion, map[string]string{"domain": "w.example.com", "version": "8.4"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("set_version on worker: %v", err)
	}
	// Health in dry-run reports ok and the upstream.
	h, err := call(t, env, siteHealth, map[string]string{"domain": "w.example.com"})
	if err != nil || h.(map[string]any)["ok"] != true {
		t.Fatalf("health: %v %v", h, err)
	}
}

func TestNodeModeSite(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "n.example.com", "framework": "laravel", "mode": "node", "node_version": "22"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("laravel in node mode: %v", err)
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "n.example.com", "framework": "node", "mode": "node", "node_version": "18"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("missing node version: %v", err)
	}
	out, err := call(t, env, siteCreate, map[string]string{"domain": "n.example.com", "framework": "node", "mode": "node", "node_version": "22"})
	if err != nil {
		t.Fatal(err)
	}
	o := out.(map[string]any)
	port := o["port"].(int)
	if port < env.Cfg.NodePortMin || port > env.Cfg.NodePortMax || o["started"] != true {
		t.Fatalf("output: %v", o)
	}
	user := "web_n_example_com"
	unit := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-app.service"))
	if !strings.Contains(unit, "v22.12.0/installation/bin/node server.js") || !strings.Contains(unit, "Environment=HOST=127.0.0.1") {
		t.Errorf("node unit:\n%s", unit)
	}
	edge := read(t, filepath.Join(env.Cfg.ProxySitesDir, "n.example.com.caddy"))
	if !strings.Contains(edge, "reverse_proxy 127.0.0.1:") {
		t.Errorf("edge:\n%s", edge)
	}
	if !fsx.Exists(filepath.Join(env.Cfg.SitesRoot, "n.example.com/releases/initial/server.js")) {
		t.Error("placeholder server.js missing")
	}

	// A second node site gets a different port.
	out2, err := call(t, env, siteCreate, map[string]string{"domain": "n2.example.com", "framework": "nuxt", "mode": "node", "node_version": "24"})
	if err != nil {
		t.Fatal(err)
	}
	if out2.(map[string]any)["port"] == port || out2.(map[string]any)["started"] != false {
		t.Fatalf("second site: %v (first port %d)", out2, port)
	}

	// Node version switch rewrites the unit.
	if _, err := call(t, env, siteSetVersion, map[string]string{"domain": "n.example.com", "version": "24"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-app.service")); !strings.Contains(u, "v24.1.0/installation/bin/node") {
		t.Errorf("after set_version:\n%s", u)
	}
	// Node sites have no PHP workers.
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "n.example.com", "worker": "queue"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("worker on node site: %v", err)
	}
	if _, err := call(t, env, serviceRestart, map[string]string{"service": "app", "domain": "n.example.com"}); err != nil {
		t.Errorf("restart app: %v", err)
	}
}

func TestSwitchModes(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "s.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "s.example.com", "worker": "queue"}); err != nil {
		t.Fatal(err)
	}
	user := "web_s_example_com"
	pool83 := filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, "8.3"), user+".conf")
	pool84 := filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, "8.4"), user+".conf")
	webFile := filepath.Join(env.Cfg.WebSitesDir, "s.example.com.caddy")
	appUnit := filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-app.service")
	queue := filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-queue.service")

	// classic 8.3 -> 8.4
	if _, err := call(t, env, siteSetVersion, map[string]string{"domain": "s.example.com", "version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if fsx.Exists(pool83) || !fsx.Exists(pool84) || !strings.Contains(read(t, webFile), "php8.4-"+user+".sock") {
		t.Fatal("PHP version switch incomplete")
	}
	if !strings.Contains(read(t, queue), "/usr/bin/php8.4 artisan queue:work") {
		t.Error("worker not moved to PHP 8.4")
	}

	// classic -> worker: pool and web block gone, app unit + instance in place, worker uses FrankenPHP.
	dr.Calls()
	if _, err := call(t, env, siteSwitchMode, map[string]string{"domain": "s.example.com", "mode": "worker", "framework": "laravel"}); err != nil {
		t.Fatal(err)
	}
	if fsx.Exists(pool84) || fsx.Exists(webFile) || !fsx.Exists(appUnit) {
		t.Fatal("classic -> worker incomplete")
	}
	if !strings.Contains(read(t, queue), "frankenphp php-cli artisan queue:work") {
		t.Error("worker not moved to FrankenPHP")
	}
	var revoked bool
	for _, c := range dr.Calls() {
		if c.Name == "setfacl" && strings.Contains(strings.Join(c.Args, " "), "-x u:panel-web") {
			revoked = true
		}
	}
	if !revoked {
		t.Error("web tier ACLs not revoked after leaving classic mode")
	}

	// worker -> node keeps the shared app unit but rewrites it; PHP workers are removed.
	if _, err := call(t, env, siteSwitchMode, map[string]string{"domain": "s.example.com", "mode": "node", "framework": "nuxt", "node_version": "22"}); err != nil {
		t.Fatal(err)
	}
	if !fsx.Exists(appUnit) || !strings.Contains(read(t, appUnit), "node .output/server/index.mjs") {
		t.Fatalf("app unit after worker -> node:\n%s", read(t, appUnit))
	}
	if fsx.Exists(filepath.Join(env.Cfg.SiteConfigDir, user+".Caddyfile")) || fsx.Exists(queue) {
		t.Error("worker-mode leftovers not removed")
	}
	if _, err := call(t, env, siteSwitchMode, map[string]string{"domain": "s.example.com", "mode": "node", "framework": "nuxt", "node_version": "24"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("same mode: %v", err)
	}

	// node -> classic: app unit gone, pool + web block back.
	if _, err := call(t, env, siteSwitchMode, map[string]string{"domain": "s.example.com", "mode": "classic", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	if fsx.Exists(appUnit) || !fsx.Exists(pool83) || !fsx.Exists(webFile) {
		t.Fatal("node -> classic incomplete")
	}
	if e := read(t, filepath.Join(env.Cfg.ProxySitesDir, "s.example.com.caddy")); !strings.Contains(e, "reverse_proxy unix//run/panel-web/web.sock") {
		t.Errorf("edge after node -> classic:\n%s", e)
	}
}

func TestSiteUnitsUseExactNames(t *testing.T) {
	env, _ := testEnv(t)
	// web_a_com is a prefix of web_a_com-x_com: deleting a.com must not touch the other site.
	for _, d := range []string{"a.com", "a.com-x.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "laravel", "php_version": "8.4"}); err != nil {
			t.Fatal(err)
		}
		if _, err := call(t, env, workerCreate, map[string]string{"domain": d, "worker": "queue"}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "a.com", "confirm": "a.com"}); err != nil {
		t.Fatal(err)
	}
	if !fsx.Exists(filepath.Join(env.Cfg.SystemdDir, "wspanel-web_a_com-x_com-queue.service")) {
		t.Fatal("deleting a.com removed a worker of a.com-x.com")
	}
}

func TestDeleteWorkerSiteCleansUp(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "d.example.com", "framework": "symfony", "mode": "worker"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "d.example.com", "confirm": "d.example.com"}); err != nil {
		t.Fatal(err)
	}
	user := "web_d_example_com"
	for _, p := range []string{
		filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-app.service"),
		filepath.Join(env.Cfg.SiteConfigDir, user+".Caddyfile"),
		filepath.Join(env.Cfg.TmpfilesDir, "wspanel-"+user+".conf"),
		filepath.Join(env.Cfg.ProxySitesDir, "d.example.com.caddy"),
	} {
		if fsx.Exists(p) {
			t.Errorf("left behind: %s", p)
		}
	}
}
