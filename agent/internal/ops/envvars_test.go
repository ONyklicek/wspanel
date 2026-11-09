package ops

import (
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestEnvVarsAreSetSecretsKeptAndThePanelsKeysRefused(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "ev.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	file := "APP_NAME=Shop\nDB_PASSWORD=panel-made\nSTRIPE_SECRET=sk_live_1\n# a comment\nOLD=1\n"
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(c.String(), "/usr/bin/cat") {
			return executor.Result{Stdout: file}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, envVars, map[string]any{"domain": "ev.example.com", "secrets": []string{"STRIPE_SECRET"}})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	vars := m["vars"].(map[string]any)
	if vars["APP_NAME"] != "Shop" || vars["STRIPE_SECRET"].(map[string]bool)["set"] != true {
		t.Errorf("vars: %v", vars)
	}
	if _, ok := vars["DB_PASSWORD"]; ok || !strings.Contains(strings.Join(m["managed"].([]string), ","), "DB_PASSWORD") {
		t.Errorf("the panel's keys are named, never shown: %v %v", vars, m["managed"])
	}
	for _, bad := range []map[string]any{
		{"vars": map[string]string{"DB_PASSWORD": "x"}}, {"vars": map[string]string{"bad-key": "x"}}, {"vars": map[string]string{"A": "a\nB=b"}},
		{"unset": []string{"DB_PASSWORD"}}, {}, {"vars": map[string]string{"A": "1"}, "unset": []string{"A"}},
	} {
		bad["domain"] = "ev.example.com"
		if _, err := call(t, env, envVarsSet, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, envVarsSet, map[string]any{"domain": "ev.example.com", "vars": map[string]string{"APP_NAME": `My "Shop" $1`, "FEATURE": "on"},
		"secrets": map[string]string{"STRIPE_SECRET": "sk_live_2"}, "unset": []string{"OLD"}}); err != nil {
		t.Fatal(err)
	}
	written := lastEnvWrite(dr)
	v := parseDotenv(written)
	if v["APP_NAME"] != `My "Shop" $1` || v["FEATURE"] != "on" || v["STRIPE_SECRET"] != "sk_live_2" || v["DB_PASSWORD"] != "panel-made" || strings.Contains(written, "OLD=") || !strings.Contains(written, "# a comment") {
		t.Errorf("written:\n%s", written)
	}
	st, _, _ := loadSite(env, "ev.example.com")
	if strings.Join(st.EnvVars, ",") != "APP_NAME,FEATURE,STRIPE_SECRET" {
		t.Errorf("remembered: %v", st.EnvVars)
	}
	// A deploy's env file does not replace what was set here.
	l, _ := layout(env.Cfg, st)
	if !panelManagedKeys(env, l, st, v)["FEATURE"] {
		t.Error("a key set in Prostředí is kept on deploy")
	}
}
