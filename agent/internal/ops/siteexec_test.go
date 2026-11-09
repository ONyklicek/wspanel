package ops

import (
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestQuickCommandsRunInTheSandboxAndTheirOutputIsFollowed(t *testing.T) {
	env, dr := testEnv(t)
	env.Cfg.DeployLogDir = filepath.Join(t.TempDir(), "deploys")
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "qc.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, siteQuickCommands, map[string]any{"domain": "qc.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	ids := []string{}
	for _, c := range out.(map[string]any)["commands"].([]map[string]string) {
		ids = append(ids, c["id"])
	}
	all := strings.Join(ids, ",")
	for _, want := range []string{"artisan-migrate", "composer-install", "npm-build"} {
		if !strings.Contains(all, want) {
			t.Errorf("laravel lacks %s: %s", want, all)
		}
	}
	if strings.Contains(all, "manage-migrate") {
		t.Error("Django's commands are not Laravel's")
	}
	for _, bad := range []map[string]any{{"command": "rm-rf"}, {}, {"command": "npm-build", "custom": "ls"}, {"custom": strings.Repeat("x", 4001)}} {
		bad["domain"] = "qc.example.com"
		if _, err := call(t, env, siteExec, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	started, err := call(t, env, siteExec, map[string]any{"domain": "qc.example.com", "command": "artisan-migrate"})
	if err != nil {
		t.Fatal(err)
	}
	run := started.(map[string]any)["run"].(string)
	var o map[string]any
	for i := 0; i < 50; i++ {
		res, err := call(t, env, siteExecOutput, map[string]any{"domain": "qc.example.com", "run": run})
		if err != nil {
			t.Fatal(err)
		}
		if o = res.(map[string]any); o["done"] == true {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if o["done"] != true || !strings.Contains(o["output"].(string), "$ php artisan migrate --force") {
		t.Errorf("output: %v", o)
	}
	var sysrun string
	for _, c := range dr.Calls() {
		if c.Name == "systemd-run" && strings.Contains(c.String(), "artisan") {
			sysrun = c.String()
		}
	}
	if !strings.Contains(sysrun, "--uid=web_qc_example_com") || !strings.Contains(sysrun, "ProtectSystem=strict") || !strings.Contains(sysrun, "--working-directory="+filepath.Join(env.Cfg.SitesRoot, "qc.example.com")) {
		t.Errorf("sandbox: %s", sysrun)
	}
	if _, err := call(t, env, siteExecOutput, map[string]any{"domain": "qc.example.com", "run": "../../x"}); code(err) != "invalid_params" {
		t.Errorf("a bad run id: %v", err)
	}
}
