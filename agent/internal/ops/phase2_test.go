package ops

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"wspanel/agent/internal/protocol"
)

func TestDisableFunctionsInSyncWithPanelPool(t *testing.T) {
	b, err := os.ReadFile("../../../provision/files/panel-pool.conf")
	if err != nil {
		t.Fatal(err)
	}
	m := regexp.MustCompile(`(?m)^php_admin_value\[disable_functions\] = (.+)$`).FindSubmatch(b)
	if m == nil || string(m[1]) != DisableFunctions {
		t.Fatalf("panel-pool.conf disable_functions = %q, want %q", m, DisableFunctions)
	}
}

func TestWorkerLifecycle(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	// Not available for the framework / unknown type.
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "app.example.com", "worker": "messenger"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("messenger on laravel: %v", err)
	}
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "app.example.com", "worker": "bash"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("unknown worker: %v", err)
	}

	before := len(dr.Calls())
	out, err := call(t, env, workerCreate, map[string]string{"domain": "app.example.com", "worker": "horizon"})
	if err != nil {
		t.Fatal(err)
	}
	if out.(map[string]any)["started"] != false {
		t.Error("must not start before the first deploy (no artisan)")
	}
	unitPath := filepath.Join(env.Cfg.SystemdDir, "wspanel-web_app_example_com-horizon.service")
	unit, err := os.ReadFile(unitPath)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"User=web_app_example_com", "ExecStart=/usr/bin/php8.3 artisan horizon",
		"WorkingDirectory=" + env.Cfg.SitesRoot + "/app.example.com/current",
		"ProtectSystem=strict", "NoNewPrivileges=yes", "ReadWritePaths=" + env.Cfg.SitesRoot + "/app.example.com\n",
	} {
		if !strings.Contains(string(unit), want) {
			t.Errorf("unit missing %q", want)
		}
	}
	var seq []string
	for _, c := range dr.Calls()[before:] {
		seq = append(seq, c.String())
	}
	if !strings.HasPrefix(seq[0], "systemd-analyze verify ") || seq[1] != "systemctl daemon-reload" || seq[2] != "systemctl enable wspanel-web_app_example_com-horizon.service" {
		t.Errorf("calls: %v", seq)
	}

	// Started when the release has artisan.
	if err := os.WriteFile(filepath.Join(env.Cfg.SitesRoot, "app.example.com/releases/initial/artisan"), nil, 0o644); err != nil {
		t.Fatal(err)
	}
	out, err = call(t, env, workerCreate, map[string]string{"domain": "app.example.com", "worker": "queue"})
	if err != nil || out.(map[string]any)["started"] != true {
		t.Fatalf("queue: %v %v", out, err)
	}

	// Controlled through service.*.
	if _, err := call(t, env, serviceRestart, map[string]string{"service": "worker", "domain": "app.example.com", "worker": "horizon"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, serviceRestart, map[string]string{"service": "worker", "domain": "app.example.com", "worker": "scheduler"}); code(err) != protocol.CodeNotFound {
		t.Fatalf("missing worker: %v", err)
	}

	// Deleting the site removes its workers.
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "app.example.com", "confirm": "app.example.com"}); err != nil {
		t.Fatal(err)
	}
	if left := siteWorkerUnits(env.Cfg, "web_app_example_com"); len(left) != 0 {
		t.Fatalf("workers left: %v", left)
	}
}

func TestSymfonyWorkerAndInferredState(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "sf.example.com", "framework": "symfony", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	// Phase 1 sites have no state file: framework/version are inferred from disk.
	if err := os.Remove(siteStatePath(env.Cfg, "sf.example.com")); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "sf.example.com", "worker": "messenger"}); err != nil {
		t.Fatal(err)
	}
	unit, _ := os.ReadFile(filepath.Join(env.Cfg.SystemdDir, "wspanel-web_sf_example_com-messenger.service"))
	if !strings.Contains(string(unit), "ExecStart=/usr/bin/php8.4 bin/console messenger:consume async") {
		t.Errorf("unit:\n%s", unit)
	}
}

func TestPanelUnitsCannotBeStopped(t *testing.T) {
	env, _ := testEnv(t)
	for _, p := range []map[string]string{{"service": "proxy"}, {"service": "web"}, {"service": "php-fpm", "version": "8.4"}} {
		if _, err := call(t, env, serviceStop, p); code(err) != protocol.CodeForbidden {
			t.Errorf("stop %v: %v", p, err)
		}
	}
	if _, err := call(t, env, serviceStop, map[string]string{"service": "php-fpm", "version": "8.3"}); err != nil {
		t.Errorf("stopping a non-panel FPM must work: %v", err)
	}
	if _, err := call(t, env, serviceReset, map[string]string{"service": "valkey"}); err != nil {
		t.Fatal(err)
	}
}

func TestFirewall(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, fwAllow, map[string]any{"port": 8080, "proto": "tcp"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, fwAllow, map[string]any{"port": 5432, "proto": "tcp", "from": "10.0.0.7/24"}); err != nil {
		t.Fatal(err)
	}
	calls := dr.Calls()
	if calls[0].String() != "ufw allow 8080/tcp comment wspanel" ||
		calls[1].String() != "ufw allow proto tcp from 10.0.0.0/24 to any port 5432 comment wspanel" {
		t.Fatalf("calls: %v", calls)
	}
	bad := []map[string]any{
		{"port": 0, "proto": "tcp"}, {"port": 70000, "proto": "tcp"}, {"port": 80, "proto": "icmp"},
		{"port": 8080, "proto": "tcp", "from": "any; reboot"}, {"port": "8080", "proto": "tcp"},
	}
	for _, b := range bad {
		if _, err := call(t, env, fwAllow, b); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: %v", b, err)
		}
	}
	// Lockout protection.
	if _, err := call(t, env, fwDeny, map[string]any{"port": 22, "proto": "tcp"}); code(err) != protocol.CodeForbidden {
		t.Errorf("deny 22: %v", err)
	}
	if _, err := call(t, env, fwDelete, map[string]any{"action": "allow", "port": 443, "proto": "tcp"}); code(err) != protocol.CodeForbidden {
		t.Errorf("delete allow 443: %v", err)
	}
	if _, err := call(t, env, fwDelete, map[string]any{"action": "deny", "port": 22, "proto": "tcp"}); err != nil {
		t.Errorf("removing a deny on 22 is safe: %v", err)
	}
}

func TestParseUFWStatus(t *testing.T) {
	out := `Status: active

To                         Action      From
--                         ------      ----
22/tcp                     ALLOW IN    Anywhere
8080/tcp                   ALLOW IN    Anywhere                   # wspanel
5432/tcp                   ALLOW IN    10.0.0.0/24                # wspanel
443                        ALLOW IN    Anywhere
22/tcp (v6)                ALLOW IN    Anywhere (v6)
8080/tcp (v6)              ALLOW IN    Anywhere (v6)              # wspanel
`
	active, rules := parseUFWStatus(out)
	if !active || len(rules) != 4 {
		t.Fatalf("active=%v rules=%+v", active, rules)
	}
	r := rules[1]
	if r.Port != 8080 || r.Proto != "tcp" || !r.Managed || !r.IPv4 || !r.IPv6 || r.From != "Anywhere" {
		t.Errorf("8080: %+v", r)
	}
	if rules[2].From != "10.0.0.0/24" || rules[3].Port != 443 || rules[3].Proto != "" || rules[0].Managed {
		t.Errorf("rules: %+v", rules)
	}
	if active, _ := parseUFWStatus("Status: inactive\n"); active {
		t.Error("inactive")
	}
}
