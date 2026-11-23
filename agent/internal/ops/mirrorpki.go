package ops

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base32"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
)

// The mirror's own certificate authority: made by the primary when the
// mirror is set up, it signs the certificate of each node (the primary's at
// once, the standby's when it pairs). The nodes talk over mutual TLS only:
// each presents its certificate, checks the other's against the CA and its
// name against the node it paired with. Nothing else of the server is
// reachable through the mirror's port.
//
// Pairing is trust on a code: the primary shows a one-time code that holds
// the fingerprint of its certificate and a secret. The standby dials the
// primary, accepts only the certificate with that fingerprint, and sends the
// secret with its certificate request; the primary signs it. The code is
// valid for an hour and once.

const (
	mirrorCertDays   = 3650
	mirrorPairingTTL = time.Hour
	mirrorCodePrefix = "WSPM1"
)

var b32 = base32.StdEncoding.WithPadding(base32.NoPadding)

func mirrorDir(env *Env) string { return filepath.Join(env.Cfg.StateDir, "mirror") }

func mirrorFile(env *Env, name string) string { return filepath.Join(mirrorDir(env), name) }

func writeMirrorFile(env *Env, name string, data []byte) error {
	if err := env.FS.MkdirAll(mirrorDir(env), 0o700, fsx.Root); err != nil {
		return err
	}
	return env.FS.WriteFile(mirrorFile(env, name), data, 0o600, fsx.Root)
}

func newECKey() (*ecdsa.PrivateKey, []byte, error) {
	k, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	der, err := x509.MarshalECPrivateKey(k)
	if err != nil {
		return nil, nil, err
	}
	return k, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: der}), nil
}

func serial() *big.Int {
	n, _ := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
	return n
}

// makeMirrorCA creates the CA and this node's certificate (the primary).
func makeMirrorCA(env *Env, nodeID string, ips []net.IP) error {
	caKey, caKeyPEM, err := newECKey()
	if err != nil {
		return err
	}
	now := time.Now()
	ca := &x509.Certificate{
		SerialNumber:          serial(),
		Subject:               pkix.Name{CommonName: "WSPanel mirror CA " + nodeID},
		NotBefore:             now.Add(-time.Hour),
		NotAfter:              now.AddDate(0, 0, mirrorCertDays),
		IsCA:                  true,
		BasicConstraintsValid: true,
		MaxPathLenZero:        true,
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
	}
	caDER, err := x509.CreateCertificate(rand.Reader, ca, ca, &caKey.PublicKey, caKey)
	if err != nil {
		return err
	}
	caPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: caDER})
	caCert, _ := x509.ParseCertificate(caDER)
	nodeKey, nodeKeyPEM, err := newECKey()
	if err != nil {
		return err
	}
	nodePEM, err := signNodeCert(caCert, caKey, nodeID, &nodeKey.PublicKey, ips)
	if err != nil {
		return err
	}
	for name, data := range map[string][]byte{"ca.crt": caPEM, "ca.key": caKeyPEM, "node.crt": nodePEM, "node.key": nodeKeyPEM} {
		if err := writeMirrorFile(env, name, data); err != nil {
			return err
		}
	}
	return nil
}

func signNodeCert(ca *x509.Certificate, caKey *ecdsa.PrivateKey, nodeID string, pub any, ips []net.IP) ([]byte, error) {
	now := time.Now()
	tpl := &x509.Certificate{
		SerialNumber: serial(),
		Subject:      pkix.Name{CommonName: nodeID},
		DNSNames:     []string{nodeID},
		IPAddresses:  ips,
		NotBefore:    now.Add(-time.Hour),
		NotAfter:     now.AddDate(0, 0, mirrorCertDays),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth, x509.ExtKeyUsageClientAuth},
	}
	der, err := x509.CreateCertificate(rand.Reader, tpl, ca, pub, caKey)
	if err != nil {
		return nil, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), nil
}

// signCSR: the primary signs the standby's request (its name is the node id
// the request carries; the key is the standby's own and never leaves it).
func signCSR(env *Env, csrPEM []byte, nodeID string) ([]byte, error) {
	blk, _ := pem.Decode(csrPEM)
	if blk == nil || blk.Type != "CERTIFICATE REQUEST" {
		return nil, errors.New("not a certificate request")
	}
	csr, err := x509.ParseCertificateRequest(blk.Bytes)
	if err != nil {
		return nil, err
	}
	if err := csr.CheckSignature(); err != nil {
		return nil, err
	}
	if csr.Subject.CommonName != nodeID {
		return nil, errors.New("the request is for another node")
	}
	ca, caKey, err := loadMirrorCA(env)
	if err != nil {
		return nil, err
	}
	return signNodeCert(ca, caKey, nodeID, csr.PublicKey, csr.IPAddresses)
}

func loadMirrorCA(env *Env) (*x509.Certificate, *ecdsa.PrivateKey, error) {
	certPEM, err := os.ReadFile(mirrorFile(env, "ca.crt"))
	if err != nil {
		return nil, nil, err
	}
	keyPEM, err := os.ReadFile(mirrorFile(env, "ca.key"))
	if err != nil {
		return nil, nil, errors.New("this node does not hold the mirror's CA (only the node that set the mirror up does)")
	}
	cb, _ := pem.Decode(certPEM)
	kb, _ := pem.Decode(keyPEM)
	if cb == nil || kb == nil {
		return nil, nil, errors.New("the mirror's CA is damaged")
	}
	ca, err := x509.ParseCertificate(cb.Bytes)
	if err != nil {
		return nil, nil, err
	}
	key, err := x509.ParseECPrivateKey(kb.Bytes)
	return ca, key, err
}

// makeCSR: the standby's key (kept) and its certificate request.
func makeCSR(env *Env, nodeID string, ips []net.IP) ([]byte, error) {
	key, keyPEM, err := newECKey()
	if err != nil {
		return nil, err
	}
	der, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{Subject: pkix.Name{CommonName: nodeID}, IPAddresses: ips}, key)
	if err != nil {
		return nil, err
	}
	if err := writeMirrorFile(env, "node.key", keyPEM); err != nil {
		return nil, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE REQUEST", Bytes: der}), nil
}

func certFingerprint(der []byte) []byte {
	sum := sha256.Sum256(der)
	return sum[:20]
}

// nodeFingerprint: of this node's certificate (in the pairing code).
func nodeFingerprint(env *Env) ([]byte, error) {
	b, err := os.ReadFile(mirrorFile(env, "node.crt"))
	if err != nil {
		return nil, err
	}
	blk, _ := pem.Decode(b)
	if blk == nil {
		return nil, errors.New("the node certificate is damaged")
	}
	return certFingerprint(blk.Bytes), nil
}

// newPairingCode: WSPM1-<fingerprint>-<secret>; the secret's hash is kept.
func newPairingCode(env *Env) (code string, hash string, err error) {
	fp, err := nodeFingerprint(env)
	if err != nil {
		return "", "", err
	}
	secret := make([]byte, 15)
	if _, err := rand.Read(secret); err != nil {
		return "", "", err
	}
	return mirrorCodePrefix + "-" + b32.EncodeToString(fp) + "-" + b32.EncodeToString(secret), secretHash(secret), nil
}

func secretHash(secret []byte) string {
	sum := sha256.Sum256(secret)
	return hex.EncodeToString(sum[:])
}

// parsePairingCode splits a code into the fingerprint and the secret.
func parsePairingCode(code string) (fp, secret []byte, err error) {
	parts := strings.Split(strings.ToUpper(strings.TrimSpace(code)), "-")
	if len(parts) != 3 || parts[0] != mirrorCodePrefix {
		return nil, nil, errors.New("not a mirror pairing code (WSPM1-…)")
	}
	if fp, err = b32.DecodeString(parts[1]); err != nil || len(fp) != 20 {
		return nil, nil, errors.New("the code's fingerprint is damaged")
	}
	if secret, err = b32.DecodeString(parts[2]); err != nil || len(secret) != 15 {
		return nil, nil, errors.New("the code's secret is damaged")
	}
	return fp, secret, nil
}

func pairingSecretOK(st *mirrorState, secret []byte) bool {
	if st.Pairing == nil || time.Now().After(st.Pairing.Expires) {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(secretHash(secret)), []byte(st.Pairing.TokenHash)) == 1
}

// mirrorTLS: this node's certificate and the CA; the peer must present a
// certificate of the CA with the paired node's name. For the listener the
// client certificate is optional at the TLS level (pairing comes without
// one) and checked per request (peerVerified).
func mirrorTLS(env *Env, peerID string, server bool) (*tls.Config, error) {
	cert, err := tls.LoadX509KeyPair(mirrorFile(env, "node.crt"), mirrorFile(env, "node.key"))
	if err != nil {
		return nil, fmt.Errorf("the mirror's node certificate: %w", err)
	}
	caPEM, err := os.ReadFile(mirrorFile(env, "ca.crt"))
	if err != nil {
		return nil, err
	}
	pool := x509.NewCertPool()
	if !pool.AppendCertsFromPEM(caPEM) {
		return nil, errors.New("the mirror's CA is damaged")
	}
	cfg := &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS13}
	if server {
		cfg.ClientCAs = pool
		cfg.ClientAuth = tls.VerifyClientCertIfGiven
		return cfg, nil
	}
	cfg.RootCAs = pool
	// The peer is known by its node name, whatever address it answers on
	// (a changed IP, NAT).
	cfg.ServerName = peerID
	return cfg, nil
}

// peerVerified: the connection carries a certificate of the mirror's CA for
// the paired node.
func peerVerified(cs tls.ConnectionState, peerID string) bool {
	if peerID == "" || len(cs.VerifiedChains) == 0 || len(cs.PeerCertificates) == 0 {
		return false
	}
	return cs.PeerCertificates[0].Subject.CommonName == peerID
}

// pinnedTLS trusts only the certificate with the code's fingerprint (the
// standby's first contact, before it has the CA).
func pinnedTLS(fp []byte) *tls.Config {
	return &tls.Config{
		MinVersion:         tls.VersionTLS13,
		InsecureSkipVerify: true, // replaced by the pin below
		VerifyPeerCertificate: func(raw [][]byte, _ [][]*x509.Certificate) error {
			if len(raw) == 0 || subtle.ConstantTimeCompare(certFingerprint(raw[0]), fp) != 1 {
				return errors.New("the primary's certificate does not match the pairing code")
			}
			return nil
		},
	}
}

func newNodeID() string {
	b := make([]byte, 6)
	_, _ = rand.Read(b)
	return "node-" + hex.EncodeToString(b)
}
