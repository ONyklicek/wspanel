package ops

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"wspanel/agent/internal/protocol"
)

func selfSigned(t *testing.T, names ...string) (string, string) {
	t.Helper()
	k, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: names[0]}, DNSNames: names,
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(90 * 24 * time.Hour)}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &k.PublicKey, k)
	if err != nil {
		t.Fatal(err)
	}
	kb, _ := x509.MarshalECPrivateKey(k)
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})), string(pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb}))
}

func TestInternalDomainsGetTheLocalCA(t *testing.T) {
	env, _ := testEnv(t)
	for d, want := range map[string]bool{"intranet.firma.lan": true, "app.test": true, "shop.example.com": false} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "php", "php_version": "8.4"}); err != nil {
			t.Fatal(err)
		}
		b, _ := os.ReadFile(filepath.Join(env.Cfg.ProxySitesDir, d+".caddy"))
		if strings.Contains(string(b), "tls internal") != want {
			t.Errorf("%s: tls internal = %v, want %v\n%s", d, !want, want, b)
		}
	}
}

func TestCustomCertificate(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "intranet.firma.cz", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	crt, key := selfSigned(t, "other.firma.cz")
	if _, err := call(t, env, siteTLS, map[string]string{"domain": "intranet.firma.cz", "mode": "custom", "cert": crt, "key": key}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("certificate for another name: %v", err)
	}
	_, otherKey := selfSigned(t, "intranet.firma.cz")
	crt, key = selfSigned(t, "intranet.firma.cz", "*.intranet.firma.cz")
	if _, err := call(t, env, siteTLS, map[string]string{"domain": "intranet.firma.cz", "mode": "custom", "cert": crt, "key": otherKey}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("key of another certificate: %v", err)
	}
	if _, err := call(t, env, siteTLS, map[string]string{"domain": "intranet.firma.cz", "mode": "custom", "cert": crt, "key": key}); err != nil {
		t.Fatal(err)
	}
	edge, _ := os.ReadFile(filepath.Join(env.Cfg.ProxySitesDir, "intranet.firma.cz.caddy"))
	if !strings.Contains(string(edge), "tls "+filepath.Join(env.Cfg.ProxyCertDir, "intranet.firma.cz.crt")) {
		t.Fatalf("edge:\n%s", edge)
	}
	if c := readCert(env, "intranet.firma.cz"); c.Status != "valid" || !strings.HasPrefix(c.Issuer, "custom") {
		t.Errorf("status: %+v", c)
	}
	if _, err := call(t, env, siteTLS, map[string]string{"domain": "intranet.firma.cz", "mode": "auto"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(env.Cfg.ProxyCertDir, "intranet.firma.cz.key")); !os.IsNotExist(err) {
		t.Error("key not removed when leaving custom mode")
	}
}
