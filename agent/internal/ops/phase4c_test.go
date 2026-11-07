package ops

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"wspanel/agent/internal/protocol"
)

func TestCronToCalendar(t *testing.T) {
	cases := map[string]string{
		"* * * * *":      "*-*-* *:*:00",
		"*/15 * * * *":   "*-*-* *:00,15,30,45:00",
		"0 3 * * *":      "*-*-* 03:00:00",
		"30 2 1 * *":     "*-*-01 02:30:00",
		"0 9-17/4 * * *": "*-*-* 09,13,17:00:00",
		"0 0 * * 1-5":    "Fri,Mon,Thu,Tue,Wed *-*-* 00:00:00",
		"0 0 * * 0,7":    "Sun *-*-* 00:00:00",
		"5 4 * 1,6 *":    "*-01,06-* 04:05:00",
	}
	for in, want := range cases {
		got, err := cronToCalendar(in)
		if err != nil || got != want {
			t.Errorf("%q: got %q %v, want %q", in, got, err, want)
		}
	}
	for _, bad := range []string{"* * * *", "60 * * * *", "* 24 * * *", "0 0 1 * 1", "a * * * *", "*/0 * * * *", "5-1 * * * *", "* * * * 8"} {
		if _, err := cronToCalendar(bad); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%q accepted", bad)
		}
	}
}

func TestCronTasks(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "cron.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	bad := []map[string]any{
		{"kind": "shell", "args": []string{"id"}, "schedule": "* * * * *"},
		{"kind": "artisan", "args": []string{"backup:run;", "id"}, "schedule": "* * * * *"},
		{"kind": "artisan", "args": []string{"x", "$(id)"}, "schedule": "* * * * *"},
		{"kind": "artisan", "args": []string{"x", "%h"}, "schedule": "* * * * *"}, // systemd specifier
		{"kind": "php", "args": []string{"../../etc/x.php"}, "schedule": "* * * * *"},
		{"kind": "console", "args": []string{"app:sync"}, "schedule": "* * * * *"}, // Laravel site
	}
	for _, b := range bad {
		b["domain"] = "cron.example.com"
		if _, err := call(t, env, cronAdd, b); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: %v", b, err)
		}
	}
	out, err := call(t, env, cronAdd, map[string]any{"domain": "cron.example.com", "kind": "artisan", "args": []string{"backup:run", "--only-db"}, "schedule": "0 3 * * *"})
	if err != nil {
		t.Fatal(err)
	}
	id := out.(map[string]any)["id"].(int)
	base := filepath.Join(env.Cfg.SystemdDir, "wspanel-web_cron_example_com-cron-1")
	svc, timer := read(t, base+".service"), read(t, base+".timer")
	for _, want := range []string{"Type=oneshot", "User=web_cron_example_com", "ExecStart=/usr/bin/php8.3 artisan backup:run --only-db", "ProtectSystem=strict", "TimeoutStartSec=1h"} {
		if !strings.Contains(svc, want) {
			t.Errorf("service missing %q:\n%s", want, svc)
		}
	}
	if strings.Contains(svc, "Restart=always") || !strings.Contains(timer, "OnCalendar=*-*-* 03:00:00") {
		t.Errorf("timer/service:\n%s\n%s", svc, timer)
	}
	var verified bool
	for _, c := range dr.Calls() {
		if c.String() == "systemd-analyze calendar *-*-* 03:00:00" {
			verified = true
		}
	}
	if !verified || id != 1 {
		t.Errorf("calendar not verified or id %d", id)
	}
	list, _ := call(t, env, cronList, map[string]string{"domain": "cron.example.com"})
	if e := list.([]cronEntry); len(e) != 1 || e[0].Schedule != "0 3 * * *" || e[0].Args[0] != "backup:run" {
		t.Errorf("list: %+v", list)
	}
	// Switch to worker mode: the task now runs on FrankenPHP's PHP.
	if _, err := call(t, env, siteSwitchMode, map[string]string{"domain": "cron.example.com", "mode": "worker", "framework": "laravel"}); err != nil {
		t.Fatal(err)
	}
	if s := read(t, base+".service"); !strings.Contains(s, "frankenphp php-cli artisan backup:run --only-db") {
		t.Errorf("task not moved to FrankenPHP:\n%s", s)
	}
	if _, err := call(t, env, cronRemove, map[string]any{"domain": "cron.example.com", "id": 1}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(base + ".timer"); !os.IsNotExist(err) {
		t.Error("timer not removed")
	}
}

func TestCronUnitsUseExactNames(t *testing.T) {
	env, _ := testEnv(t)
	// web_a_com vs web_a_com-cron-1_com (domain a.com-cron-1.com)
	for _, d := range []string{"a.com", "a.com-cron-1.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "laravel", "php_version": "8.4"}); err != nil {
			t.Fatal(err)
		}
		if _, err := call(t, env, cronAdd, map[string]any{"domain": d, "kind": "artisan", "args": []string{"inspire"}, "schedule": "* * * * *"}); err != nil {
			t.Fatal(err)
		}
	}
	if ids := siteCronIDs(env.Cfg, "web_a_com"); len(ids) != 1 {
		t.Fatalf("a.com sees tasks %v", ids)
	}
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "a.com", "confirm": "a.com"}); err != nil {
		t.Fatal(err)
	}
	if ids := siteCronIDs(env.Cfg, "web_a_com-cron-1_com"); len(ids) != 1 {
		t.Fatal("deleting a.com removed a task of a.com-cron-1.com")
	}
}

func TestSSLStatusReadsCaddyStorage(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.ProxyStorage = filepath.Join(t.TempDir(), "caddy")
	env.Cfg.PanelDomain = "panel.example.com"
	for _, d := range []string{"ok.example.com", "soon.example.com", "none.example.com", "short.example.com"} {
		if err := os.WriteFile(filepath.Join(env.Cfg.ProxySitesDir, d+".caddy"), nil, 0o644); err != nil {
			t.Fatal(err)
		}
	}
	writeCert(t, env.Cfg.ProxyStorage, "acme-v02.api.letsencrypt.org-directory", "ok.example.com", 60*24*time.Hour)
	writeCert(t, env.Cfg.ProxyStorage, "local", "soon.example.com", 5*24*time.Hour) // 5 d left of a 90 d lifetime
	writeCert(t, env.Cfg.ProxyStorage, "local", "short.example.com", 11*time.Hour)  // 11 h left of a 12 h local cert
	out, err := call(t, env, sslStatus, map[string]string{})
	if err != nil {
		t.Fatal(err)
	}
	got := map[string]certInfo{}
	for _, c := range out.([]certInfo) {
		got[c.Domain] = c
	}
	if c := got["ok.example.com"]; c.Status != "valid" || c.Issuer != "acme-v02.api.letsencrypt.org-directory" || c.DaysLeft < 58 {
		t.Errorf("ok: %+v", c)
	}
	if got["short.example.com"].Status != "valid" {
		t.Errorf("a fresh short-lived cert is valid: %+v", got["short.example.com"])
	}
	if got["soon.example.com"].Status != "expiring" || got["none.example.com"].Status != "missing" || got["panel.example.com"].Status != "missing" {
		t.Errorf("statuses: %+v", got)
	}
}

func writeCert(t *testing.T, storage, issuer, domain string, validFor time.Duration) {
	t.Helper()
	lifetime := 90 * 24 * time.Hour
	if validFor < 24*time.Hour {
		lifetime = 12 * time.Hour
	}
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: domain}, DNSNames: []string{domain},
		NotBefore: time.Now().Add(validFor - lifetime), NotAfter: time.Now().Add(validFor)}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(storage, "certificates", issuer, domain)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, domain+".crt"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o644); err != nil {
		t.Fatal(err)
	}
}
