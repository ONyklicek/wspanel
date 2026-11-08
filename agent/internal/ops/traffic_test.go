package ops

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func accessLine(ts float64, ip, uri string, status int, size int64, ua, ref string) string {
	return fmt.Sprintf(`{"level":"info","ts":%f,"msg":"handled request","request":{"client_ip":%q,"uri":%q,"headers":{"User-Agent":[%q],"Referer":[%q]}},"duration":0.05,"size":%d,"status":%d}`+"\n",
		ts, ip, uri, ua, ref, size, status)
}

func TestTrafficSumsTheNewLinesByHour(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.AccessLogDir = t.TempDir()
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "t.example.com", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	log := filepath.Join(env.Cfg.AccessLogDir, "t.example.com.log")
	h := float64(1794100000 / 3600 * 3600)
	body := accessLine(h+1, "1.2.3.4", "/?q=1", 200, 1000, "Mozilla/5.0", "https://google.com/search") +
		accessLine(h+2, "1.2.3.4", "/kontakt", 404, 10, "Mozilla/5.0", "https://t.example.com/") +
		accessLine(h+3, "5.6.7.8", "/", 502, 0, "Googlebot/2.1", "") +
		accessLine(h+3601, "5.6.7.8", "/", 200, 500, "Mozilla/5.0", "")
	if err := os.WriteFile(log, []byte(body+`{"ts":1794100000,"partial`), 0o644); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, siteTraffic, map[string]string{"domain": "t.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	b := out.(map[string]any)["buckets"].([]trafficBucket)
	if len(b) != 2 || b[0].Requests != 3 || b[1].Requests != 1 {
		t.Fatalf("by hour: %+v", b)
	}
	first := b[0]
	if first.S2xx != 1 || first.S4xx != 1 || first.S5xx != 1 || first.Bytes != 1010 || first.Bots != 1 {
		t.Errorf("counts: %+v", first)
	}
	if first.Paths["/"] != 2 || first.IPs["1.2.3.4"] != 2 || first.Referers["google.com"] != 1 || len(first.Referers) != 1 {
		t.Errorf("tops (query cut, own domain not a referer): %+v", first)
	}

	// The next call reads only what was added (the partial line is finished now).
	f, _ := os.OpenFile(log, os.O_APPEND|os.O_WRONLY, 0)
	_, _ = f.WriteString(`"}` + "\n" + accessLine(h+3602, "9.9.9.9", "/new", 200, 1, "Mozilla/5.0", ""))
	f.Close()
	out, _ = call(t, env, siteTraffic, map[string]string{"domain": "t.example.com"})
	b = out.(map[string]any)["buckets"].([]trafficBucket)
	if len(b) != 1 || b[0].Requests != 1 || b[0].Paths["/new"] != 1 {
		t.Errorf("only the new lines: %+v", b)
	}

	// Rotated (a new file): from its start.
	_ = os.Remove(log)
	_ = os.WriteFile(log, []byte(accessLine(h+7200, "1.1.1.1", "/r", 200, 1, "x", "")), 0o644)
	out, _ = call(t, env, siteTraffic, map[string]string{"domain": "t.example.com"})
	if b = out.(map[string]any)["buckets"].([]trafficBucket); len(b) != 1 || b[0].Paths["/r"] != 1 {
		t.Errorf("after rotation: %+v", b)
	}
	edge := read2(t, filepath.Join(env.Cfg.ProxySitesDir, "t.example.com.caddy"))
	if !strings.Contains(edge, "output file "+log) || !strings.Contains(edge, "format json") {
		t.Errorf("the edge writes the site's JSON log:\n%s", edge)
	}
}
