package ops

import (
	"context"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

// lastEnvWrite is what the agent last wrote into a site's env file (tee's stdin).
func lastEnvWrite(dr *executor.DryRun) string {
	out := ""
	for _, c := range dr.Calls() {
		if c.Name == "setpriv" && strings.Contains(c.String(), "/usr/bin/tee") {
			out = c.Stdin
		}
	}
	return out
}

func TestReverbConfiguresTheApp(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "rt.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, workerCreate, map[string]any{"domain": "rt.example.com", "worker": "reverb", "public_port": 8443})
	if err != nil {
		t.Fatal(err)
	}
	port := itoa(out.(map[string]any)["port"].(int))
	e := lastEnvWrite(dr)
	for _, want := range []string{
		`BROADCAST_CONNECTION="reverb"`, `REVERB_HOST="127.0.0.1"`, `REVERB_PORT="` + port + `"`, `REVERB_SCHEME="http"`,
		`REVERB_SERVER_HOST="127.0.0.1"`, `REVERB_SERVER_PORT="` + port + `"`,
		`VITE_REVERB_HOST="rt.example.com"`, `VITE_REVERB_PORT="8443"`, `VITE_REVERB_SCHEME="https"`, `REVERB_APP_ID="`, `REVERB_APP_SECRET="`,
	} {
		if !strings.Contains(e, want) {
			t.Errorf(".env missing %s:\n%s", want, e)
		}
	}
	key := parseDotenv(e)["REVERB_APP_KEY"]
	if len(key) != 20 || parseDotenv(e)["VITE_REVERB_APP_KEY"] != key {
		t.Errorf("app key %q must reach the frontend too", key)
	}
}

func TestReverbKeepsExistingAppKeys(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "rk.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(c.String(), "/usr/bin/cat") {
			return executor.Result{Stdout: "REVERB_APP_ID=111\nREVERB_APP_KEY=mykey\nREVERB_APP_SECRET=mysecret\n"}, nil
		}
		return executor.Result{}, nil
	}
	if _, err := call(t, env, workerCreate, map[string]any{"domain": "rk.example.com", "worker": "reverb"}); err != nil {
		t.Fatal(err)
	}
	v := parseDotenv(lastEnvWrite(dr))
	if v["REVERB_APP_KEY"] != "mykey" || v["REVERB_APP_SECRET"] != "mysecret" || v["REVERB_APP_ID"] != "111" || v["VITE_REVERB_PORT"] != "443" {
		t.Errorf("existing keys must be kept: %v", v)
	}
}

func TestValkeyUseSetsLaravelDrivers(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "u.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, valkeySet, map[string]any{"domain": "u.example.com", "enabled": true, "maxmemory_mb": 64, "use": []string{"cache", "queue"}}); err != nil {
		t.Fatal(err)
	}
	v := parseDotenv(lastEnvWrite(dr))
	if v["CACHE_STORE"] != "redis" || v["QUEUE_CONNECTION"] != "redis" || v["SESSION_DRIVER"] != "database" {
		t.Errorf("drivers: %v", v)
	}
	if _, err := call(t, env, valkeySet, map[string]any{"domain": "u.example.com", "enabled": true, "maxmemory_mb": 64, "use": []string{"everything"}}); code(err) != "invalid_params" {
		t.Errorf("unknown use: %v", err)
	}
	if _, err := call(t, env, workerCreate, map[string]any{"domain": "u.example.com", "worker": "horizon"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, valkeySet, map[string]any{"domain": "u.example.com", "enabled": false}); code(err) != "conflict" {
		t.Errorf("Valkey must not go while Horizon keeps its data there: %v", err)
	}
}

func TestSymfonyQueueGoesToValkey(t *testing.T) {
	keys, v := valkeyUseEnv("symfony", []string{"queue"}, 601, "pw")
	if len(keys) != 1 || v["MESSENGER_TRANSPORT_DSN"] != "redis://:pw@127.0.0.1:601/messages" {
		t.Errorf("%v %v", keys, v)
	}
}

func TestReleaseEnvMergesButThePanelsKeysWin(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "gh.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, valkeySet, map[string]any{"domain": "gh.example.com", "enabled": true, "maxmemory_mb": 64, "use": []string{"cache"}}); err != nil {
		t.Fatal(err)
	}
	st, l, _ := loadSite(env, "gh.example.com")
	current := parseDotenv("DB_HOST=127.0.0.1\nDB_PASSWORD=panel\nREDIS_HOST=127.0.0.1\nAPP_KEY=base64:real\n")
	managed := panelManagedKeys(env, l, st, current)
	in := parseDotenv("APP_KEY=\nAPP_NAME=\"From GitHub\"\nSTRIPE_SECRET=sk_live\nDB_PASSWORD=from-github\nREDIS_HOST=10.0.0.5\nCACHE_STORE=file\nSESSION_DRIVER=cookie\nbad-key=x\n")
	keys, values, kept := envFromRelease(in, managed)
	if strings.Join(keys, ",") != "APP_NAME,SESSION_DRIVER,STRIPE_SECRET" || values["STRIPE_SECRET"] != "sk_live" {
		t.Errorf("taken: %v %v", keys, values)
	}
	if strings.Join(kept, ",") != "CACHE_STORE,DB_PASSWORD,REDIS_HOST" {
		t.Errorf("the panel's keys must win: kept %v", kept)
	}
}

func TestDeployCommandsAreConsoleCommandsOnly(t *testing.T) {
	ok := deployConfig{Repo: "https://github.com/a/b", Branch: "main", Keep: 5, Commands: []string{"install:permissions", "livewire:publish --assets", "erag:update-manifest"}}
	if err := ok.validate(); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{"migrate; rm -rf /", "tinker --execute=system('id')", "down && up", "x $(id)", "../evil"} {
		c := ok
		c.Commands = []string{bad}
		if err := c.validate(); err == nil {
			t.Errorf("%q must be refused", bad)
		}
	}
}

func TestEnvFromDeployLeavesOnlyReverbsListener(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "d.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	st, l, _ := loadSite(env, "d.example.com")
	st.Deploy = &deployConfig{EnvFromDeploy: true}
	managed := panelManagedKeys(env, l, st, parseDotenv("MAIL_HOST=127.0.0.1\nDB_PASSWORD=x\nREVERB_SERVER_PORT=601\n"))
	if len(managed) != 1 || !managed["REVERB_SERVER_PORT"] {
		t.Errorf("managed %v", managed)
	}
}

func TestAFirstDeployGetsTheAppSecretItCannotStartWithout(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "k.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	_, l, _ := loadSite(env, "k.example.com")
	d := &deployment{env: env, l: l, log: io.Discard}
	if err := d.ensureAppSecrets(t.Context()); err != nil {
		t.Fatal(err)
	}
	var written string
	for _, c := range dr.Calls() {
		if strings.Contains(c.String(), "/usr/bin/tee") && strings.Contains(c.String(), "/shared/..env") {
			written = c.Stdin
		}
	}
	if !regexp.MustCompile(`APP_KEY="base64:[A-Za-z0-9+/]{43}="`).MatchString(written) {
		t.Errorf("a new APP_KEY:\n%s", written)
	}

	// One that is there (the operator's, or the release's) stays.
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if strings.Contains(c.String(), "/usr/bin/cat") {
			return executor.Result{Stdout: "APP_KEY=base64:keep\n"}, nil
		}
		return executor.Result{}, nil
	}
	before := len(dr.Calls())
	_ = d.ensureAppSecrets(t.Context())
	for _, c := range dr.Calls()[before:] {
		if strings.Contains(c.String(), "/usr/bin/tee") {
			t.Errorf("an existing key is never replaced: %s", c)
		}
	}
}

func TestInertiaSSRWorker(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "ssr.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, workerCreate, map[string]any{"domain": "ssr.example.com", "worker": "ssr"})
	if err != nil {
		t.Fatal(err)
	}
	port := itoa(out.(map[string]any)["port"].(int))
	unit := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-web_ssr_example_com-ssr@.service"))
	for _, want := range []string{
		filepath.Join(env.Cfg.ToolsDir, "web_ssr_example_com", "node") + " bootstrap/ssr/ssr.js",
		"Environment=INERTIA_SSR_PORT=" + port, "Environment=NODE_ENV=production", "SocketBindAllow=tcp:" + port, "SocketBindDeny=any",
	} {
		if !strings.Contains(unit, want) {
			t.Errorf("unit lacks %q:\n%s", want, unit)
		}
	}
	if e := lastEnvWrite(dr); !strings.Contains(e, `INERTIA_SSR_URL="http://127.0.0.1:`+port+`"`) {
		t.Errorf(".env:\n%s", e)
	}
	// No route at the edge: only the app talks to it.
	if e := read(t, filepath.Join(env.Cfg.ProxySitesDir, "ssr.example.com.caddy")); strings.Contains(e, port) {
		t.Errorf("SSR exposed at the edge:\n%s", e)
	}
	if _, err := call(t, env, workerDelete, map[string]string{"domain": "ssr.example.com", "worker": "ssr"}); err != nil {
		t.Fatal(err)
	}
	if st, _, _ := loadSite(env, "ssr.example.com"); st.SSRPort != 0 {
		t.Error("SSR port not released")
	}
}

func TestSSRHintsReadTheApp(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "h.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	_, l, _ := loadSite(env, "h.example.com")
	if err := os.MkdirAll(filepath.Join(l.Home, "current"), 0o755); err != nil {
		t.Fatal(err)
	}
	files := map[string]string{
		"config/inertia.php":   "'url' => 'http://127.0.0.1:13714',",
		"resources/js/ssr.tsx": "createServer((page) => createInertiaApp({ page }))",
	}
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		for f, body := range files {
			if c.Name == "setpriv" && strings.HasSuffix(c.String(), "/current/"+f) {
				return executor.Result{Stdout: body}, nil
			}
		}
		return executor.Result{}, nil
	}
	h := ssrHints(context.Background(), env, l)
	if len(h) != 2 || !strings.HasPrefix(h[0], "config/inertia.php") || !strings.HasPrefix(h[1], "resources/js/ssr.tsx") {
		t.Fatalf("hints: %v", h)
	}
	files["config/inertia.php"] = "'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),"
	files["resources/js/ssr.tsx"] = "createServer(render, Number(process.env.INERTIA_SSR_PORT) || 13714)"
	if h := ssrHints(context.Background(), env, l); len(h) != 0 {
		t.Fatalf("hints for a ready app: %v", h)
	}
}
