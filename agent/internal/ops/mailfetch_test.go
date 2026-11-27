package ops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func externalTestEnv(t *testing.T) (*Env, *executor.DryRun) {
	t.Helper()
	env, dr := mailTestEnv(t)
	if _, err := call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mailMailboxSet, map[string]any{"address": "alice@firma.cz", "password_hash": testHash, "quota_mb": 51200, "enabled": true}); err != nil {
		t.Fatal(err)
	}
	return env, dr
}

func TestAnExternalMailboxIsDownloadedByGetmailOverLMTP(t *testing.T) {
	env, dr := externalTestEnv(t)
	_, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz",
		"fetch": map[string]any{"protocol": "imap", "host": "IMAP.Provider.cz", "user": "alice@firma.cz", "password": "tajné heslo", "keep_days": 7}})
	if err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(env.Cfg.MailFetchDir, "alice@firma.cz")
	rc := read2(t, filepath.Join(dir, "getmailrc"))
	for _, want := range []string{"type = SimpleIMAPSSLRetriever", "server = imap.provider.cz", "port = 993", "username = alice@firma.cz",
		`password_command = ("/usr/bin/cat", "` + filepath.Join(dir, "password") + `")`,
		"type = MDA_lmtp", "host = " + env.Cfg.MailFetchLMTP, "override = alice@firma.cz", "read_all = false", "delete = false", "delete_after = 7"} {
		if !strings.Contains(rc, want) {
			t.Errorf("getmailrc lacks %q:\n%s", want, rc)
		}
	}
	if strings.Contains(rc, "tajné heslo") {
		t.Error("the password stays out of getmailrc (getmail reads it from its own file)")
	}
	if got := read2(t, filepath.Join(dir, "password")); got != "tajné heslo" {
		t.Errorf("password file: %q", got)
	}
	if fi, _ := os.Stat(filepath.Join(dir, "password")); fi == nil || fi.Mode().Perm() != 0o600 {
		t.Error("the password file is readable by its owner only")
	}
	timer := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-mailfetch.timer"))
	if !strings.Contains(timer, "OnUnitInactiveSec=2min") {
		t.Errorf("every two minutes after the last run:\n%s", timer)
	}
	if svc := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-mailfetch.service")); !strings.Contains(svc, "-mail-fetch") {
		t.Errorf("the service runs the agent's fetch:\n%s", svc)
	}
	if !ranCall(dr, "systemctl", "enable", "--now", "wspanel-mailfetch.timer") {
		t.Error("the timer is switched on")
	}

	// Removed at once at the provider (0 days); then no downloading at all.
	if _, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz",
		"fetch": map[string]any{"protocol": "imap", "host": "imap.provider.cz", "user": "alice@firma.cz", "keep_days": 0}}); err != nil {
		t.Fatalf("an empty password keeps the stored one (same server): %v", err)
	}
	if got := read2(t, filepath.Join(dir, "password")); got != "tajné heslo" {
		t.Errorf("kept password: %q", got)
	}
	if !strings.Contains(read2(t, filepath.Join(dir, "getmailrc")), "delete = true") {
		t.Error("0 days = removed at the provider at once")
	}
	if _, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz",
		"fetch": map[string]any{"protocol": "pop3", "host": "pop.provider.cz", "user": "alice@firma.cz", "password": "jine", "keep_days": 0}}); err != nil {
		t.Fatal(err)
	}
	rc = read2(t, filepath.Join(dir, "getmailrc"))
	if !strings.Contains(rc, "SimplePOP3SSLRetriever") || !strings.Contains(rc, "port = 995") {
		t.Errorf("POP3:\n%s", rc)
	}
	if _, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(dir); !os.IsNotExist(err) {
		t.Error("the mailbox's folder (with its password) goes when it stops downloading")
	}
	if _, err := os.Stat(filepath.Join(env.Cfg.SystemdDir, "wspanel-mailfetch.timer")); !os.IsNotExist(err) {
		t.Error("no external mailbox, no timer")
	}
}

func TestAnExternalMailboxSendsThroughItsProvider(t *testing.T) {
	env, _ := externalTestEnv(t)
	_, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz",
		"fetch": map[string]any{"protocol": "imap", "host": "imap.provider.cz", "user": "alice@firma.cz", "password": "heslo", "keep_days": 7},
		"relay": map[string]any{"host": "smtp.provider.cz", "user": "alice@firma.cz"}})
	if err != nil {
		t.Fatal(err)
	}
	if got := read2(t, env.Cfg.SenderTransport); !strings.Contains(got, "alice@firma.cz FILTER wspanel-relay:[smtp.provider.cz]:587\n") {
		t.Errorf("transport by sender:\n%s", got)
	}
	if got := read2(t, env.Cfg.SenderSASL); !strings.Contains(got, "alice@firma.cz alice@firma.cz:heslo\n") {
		t.Errorf("the relay with the fetch's login takes its password:\n%s", got)
	}
	if fi, _ := os.Stat(env.Cfg.SenderSASL); fi == nil || fi.Mode().Perm() != 0o600 {
		t.Error("the logins are root's only")
	}
	if _, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz",
		"relay": map[string]any{"host": "smtp.provider.cz", "user": "alice@firma.cz"}}); err != nil {
		t.Fatalf("the stored relay password is kept for the same server: %v", err)
	}
	if _, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz",
		"relay": map[string]any{"host": "smtp.provider.cz", "port": 465, "user": "alice@firma.cz", "password": "heslo"}}); err != nil {
		t.Fatal(err)
	}
	if got := read2(t, env.Cfg.SenderTransport); !strings.Contains(got, "FILTER wspanel-relays:[smtp.provider.cz]:465") {
		t.Errorf("465 = TLS from the start:\n%s", got)
	}
	// Deleting the mailbox takes its route and login away too.
	if _, err := call(t, env, mailMailboxDelete, map[string]any{"address": "alice@firma.cz"}); err != nil {
		t.Fatal(err)
	}
	if got := read2(t, env.Cfg.SenderSASL); strings.Contains(got, "alice") {
		t.Errorf("a deleted mailbox's login stays nowhere:\n%s", got)
	}
}

func TestAStoredPasswordNeverGoesToAnotherServer(t *testing.T) {
	env, _ := externalTestEnv(t)
	if _, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz",
		"fetch": map[string]any{"protocol": "imap", "host": "imap.provider.cz", "user": "alice@firma.cz", "password": "heslo", "keep_days": 7},
		"relay": map[string]any{"host": "smtp.provider.cz", "user": "alice@firma.cz"}}); err != nil {
		t.Fatal(err)
	}
	for name, params := range map[string]map[string]any{
		"fetch to another server": {"fetch": map[string]any{"protocol": "imap", "host": "evil.example", "user": "alice@firma.cz", "keep_days": 7}},
		"fetch to another port":   {"fetch": map[string]any{"protocol": "imap", "host": "imap.provider.cz", "port": 143, "user": "alice@firma.cz", "keep_days": 7}},
		"relay to another server": {"relay": map[string]any{"host": "evil.example", "user": "alice@firma.cz"}},
		// The fetch's password is stored, not typed: it does not follow the relay elsewhere.
		"relay inherits a stored fetch password": {"fetch": map[string]any{"protocol": "imap", "host": "imap.provider.cz", "user": "alice@firma.cz", "keep_days": 7},
			"relay": map[string]any{"host": "evil.example", "user": "alice@firma.cz"}},
	} {
		params["address"] = "alice@firma.cz"
		if _, err := call(t, env, mailExternalTest, params); code(err) != "invalid_params" {
			t.Errorf("test, %s: want invalid, got %v", name, err)
		}
		if _, err := call(t, env, mailMailboxExternal, params); code(err) != "invalid_params" {
			t.Errorf("save, %s: want invalid, got %v", name, err)
		}
	}
}

func TestExternalMailboxSettingsAreChecked(t *testing.T) {
	env, _ := externalTestEnv(t)
	for name, params := range map[string]map[string]any{
		"unknown protocol":  {"fetch": map[string]any{"protocol": "http", "host": "x.cz", "user": "a", "password": "p"}},
		"no password":       {"fetch": map[string]any{"protocol": "imap", "host": "x.cz", "user": "a"}},
		"host injection":    {"fetch": map[string]any{"protocol": "imap", "host": "x.cz\nport = 1", "user": "a", "password": "p"}},
		"user injection":    {"fetch": map[string]any{"protocol": "imap", "host": "x.cz", "user": "a b:c", "password": "p"}},
		"password new line": {"fetch": map[string]any{"protocol": "imap", "host": "x.cz", "user": "a", "password": "p\nq"}},
		"keep too long":     {"fetch": map[string]any{"protocol": "imap", "host": "x.cz", "user": "a", "password": "p", "keep_days": 1000}},
		"plain SMTP port":   {"relay": map[string]any{"host": "smtp.x.cz", "port": 25, "user": "a", "password": "p"}},
	} {
		params["address"] = "alice@firma.cz"
		if _, err := call(t, env, mailMailboxExternal, params); code(err) != "invalid_params" {
			t.Errorf("%s: want invalid, got %v", name, err)
		}
	}
	if _, err := call(t, env, mailMailboxExternal, map[string]any{"address": "nobody@firma.cz"}); code(err) != "not_found" {
		t.Errorf("unknown mailbox: %v", err)
	}
}

func TestMailFetchRecordsHowEachMailboxWent(t *testing.T) {
	env, dr := externalTestEnv(t)
	if _, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz",
		"fetch": map[string]any{"protocol": "imap", "host": "imap.provider.cz", "user": "alice@firma.cz", "password": "heslo", "keep_days": 7}}); err != nil {
		t.Fatal(err)
	}
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" {
			return executor.Result{Stdout: "getmail version 6.19.07\nSimpleIMAPSSLRetriever:alice@firma.cz@imap.provider.cz:993:\n  3 messages (4521 bytes) retrieved, 0 skipped\n"}, nil
		}
		return executor.Result{}, nil
	}
	if err := RunMailFetch(context.Background(), env); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, mailFetchStatus, map[string]any{"domain": "firma.cz"})
	if err != nil {
		t.Fatal(err)
	}
	s := out.(map[string]any)["mailboxes"].(map[string]fetchStatus)["alice@firma.cz"]
	if !s.OK || s.Retrieved != 3 || s.LastMail == nil {
		t.Errorf("status: %+v", s)
	}
	if !ranCall(dr, "setpriv", "--reuid=vmail") {
		t.Error("getmail runs as vmail, never root")
	}
}

// ranCall: whether a call of name began with these arguments.
func ranCall(dr *executor.DryRun, name string, args ...string) bool {
	for _, c := range dr.Calls() {
		if c.Name == name && len(c.Args) >= len(args) && strings.Join(c.Args[:len(args)], "\x00") == strings.Join(args, "\x00") {
			return true
		}
	}
	return false
}

func TestAClientCannotPointAMailboxIntoTheLocalNetwork(t *testing.T) {
	env, _ := externalTestEnv(t)
	for name, params := range map[string]map[string]any{
		"loopback":     {"fetch": map[string]any{"protocol": "imap", "host": "127.0.0.1", "user": "a", "password": "p"}},
		"private":      {"fetch": map[string]any{"protocol": "imap", "host": "10.0.0.5", "user": "a", "password": "p"}},
		"link-local":   {"relay": map[string]any{"host": "169.254.169.254", "user": "a", "password": "p"}},
		"other port":   {"fetch": map[string]any{"protocol": "imap", "host": "imap.provider.cz", "port": 22, "user": "a", "password": "p"}},
		"carrier NAT":  {"relay": map[string]any{"host": "100.64.1.1", "user": "a", "password": "p"}},
		"the test too": {"fetch": map[string]any{"protocol": "pop3", "host": "192.168.1.10", "user": "a", "password": "p"}},
	} {
		params["address"] = "alice@firma.cz"
		if _, err := call(t, env, mailExternalTest, params); code(err) != "invalid_params" {
			t.Errorf("test, %s: want invalid, got %v", name, err)
		}
		if _, err := call(t, env, mailMailboxExternal, params); code(err) != "invalid_params" {
			t.Errorf("save, %s: want invalid, got %v", name, err)
		}
	}
	// The administrator may: a mail server in the office network.
	if _, err := call(t, env, mailMailboxExternal, map[string]any{"address": "alice@firma.cz", "allow_private": true,
		"fetch": map[string]any{"protocol": "imap", "host": "10.0.0.5", "port": 143, "user": "a", "password": "p", "keep_days": 7}}); err != nil {
		t.Errorf("an administrator's local server: %v", err)
	}
}
