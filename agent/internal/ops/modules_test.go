package ops

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/protocol"
)

// moduleEnv: a dry-run agent with the installer's saved choices and its own
// agent.json in a temporary root.
func moduleEnv(t *testing.T, flags string) *Env {
	t.Helper()
	env, _ := testEnv(t)
	root := t.TempDir()
	env.Cfg.InstallFlags = filepath.Join(root, "install.flags")
	env.Cfg.MailDir = filepath.Join(root, "mail")
	env.Cfg.PythonDir = filepath.Join(root, "opt/uv/python")
	env.Cfg.UVBin = filepath.Join(root, "bin/uv")
	env.Cfg.PowerDNSSetup = filepath.Join(root, "pdns.d/wspanel.conf")
	env.ConfigPath = filepath.Join(root, "agent.json")
	write(t, env.Cfg.InstallFlags, flags)
	write(t, env.ConfigPath, `{"socket": "/run/panel-agent.sock", "powerdns_api": "http://127.0.0.1:8081", "powerdns_key": "k"}`)
	return env
}

func write(t *testing.T, path, data string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(data), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestARemovedModuleLeavesTheInstallersChoices(t *testing.T) {
	env := moduleEnv(t, "--panel-domain=panel.example.com\n--dns=powerdns\n--python=3.12,3.13\n--mail-hosting=yes\n")
	for _, c := range []struct{ flag, value, want string }{
		{"--dns=", "", "--panel-domain=panel.example.com\n--python=3.12,3.13\n--mail-hosting=yes\n"},
		{"--python=", "3.13", "--panel-domain=panel.example.com\n--python=3.12\n--mail-hosting=yes\n"},
		{"--python=", "3.11", "--panel-domain=panel.example.com\n--python=3.12\n--mail-hosting=yes\n"}, // not there: nothing changes
		{"--python=", "3.12", "--panel-domain=panel.example.com\n--mail-hosting=yes\n"},
		{"--xhprof=", "", "--panel-domain=panel.example.com\n--mail-hosting=yes\n"},
	} {
		if err := dropInstallFlag(env, c.flag, c.value); err != nil {
			t.Fatal(err)
		}
		if got := read2(t, env.Cfg.InstallFlags); got != c.want {
			t.Errorf("%s%s: got\n%s", c.flag, c.value, got)
		}
	}
	// A server installed before the choices were saved has none: nothing to do.
	env.Cfg.InstallFlags = filepath.Join(t.TempDir(), "missing")
	if err := dropInstallFlag(env, "--dns=", ""); err != nil {
		t.Fatal(err)
	}
}

func TestDNSGoesOnlyWithoutZones(t *testing.T) {
	env := moduleEnv(t, "--dns=powerdns\n")
	env.Cfg.PowerDNSAPI, env.Cfg.PowerDNSKey = "http://127.0.0.1:8081", "k"
	zones := `[{"name":"example.com."}]`
	fakePDNS(t, func(method, path string) (string, int) { return zones, 200 })
	if _, err := call(t, env, dnsUninstall, map[string]any{}); code(err) != protocol.CodeConflict || !strings.Contains(err.Error(), "example.com") {
		t.Fatalf("a zone is left: %v", err)
	}
	if env.Cfg.PowerDNSKey == "" || read2(t, env.Cfg.InstallFlags) != "--dns=powerdns\n" {
		t.Fatal("refused, yet changed")
	}
	zones = `[]`
	if _, err := call(t, env, dnsUninstall, map[string]any{}); err != nil {
		t.Fatal(err)
	}
	if env.Cfg.PowerDNSAPI != "" || env.Cfg.PowerDNSKey != "" || pdnsEnabled(env) == nil {
		t.Error("the agent still uses PowerDNS")
	}
	var cfg map[string]any
	if err := json.Unmarshal([]byte(read2(t, env.ConfigPath)), &cfg); err != nil {
		t.Fatal(err)
	}
	if cfg["powerdns_key"] != "" || cfg["socket"] != "/run/panel-agent.sock" {
		t.Errorf("agent.json: %v", cfg)
	}
	if read2(t, env.Cfg.InstallFlags) != "" {
		t.Error("--dns stays in the installer's choices")
	}
}

func TestDNSAddedTakesTheKeyTheSetupWrote(t *testing.T) {
	env := moduleEnv(t, "")
	env.Cfg.DryRun = false
	env.Cfg.PowerDNSAPI, env.Cfg.PowerDNSKey = "", ""
	write(t, env.Cfg.PowerDNSSetup, "launch=gsqlite3\napi=yes\napi-key=s3cret\n")
	if _, err := call(t, env, dnsInstall, map[string]any{}); err != nil {
		t.Fatal(err)
	}
	if env.Cfg.PowerDNSKey != "s3cret" || env.Cfg.PowerDNSAPI == "" {
		t.Errorf("key %q api %q", env.Cfg.PowerDNSKey, env.Cfg.PowerDNSAPI)
	}
	if !strings.Contains(read2(t, env.ConfigPath), `"powerdns_key": "s3cret"`) {
		t.Error("the key is not kept in agent.json")
	}
}

func TestMailHostingGoesOnlyWithoutDomains(t *testing.T) {
	env := moduleEnv(t, "--mail-hosting=yes\n")
	write(t, filepath.Join(env.Cfg.MailDir, "hosting"), "")
	write(t, mailStatePath(env), `{"domains": {"example.com": {}}}`)
	if _, err := call(t, env, mailUninstall, map[string]any{}); code(err) != protocol.CodeConflict {
		t.Fatalf("a domain is left: %v", err)
	}
	write(t, mailStatePath(env), `{"domains": {}}`)
	if _, err := call(t, env, mailUninstall, map[string]any{}); err != nil {
		t.Fatal(err)
	}
	if read2(t, env.Cfg.InstallFlags) != "" {
		t.Error("--mail-hosting stays in the installer's choices")
	}
}

func TestXhprofGoesOnlyWhenNoSiteProfiles(t *testing.T) {
	env := moduleEnv(t, "--xhprof=yes\n")
	st := siteState{Domain: "shop.example.com", Mode: "classic", PHPVersion: "8.4", Xhprof: &xhprofConfig{Ratio: 10}}
	if err := saveSiteState(env, st); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, xhprofUninstall, map[string]any{}); code(err) != protocol.CodeConflict || !strings.Contains(err.Error(), "shop.example.com") {
		t.Fatalf("a site profiles: %v", err)
	}
	st.Xhprof = nil
	if err := saveSiteState(env, st); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, xhprofUninstall, map[string]any{}); err != nil {
		t.Fatal(err)
	}
}

func TestAPythonVersionGoesOnlyWhenNoSiteRunsOnIt(t *testing.T) {
	env := moduleEnv(t, "--python=3.12,3.13\n")
	env.Cfg.DryRun = false
	for _, d := range []string{"cpython-3.13.5-linux-x86_64-gnu", "cpython-3.12.9-linux-x86_64-gnu", "cpython-3.1.5-linux-x86_64-gnu"} {
		write(t, filepath.Join(env.Cfg.PythonDir, d, "bin", "python"+strings.Join(strings.Split(d, ".")[:2], ".")[len("cpython-"):]), "")
	}
	if err := os.Symlink("cpython-3.13.5-linux-x86_64-gnu", filepath.Join(env.Cfg.PythonDir, "cpython-3.13-linux-x86_64-gnu")); err != nil {
		t.Fatal(err)
	}
	write(t, env.Cfg.UVBin, "")
	if err := saveSiteState(env, siteState{Domain: "api.example.com", Mode: "python", PythonVersion: "3.13"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, pythonRemove, map[string]any{"version": "3.13"}); code(err) != protocol.CodeConflict {
		t.Fatalf("a site runs on it: %v", err)
	}
	if _, err := call(t, env, pythonRemove, map[string]any{"version": "3.8"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("not installed: %v", err)
	}
	if err := saveSiteState(env, siteState{Domain: "api.example.com", Mode: "python", PythonVersion: "3.12"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, pythonRemove, map[string]any{"version": "3.13"}); err != nil {
		t.Fatal(err)
	}
	left := pythonMinors(env.Cfg)
	if strings.Join(left, ",") != "3.1,3.12" && strings.Join(left, ",") != "3.12,3.1" {
		t.Errorf("left: %v (3.1 must not go with 3.13)", left)
	}
	if read2(t, env.Cfg.InstallFlags) != "--python=3.12\n" {
		t.Errorf("flags: %q", read2(t, env.Cfg.InstallFlags))
	}
	if !fileIn(filepath.Dir(env.Cfg.UVBin), "uv") {
		t.Error("uv went while versions are left")
	}
}
