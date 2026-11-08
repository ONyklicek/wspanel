package ops

import (
	"context"
	"encoding/json"
	"strings"
	"testing"

	"wspanel/agent/internal/protocol"
)

// fakePDNS answers the PowerDNS API from a handler and records the calls.
func fakePDNS(t *testing.T, answer func(method, path string) (string, int)) *[]string {
	t.Helper()
	old := pdns
	t.Cleanup(func() { pdns = old })
	var calls []string
	pdns = func(_ context.Context, _ *Env, method, path string, body any) ([]byte, int, error) {
		b, _ := json.Marshal(body)
		calls = append(calls, method+" "+path+" "+string(b))
		out, code := answer(method, path)
		return []byte(out), code, nil
	}
	return &calls
}

func TestSecondaryServersMayTransferAndGetNotified(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.PowerDNSAPI, env.Cfg.PowerDNSKey = "http://127.0.0.1:8081", "k"
	calls := fakePDNS(t, func(method, path string) (string, int) {
		if method == "GET" && path == "/zones" {
			return `[{"name":"old.cz.","kind":"Native"},{"name":"new.cz.","kind":"Master"}]`, 200
		}
		return "", 204
	})
	out, err := call(t, env, dnsSecondarySet, map[string]any{"ips": []string{"198.51.100.7", "2001:db8::53"}, "nameservers": []string{"NS2.Provider.net."}})
	if err != nil {
		t.Fatal(err)
	}
	if sec := out.(dnsSecondary); sec.Nameservers[0] != "ns2.provider.net" {
		t.Errorf("nameservers: %v", sec.Nameservers)
	}
	conf := read2(t, env.Cfg.PowerDNSConf)
	for _, want := range []string{"gsqlite3-dnssec=yes", "primary=yes", "allow-axfr-ips=127.0.0.0/8, ::1, 198.51.100.7, 2001:db8::53", "also-notify=198.51.100.7, 2001:db8::53"} {
		if !strings.Contains(conf, want) {
			t.Errorf("missing %q in\n%s", want, conf)
		}
	}
	all := strings.Join(*calls, "\n")
	if !strings.Contains(all, `PUT /zones/old.cz. {"kind":"Master"}`) || strings.Contains(all, `PUT /zones/new.cz. {"kind"`) {
		t.Errorf("only the Native zone becomes Master:\n%s", all)
	}
	for _, bad := range []string{"127.0.0.1", "0.0.0.0", "ns.example.com"} {
		if _, err := call(t, env, dnsSecondarySet, map[string]any{"ips": []string{bad}}); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%s: %v", bad, err)
		}
	}
}

func TestDNSSECAddsOneKeyAndShowsItsDS(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.PowerDNSAPI, env.Cfg.PowerDNSKey = "http://127.0.0.1:8081", "k"
	signed := false
	calls := fakePDNS(t, func(method, path string) (string, int) {
		switch {
		case method == "GET" && path == "/zones/firma.cz./cryptokeys" && signed:
			return `[{"id":1,"active":true,"ds":["12345 13 2 abcdef"]}]`, 200
		case method == "GET" && path == "/zones/firma.cz./cryptokeys":
			return `[]`, 200
		case method == "POST":
			signed = true
		}
		return "", 204
	})
	out, err := call(t, env, dnsDNSSEC, map[string]any{"domain": "firma.cz", "action": "enable"})
	if err != nil {
		t.Fatal(err)
	}
	if m := out.(map[string]any); m["enabled"] != true || m["ds"].([]string)[0] != "12345 13 2 abcdef" {
		t.Errorf("got %v", m)
	}
	if _, err := call(t, env, dnsDNSSEC, map[string]any{"domain": "firma.cz", "action": "enable"}); err != nil {
		t.Fatal(err)
	}
	if n := strings.Count(strings.Join(*calls, "\n"), "POST /zones/firma.cz./cryptokeys"); n != 1 {
		t.Errorf("enabling twice must add one key, added %d", n)
	}
	if !strings.Contains(read2(t, env.Cfg.PowerDNSConf), "gsqlite3-dnssec=yes") {
		t.Error("signing needs gsqlite3-dnssec")
	}
}
