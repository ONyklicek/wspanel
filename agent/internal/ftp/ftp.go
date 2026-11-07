// Package ftp is a small FTP client for backups: login over TLS (explicit
// AUTH TLS or implicit), passive data connections, store, retrieve, list,
// delete and make directories. Standard library only.
package ftp

import (
	"bufio"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"io"
	"net"
	"path"
	"regexp"
	"strconv"
	"strings"
	"time"
)

type TLSMode string

const (
	Explicit TLSMode = "explicit" // AUTH TLS on the plain port (FTPS, usually 21)
	Implicit TLSMode = "implicit" // TLS from the first byte (usually 990)
	None     TLSMode = "none"     // plain FTP: password and data readable on the way
)

type Config struct {
	Host     string
	Port     int
	Username string
	Password string
	TLS      TLSMode
	// PinSHA256 trusts exactly the certificate with this SHA-256 fingerprint
	// (hex, colons allowed): a NAS with its own certificate. Empty = the
	// system's trusted authorities.
	PinSHA256 string
	Timeout   time.Duration
}

type Entry struct {
	Name     string
	Size     int64
	Modified time.Time
}

// Error is a negative reply of the server.
type Error struct {
	Code int
	Msg  string
}

func (e *Error) Error() string { return fmt.Sprintf("FTP %d: %s", e.Code, e.Msg) }

// IsNotFound: the file or folder does not exist (550 and friends).
func IsNotFound(err error) bool {
	var e *Error
	return errors.As(err, &e) && (e.Code == 550 || e.Code == 450)
}

type Conn struct {
	cfg  Config
	conn net.Conn
	r    *bufio.Reader
	tls  *tls.Config
	host string // the server's address: data connections only go there
}

// Dial connects and logs in.
func Dial(ctx context.Context, cfg Config) (*Conn, error) {
	if cfg.Port == 0 {
		cfg.Port = 21
		if cfg.TLS == Implicit {
			cfg.Port = 990
		}
	}
	if cfg.Timeout == 0 {
		cfg.Timeout = 30 * time.Second
	}
	if strings.ContainsAny(cfg.Username+cfg.Password, "\r\n") {
		return nil, errors.New("user name and password must be one line")
	}
	d := net.Dialer{Timeout: cfg.Timeout}
	raw, err := d.DialContext(ctx, "tcp", net.JoinHostPort(cfg.Host, strconv.Itoa(cfg.Port)))
	if err != nil {
		return nil, err
	}
	c := &Conn{cfg: cfg, conn: raw}
	c.host, _, _ = net.SplitHostPort(raw.RemoteAddr().String())
	c.tls = tlsConfig(cfg)
	if cfg.TLS == Implicit {
		c.conn = tls.Client(raw, c.tls)
	}
	c.r = bufio.NewReader(c.conn)
	if err := c.login(); err != nil {
		c.conn.Close()
		return nil, err
	}
	return c, nil
}

// Fingerprint is the SHA-256 of a certificate as AA:BB:….
func Fingerprint(der []byte) string {
	sum := sha256.Sum256(der)
	parts := make([]string, len(sum))
	for i, b := range sum {
		parts[i] = fmt.Sprintf("%02X", b)
	}
	return strings.Join(parts, ":")
}

func normalizePin(p string) string {
	return strings.ToUpper(strings.NewReplacer(":", "", " ", "").Replace(strings.TrimSpace(p)))
}

// UntrustedError: the server's certificate is not trusted; Fingerprint is
// what to pin after checking it.
type UntrustedError struct {
	Fingerprint string
	Reason      string
}

func (e *UntrustedError) Error() string {
	return "the server's certificate is not trusted (" + e.Reason + "); its SHA-256 fingerprint is " + e.Fingerprint
}

func tlsConfig(cfg Config) *tls.Config {
	tc := &tls.Config{ServerName: cfg.Host, ClientSessionCache: tls.NewLRUClientSessionCache(4), MinVersion: tls.VersionTLS12}
	pin := normalizePin(cfg.PinSHA256)
	// Verification is done here, so that a failure can name the fingerprint.
	tc.InsecureSkipVerify = true
	tc.VerifyConnection = func(cs tls.ConnectionState) error {
		if len(cs.PeerCertificates) == 0 {
			return &UntrustedError{Reason: "no certificate"}
		}
		leaf := cs.PeerCertificates[0]
		fp := Fingerprint(leaf.Raw)
		if pin != "" {
			if strings.ReplaceAll(fp, ":", "") != pin {
				return &UntrustedError{Fingerprint: fp, Reason: "it is not the pinned one"}
			}
			return nil
		}
		inter := x509.NewCertPool()
		for _, c := range cs.PeerCertificates[1:] {
			inter.AddCert(c)
		}
		if _, err := leaf.Verify(x509.VerifyOptions{DNSName: cfg.Host, Intermediates: inter}); err != nil {
			return &UntrustedError{Fingerprint: fp, Reason: err.Error()}
		}
		return nil
	}
	return tc
}

func (c *Conn) login() error {
	if _, _, err := c.expect(220); err != nil {
		return err
	}
	if c.cfg.TLS == Explicit {
		if _, _, err := c.cmd(234, "AUTH TLS"); err != nil {
			return fmt.Errorf("the server does not offer TLS (FTPS): %w", err)
		}
		c.conn = tls.Client(c.conn, c.tls)
		c.r = bufio.NewReader(c.conn)
	}
	code, _, err := c.cmd(0, "USER "+c.cfg.Username)
	if err != nil {
		return err
	}
	if code == 331 {
		if _, _, err := c.cmd(230, "PASS "+c.cfg.Password); err != nil {
			return err
		}
	} else if code != 230 {
		return &Error{Code: code, Msg: "login refused"}
	}
	if c.cfg.TLS != None {
		if _, _, err := c.cmd(200, "PBSZ 0"); err != nil {
			return err
		}
		if _, _, err := c.cmd(200, "PROT P"); err != nil {
			return err
		}
	}
	_, _, err = c.cmd(200, "TYPE I")
	return err
}

func (c *Conn) Close() error {
	_ = c.conn.SetDeadline(time.Now().Add(5 * time.Second))
	_, _, _ = c.cmd(0, "QUIT")
	return c.conn.Close()
}

// readReply reads one (possibly multi-line) reply.
func (c *Conn) readReply() (int, string, error) {
	_ = c.conn.SetReadDeadline(time.Now().Add(c.cfg.Timeout))
	line, err := c.r.ReadString('\n')
	if err != nil {
		return 0, "", err
	}
	if len(line) < 4 {
		return 0, "", errors.New("unreadable FTP reply")
	}
	code, err := strconv.Atoi(line[:3])
	if err != nil {
		return 0, "", errors.New("unreadable FTP reply")
	}
	msg := strings.TrimSpace(line[4:])
	if line[3] == '-' {
		end := line[:3] + " "
		for i := 0; i < 1000; i++ {
			l, err := c.r.ReadString('\n')
			if err != nil {
				return 0, "", err
			}
			if strings.HasPrefix(l, end) {
				break
			}
			msg += "\n" + strings.TrimSpace(l)
		}
	}
	return code, msg, nil
}

func (c *Conn) expect(want int) (int, string, error) {
	code, msg, err := c.readReply()
	if err != nil {
		return 0, "", err
	}
	if want != 0 && code != want && !(want == 200 && code/100 == 2) {
		return code, msg, &Error{Code: code, Msg: msg}
	}
	return code, msg, nil
}

// cmd sends one command; want 0 accepts any reply, 200 any 2xx.
func (c *Conn) cmd(want int, line string) (int, string, error) {
	if strings.ContainsAny(line, "\r\n") {
		return 0, "", errors.New("line break in an FTP command")
	}
	_ = c.conn.SetWriteDeadline(time.Now().Add(c.cfg.Timeout))
	if _, err := io.WriteString(c.conn, line+"\r\n"); err != nil {
		return 0, "", err
	}
	return c.expect(want)
}

var epsvRe = regexp.MustCompile(`\(\|\|\|(\d+)\|\)`)
var pasvRe = regexp.MustCompile(`(\d+),(\d+),(\d+),(\d+),(\d+),(\d+)`)

// data opens a passive data connection, always to the server's own address
// (the address in a PASV reply is ignored: NAT, and no bouncing elsewhere).
func (c *Conn) data() (net.Conn, error) {
	port := 0
	if _, msg, err := c.cmd(229, "EPSV"); err == nil {
		if m := epsvRe.FindStringSubmatch(msg); m != nil {
			port, _ = strconv.Atoi(m[1])
		}
	}
	if port == 0 {
		_, msg, err := c.cmd(227, "PASV")
		if err != nil {
			return nil, err
		}
		m := pasvRe.FindStringSubmatch(msg)
		if m == nil {
			return nil, errors.New("unreadable PASV reply")
		}
		hi, _ := strconv.Atoi(m[5])
		lo, _ := strconv.Atoi(m[6])
		port = hi*256 + lo
	}
	if port <= 0 || port > 65535 {
		return nil, errors.New("bad data port")
	}
	d := net.Dialer{Timeout: c.cfg.Timeout}
	conn, err := d.Dial("tcp", net.JoinHostPort(c.host, strconv.Itoa(port)))
	if err != nil {
		return nil, err
	}
	if c.cfg.TLS != None {
		return tls.Client(conn, c.tls), nil
	}
	return conn, nil
}

// transfer runs a command over a new data connection.
func (c *Conn) transfer(line string, fn func(net.Conn) error) error {
	dc, err := c.data()
	if err != nil {
		return err
	}
	code, msg, err := c.cmd(0, line)
	if err != nil {
		dc.Close()
		return err
	}
	if code != 150 && code != 125 {
		dc.Close()
		return &Error{Code: code, Msg: msg}
	}
	ferr := fn(dc)
	if tc, ok := dc.(*tls.Conn); ok && ferr == nil {
		ferr = tc.CloseWrite()
	}
	dc.Close()
	// The final reply may take a while after a large transfer.
	_ = c.conn.SetReadDeadline(time.Now().Add(5 * time.Minute))
	if _, _, err := c.expect(226); err != nil && ferr == nil {
		ferr = err
	}
	return ferr
}

// Store uploads r under name (in a folder that exists).
func (c *Conn) Store(name string, r io.Reader) error {
	return c.transfer("STOR "+name, func(dc net.Conn) error {
		_, err := io.Copy(&deadlineWriter{dc, c.cfg.Timeout}, r)
		return err
	})
}

// Retrieve downloads name into w.
func (c *Conn) Retrieve(name string, w io.Writer) (int64, error) {
	var n int64
	err := c.transfer("RETR "+name, func(dc net.Conn) error {
		var err error
		n, err = io.Copy(w, &deadlineReader{dc, c.cfg.Timeout})
		return err
	})
	return n, err
}

func (c *Conn) Delete(name string) error {
	_, _, err := c.cmd(250, "DELE "+name)
	return err
}

// MkdirAll makes every missing folder of dir (an existing one is fine).
func (c *Conn) MkdirAll(dir string) error {
	dir = strings.Trim(path.Clean(dir), "/")
	if dir == "." || dir == "" {
		return nil
	}
	acc := ""
	for _, part := range strings.Split(dir, "/") {
		acc = path.Join(acc, part)
		code, msg, err := c.cmd(0, "MKD "+acc)
		if err != nil {
			return err
		}
		if code != 257 && code != 550 && code != 521 {
			return &Error{Code: code, Msg: msg}
		}
	}
	return nil
}

// List returns the files of dir: MLSD where the server has it, otherwise
// NLST with SIZE and MDTM per file. A missing folder is an empty list.
func (c *Conn) List(dir string) ([]Entry, error) {
	var out []Entry
	var buf strings.Builder
	err := c.transfer("MLSD "+dir, func(dc net.Conn) error {
		_, err := io.Copy(&buf, io.LimitReader(&deadlineReader{dc, c.cfg.Timeout}, 16<<20))
		return err
	})
	if err == nil {
		for _, line := range strings.Split(buf.String(), "\n") {
			facts, name, ok := strings.Cut(strings.TrimRight(line, "\r"), " ")
			if !ok {
				continue
			}
			e := Entry{Name: name}
			isFile := false
			for _, f := range strings.Split(facts, ";") {
				k, v, _ := strings.Cut(f, "=")
				switch strings.ToLower(k) {
				case "type":
					isFile = strings.EqualFold(v, "file")
				case "size":
					e.Size, _ = strconv.ParseInt(v, 10, 64)
				case "modify":
					e.Modified, _ = time.Parse("20060102150405", v[:min(len(v), 14)])
				}
			}
			if isFile {
				out = append(out, e)
			}
		}
		return out, nil
	}
	var fe *Error
	if !errors.As(err, &fe) {
		return nil, err
	}
	if IsNotFound(err) {
		return nil, nil
	}
	// No MLSD (vsftpd): names, then size and time of each.
	buf.Reset()
	if err := c.transfer("NLST "+dir, func(dc net.Conn) error {
		_, err := io.Copy(&buf, io.LimitReader(&deadlineReader{dc, c.cfg.Timeout}, 16<<20))
		return err
	}); err != nil {
		if IsNotFound(err) {
			return nil, nil
		}
		return nil, err
	}
	for _, line := range strings.Split(buf.String(), "\n") {
		name := path.Base(strings.TrimSpace(line))
		if name == "" || name == "." || name == ".." {
			continue
		}
		full := path.Join(dir, name)
		_, msg, err := c.cmd(213, "SIZE "+full)
		if err != nil {
			continue // a folder
		}
		e := Entry{Name: name}
		e.Size, _ = strconv.ParseInt(strings.TrimSpace(msg), 10, 64)
		if _, msg, err := c.cmd(213, "MDTM "+full); err == nil {
			e.Modified, _ = time.Parse("20060102150405", strings.TrimSpace(msg)[:min(len(strings.TrimSpace(msg)), 14)])
		}
		out = append(out, e)
	}
	return out, nil
}

// deadline wrappers keep a stalled data connection from hanging for ever.
type deadlineWriter struct {
	c net.Conn
	d time.Duration
}

func (w *deadlineWriter) Write(p []byte) (int, error) {
	_ = w.c.SetWriteDeadline(time.Now().Add(w.d))
	return w.c.Write(p)
}

type deadlineReader struct {
	c net.Conn
	d time.Duration
}

func (r *deadlineReader) Read(p []byte) (int, error) {
	_ = r.c.SetReadDeadline(time.Now().Add(r.d))
	return r.c.Read(p)
}
