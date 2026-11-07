package ftp

import (
	"bufio"
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net"
	"path"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"
)

// server is a small FTP server: one user, files in memory, explicit TLS
// (or none), passive mode, MLSD optional.
type server struct {
	ln      net.Listener
	tls     *tls.Config
	mlsd    bool
	mu      sync.Mutex
	files   map[string][]byte
	dirs    map[string]bool
	sawPass string
}

func newServer(t *testing.T, withTLS, mlsd bool) *server {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	s := &server{ln: ln, mlsd: mlsd, files: map[string][]byte{}, dirs: map[string]bool{".": true}}
	if withTLS {
		s.tls = selfSigned(t)
	}
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go s.serve(c)
		}
	}()
	t.Cleanup(func() { ln.Close() })
	return s
}

func selfSigned(t *testing.T) *tls.Config {
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "localhost"}, DNSNames: []string{"localhost"},
		IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour)}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return &tls.Config{Certificates: []tls.Certificate{{Certificate: [][]byte{der}, PrivateKey: key}}}
}

func (s *server) port() int { return s.ln.Addr().(*net.TCPAddr).Port }

func (s *server) fingerprint() string { return Fingerprint(s.tls.Certificates[0].Certificate[0]) }

func (s *server) serve(conn net.Conn) {
	defer conn.Close()
	r := bufio.NewReader(conn)
	reply := func(f string, a ...any) { fmt.Fprintf(conn, f+"\r\n", a...) }
	reply("220 fake")
	var data net.Listener
	prot := false
	authed := false
	open := func() net.Conn {
		if data == nil {
			return nil
		}
		dc, err := data.Accept()
		data.Close()
		data = nil
		if err != nil {
			return nil
		}
		if prot {
			return tls.Server(dc, s.tls)
		}
		return dc
	}
	for {
		line, err := r.ReadString('\n')
		if err != nil {
			return
		}
		line = strings.TrimRight(line, "\r\n")
		cmd, arg, _ := strings.Cut(line, " ")
		if !authed && !map[string]bool{"AUTH": true, "USER": true, "PASS": true, "QUIT": true}[cmd] {
			reply("530 log in first")
			continue
		}
		s.mu.Lock()
		switch cmd {
		case "AUTH":
			if s.tls == nil {
				reply("502 no TLS")
				break
			}
			reply("234 go")
			tc := tls.Server(conn, s.tls)
			conn, r = tc, bufio.NewReader(tc)
		case "USER":
			reply("331 password")
		case "PASS":
			s.sawPass = arg
			if arg == "secret" {
				authed = true
				reply("230 in")
			} else {
				reply("530 wrong")
			}
		case "PBSZ":
			reply("200 ok")
		case "PROT":
			prot = arg == "P"
			reply("200 ok")
		case "TYPE":
			reply("200 binary")
		case "EPSV":
			data, _ = net.Listen("tcp", "127.0.0.1:0")
			reply("229 Entering (|||%d|)", data.Addr().(*net.TCPAddr).Port)
		case "MKD":
			if s.dirs[arg] {
				reply("550 exists")
			} else {
				s.dirs[arg] = true
				reply("257 created")
			}
		case "STOR":
			if !s.dirs[path.Dir(arg)] {
				reply("553 no such folder")
				break
			}
			reply("150 send")
			s.mu.Unlock()
			dc := open()
			b, _ := io.ReadAll(dc)
			dc.Close()
			s.mu.Lock()
			s.files[arg] = b
			reply("226 stored")
		case "RETR":
			b, ok := s.files[arg]
			if !ok {
				reply("550 not found")
				break
			}
			reply("150 sending")
			dc := open()
			dc.Write(b)
			dc.Close()
			reply("226 sent")
		case "DELE":
			if _, ok := s.files[arg]; !ok {
				reply("550 not found")
				break
			}
			delete(s.files, arg)
			reply("250 deleted")
		case "MLSD", "NLST":
			if cmd == "MLSD" && !s.mlsd {
				reply("500 unknown command")
				break
			}
			if !s.dirs[arg] {
				reply("550 no such folder")
				break
			}
			var names []string
			for k := range s.files {
				if path.Dir(k) == arg {
					names = append(names, path.Base(k))
				}
			}
			sort.Strings(names)
			reply("150 listing")
			dc := open()
			for _, n := range names {
				if cmd == "MLSD" {
					fmt.Fprintf(dc, "type=file;size=%d;modify=20261107190148; %s\r\n", len(s.files[path.Join(arg, n)]), n)
				} else {
					fmt.Fprintf(dc, "%s\r\n", path.Join(arg, n))
				}
			}
			if cmd == "MLSD" {
				fmt.Fprint(dc, "type=dir;modify=20261107190148; sub\r\n")
			}
			dc.Close()
			reply("226 done")
		case "SIZE":
			if b, ok := s.files[arg]; ok {
				reply("213 %d", len(b))
			} else {
				reply("550 not a file")
			}
		case "MDTM":
			reply("213 20261107190148")
		case "QUIT":
			reply("221 bye")
			s.mu.Unlock()
			return
		default:
			reply("502 not implemented")
		}
		s.mu.Unlock()
	}
}

func TestStoreListRetrieveDeleteOverTLS(t *testing.T) {
	for _, mlsd := range []bool{true, false} {
		s := newServer(t, true, mlsd)
		ctx := context.Background()
		c, err := Dial(ctx, Config{Host: "127.0.0.1", Port: s.port(), Username: "u", Password: "secret", TLS: Explicit, PinSHA256: s.fingerprint()})
		if err != nil {
			t.Fatal(err)
		}
		if err := c.MkdirAll("srv/sites/a"); err != nil {
			t.Fatal(err)
		}
		if err := c.MkdirAll("srv/sites/a"); err != nil {
			t.Fatal("an existing folder must be fine:", err)
		}
		body := bytes.Repeat([]byte("backup"), 50000)
		if err := c.Store("srv/sites/a/x.tar.gz", bytes.NewReader(body)); err != nil {
			t.Fatal(err)
		}
		entries, err := c.List("srv/sites/a")
		if err != nil || len(entries) != 1 || entries[0].Name != "x.tar.gz" || entries[0].Size != int64(len(body)) || entries[0].Modified.Year() != 2026 {
			t.Fatalf("mlsd=%v: %+v %v", mlsd, entries, err)
		}
		if e, err := c.List("srv/nothing"); err != nil || len(e) != 0 {
			t.Fatalf("missing folder: %v %v", e, err)
		}
		var got bytes.Buffer
		if n, err := c.Retrieve("srv/sites/a/x.tar.gz", &got); err != nil || n != int64(len(body)) || !bytes.Equal(got.Bytes(), body) {
			t.Fatalf("%d %v", n, err)
		}
		if err := c.Delete("srv/sites/a/x.tar.gz"); err != nil {
			t.Fatal(err)
		}
		if _, err := c.Retrieve("srv/sites/a/x.tar.gz", io.Discard); !IsNotFound(err) {
			t.Fatal(err)
		}
		c.Close()
	}
}

func TestLoginRequiresTLSAndTheRightPassword(t *testing.T) {
	plain := newServer(t, false, true)
	ctx := context.Background()
	if _, err := Dial(ctx, Config{Host: "127.0.0.1", Port: plain.port(), Username: "u", Password: "secret", TLS: Explicit}); err == nil || !strings.Contains(err.Error(), "TLS") {
		t.Fatalf("logged in without TLS: %v", err)
	}
	if plain.sawPass != "" {
		t.Fatal("the password was sent in clear")
	}
	secure := newServer(t, true, true)
	_, err := Dial(ctx, Config{Host: "127.0.0.1", Port: secure.port(), Username: "u", Password: "secret", TLS: Explicit})
	var ue *UntrustedError
	if !errors.As(err, &ue) || ue.Fingerprint != secure.fingerprint() {
		t.Fatalf("a self-signed certificate must be refused and named: %v", err)
	}
	if secure.sawPass != "" {
		t.Fatal("the password went to an untrusted server")
	}
	other := newServer(t, true, true)
	if _, err := Dial(ctx, Config{Host: "127.0.0.1", Port: secure.port(), Username: "u", Password: "secret", TLS: Explicit, PinSHA256: other.fingerprint()}); !errors.As(err, &ue) {
		t.Fatalf("another pinned certificate accepted: %v", err)
	}
	if _, err := Dial(ctx, Config{Host: "127.0.0.1", Port: secure.port(), Username: "u", Password: "wrong", TLS: Explicit, PinSHA256: secure.fingerprint()}); err == nil {
		t.Fatal("wrong password accepted")
	}
	if _, err := Dial(ctx, Config{Host: "127.0.0.1", Port: secure.port(), Username: "u\r\nDELE x", Password: "secret", TLS: Explicit}); err == nil {
		t.Fatal("command injection through the user name")
	}
	c, err := Dial(ctx, Config{Host: "127.0.0.1", Port: plain.port(), Username: "u", Password: "secret", TLS: None})
	if err != nil {
		t.Fatal("plain FTP when chosen:", err)
	}
	c.Close()
}
