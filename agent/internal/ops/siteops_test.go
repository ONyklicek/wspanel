package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/protocol"
)

func TestSiteDisableEnable(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "shop.example.com", "worker": "queue"}); err != nil {
		t.Fatal(err)
	}
	edge := filepath.Join(env.Cfg.ProxySitesDir, "shop.example.com.caddy")

	if _, err := call(t, env, siteDisable, map[string]string{"domain": "shop.example.com"}); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(edge)
	if !strings.Contains(string(b), "503") || strings.Contains(string(b), "reverse_proxy") {
		t.Fatalf("disabled edge must answer 503 without proxying:\n%s", b)
	}
	stopped := false
	for _, c := range dr.Calls() {
		if c.Name == "systemctl" && strings.Join(c.Args, " ") == "stop --no-block wspanel-web_shop_example_com-queue@initial.service" {
			stopped = true
		}
	}
	if !stopped {
		t.Error("worker not stopped")
	}
	if _, err := call(t, env, deployRun, map[string]string{"domain": "shop.example.com"}); err == nil {
		t.Error("deploy must be refused (not configured or disabled)")
	}
	if _, err := call(t, env, siteRestart, map[string]string{"domain": "shop.example.com"}); code(err) != protocol.CodeConflict {
		t.Errorf("restart of a disabled site: %v", err)
	}

	if _, err := call(t, env, siteEnable, map[string]string{"domain": "shop.example.com"}); err != nil {
		t.Fatal(err)
	}
	b, _ = os.ReadFile(edge)
	if strings.Contains(string(b), "503") || !strings.Contains(string(b), "reverse_proxy") {
		t.Fatalf("enabled edge must proxy:\n%s", b)
	}
	out, err := call(t, env, siteProcesses, map[string]string{"domain": "shop.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	procs := out.(map[string]any)["processes"].([]Process)
	if len(procs) != 1 || procs[0].Kind != "worker" || procs[0].Name != "queue" {
		t.Fatalf("processes: %+v", procs)
	}
}

func TestSiteLogsReadsFilesAsSiteUser(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(env.Cfg.SitesRoot, "shop.example.com")
	if err := os.WriteFile(filepath.Join(home, "logs/php-error.log"), []byte("x"), 0o640); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteLogs, map[string]string{"domain": "shop.example.com", "source": "php"}); err != nil {
		t.Fatal(err)
	}
	last := dr.Calls()[len(dr.Calls())-1]
	if last.Name != "setpriv" || last.Args[0] != "--reuid=web_shop_example_com" {
		t.Fatalf("log file must be read as the site user, got %v", last)
	}
	if _, err := call(t, env, siteLogs, map[string]string{"domain": "shop.example.com", "source": "../../etc"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("unknown source: %v", err)
	}
	if _, err := call(t, env, siteLogs, map[string]any{"domain": "shop.example.com", "source": "task", "name": "1;rm"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("bad task id: %v", err)
	}
}

func TestSSHPasswordGoesThroughStdin(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "php", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, sshSet, map[string]any{"domain": "shop.example.com", "access": "sftp", "password_mode": "set", "password": "short"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("short password: %v", err)
	}
	if _, err := call(t, env, sshSet, map[string]any{"domain": "shop.example.com", "access": "sftp"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("neither key nor password: %v", err)
	}
	pw := "correct-horse-battery"
	out, err := call(t, env, sshSet, map[string]any{"domain": "shop.example.com", "access": "sftp", "password_mode": "set", "password": pw})
	if err != nil {
		t.Fatal(err)
	}
	if out.(map[string]any)["password"] != true {
		t.Fatalf("output: %v", out)
	}
	found := false
	for _, c := range dr.Calls() {
		if strings.Contains(strings.Join(c.Args, " "), pw) {
			t.Fatalf("password in argv: %v", c)
		}
		if c.Name == "chpasswd" && c.Stdin == "web_shop_example_com:"+pw+"\n" {
			found = true
		}
	}
	if !found {
		t.Fatal("chpasswd not called with the password on stdin")
	}
	// Keep: switching to shell keeps the password, no key needed.
	if _, err := call(t, env, sshSet, map[string]any{"domain": "shop.example.com", "access": "shell"}); err != nil {
		t.Fatal(err)
	}
	// Off locks the password.
	if _, err := call(t, env, sshSet, map[string]any{"domain": "shop.example.com", "access": "off"}); err != nil {
		t.Fatal(err)
	}
	last := dr.Calls()
	locked := false
	for _, c := range last {
		if c.Name == "usermod" && strings.Join(c.Args, " ") == "--lock web_shop_example_com" {
			locked = true
		}
	}
	if !locked {
		t.Fatal("password not locked when access is off")
	}
	if _, err := os.Stat(filepath.Join(env.Cfg.SitesRoot, "shop.example.com", "public_html")); err != nil {
		t.Errorf("plain PHP site has no public_html: %v", err)
	}
}

func TestSitesMaySendOnlyAsTheirOwnDomain(t *testing.T) {
	env, dr := testEnv(t)
	for _, d := range []string{"a.example.com", "b.example.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "laravel", "php_version": "8.3"}); err != nil {
			t.Fatal(err)
		}
	}
	logins, _ := os.ReadFile(env.Cfg.SenderLogins)
	senders, _ := os.ReadFile(env.Cfg.LoginSenders)
	for _, want := range []string{"@a.example.com web_a_example_com@", "@b.example.com web_b_example_com@"} {
		if !strings.Contains(string(logins), want) {
			t.Errorf("sender_logins lacks %q:\n%s", want, logins)
		}
	}
	if !strings.Contains(string(senders), "web_a_example_com @a.example.com\n") {
		t.Errorf("login_senders:\n%s", senders)
	}
	envFile, _ := os.ReadFile(filepath.Join(env.Cfg.SitesRoot, "a.example.com/shared/.env"))
	if !strings.Contains(string(envFile), `MAIL_USERNAME="web_a_example_com@`) || !strings.Contains(string(envFile), `MAIL_PASSWORD="`) {
		t.Errorf("site env lacks its SMTP login:\n%s", envFile)
	}
	for _, c := range dr.Calls() {
		if c.Name == "saslpasswd2" && strings.Contains(strings.Join(c.Args, " "), "-c") && c.Stdin == "" {
			t.Error("SMTP password must come over stdin")
		}
	}
}
