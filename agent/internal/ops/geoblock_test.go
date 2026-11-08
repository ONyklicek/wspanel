package ops

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/protocol"
)

func TestCountriesAreBlockedFromTheirOwnRanges(t *testing.T) {
	env, dr := testEnv(t)
	env.Cfg.ProtectedPorts = []int{2222, 80, 443}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/xx.v4":
			w.Write([]byte("# ranges\n203.0.113.0/24\n198.51.100.0/25\n"))
		case "/xx.v6":
			w.Write([]byte("2001:db8::/32\n"))
		case "/yy.v4":
			w.Write([]byte("192.0.2.0/24; drop table x\n"))
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	old := geoSource
	t.Cleanup(func() { geoSource = old })
	geoSource = func(cc string, v6 bool) string {
		return srv.URL + "/" + cc + map[bool]string{false: ".v4", true: ".v6"}[v6]
	}

	if _, err := call(t, env, fwGeoblock, map[string]any{"action": "set", "countries": []string{"XX"}, "scope": "admin", "allow": []string{"203.0.113.9"}}); err != nil {
		t.Fatal(err)
	}
	rules := read2(t, filepath.Join(env.Cfg.StateDir, "geoblock.nft"))
	for _, want := range []string{
		"elements = { 203.0.113.0/24, 198.51.100.0/25 }",
		"elements = { 2001:db8::/32 }",
		"elements = { 203.0.113.9/32 }",
		"ct state established,related accept",
		"ip saddr @blocked4 meta l4proto { tcp, udp } th dport { 2222 } drop",
	} {
		if !strings.Contains(rules, want) {
			t.Errorf("missing %q in\n%s", want, rules)
		}
	}
	loaded := false
	for _, c := range dr.Calls() {
		loaded = loaded || (c.Name == "nft" && strings.Join(c.Args, " ") == "-f "+filepath.Join(env.Cfg.StateDir, "geoblock.nft"))
	}
	if !loaded {
		t.Error("the ruleset was not loaded")
	}
	if _, err := os.Stat(filepath.Join(env.Cfg.SystemdDir, "wspanel-geoblock.service")); err != nil {
		t.Error("no boot unit")
	}

	if _, err := call(t, env, fwGeoblock, map[string]any{"action": "set", "countries": []string{"yy"}, "scope": "all"}); err == nil {
		t.Error("a list that is not only networks must be refused")
	}
	for _, bad := range []map[string]any{
		{"action": "set", "countries": []string{"x y"}, "scope": "all"},
		{"action": "set", "countries": []string{"xx"}, "scope": "web"},
		{"action": "set", "countries": []string{"xx"}, "scope": "all", "allow": []string{"office"}},
	} {
		if _, err := call(t, env, fwGeoblock, bad); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: %v", bad, err)
		}
	}

	if _, err := call(t, env, fwGeoblock, map[string]any{"action": "set", "countries": []string{}, "scope": "all"}); err != nil {
		t.Fatal(err)
	}
	if rules := read2(t, filepath.Join(env.Cfg.StateDir, "geoblock.nft")); strings.Contains(rules, "chain input") {
		t.Errorf("nothing blocked must leave only the removal:\n%s", rules)
	}
	if _, err := os.Stat(filepath.Join(env.Cfg.SystemdDir, "wspanel-geoblock.service")); !os.IsNotExist(err) {
		t.Error("the boot unit must go")
	}
}
