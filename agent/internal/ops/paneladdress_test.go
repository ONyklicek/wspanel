package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestPanelPublicAddressGoesToTheEnvAndTheConfigCache(t *testing.T) {
	env, dr := testEnv(t)
	old := panelEnvFile
	panelEnvFile = filepath.Join(t.TempDir(), ".env")
	t.Cleanup(func() { panelEnvFile = old })
	os.WriteFile(panelEnvFile, []byte("APP_URL=https://panel.example.com\nPANEL_PUBLIC_IPV4=192.168.1.10\n"), 0o600)

	for _, bad := range []map[string]string{{"ipv4": "2001:db8::1"}, {"ipv4": "x"}, {"ipv6": "203.0.113.7"}} {
		if _, err := call(t, env, panelPublicAddress, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, panelPublicAddress, map[string]string{"ipv4": "203.0.113.7", "ipv6": "2001:db8::7"}); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(panelEnvFile)
	if string(b) != "APP_URL=https://panel.example.com\nPANEL_PUBLIC_IPV4=203.0.113.7\nPANEL_PUBLIC_IPV6=2001:db8::7\n" {
		t.Errorf(".env: %q", b)
	}
	got := []string{}
	for _, c := range dr.Calls() {
		got = append(got, c.Args[len(c.Args)-1])
	}
	if strings.Join(got, "|") != "config:cache|queue:restart" {
		t.Errorf("calls: %v", got)
	}
}
