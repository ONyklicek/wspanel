package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestDeployRotatesWorkersWithoutMixingCode(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "rot.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	st, l, _ := loadSite(env, "rot.example.com")
	_ = st
	home := l.Home
	// Release 1 runs both workers.
	for _, rel := range []string{"r1", "r2"} {
		if err := os.MkdirAll(filepath.Join(home, "releases", rel), 0o755); err != nil {
			t.Fatal(err)
		}
		_ = os.WriteFile(filepath.Join(home, "releases", rel, "artisan"), nil, 0o644)
	}
	_ = os.Remove(filepath.Join(home, "current"))
	_ = os.Symlink("releases/r1", filepath.Join(home, "current"))
	for _, w := range []string{"queue", "scheduler"} {
		if _, err := call(t, env, workerCreate, map[string]string{"domain": "rot.example.com", "worker": w}); err != nil {
			t.Fatal(err)
		}
	}
	// Deploy: current -> r2; systemd still runs the r1 instances.
	_ = os.Remove(filepath.Join(home, "current"))
	_ = os.Symlink("releases/r2", filepath.Join(home, "current"))
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "systemctl" && len(c.Args) > 0 && c.Args[0] == "list-units" {
			pattern := c.Args[len(c.Args)-1]
			typ := strings.TrimSuffix(strings.TrimPrefix(pattern, "wspanel-web_rot_example_com-"), "@*.service")
			return executor.Result{Stdout: "wspanel-web_rot_example_com-" + typ + "@r1.service loaded active running x\n"}, nil
		}
		return executor.Result{}, nil
	}
	before := len(dr.Calls())
	rotateWorkers(t.Context(), env, l, func(string, ...any) {})
	var seq []string
	for _, c := range dr.Calls()[before:] {
		if c.Name == "systemctl" && c.Args[0] != "list-units" && c.Args[0] != "reset-failed" {
			seq = append(seq, c.String())
		}
	}
	got := strings.Join(seq, "\n")
	queueNew := strings.Index(got, "enable --now wspanel-web_rot_example_com-queue@r2.service")
	queueOld := strings.Index(got, "stop --no-block wspanel-web_rot_example_com-queue@r1.service")
	if queueNew < 0 || queueOld < 0 || queueNew > queueOld {
		t.Errorf("queue: the new release's worker starts first, the old one finishes its job without blocking:\n%s", got)
	}
	schedOld := strings.Index(got, "systemctl stop wspanel-web_rot_example_com-scheduler@r1.service")
	schedNew := strings.Index(got, "enable --now wspanel-web_rot_example_com-scheduler@r2.service")
	if schedOld < 0 || schedNew < 0 || schedOld > schedNew {
		t.Errorf("scheduler: never two at once (old stops, then new starts):\n%s", got)
	}
	unit, _ := os.ReadFile(filepath.Join(env.Cfg.SystemdDir, "wspanel-web_rot_example_com-queue@.service"))
	if !strings.Contains(string(unit), "WorkingDirectory="+home+"/releases/%i") {
		t.Errorf("an instance runs from its release, so an old one keeps its code:\n%s", unit)
	}
	if !releaseInUse(t.Context(), env, l, "r1") {
		t.Error("a release an old worker still runs from must not be pruned")
	}
}

func TestMaintenancePageWithBypass(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "m.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	_, l, _ := loadSite(env, "m.example.com")
	var log strings.Builder
	d := &deployment{env: env, l: l, cfg: deployConfig{Migrate: true, Maintenance: true}, log: &log}
	d.maintenanceForMigrations(t.Context())
	st, _, _ := loadSite(env, "m.example.com")
	edge, _ := os.ReadFile(filepath.Join(env.Cfg.ProxySitesDir, "m.example.com.caddy"))
	if len(st.Maintenance) != 24 || !strings.Contains(string(edge), "respond @wsp_maint") || !strings.Contains(string(edge), "wsp_maintenance="+st.Maintenance) {
		t.Fatalf("maintenance page with its bypass:\n%s", edge)
	}
	if !strings.Contains(log.String(), "?wsp_maintenance="+st.Maintenance) {
		t.Error("the deploy log gives the operator the bypass link")
	}
	d.setMaintenance(t.Context(), false)
	edge, _ = os.ReadFile(filepath.Join(env.Cfg.ProxySitesDir, "m.example.com.caddy"))
	if strings.Contains(string(edge), "wsp_maint") {
		t.Error("the page must come down")
	}
}
