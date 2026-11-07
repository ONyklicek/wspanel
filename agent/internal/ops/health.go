package ops

import (
	"context"
	"encoding/json"
	"net"
	"net/http"
	"strings"
	"time"
)

// siteHealth checks that the site's backend answers HTTP, bypassing the edge:
// classic via the web tier socket, worker via its instance socket, node on
// its 127.0.0.1 port. Read-only.
func siteHealth(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	out := map[string]any{"domain": l.Domain, "mode": l.Mode, "upstream": l.Upstream}
	if env.Cfg.DryRun {
		out["ok"], out["status"], out["dry_run"] = true, 200, true
		return out, nil
	}

	network, addr := "tcp", l.Upstream
	if strings.HasPrefix(addr, "unix/") {
		network, addr = "unix", strings.TrimPrefix(addr, "unix/")
	}
	client := &http.Client{
		Timeout: 5 * time.Second,
		Transport: &http.Transport{
			DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
				return (&net.Dialer{Timeout: 2 * time.Second}).DialContext(ctx, network, addr)
			},
			DisableKeepAlives: true,
		},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "http://"+l.Domain+"/", nil)
	req.Header.Set("X-Forwarded-Proto", "https")
	req.Header.Set("User-Agent", "wspanel-health")
	start := time.Now()
	resp, err := client.Do(req)
	out["ms"] = time.Since(start).Milliseconds()
	if err != nil {
		out["ok"], out["error"] = false, err.Error()
		return out, nil
	}
	resp.Body.Close()
	out["status"] = resp.StatusCode
	out["ok"] = resp.StatusCode < 500
	return out, nil
}
