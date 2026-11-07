package ops

import (
	"context"
	"encoding/json"
	"strings"
	"testing"

	"wspanel/agent/internal/protocol"
)

func TestNormalizeRecord(t *testing.T) {
	long := strings.Repeat("a", 300)
	for _, c := range []struct{ typ, in, want string }{
		{"A", "203.0.113.5", "203.0.113.5"},
		{"AAAA", "2001:db8::1", "2001:db8::1"},
		{"CNAME", "Shop.Example.com", "shop.example.com."},
		{"MX", "10 mail.example.com.", "10 mail.example.com."},
		{"TXT", "v=spf1 a mx ~all", `"v=spf1 a mx ~all"`},
		{"TXT", long, `"` + long[:255] + `" "` + long[255:] + `"`},
		{"CAA", `0 issue "letsencrypt.org"`, `0 issue "letsencrypt.org"`},
	} {
		got, err := normalizeRecord(c.typ, c.in)
		if err != nil || got != c.want {
			t.Errorf("%s %q: got %q, %v; want %q", c.typ, c.in, got, err, c.want)
		}
	}
	for _, c := range [][2]string{{"A", "2001:db8::1"}, {"AAAA", "1.2.3.4"}, {"CNAME", "not a host"}, {"MX", "mail.example.com"}, {"TXT", "a\"b"}, {"TXT", "a\nb"}} {
		if _, err := normalizeRecord(c[0], c[1]); err == nil {
			t.Errorf("%s %q must be rejected", c[0], c[1])
		}
	}
}

func TestDNSRecordSetStaysInsideTheZone(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.PowerDNSAPI, env.Cfg.PowerDNSKey = "http://127.0.0.1:8081", "k"
	var sent []map[string]any
	pdns = func(_ context.Context, _ *Env, method, path string, body any) ([]byte, int, error) {
		b, _ := json.Marshal(body)
		var m map[string]any
		_ = json.Unmarshal(b, &m)
		sent = append(sent, map[string]any{"method": method, "path": path, "body": m})
		return []byte("{}"), 204, nil
	}
	if _, err := call(t, env, dnsRecordSet, map[string]any{"domain": "example.com", "name": "www.example.com", "type": "CNAME", "records": []string{"example.com"}}); err != nil {
		t.Fatal(err)
	}
	if len(sent) != 1 || sent[0]["path"] != "/zones/example.com." {
		t.Fatalf("sent: %v", sent)
	}
	for _, bad := range []map[string]any{
		{"domain": "example.com", "name": "evil.com", "type": "A", "records": []string{"1.2.3.4"}},
		{"domain": "example.com", "name": "x.notexample.com", "type": "A", "records": []string{"1.2.3.4"}},
		{"domain": "example.com", "name": "example.com", "type": "CNAME", "records": []string{"other.com"}},
		{"domain": "example.com", "name": "example.com", "type": "SOA", "records": []string{"x"}},
		{"domain": "example.com", "name": "a/../b.example.com", "type": "A", "records": []string{"1.2.3.4"}},
	} {
		if _, err := call(t, env, dnsRecordSet, bad); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: %v", bad, err)
		}
	}
}
