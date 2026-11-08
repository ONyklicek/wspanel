package ops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

const testHash = "$6$saltsalt$" + "abcdefghijABCDEFGHIJabcdefghijABCDEFGHIJabcdefghijABCDEFGHIJabcdefghijABCDEFGHIJabcdef"

func mailTestEnv(t *testing.T) (*Env, *executor.DryRun) {
	env, dr := testEnv(t)
	root := t.TempDir()
	env.Cfg.MailDir = filepath.Join(root, "mail")
	env.Cfg.VDomains = filepath.Join(root, "vdomains")
	env.Cfg.VMailboxes = filepath.Join(root, "vmailboxes")
	env.Cfg.VAliases = filepath.Join(root, "valiases")
	env.Cfg.MailboxSenders = filepath.Join(root, "senders")
	env.Cfg.DovecotUsers = filepath.Join(root, "users")
	env.Cfg.VmailRoot = filepath.Join(root, "vmail")
	env.Cfg.DovecotBlocked = filepath.Join(root, "blocked")
	env.Cfg.MailEdgeFile = filepath.Join(root, "mail-sites.caddy")
	env.Cfg.WebmailTokens = filepath.Join(root, "webmail-tokens")
	env.Cfg.WebmailUser = "root"
	env.Cfg.DomainSieveDir = filepath.Join(root, "domain-sieve")
	return env, dr
}

func read2(t *testing.T, p string) string {
	b, _ := os.ReadFile(p)
	return string(b)
}

func TestMailDomainsMailboxesAndAliasesRenderTogether(t *testing.T) {
	env, _ := mailTestEnv(t)
	if _, err := call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mailMailboxSet, map[string]any{"address": "Alice@Firma.cz", "password_hash": testHash, "quota_mb": 500, "enabled": true}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mailAliasSet, map[string]any{"domain": "firma.cz", "aliases": map[string][]string{"info": {"alice@firma.cz", "boss@gmail.com"}, "": {"alice@firma.cz"}}}); err != nil {
		t.Fatal(err)
	}
	if got := read2(t, env.Cfg.VDomains); !strings.Contains(got, "firma.cz OK") {
		t.Errorf("domains:\n%s", got)
	}
	if got := read2(t, env.Cfg.DovecotUsers); !strings.Contains(got, "alice@firma.cz:{SHA512-CRYPT}"+testHash+"::::::userdb_quota/user/quota_storage_size=500M") {
		t.Errorf("users:\n%s", got)
	}
	al := read2(t, env.Cfg.VAliases)
	if !strings.Contains(al, "info@firma.cz alice@firma.cz,boss@gmail.com") || !strings.Contains(al, "\n@firma.cz alice@firma.cz") {
		t.Errorf("aliases (with catch-all):\n%s", al)
	}
	if !strings.Contains(al, "alice@firma.cz alice@firma.cz") {
		t.Errorf("with a catch-all every mailbox must map to itself, or the catch-all takes its mail:\n%s", al)
	}
	if got := read2(t, env.Cfg.MailboxSenders); !strings.Contains(got, "alice@firma.cz alice@firma.cz") || !strings.Contains(got, "info@firma.cz alice@firma.cz") {
		t.Errorf("a mailbox may send as itself and its aliases:\n%s", got)
	}
	// Disabled: no login, mail still arrives.
	if _, err := call(t, env, mailMailboxSet, map[string]any{"address": "alice@firma.cz", "quota_mb": 500, "enabled": false}); err != nil {
		t.Fatal(err)
	}
	if got := read2(t, env.Cfg.DovecotUsers); !strings.Contains(got, "alice@firma.cz:{CRYPT}*::::::") || strings.Contains(got, testHash) {
		t.Errorf("a blocked mailbox has a password nothing matches:\n%s", got)
	}
	if st, _ := loadMail(env); st.Domains["firma.cz"].Mailboxes["alice"].Hash != testHash {
		t.Error("the real hash stays for unblocking")
	}
	if _, err := call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": false}); code(err) != "conflict" {
		t.Errorf("a domain with mailboxes stays: %v", err)
	}
}

func TestMailRefusesBadInput(t *testing.T) {
	env, _ := mailTestEnv(t)
	if _, err := call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true}); err != nil {
		t.Fatal(err)
	}
	for name, p := range map[string]map[string]any{
		"plain password":     {"address": "a@firma.cz", "password_hash": "Heslo123", "quota_mb": 100, "enabled": true},
		"injected line":      {"address": "a@firma.cz", "password_hash": testHash + "\nroot@x:", "quota_mb": 100, "enabled": true},
		"colon in address":   {"address": "a:b@firma.cz", "password_hash": testHash, "quota_mb": 100, "enabled": true},
		"unknown domain":     {"address": "a@jiny.cz", "password_hash": testHash, "quota_mb": 100, "enabled": true},
		"no password at all": {"address": "new@firma.cz", "quota_mb": 100, "enabled": true},
		"tiny quota":         {"address": "a@firma.cz", "password_hash": testHash, "quota_mb": 1, "enabled": true},
	} {
		if _, err := call(t, env, mailMailboxSet, p); err == nil {
			t.Errorf("%s must be refused", name)
		}
	}
	if _, err := call(t, env, mailAliasSet, map[string]any{"domain": "firma.cz", "aliases": map[string][]string{"x": {"a b@c.cz"}}}); err == nil {
		t.Error("an address with a space must be refused")
	}
	if _, err := call(t, env, mailAliasSet, map[string]any{"domain": "firma.cz", "aliases": map[string][]string{"bad\nroot": {"a@c.cz"}}}); err == nil {
		t.Error("a local part with a newline must be refused")
	}
}

func TestAutoreplyIsOneSieveScriptAsVmail(t *testing.T) {
	env, dr := mailTestEnv(t)
	_, _ = call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true})
	_, _ = call(t, env, mailMailboxSet, map[string]any{"address": "alice@firma.cz", "password_hash": testHash, "quota_mb": 100, "enabled": true})
	if _, err := call(t, env, mailAutoreply, map[string]any{"address": "alice@firma.cz", "enabled": true, "subject": "Dovolená\r\nBcc: x@y", "body": `Jsem pryč "do" pondělí`}); err != nil {
		t.Fatal(err)
	}
	var script string
	for _, c := range dr.Calls() {
		if c.Name == "setpriv" && strings.Contains(c.String(), "/usr/bin/tee") {
			script = c.Stdin
		}
		if c.Name == "setpriv" && !strings.Contains(c.String(), "--reuid=vmail") {
			t.Errorf("mailbox files are vmail's: %s", c)
		}
	}
	if !strings.Contains(script, `:subject "Dovolená  Bcc: x@y"`) || !strings.Contains(script, `"Jsem pryč \"do\" pondělí"`) {
		t.Errorf("subject on one line, quotes escaped:\n%s", script)
	}
}

func TestEveryMailDomainGetsItsOwnWebmailAndClientSettings(t *testing.T) {
	env, dr := mailTestEnv(t)
	env.Cfg.MailHost = "mail.hosting.cz"
	_, _ = call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true})
	_, _ = call(t, env, mailDomainSet, map[string]any{"domain": "druha.cz", "enabled": true, "webmail": false, "autoconfig": false})
	edge := read2(t, env.Cfg.MailEdgeFile)
	if !strings.Contains(edge, "webmail.firma.cz {") || !strings.Contains(edge, "header_up X-Wsp-Mail-Domain firma.cz") {
		t.Errorf("webmail.<domain> tells Roundcube its domain:\n%s", edge)
	}
	if !strings.Contains(edge, "autoconfig.firma.cz, autodiscover.firma.cz {") || !strings.Contains(edge, "<hostname>mail.hosting.cz</hostname>") {
		t.Errorf("mail clients find the mail host:\n%s", edge)
	}
	if strings.Contains(edge, "druha.cz") {
		t.Errorf("a domain with both off has no hosts:\n%s", edge)
	}
	reloaded := false
	for _, c := range dr.Calls() {
		if c.Name == "systemctl" && strings.Contains(c.String(), "reload "+env.Cfg.ProxyUnit) {
			reloaded = true
		}
	}
	if !reloaded {
		t.Error("the edge reloads with the new hosts")
	}
}

func TestBlockedMailboxCannotSignInToWebmail(t *testing.T) {
	env, _ := mailTestEnv(t)
	_, _ = call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true})
	_, _ = call(t, env, mailMailboxSet, map[string]any{"address": "alice@firma.cz", "password_hash": testHash, "quota_mb": 100, "enabled": true})

	out, err := call(t, env, mailWebmailLogin, map[string]any{"address": "alice@firma.cz"})
	if err != nil {
		t.Fatal(err)
	}
	res := out.(map[string]any)
	token := res["token"].(string)
	if res["url"] != "https://webmail.firma.cz/?_task=login" || len(token) != 64 || strings.Contains(res["url"].(string), token) {
		t.Fatalf("a one-time token for the domain's own webmail, never in the URL: %v", res)
	}
	if got := read2(t, filepath.Join(env.Cfg.WebmailTokens, token+".json")); !strings.Contains(got, `"address":"alice@firma.cz"`) {
		t.Errorf("token file: %s", got)
	}

	_, _ = call(t, env, mailMailboxSet, map[string]any{"address": "alice@firma.cz", "quota_mb": 100, "enabled": false})
	if got := read2(t, env.Cfg.DovecotBlocked); got != "alice@firma.cz:\n" {
		t.Errorf("the deny passdb lists the blocked mailbox (also against the master login): %q", got)
	}
	if _, err := call(t, env, mailWebmailLogin, map[string]any{"address": "alice@firma.cz"}); code(err) != "conflict" {
		t.Errorf("no sign-in link for a blocked mailbox: %v", err)
	}
}

func TestDomainSenderListsApplyPerRecipientInSieve(t *testing.T) {
	env, _ := mailTestEnv(t)
	_, _ = call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true})
	_, _ = call(t, env, mailDomainSet, map[string]any{"domain": "druha.cz", "enabled": true})
	if _, err := call(t, env, mailSpamSet, map[string]any{"domain": "firma.cz", "spam": map[string]any{
		"allow": []string{"Boss@Partner.cz", "@bank.cz"}, "block": []string{"@spam.example"},
	}}); err != nil {
		t.Fatal(err)
	}
	got := read2(t, filepath.Join(env.Cfg.DomainSieveDir, "firma.cz.sieve"))
	for _, want := range []string{
		`if envelope :domain :is "from" ["spam.example"] {` + "\n  discard;",
		`if not allof (anyof (envelope :all :is "from" ["boss@partner.cz"], envelope :domain :is "from" ["bank.cz"]), header :contains "Authentication-Results" "spf=pass") {`,
		`fileinto "Junk";`,
	} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in:\n%s", want, got)
		}
	}
	if other := read2(t, filepath.Join(env.Cfg.DomainSieveDir, "druha.cz.sieve")); strings.Contains(other, "partner") || !strings.Contains(other, `fileinto "Junk"`) {
		t.Errorf("another domain keeps only the spam rule:\n%s", other)
	}
	for name, spam := range map[string]map[string]any{
		"injected sender": {"allow": []string{"a@b.cz\"]; discard; [\""}},
		"bare word":       {"block": []string{"spammer"}},
	} {
		if _, err := call(t, env, mailSpamSet, map[string]any{"domain": "firma.cz", "spam": spam}); code(err) != "invalid_params" {
			t.Errorf("%s must be refused: %v", name, err)
		}
	}
	_, _ = call(t, env, mailDomainSet, map[string]any{"domain": "druha.cz", "enabled": false})
	if _, err := os.Stat(filepath.Join(env.Cfg.DomainSieveDir, "druha.cz.sieve")); err == nil {
		t.Error("a removed domain's script goes")
	}
}

func TestMailboxForwardingKeepsACopyOnlyWhenAsked(t *testing.T) {
	env, _ := mailTestEnv(t)
	if _, err := call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true}); err != nil {
		t.Fatal(err)
	}
	for _, lp := range []string{"alice", "bob"} {
		if _, err := call(t, env, mailMailboxSet, map[string]any{"address": lp + "@firma.cz", "password_hash": testHash, "quota_mb": 500, "enabled": true}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := call(t, env, mailForwardSet, map[string]any{"address": "alice@firma.cz", "to": []string{"Bob@firma.cz"}, "keep_copy": false}); err != nil {
		t.Fatal(err)
	}
	if al := read2(t, env.Cfg.VAliases); !strings.Contains(al, "\nalice@firma.cz bob@firma.cz\n") {
		t.Errorf("without a copy the mail goes on only:\n%s", al)
	}
	if _, err := call(t, env, mailForwardSet, map[string]any{"address": "alice@firma.cz", "to": []string{"bob@firma.cz", "boss@gmail.com"}, "keep_copy": true}); err != nil {
		t.Fatal(err)
	}
	if al := read2(t, env.Cfg.VAliases); !strings.Contains(al, "\nalice@firma.cz alice@firma.cz,bob@firma.cz,boss@gmail.com\n") {
		t.Errorf("with a copy the mailbox stays in its own expansion:\n%s", al)
	}
	// A password change keeps the forwarding; a catch-all does not add a second line.
	if _, err := call(t, env, mailMailboxSet, map[string]any{"address": "alice@firma.cz", "password_hash": testHash, "quota_mb": 600, "enabled": true}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mailAliasSet, map[string]any{"domain": "firma.cz", "aliases": map[string][]string{"": {"bob@firma.cz"}}}); err != nil {
		t.Fatal(err)
	}
	al := read2(t, env.Cfg.VAliases)
	if strings.Count(al, "\nalice@firma.cz ") != 1 || !strings.Contains(al, "alice@firma.cz alice@firma.cz,bob@firma.cz") || !strings.Contains(al, "\nbob@firma.cz bob@firma.cz\n") {
		t.Errorf("forward kept, one line per mailbox:\n%s", al)
	}
	if _, err := call(t, env, mailForwardSet, map[string]any{"address": "alice@firma.cz", "to": []string{"alice@firma.cz"}}); err == nil {
		t.Error("a mailbox must not forward to itself")
	}
	if _, err := call(t, env, mailForwardSet, map[string]any{"address": "nobody@firma.cz", "to": []string{"bob@firma.cz"}}); err == nil {
		t.Error("only an existing mailbox forwards")
	}
	if _, err := call(t, env, mailForwardSet, map[string]any{"address": "alice@firma.cz", "to": []string{}, "keep_copy": true}); err != nil {
		t.Fatal(err)
	}
	if al := read2(t, env.Cfg.VAliases); !strings.Contains(al, "\nalice@firma.cz alice@firma.cz\n") {
		t.Errorf("forwarding off (catch-all on): the mailbox maps to itself again:\n%s", al)
	}
}

func TestOwnDomainsAreNeverSRSRewritten(t *testing.T) {
	env, _ := mailTestEnv(t)
	env.Cfg.SRSExclude = filepath.Join(t.TempDir(), "srs_exclude")
	env.Cfg.PanelDomain = "panel.example.net"
	if err := saveSiteState(env, siteState{Domain: "shop.cz", Mode: "classic"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true}); err != nil {
		t.Fatal(err)
	}
	got := read2(t, env.Cfg.SRSExclude)
	for _, line := range []string{`/^(.+@firma\.cz)$/ ${1}`, `/^(.+@shop\.cz)$/ ${1}`, `/^(.+@panel\.example\.net)$/ ${1}`} {
		if !strings.Contains(got, line+"\n") {
			t.Errorf("missing %q in:\n%s", line, got)
		}
	}
}

func TestSenderListsAlsoMatchTheSRSFormOfAForeignSender(t *testing.T) {
	got := senderTest([]string{"boss@gmail.com", "@spam.net"}, "mail.example.net")
	for _, want := range []string{`envelope :all :is "from" ["boss@gmail.com"]`, `envelope :domain :is "from" ["spam.net"]`,
		`envelope :all :regex "from" ["^SRS0=[^=]+=[^=]+=gmail\\.com=boss@mail\\.example\\.net$", "^SRS0=[^=]+=[^=]+=spam\\.net=[^@]+@mail\\.example\\.net$"]`} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %s in %s", want, got)
		}
	}
}

func TestSendingLimitsAreRenderedForMailboxesAndSiteLogins(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.PanelDomain = "panel.example.net"
	st := &mailState{Domains: map[string]*mailDomain{}}
	if err := writeRatelimit(context.Background(), env, st); err != nil {
		t.Fatal(err)
	}
	got := read2(t, env.Cfg.RspamdRatelimit)
	for _, want := range []string{
		`selector = "user.lower.regexp('^(?!web_[a-z0-9_]+@panel[.]example[.]net$)[^@]+@[^@]+$')";`,
		`bucket = { burst = 200; rate = "200 / 1h"; }`,
		`selector = "user.lower.regexp('^web_[a-z0-9_]+@panel[.]example[.]net$')";`,
		`bucket = { burst = 1000; rate = "1000 / 1h"; }`,
	} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %s in\n%s", want, got)
		}
	}

	st.Limits = &mailLimits{MailboxPerHour: 50}
	if err := writeRatelimit(context.Background(), env, st); err != nil {
		t.Fatal(err)
	}
	got = read2(t, env.Cfg.RspamdRatelimit)
	if !strings.Contains(got, `rate = "50 / 1h"`) || strings.Contains(got, "wspanel_site") {
		t.Errorf("site limit 0 must mean none, mailbox 50:\n%s", got)
	}
}

func TestAMailboxChangesItsOwnPasswordOnlyWithTheCurrentOne(t *testing.T) {
	env, _ := mailTestEnv(t)
	// "Hello world!" (Drepper's reference vector).
	const current = "$6$saltstring$svn8UoSVapNtMuq1ukKS4tPQd8iKwSMHWjl/O817G3uBnIFNjnQJuesI68u4OTLiBFdcbYEdFCoEOfaS35inz1"
	if _, err := call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mailMailboxSet, map[string]any{"address": "alice@firma.cz", "password_hash": current, "quota_mb": 500, "enabled": true}); err != nil {
		t.Fatal(err)
	}
	change := func(pw string) error {
		_, err := call(t, env, mailMailboxPassword, map[string]any{"address": "alice@firma.cz", "current_password": pw, "password_hash": testHash})
		return err
	}
	if change("wrong") == nil || change("") == nil {
		t.Fatal("changed without the current password")
	}
	if !strings.Contains(read2(t, env.Cfg.DovecotUsers), current) {
		t.Fatal("a refused change must keep the old hash")
	}
	if err := change("Hello world!"); err != nil {
		t.Fatal(err)
	}
	if u := read2(t, env.Cfg.DovecotUsers); !strings.Contains(u, testHash) {
		t.Errorf("new hash not in the users file:\n%s", u)
	}
	if _, err := call(t, env, mailMailboxPassword, map[string]any{"address": "nobody@firma.cz", "current_password": "x", "password_hash": testHash}); err == nil {
		t.Error("an unknown mailbox must be refused like a wrong password")
	}
}
