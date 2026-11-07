package ops

import (
	"context"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"net"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"wspanel/agent/internal/validate"
)

// Certificates are obtained and renewed by the edge proxy (Caddy) on its own
// as soon as a site block exists. The agent only reports what Caddy has and
// checks the prerequisites (DNS points here, 443 open).

type certInfo struct {
	Domain    string    `json:"domain"`
	Issuer    string    `json:"issuer"` // acme-v02.api.letsencrypt.org-directory, local, ...
	NotBefore time.Time `json:"not_before,omitzero"`
	NotAfter  time.Time `json:"not_after,omitzero"`
	DaysLeft  int       `json:"days_left"`
	Status    string    `json:"status"` // valid | expiring | expired | missing
	LastError string    `json:"last_error,omitempty"`
}

// edgeDomains: every domain the edge serves (site blocks + the panel).
func edgeDomains(env *Env) []string {
	files, _ := filepath.Glob(filepath.Join(env.Cfg.ProxySitesDir, "*.caddy"))
	var out []string
	for _, f := range files {
		d := strings.TrimSuffix(filepath.Base(f), ".caddy")
		if validate.Domain(d) == nil {
			out = append(out, d)
		}
	}
	if env.Cfg.PanelDomain != "" {
		out = append(out, env.Cfg.PanelDomain)
	}
	slices.Sort(out)
	return out
}

func readCert(env *Env, domain string) certInfo {
	c := certInfo{Domain: domain, Status: "missing"}
	matches, _ := filepath.Glob(filepath.Join(env.Cfg.ProxyStorage, "certificates", "*", domain, domain+".crt"))
	for _, m := range matches {
		b, err := os.ReadFile(m)
		if err != nil {
			continue
		}
		block, _ := pem.Decode(b)
		if block == nil {
			continue
		}
		cert, err := x509.ParseCertificate(block.Bytes)
		if err != nil || (!c.NotAfter.IsZero() && cert.NotAfter.Before(c.NotAfter)) {
			continue
		}
		c.Issuer = filepath.Base(filepath.Dir(filepath.Dir(m)))
		c.NotBefore, c.NotAfter = cert.NotBefore, cert.NotAfter
	}
	if !c.NotAfter.IsZero() {
		left := time.Until(c.NotAfter)
		c.DaysLeft = int(left.Hours() / 24)
		// Caddy renews when a third of the lifetime is left; under a fifth means
		// renewal is failing. A ratio, not fixed days: lifetimes range from 12 h
		// (local CA) to 90 days and are getting shorter.
		switch {
		case left <= 0:
			c.Status = "expired"
		case left < c.NotAfter.Sub(c.NotBefore)/5:
			c.Status = "expiring"
		default:
			c.Status = "valid"
		}
	}
	return c
}

// lastCertErrors scans the edge's recent log for certificate errors per domain.
func lastCertErrors(ctx context.Context, env *Env) map[string]string {
	res, err := env.Exec.Run(ctx, "journalctl", "--unit", env.Cfg.ProxyUnit, "--since", "-2days", "--output", "cat", "--no-pager", "--lines", "3000")
	out := map[string]string{}
	if err != nil {
		return out
	}
	for _, line := range strings.Split(res.Stdout, "\n") {
		if !strings.Contains(line, `"level":"error"`) || !strings.Contains(line, `"identifier"`) {
			continue
		}
		var e struct {
			Identifier string `json:"identifier"`
			Error      string `json:"error"`
			Msg        string `json:"msg"`
		}
		if json.Unmarshal([]byte(line), &e) == nil && e.Identifier != "" {
			msg := e.Error
			if msg == "" {
				msg = e.Msg
			}
			if len(msg) > 300 {
				msg = msg[:300] + "…"
			}
			out[e.Identifier] = msg
		}
	}
	return out
}

func sslStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	errs := lastCertErrors(ctx, env)
	certs := []certInfo{}
	for _, d := range edgeDomains(env) {
		c := readCert(env, d)
		if c.Status != "valid" {
			c.LastError = errs[d]
		}
		certs = append(certs, c)
	}
	return certs, nil
}

// sslCheck verifies the prerequisites for an ACME certificate: the domain
// resolves to an address of this server, and 443 is allowed in the firewall.
func sslCheck(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	local := localAddresses()
	rctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	addrs, dnsErr := net.DefaultResolver.LookupIPAddr(rctx, p.Domain)
	var resolved []string
	pointsHere := false
	for _, a := range addrs {
		resolved = append(resolved, a.IP.String())
		if local[a.IP.String()] {
			pointsHere = true
		}
	}
	firewallOK := false
	if res, err := env.Exec.Run(ctx, "ufw", "status"); err == nil {
		active, rules := parseUFWStatus(res.Stdout)
		firewallOK = !active
		for _, r := range rules {
			if r.Action == "allow" && r.Port == 443 && (r.Proto == "" || r.Proto == "tcp") && r.From == "Anywhere" {
				firewallOK = true
			}
		}
	}
	out := map[string]any{
		"domain": p.Domain, "resolves_to": resolved, "server_addresses": keys(local),
		"dns_points_here": pointsHere, "port_443_open": firewallOK,
		"note": "Behind NAT or a CDN the public address differs from the server's own; then a mismatch is expected.",
	}
	if dnsErr != nil {
		out["dns_error"] = dnsErr.Error()
	}
	return out, nil
}

func localAddresses() map[string]bool {
	m := map[string]bool{}
	addrs, _ := net.InterfaceAddrs()
	for _, a := range addrs {
		if ipn, ok := a.(*net.IPNet); ok && !ipn.IP.IsLoopback() && !ipn.IP.IsLinkLocalUnicast() {
			m[ipn.IP.String()] = true
		}
	}
	return m
}

func keys(m map[string]bool) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	slices.Sort(out)
	return out
}
