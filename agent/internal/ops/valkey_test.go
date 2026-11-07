package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fsx"
)

func TestValkeyPerSite(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "kv.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, valkeySet, map[string]any{"domain": "kv.example.com", "enabled": true, "maxmemory_mb": 64})
	if err != nil {
		t.Fatal(err)
	}
	socket := "/run/wspanel-valkey/web_kv_example_com/valkey.sock"
	if out.(map[string]any)["socket"] != socket {
		t.Fatalf("output %v", out)
	}
	conf, _ := os.ReadFile(filepath.Join(env.Cfg.SiteConfigDir, "web_kv_example_com.valkey.conf"))
	for _, want := range []string{"port 0\n", "unixsocket " + socket, "unixsocketperm 700", "maxmemory 64mb", "maxmemory-policy allkeys-lru", `save ""`} {
		if !strings.Contains(string(conf), want) {
			t.Errorf("config missing %q:\n%s", want, conf)
		}
	}
	unit, _ := os.ReadFile(filepath.Join(env.Cfg.SystemdDir, "wspanel-web_kv_example_com-valkey.service"))
	for _, want := range []string{"User=web_kv_example_com", "RuntimeDirectory=wspanel-valkey/web_kv_example_com", "RuntimeDirectoryMode=0700", "MemoryMax=160M", "ProtectSystem=strict"} {
		if !strings.Contains(string(unit), want) {
			t.Errorf("unit missing %q:\n%s", want, unit)
		}
	}
	if !hasCall(dr, "systemd-analyze verify") || !hasCall(dr, "systemctl restart wspanel-web_kv_example_com-valkey.service") {
		t.Errorf("unit must be verified and started:\n%s", strings.Join(callLines(dr), "\n"))
	}
	var env2 string
	for _, c := range dr.Calls() {
		if c.Name == "setpriv" && strings.Contains(c.String(), "tee") {
			env2 = c.Stdin
		}
	}
	if !strings.Contains(env2, `REDIS_HOST="`+socket+`"`) || !strings.Contains(env2, `REDIS_PORT="0"`) {
		t.Errorf(".env must point to the site's socket:\n%s", env2)
	}

	if _, err := call(t, env, valkeySet, map[string]any{"domain": "kv.example.com", "enabled": false}); err != nil {
		t.Fatal(err)
	}
	if fsx.Exists(filepath.Join(env.Cfg.SiteConfigDir, "web_kv_example_com.valkey.conf")) {
		t.Error("config must go with the server")
	}
	if st, _, _ := loadSite(env, "kv.example.com"); st.Valkey != nil {
		t.Error("state must forget it")
	}
}

func TestValkeyRejectsBadSettings(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "kv.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	for _, p := range []map[string]any{
		{"domain": "kv.example.com", "enabled": true, "maxmemory_mb": 2},
		{"domain": "kv.example.com", "enabled": true, "maxmemory_mb": 64, "policy": "allkeys-random\nrequirepass x"},
	} {
		if _, err := call(t, env, valkeySet, p); code(err) != "invalid_params" {
			t.Errorf("%v: %v", p, err)
		}
	}
	if _, err := call(t, env, valkeyFlush, map[string]string{"domain": "kv.example.com"}); code(err) != "conflict" {
		t.Errorf("flush without Valkey: %v", err)
	}
}

func TestValkeyStatusReadsInfo(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "kv.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, valkeySet, map[string]any{"domain": "kv.example.com", "enabled": true, "maxmemory_mb": 64, "policy": "noeviction", "persist": true}); err != nil {
		t.Fatal(err)
	}
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(c.String(), "valkey-cli -s") && strings.HasSuffix(c.String(), "INFO") {
			return executor.Result{Stdout: "# Memory\r\nused_memory:1048576\r\nmaxmemory:67108864\r\n# Keyspace\r\ndb0:keys=12,expires=0\r\ndb1:keys=3,expires=1\r\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, valkeyStatus, map[string]string{"domain": "kv.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	if m["used_memory"] != int64(1048576) || m["keys"] != int64(15) || m["policy"] != "noeviction" || m["persist"] != true {
		t.Errorf("status %v", m)
	}
}

func TestValkeyFlushRunsAsTheSiteUser(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "kv.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, valkeySet, map[string]any{"domain": "kv.example.com", "enabled": true, "maxmemory_mb": 64}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, valkeyFlush, map[string]string{"domain": "kv.example.com"}); err != nil {
		t.Fatal(err)
	}
	if !hasCall(dr, "setpriv --reuid=web_kv_example_com") || hasCall(dr, "valkey-cli") {
		t.Errorf("flush must go through setpriv as the site user:\n%s", strings.Join(callLines(dr), "\n"))
	}
	found := false
	for _, c := range callLines(dr) {
		if strings.HasPrefix(c, "setpriv --reuid=web_kv_example_com") && strings.HasSuffix(c, "FLUSHALL") {
			found = true
		}
	}
	if !found {
		t.Error("no FLUSHALL as the site user")
	}
}

func TestSiteProcessesCanBeControlled(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "kv.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, valkeySet, map[string]any{"domain": "kv.example.com", "enabled": true, "maxmemory_mb": 64}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, serviceStop, map[string]string{"service": "site-valkey", "domain": "kv.example.com"}); err != nil {
		t.Fatal(err)
	}
	if !hasCall(dr, "systemctl stop wspanel-web_kv_example_com-valkey.service") {
		t.Errorf("site Valkey stop:\n%s", strings.Join(callLines(dr), "\n"))
	}
	if _, err := call(t, env, serviceStop, map[string]string{"service": "task", "domain": "kv.example.com", "worker": "7"}); code(err) != "not_found" {
		t.Errorf("unknown task: %v", err)
	}
	if _, err := call(t, env, serviceStop, map[string]string{"service": "task", "domain": "kv.example.com", "worker": "../x"}); code(err) != "invalid_params" {
		t.Errorf("bad task id: %v", err)
	}
}
