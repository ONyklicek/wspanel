package ops

import (
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestPanelUpgradeRunsInAUnitOfItsOwn(t *testing.T) {
	env, dr := testEnv(t)
	state := "inactive"
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch {
		case strings.HasPrefix(c.String(), "systemctl show"):
			return executor.Result{Stdout: "ActiveState=" + state + "\nSubState=start\nResult=success\nInvocationID=0123456789abcdef0123456789abcdef\n"}, nil
		case c.Name == "journalctl":
			return executor.Result{Stdout: "WSPANEL-UPGRADE start 1.2.0\n==> Done.\n"}, nil
		}
		return executor.Result{}, nil
	}
	for _, bad := range []string{"", "latest", "1.2", "1.2.0; reboot", "--help"} {
		if _, err := call(t, env, panelUpgrade, map[string]string{"version": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, panelUpgrade, map[string]string{"version": "v1.2.0"}); err != nil {
		t.Fatal(err)
	}
	last := dr.Calls()[len(dr.Calls())-1]
	if last.Name != "systemd-run" || !strings.Contains(last.String(), "--unit=wspanel-upgrade.service") || !strings.HasSuffix(last.String(), "-- /usr/lib/wspanel/panel-upgrade.sh 1.2.0") {
		t.Errorf("start: %v", last)
	}
	state = "activating"
	if _, err := call(t, env, panelUpgrade, map[string]string{"version": "1.2.1"}); code(err) != "conflict" {
		t.Errorf("a second upgrade while one runs: %v", err)
	}
	out, err := call(t, env, panelUpgradeStatus, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	if m := out.(map[string]any); m["state"] != "running" || m["version"] != "1.2.0" || len(m["log"].([]string)) != 2 {
		t.Errorf("status: %v", m)
	}
	state = "failed"
	if out, _ := call(t, env, panelUpgradeStatus, map[string]any{}); out.(map[string]any)["state"] != "failed" {
		t.Errorf("failed: %v", out)
	}
}
