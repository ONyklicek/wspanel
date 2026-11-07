package ops

import (
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"

	"wspanel/agent/internal/protocol"
)

// A real ed25519 public key (test fixture, no private key exists for it here).
const testKey = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGWV8cCv0YxZ7QS0jcjKOzzD8qUuDmzQ4b0V2Rz1fNnS admin@laptop"

func TestNodeUnitMayBindOnlyItsPort(t *testing.T) {
	env, _ := testEnv(t)
	out, err := call(t, env, siteCreate, map[string]string{"domain": "p.example.com", "framework": "node", "mode": "node", "node_version": "22"})
	if err != nil {
		t.Fatal(err)
	}
	port := out.(map[string]any)["port"].(int)
	unit := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-web_p_example_com-app.service"))
	for _, want := range []string{"AmbientCapabilities=CAP_NET_BIND_SERVICE", "SocketBindDeny=any", "SocketBindAllow=tcp:" + itoa(port)} {
		if !strings.Contains(unit, want) {
			t.Errorf("node unit missing %q", want)
		}
	}
	// Other units may not bind IP ports at all.
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "q.example.com", "framework": "symfony", "mode": "worker"}); err != nil {
		t.Fatal(err)
	}
	u := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-web_q_example_com-app.service"))
	if !strings.Contains(u, "SocketBindDeny=any") || !strings.Contains(u, "CapabilityBoundingSet=\n") || strings.Contains(u, "SocketBindAllow") {
		t.Errorf("worker app unit:\n%s", u)
	}
}

func itoa(n int) string { return strconv.Itoa(n) }

func TestReverbAndPulse(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "rt.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, workerCreate, map[string]string{"domain": "rt.example.com", "worker": "reverb"})
	if err != nil {
		t.Fatal(err)
	}
	port := out.(map[string]any)["port"].(int)
	user := "web_rt_example_com"
	unit := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-reverb.service"))
	if !strings.Contains(unit, "artisan reverb:start --host=127.0.0.1 --port="+itoa(port)) || !strings.Contains(unit, "SocketBindAllow=tcp:"+itoa(port)) {
		t.Errorf("reverb unit:\n%s", unit)
	}
	edge := read(t, filepath.Join(env.Cfg.ProxySitesDir, "rt.example.com.caddy"))
	if !strings.Contains(edge, "@reverb path /app /app/* /apps /apps/*") || !strings.Contains(edge, "reverse_proxy 127.0.0.1:"+itoa(port)) {
		t.Errorf("edge:\n%s", edge)
	}
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "rt.example.com", "worker": "pulse"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-pulse.service")); !strings.Contains(u, "artisan pulse:check") {
		t.Errorf("pulse unit:\n%s", u)
	}
	// Reverb survives a switch to worker mode (port and route kept, now on FrankenPHP's PHP).
	if _, err := call(t, env, siteSwitchMode, map[string]string{"domain": "rt.example.com", "mode": "worker", "framework": "laravel"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-reverb.service")); !strings.Contains(u, "frankenphp php-cli artisan reverb:start --host=127.0.0.1 --port="+itoa(port)) {
		t.Errorf("reverb after switch:\n%s", u)
	}
	if e := read(t, filepath.Join(env.Cfg.ProxySitesDir, "rt.example.com.caddy")); !strings.Contains(e, "reverse_proxy 127.0.0.1:"+itoa(port)) {
		t.Errorf("reverb route lost on switch:\n%s", e)
	}
	// Removing Reverb drops the route and frees the port.
	if _, err := call(t, env, workerDelete, map[string]string{"domain": "rt.example.com", "worker": "reverb"}); err != nil {
		t.Fatal(err)
	}
	if e := read(t, filepath.Join(env.Cfg.ProxySitesDir, "rt.example.com.caddy")); strings.Contains(e, "@reverb") {
		t.Errorf("reverb route left behind:\n%s", e)
	}
	st, _, _ := loadSite(env, "rt.example.com")
	if st.ReverbPort != 0 {
		t.Error("reverb port not released")
	}
}

func TestParsePublicKey(t *testing.T) {
	if got, err := parsePublicKey("  " + testKey + "  "); err != nil || got != testKey {
		t.Fatalf("%q %v", got, err)
	}
	bad := []string{
		`command="sh" ` + testKey,        // options
		"ssh-dss AAAAB3NzaC1kc3MAAACBAP", // unsupported type
		"ssh-ed25519 not-base64!!",       // bad base64
		"ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAIGWV8cCv0YxZ7QS0jcjKOzzD8qUuDmzQ4b0V2Rz1fNnS", // type mismatch
		testKey + "\nssh-ed25519 AAAA",                        // newline smuggling
		strings.Replace(testKey, "admin@laptop", "x\x01y", 1), // control chars in comment
	}
	for _, k := range bad {
		if _, err := parsePublicKey(k); err == nil {
			t.Errorf("accepted %q", k)
		}
	}
}

func TestSSHSet(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "ssh.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	user := "web_ssh_example_com"
	keyFile := filepath.Join(env.Cfg.AuthorizedKeysDir, user)

	if _, err := call(t, env, sshSet, map[string]any{"domain": "ssh.example.com", "access": "shell", "keys": []string{}}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("no keys: %v", err)
	}
	before := len(dr.Calls())
	if _, err := call(t, env, sshSet, map[string]any{"domain": "ssh.example.com", "access": "shell", "keys": []string{testKey}}); err != nil {
		t.Fatal(err)
	}
	if got := read(t, keyFile); !strings.Contains(got, "\nrestrict,pty "+testKey+"\n") {
		t.Errorf("authorized keys:\n%s", got)
	}
	if fi, _ := os.Stat(keyFile); fi.Mode().Perm() != 0o644 {
		t.Errorf("key file mode %v", fi.Mode().Perm())
	}
	if c := dr.Calls()[before]; c.String() != "usermod --groups wspanel-ssh --shell /bin/bash "+user {
		t.Errorf("usermod: %s", c)
	}

	if _, err := call(t, env, sshSet, map[string]any{"domain": "ssh.example.com", "access": "sftp", "keys": []string{testKey}}); err != nil {
		t.Fatal(err)
	}
	if got := read(t, keyFile); !strings.Contains(got, "\nrestrict "+testKey+"\n") {
		t.Errorf("sftp keys must not allow a pty:\n%s", got)
	}
	var sawGroup bool
	for _, c := range dr.Calls() {
		if c.String() == "usermod --groups wspanel-sftp --shell /usr/sbin/nologin "+user {
			sawGroup = true
		}
	}
	if !sawGroup {
		t.Error("sftp group/shell not set")
	}

	if _, err := call(t, env, sshSet, map[string]any{"domain": "ssh.example.com", "access": "off", "keys": []string{}}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(keyFile); !os.IsNotExist(err) {
		t.Error("key file not removed on off")
	}
	if st, _, _ := loadSite(env, "ssh.example.com"); st.SSHAccess != "off" {
		t.Errorf("state: %+v", st)
	}
}

func TestToolLinks(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "tl.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(env.Cfg.ToolsDir, "web_tl_example_com")
	if target, _ := os.Readlink(filepath.Join(dir, "php")); target != "/usr/bin/php8.3" {
		t.Errorf("php -> %q", target)
	}
	if target, _ := os.Readlink(filepath.Join(dir, "node")); !strings.Contains(target, "v24.1.0") {
		t.Errorf("PHP site gets the newest Node for asset builds, node -> %q", target)
	}
	if _, err := call(t, env, siteSwitchMode, map[string]string{"domain": "tl.example.com", "mode": "worker", "framework": "laravel"}); err != nil {
		t.Fatal(err)
	}
	if w := read(t, filepath.Join(dir, "php")); !strings.Contains(w, "exec /usr/local/bin/frankenphp php-cli \"$@\"") {
		t.Errorf("worker php wrapper:\n%s", w)
	}
}

func TestCurrentReleaseValidation(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "cr.example.com", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	_, l, err := loadSite(env, "cr.example.com")
	if err != nil {
		t.Fatal(err)
	}
	if name, err := currentRelease(l); err != nil || name != "initial" {
		t.Fatalf("%q %v", name, err)
	}
	cur := filepath.Join(l.Home, "current")
	for _, target := range []string{"/etc", "releases/../../other", "shared"} {
		os.Remove(cur)
		os.Symlink(target, cur)
		if _, err := currentRelease(l); err == nil {
			t.Errorf("accepted current -> %s", target)
		}
	}
	// releases/<name> itself being a symlink is rejected too.
	os.Remove(cur)
	os.Symlink("/etc", filepath.Join(l.Home, "releases", "evil"))
	os.Symlink("releases/evil", cur)
	if _, err := currentRelease(l); err == nil {
		t.Error("accepted releases/evil -> /etc")
	}
}
