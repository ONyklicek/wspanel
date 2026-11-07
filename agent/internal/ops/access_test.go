package ops

import (
	"os"
	"strings"
	"testing"

	"wspanel/agent/internal/protocol"
)

func TestPanelAccess(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.ProxyConfig = t.TempDir() + "/Caddyfile"
	if _, err := call(t, env, panelAccess, map[string]any{"allow": []string{"10.8.0.0/24", "203.0.113.7"}, "public_hooks": true}); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(panelAccessFile(env))
	for _, want := range []string{"not remote_ip 127.0.0.1/8 ::1 10.8.0.0/24 203.0.113.7", "not path /hooks/deploy/* /api/deploy/*", "respond @panel_outsider 404"} {
		if !strings.Contains(string(b), want) {
			t.Errorf("missing %q:\n%s", want, b)
		}
	}
	for _, bad := range []string{"0.0.0.0/0", "evil\n}", "10.0.0.0/33"} {
		if _, err := call(t, env, panelAccess, map[string]any{"allow": []string{bad}}); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%q: %v", bad, err)
		}
	}
	if _, err := call(t, env, panelAccess, map[string]any{"allow": []string{}}); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(panelAccessFile(env)); strings.Contains(string(b), "remote_ip") {
		t.Errorf("public panel must not restrict:\n%s", b)
	}
}

func TestVersionOrder(t *testing.T) {
	for _, c := range [][2]string{{"v1.9.3", "v1.10.0"}, {"1.0.0-rc1", "1.0.0"}, {"release-x", "v0.0.1"}, {"v2", "v2.0.1"}} {
		if !versionLess(c[0], c[1]) || versionLess(c[1], c[0]) {
			t.Errorf("%s < %s", c[0], c[1])
		}
	}
}
