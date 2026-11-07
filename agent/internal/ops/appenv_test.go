package ops

import (
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
