package ops

import (
	"context"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// TLS per site:
//   auto      certificates from Let's Encrypt / ZeroSSL (the domain must be public)
//   internal  the edge's own CA: for intranet / LAN / test domains; browsers
//             trust it once its root certificate is installed (ssl.root_ca)
//   custom    an uploaded certificate (company CA, purchased certificate)

var TLSModes = []string{"auto", "internal", "custom"}

// internalTLDs cannot get a certificate from a public CA.
var internalTLDs = []string{"localhost", "local", "lan", "internal", "intranet", "test", "home", "corp", "private", "home.arpa", "example", "invalid"}

func isInternalDomain(domain string) bool {
	for _, t := range internalTLDs {
		if strings.HasSuffix(domain, "."+t) {
			return true
		}
	}
	return false
}

type siteTLSParams struct {
	Domain string `json:"domain"`
	Mode   string `json:"mode"`
	Cert   string `json:"cert,omitempty"` // custom: PEM chain (leaf first)
	Key    string `json:"key,omitempty"`  // custom: PEM private key
}

type certSummary struct {
	Subject  string    `json:"subject"`
	Issuer   string    `json:"issuer"`
	Names    []string  `json:"names"`
	NotAfter time.Time `json:"not_after"`
}

// parseCustomCert checks that the pair matches, is in date and covers the domain.
func parseCustomCert(domain, certPEM, keyPEM string) (certSummary, error) {
	if len(certPEM) > 64<<10 || len(keyPEM) > 16<<10 {
		return certSummary{}, protocol.Invalid("cert/key: too large")
	}
	pair, err := tls.X509KeyPair([]byte(certPEM), []byte(keyPEM))
	if err != nil {
		return certSummary{}, protocol.Invalid("cert/key: " + err.Error())
	}
	leaf, err := x509.ParseCertificate(pair.Certificate[0])
	if err != nil {
		return certSummary{}, protocol.Invalid("cert: " + err.Error())
	}
	if err := leaf.VerifyHostname(domain); err != nil {
		return certSummary{}, protocol.Invalid("cert: does not cover " + domain + " (" + strings.Join(leaf.DNSNames, ", ") + ")")
	}
	now := time.Now()
	if now.After(leaf.NotAfter) || now.Before(leaf.NotBefore) {
		return certSummary{}, protocol.Invalid("cert: not valid now (valid " + leaf.NotBefore.Format("2006-01-02") + " – " + leaf.NotAfter.Format("2006-01-02") + ")")
	}
	return certSummary{Subject: leaf.Subject.CommonName, Issuer: leaf.Issuer.CommonName, Names: leaf.DNSNames, NotAfter: leaf.NotAfter}, nil
}

func siteTLS(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p siteTLSParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !slices.Contains(TLSModes, p.Mode) {
		return nil, protocol.Invalid("mode: auto, internal or custom")
	}
	if p.Mode != "custom" && (p.Cert != "" || p.Key != "") {
		return nil, protocol.Invalid("cert/key: only with mode custom")
	}
	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	out := map[string]any{"domain": p.Domain, "mode": p.Mode}
	crt := filepath.Join(env.Cfg.ProxyCertDir, p.Domain+".crt")
	key := filepath.Join(env.Cfg.ProxyCertDir, p.Domain+".key")
	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()
	if p.Mode == "custom" {
		sum, err := parseCustomCert(p.Domain, p.Cert, p.Key)
		if err != nil {
			return nil, err
		}
		owner, err := lookupGroupOwner(env, env.Cfg.ProxyGroup)
		if err != nil {
			return nil, err
		}
		if err := env.FS.MkdirAll(env.Cfg.ProxyCertDir, 0o750, owner); err != nil {
			return nil, err
		}
		oldCrt, _ := os.ReadFile(crt)
		oldKey, _ := os.ReadFile(key)
		if err := env.FS.WriteFile(crt, []byte(p.Cert), 0o640, owner); err != nil {
			return nil, err
		}
		if err := env.FS.WriteFile(key, []byte(p.Key), 0o640, owner); err != nil {
			return nil, err
		}
		rollback.add(func() {
			if oldCrt == nil {
				_ = os.Remove(crt)
				_ = os.Remove(key)
				return
			}
			_ = env.FS.WriteFile(crt, oldCrt, 0o640, owner)
			_ = env.FS.WriteFile(key, oldKey, 0o640, owner)
		})
		out["certificate"] = sum
	}
	prev := st
	st.TLS = p.Mode
	if p.Mode == "auto" {
		st.TLS = ""
	}
	l, err := layout(env.Cfg, st)
	if err != nil {
		return nil, err
	}
	if err = writeEdge(ctx, env, l, &rollback); err != nil {
		return nil, err
	}
	if err = saveSiteState(env, st); err != nil {
		return nil, err
	}
	rollback.add(func() { _ = saveSiteState(env, prev) })
	if p.Mode != "custom" {
		_ = os.Remove(crt)
		_ = os.Remove(key)
	}
	return out, nil
}

// sslRootCA returns the edge's local CA root certificate (public), to be
// installed on computers that open internal-TLS sites.
func sslRootCA(_ context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	b, err := os.ReadFile(filepath.Join(env.Cfg.ProxyStorage, "pki", "authorities", "local", "root.crt"))
	if err != nil {
		return nil, protocol.NotFound("the local CA does not exist yet: it is created with the first internal certificate")
	}
	block, _ := pem.Decode(b)
	if block == nil {
		return nil, fmt.Errorf("root certificate unreadable")
	}
	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil, err
	}
	sum := sha256.Sum256(block.Bytes)
	return map[string]any{
		"pem": string(b), "subject": cert.Subject.CommonName,
		"not_after": cert.NotAfter, "sha256": strings.ToUpper(hex.EncodeToString(sum[:])),
	}, nil
}

// customCertInfo reads an uploaded certificate for ssl.status.
func customCertInfo(env *Env, domain string) (certInfo, bool) {
	b, err := os.ReadFile(filepath.Join(env.Cfg.ProxyCertDir, domain+".crt"))
	if err != nil || !fsx.Exists(filepath.Join(env.Cfg.ProxyCertDir, domain+".key")) {
		return certInfo{}, false
	}
	block, _ := pem.Decode(b)
	if block == nil {
		return certInfo{}, false
	}
	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return certInfo{}, false
	}
	c := certInfo{Domain: domain, Issuer: "custom: " + cert.Issuer.CommonName, NotBefore: cert.NotBefore, NotAfter: cert.NotAfter}
	left := time.Until(cert.NotAfter)
	c.DaysLeft = int(left.Hours() / 24)
	switch {
	case left <= 0:
		c.Status = "expired"
	case left < 14*24*time.Hour: // nobody renews an uploaded certificate automatically
		c.Status = "expiring"
	default:
		c.Status = "valid"
	}
	return c, true
}
