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
	// A template written by an older agent (FrankenPHP's php-cli) is rewritten by the deploy.
	queueTpl := filepath.Join(env.Cfg.SystemdDir, "wspanel-web_rot_example_com-queue@.service")
	if err := os.WriteFile(queueTpl, []byte("[Service]\nExecStart=/usr/local/bin/frankenphp php-cli artisan queue:work\n"), 0o644); err != nil {
		t.Fatal(err)
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
	if strings.Contains(string(unit), "php-cli") || !strings.Contains(string(unit), "ExecStart=") {
		t.Errorf("the deploy rewrites the template:\n%s", unit)
	}
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

func TestNodeSwitchesSlotsWithoutAGap(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "n.example.com", "framework": "node", "mode": "node", "node_version": "22"}); err != nil {
		t.Fatal(err)
	}
	st0, l, _ := loadSite(env, "n.example.com")
	before := len(dr.Calls())
	d := &deployment{env: env, l: l, log: &strings.Builder{}}
	switched, err := d.blueGreenNode(t.Context())
	if err != nil || !switched {
		t.Fatalf("switch: %v %v", switched, err)
	}
	st, nl, _ := loadSite(env, "n.example.com")
	if st.Slot != "b" || st.PortB == 0 || st.PortB == st0.Port || nl.Port != st.PortB {
		t.Fatalf("state after the switch: %+v", st)
	}
	edge, _ := os.ReadFile(filepath.Join(env.Cfg.ProxySitesDir, "n.example.com.caddy"))
	if !strings.Contains(string(edge), "reverse_proxy 127.0.0.1:"+itoa(st.PortB)) {
		t.Errorf("the edge must send visitors to the new slot:\n%s", edge)
	}
	var seq []string
	for _, c := range dr.Calls()[before:] {
		if c.Name == "systemctl" {
			seq = append(seq, c.String())
		}
	}
	got := strings.Join(seq, "\n")
	start := strings.Index(got, "start wspanel-web_n_example_com-appb.service")
	reload := strings.Index(got, "reload panel-proxy")
	stopOld := strings.Index(got, "stop --no-block wspanel-web_n_example_com-app.service")
	if start < 0 || stopOld < 0 || start > stopOld || (reload >= 0 && reload > stopOld) {
		t.Errorf("new slot first, then the edge, then the old slot:\n%s", got)
	}
	// And back on the next one.
	d = &deployment{env: env, l: nl, log: &strings.Builder{}}
	if ok, err := d.blueGreenNode(t.Context()); err != nil || !ok {
		t.Fatal(err)
	}
	if st, _, _ := loadSite(env, "n.example.com"); st.Slot != "" {
		t.Errorf("slot %q", st.Slot)
	}
}

func TestServiceAppTargetsTheServingSlot(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "n2.example.com", "framework": "node", "mode": "node", "node_version": "22"}); err != nil {
		t.Fatal(err)
	}
	_, l, _ := loadSite(env, "n2.example.com")
	if ok, err := (&deployment{env: env, l: l, log: &strings.Builder{}}).blueGreenNode(t.Context()); err != nil || !ok {
		t.Fatal(err)
	}
	unit, err := serviceParams{Service: "app", Domain: "n2.example.com"}.unit(env.Cfg)
	if err != nil || unit != "wspanel-web_n2_example_com-appb.service" {
		t.Errorf("app control must reach the serving slot, got %q %v", unit, err)
	}
}
