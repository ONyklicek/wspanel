package ops

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/smtp"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// External mailboxes: the mail of a provider's mailbox (one that is too
// small there, say 1 GB) is downloaded into a mailbox here every two
// minutes and removed at the provider after KeepDays, so the provider's
// limit stops mattering. getmail (getmail6) runs as vmail and delivers
// over Dovecot's LMTP, so the quota, the domain's sender lists and the
// vacation reply apply as to any other mail. What the mailbox sends leaves
// through the provider's SMTP with the mailbox's own login (Postfix picks
// the route by the sender), and its copy stays in Sent here.

type extFetch struct {
	Protocol string `json:"protocol"` // imap | pop3, always over TLS
	Host     string `json:"host"`
	Port     int    `json:"port"`
	User     string `json:"user"`
	Password string `json:"password"`
	// KeepDays: how long a downloaded message stays at the provider
	// (0 = removed at once).
	KeepDays int `json:"keep_days"`
}

type extRelay struct {
	Host     string `json:"host"`
	Port     int    `json:"port"` // 587 (STARTTLS) or 465 (TLS)
	User     string `json:"user"`
	Password string `json:"password"`
}

const (
	mailFetchUnit = "wspanel-mailfetch"
	// MailFetchEvery is how often the provider is asked for new mail.
	mailFetchEvery = "2min"
)

var (
	// A login at a provider: an address or a user name, nothing that could
	// break getmail's file or Postfix's map (no spaces, quotes, colons, %).
	extUserRe = regexp.MustCompile(`^[A-Za-z0-9._@+=-]{1,256}$`)
	// The password: anything printable but a line break (the maps are
	// line based); it reaches getmail through a file, never the config.
	extPasswordRe = regexp.MustCompile(`^[^\x00-\x1f\x7f]{1,256}$`)
)

func (f *extFetch) validate() error {
	switch f.Protocol {
	case "imap", "pop3":
	default:
		return protocol.Invalid("fetch.protocol: imap or pop3")
	}
	if f.Port == 0 {
		f.Port = map[string]int{"imap": 993, "pop3": 995}[f.Protocol]
	}
	f.Host = strings.ToLower(strings.TrimSpace(f.Host))
	switch {
	case !relayHostRe.MatchString(f.Host):
		return protocol.Invalid("fetch.host: the provider's server name")
	case f.Port < 1 || f.Port > 65535:
		return protocol.Invalid("fetch.port: 1-65535")
	case !extUserRe.MatchString(f.User):
		return protocol.Invalid("fetch.user: the login at the provider")
	case f.Password != "" && !extPasswordRe.MatchString(f.Password):
		return protocol.Invalid("fetch.password: up to 256 characters, no line breaks")
	case f.KeepDays < 0 || f.KeepDays > 365:
		return protocol.Invalid("fetch.keep_days: 0-365")
	}
	return nil
}

func (r *extRelay) validate() error {
	if r.Port == 0 {
		r.Port = 587
	}
	r.Host = strings.ToLower(strings.TrimSpace(r.Host))
	switch {
	case !relayHostRe.MatchString(r.Host):
		return protocol.Invalid("relay.host: the provider's SMTP server name")
	case r.Port != 587 && r.Port != 465:
		return protocol.Invalid("relay.port: 587 (STARTTLS) or 465 (TLS)")
	case !extUserRe.MatchString(r.User):
		return protocol.Invalid("relay.user: the login at the provider")
	case r.Password != "" && !extPasswordRe.MatchString(r.Password):
		return protocol.Invalid("relay.password: up to 256 characters, no line breaks")
	}
	return nil
}

type extParams struct {
	Address string    `json:"address"`
	Fetch   *extFetch `json:"fetch"` // nil = no downloading
	Relay   *extRelay `json:"relay"` // nil = sends from this server
}

// normalize validates p and fills the passwords left empty (= keep) from
// what mb has, but only for the very same destination (protocol, server,
// port, login): a stored password never goes to a server it was not given
// for (someone who may edit the mailbox could otherwise point it at their
// own server and collect it, also through the test). A relay with the
// fetch's login takes the fetch's password only when it was typed now.
func (p *extParams) normalize(mb mailbox) error {
	fetchTyped := false
	if p.Fetch != nil {
		if err := p.Fetch.validate(); err != nil {
			return err
		}
		fetchTyped = p.Fetch.Password != ""
		if old := mb.Fetch; !fetchTyped && old != nil && old.Protocol == p.Fetch.Protocol && old.Host == p.Fetch.Host && old.Port == p.Fetch.Port && old.User == p.Fetch.User {
			p.Fetch.Password = old.Password
		}
		if p.Fetch.Password == "" {
			return protocol.Invalid("fetch.password: the password at the provider (needed again when the server or login changes)")
		}
	}
	if p.Relay != nil {
		if err := p.Relay.validate(); err != nil {
			return err
		}
		if old := mb.Relay; p.Relay.Password == "" && old != nil && old.Host == p.Relay.Host && old.Port == p.Relay.Port && old.User == p.Relay.User {
			p.Relay.Password = old.Password
		}
		if p.Relay.Password == "" && fetchTyped && p.Fetch.User == p.Relay.User {
			p.Relay.Password = p.Fetch.Password
		}
		if p.Relay.Password == "" {
			return protocol.Invalid("relay.password: the password at the provider (needed again when the server or login changes)")
		}
	}
	return nil
}

// mailMailboxExternal sets (or clears) a mailbox's provider: downloading
// from it, sending through it, or both.
func mailMailboxExternal(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p extParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	lp, d, err := splitAddress("address", p.Address)
	if err != nil {
		return nil, err
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	if !hasMailbox(st, lp, d) {
		return nil, protocol.NotFound("mailbox " + lp + "@" + d + " does not exist")
	}
	mb := st.Domains[d].Mailboxes[lp]
	if err := p.normalize(mb); err != nil {
		return nil, err
	}
	if p.Fetch != nil {
		if err := ensureGetmail(ctx, env); err != nil {
			return nil, err
		}
	}
	mb.Fetch, mb.Relay = p.Fetch, p.Relay
	st.Domains[d].Mailboxes[lp] = mb
	if err := saveMail(ctx, env, st); err != nil {
		return nil, err
	}
	addr := lp + "@" + d
	if p.Fetch != nil {
		// The first download now, not in two minutes.
		_, _ = env.Exec.Run(ctx, "systemctl", "start", "--no-block", mailFetchUnit+".service")
	} else {
		forgetFetchStatus(env, addr)
	}
	return map[string]any{"address": addr, "fetch": p.Fetch != nil, "relay": p.Relay != nil}, nil
}

// ensureGetmail installs getmail6 the first time a mailbox downloads.
func ensureGetmail(ctx context.Context, env *Env) error {
	if env.Cfg.DryRun || fsx.Exists("/usr/bin/getmail") {
		return nil
	}
	if _, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", "install", "-y", "-q", "--no-install-recommends", "getmail6"); err != nil {
		return fmt.Errorf("installing getmail6 failed: %w", err)
	}
	return nil
}

// mailExternalTest: whether the provider lets the login in, for the form's
// "Vyzkoušet" (nothing is stored). Empty passwords are the stored ones.
func mailExternalTest(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p extParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	var mb mailbox
	if lp, d, err := splitAddress("address", p.Address); err == nil {
		if st, err := loadMail(env); err == nil && hasMailbox(st, lp, d) {
			mb = st.Domains[d].Mailboxes[lp]
		}
	}
	if err := p.normalize(mb); err != nil {
		return nil, err
	}
	out := map[string]any{}
	answer := func(err error) map[string]any {
		if err != nil {
			return map[string]any{"ok": false, "error": err.Error()}
		}
		return map[string]any{"ok": true}
	}
	if p.Fetch != nil {
		out["fetch"] = answer(testFetchLogin(ctx, *p.Fetch))
	}
	if p.Relay != nil {
		out["relay"] = answer(testRelayLogin(ctx, env, *p.Relay))
	}
	return out, nil
}

// testFetchLogin signs in to the provider's IMAP or POP3 (over TLS) and out.
func testFetchLogin(ctx context.Context, f extFetch) error {
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	d := tls.Dialer{NetDialer: &net.Dialer{}, Config: &tls.Config{ServerName: f.Host, MinVersion: tls.VersionTLS12}}
	conn, err := d.DialContext(ctx, "tcp", net.JoinHostPort(f.Host, strconv.Itoa(f.Port)))
	if err != nil {
		return fmt.Errorf("the server does not answer over TLS: %w", err)
	}
	defer conn.Close()
	if dl, ok := ctx.Deadline(); ok {
		_ = conn.SetDeadline(dl)
	}
	buf := make([]byte, 0, 4096)
	readLine := func(prefixes ...string) (string, error) {
		for {
			if i := bytes.IndexByte(buf, '\n'); i >= 0 {
				line := strings.TrimRight(string(buf[:i]), "\r")
				buf = buf[i+1:]
				for _, pre := range prefixes {
					if strings.HasPrefix(line, pre) {
						return line, nil
					}
				}
				if len(prefixes) == 0 {
					return line, nil
				}
				continue
			}
			chunk := make([]byte, 2048)
			n, err := conn.Read(chunk)
			if n > 0 {
				buf = append(buf, chunk[:n]...)
			}
			if err != nil {
				return "", err
			}
		}
	}
	send := func(s string) error { _, err := conn.Write([]byte(s + "\r\n")); return err }
	if f.Protocol == "pop3" {
		if l, err := readLine(); err != nil || !strings.HasPrefix(l, "+OK") {
			return errors.New("not a POP3 server")
		}
		for _, cmd := range []string{"USER " + f.User, "PASS " + f.Password} {
			if err := send(cmd); err != nil {
				return err
			}
			if l, err := readLine(); err != nil {
				return err
			} else if !strings.HasPrefix(l, "+OK") {
				return fmt.Errorf("the provider refused the login: %s", strings.TrimSpace(strings.TrimPrefix(l, "-ERR")))
			}
		}
		_ = send("QUIT")
		return nil
	}
	if l, err := readLine(); err != nil || !strings.HasPrefix(l, "* ") {
		return errors.New("not an IMAP server")
	}
	// SASL PLAIN as a literal-free argument: any password, no quoting.
	plain := base64.StdEncoding.EncodeToString([]byte("\x00" + f.User + "\x00" + f.Password))
	if err := send("a1 AUTHENTICATE PLAIN"); err != nil {
		return err
	}
	l, err := readLine("+", "a1 ")
	if err != nil {
		return err
	}
	if strings.HasPrefix(l, "+") {
		if err := send(plain); err != nil {
			return err
		}
		if l, err = readLine("a1 "); err != nil {
			return err
		}
	}
	if !strings.HasPrefix(l, "a1 OK") {
		return fmt.Errorf("the provider refused the login: %s", strings.TrimSpace(strings.TrimPrefix(strings.TrimPrefix(l, "a1 NO"), "a1 BAD")))
	}
	_ = send("a2 LOGOUT")
	return nil
}

// testRelayLogin signs in to the provider's SMTP (TLS on 465, STARTTLS on 587).
func testRelayLogin(ctx context.Context, env *Env, r extRelay) error {
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	addr := net.JoinHostPort(r.Host, strconv.Itoa(r.Port))
	tc := &tls.Config{ServerName: r.Host, MinVersion: tls.VersionTLS12}
	var conn net.Conn
	var err error
	if r.Port == 465 {
		d := tls.Dialer{NetDialer: &net.Dialer{}, Config: tc}
		conn, err = d.DialContext(ctx, "tcp", addr)
	} else {
		conn, err = (&net.Dialer{}).DialContext(ctx, "tcp", addr)
	}
	if err != nil {
		return fmt.Errorf("the SMTP server does not answer: %w", err)
	}
	defer conn.Close()
	if dl, ok := ctx.Deadline(); ok {
		_ = conn.SetDeadline(dl)
	}
	c, err := smtp.NewClient(conn, r.Host)
	if err != nil {
		return fmt.Errorf("not an SMTP server: %w", err)
	}
	defer c.Close()
	if err := c.Hello(mailHostName(env)); err != nil {
		return err
	}
	if r.Port != 465 {
		if ok, _ := c.Extension("STARTTLS"); !ok {
			return errors.New("the SMTP server offers no STARTTLS")
		}
		if err := c.StartTLS(tc); err != nil {
			return fmt.Errorf("STARTTLS failed: %w", err)
		}
	}
	if err := c.Auth(smtp.PlainAuth("", r.User, r.Password, r.Host)); err != nil {
		return fmt.Errorf("the provider refused the login: %w", err)
	}
	_ = c.Quit()
	return nil
}

// writeSenderRelays renders which mailboxes send through their provider:
// Postfix's sender-dependent transport (wspanel-relay = STARTTLS on 587,
// wspanel-relays = TLS on 465; both refuse plain text) and the logins.
func writeSenderRelays(ctx context.Context, env *Env, st *mailState) error {
	var transport, sasl bytes.Buffer
	transport.WriteString("# Managed by WSPanel agent (mail.json).\n")
	sasl.WriteString("# Managed by WSPanel agent (mail.json).\n")
	for _, d := range sortedKeys(st.Domains) {
		md := st.Domains[d]
		for _, lp := range sortedKeys(md.Mailboxes) {
			r := md.Mailboxes[lp].Relay
			if r == nil {
				continue
			}
			via := "wspanel-relay"
			if r.Port == 465 {
				via = "wspanel-relays"
			}
			fmt.Fprintf(&transport, "%s@%s %s:[%s]:%d\n", lp, d, via, r.Host, r.Port)
			fmt.Fprintf(&sasl, "%s@%s %s:%s\n", lp, d, r.User, r.Password)
		}
	}
	for _, f := range []struct {
		path    string
		content []byte
		mode    os.FileMode
	}{
		{env.Cfg.SenderTransport, transport.Bytes(), 0o644},
		{env.Cfg.SenderSASL, sasl.Bytes(), 0o600},
	} {
		if f.path == "" {
			continue
		}
		if err := env.FS.WriteFile(f.path, f.content, f.mode, fsx.Root); err != nil {
			return err
		}
		if _, err := env.Exec.Run(ctx, "postmap", f.path); err != nil {
			return err
		}
	}
	return nil
}

// getmailRC is one mailbox's getmail configuration: the provider's inbox,
// delivered here over LMTP (to this address whatever the message says),
// what was downloaded forgotten at the provider after KeepDays.
func getmailRC(env *Env, addr string, f extFetch) string {
	retriever := map[string]string{"imap": "SimpleIMAPSSLRetriever", "pop3": "SimplePOP3SSLRetriever"}[f.Protocol]
	var b strings.Builder
	fmt.Fprintf(&b, "# Managed by WSPanel agent (mail.json): %s\n", addr)
	fmt.Fprintf(&b, "[retriever]\ntype = %s\nserver = %s\nport = %d\nusername = %s\n", retriever, f.Host, f.Port, f.User)
	fmt.Fprintf(&b, "password_command = (\"/usr/bin/cat\", \"%s\")\n", filepath.Join(env.Cfg.MailFetchDir, addr, "password"))
	fmt.Fprintf(&b, "\n[destination]\ntype = MDA_lmtp\nhost = %s\noverride = %s\n", env.Cfg.MailFetchLMTP, addr)
	b.WriteString("\n[options]\nread_all = false\nreceived = false\ndelivered_to = false\nmessage_log_syslog = false\nverbose = 1\n")
	if f.KeepDays == 0 {
		b.WriteString("delete = true\n")
	} else {
		fmt.Fprintf(&b, "delete = false\ndelete_after = %d\n", f.KeepDays)
	}
	return b.String()
}

// writeMailFetch renders a folder per downloading mailbox (vmail's: the
// configuration, the password, getmail's memory of what it already has),
// drops the folders of the others, and keeps the timer on while any is.
func writeMailFetch(ctx context.Context, env *Env, st *mailState) error {
	root := env.Cfg.MailFetchDir
	if root == "" {
		return nil
	}
	vmail := fsx.Root
	if o, err := lookupOwner(env, "vmail"); err == nil {
		vmail = o
	}
	want := map[string]bool{}
	for _, d := range sortedKeys(st.Domains) {
		md := st.Domains[d]
		for _, lp := range sortedKeys(md.Mailboxes) {
			f := md.Mailboxes[lp].Fetch
			if f == nil {
				continue
			}
			addr := lp + "@" + d
			want[addr] = true
			dir := filepath.Join(root, addr)
			if err := env.FS.MkdirAll(dir, 0o700, vmail); err != nil {
				return err
			}
			if err := env.FS.WriteFile(filepath.Join(dir, "password"), []byte(f.Password), 0o600, vmail); err != nil {
				return err
			}
			if err := env.FS.WriteFile(filepath.Join(dir, "getmailrc"), []byte(getmailRC(env, addr, *f)), 0o600, vmail); err != nil {
				return err
			}
		}
	}
	if len(want) > 0 {
		if err := env.FS.MkdirAll(root, 0o700, vmail); err != nil {
			return err
		}
	}
	if entries, err := os.ReadDir(root); err == nil {
		for _, e := range entries {
			if e.IsDir() && !want[e.Name()] && fsx.Within(root, filepath.Join(root, e.Name())) {
				_ = os.RemoveAll(filepath.Join(root, e.Name()))
			}
		}
	}
	return writeMailFetchTimer(ctx, env, len(want) > 0)
}

// writeMailFetchTimer: the timer that downloads (every two minutes after
// the previous run ended, so two never overlap), only while it has work.
func writeMailFetchTimer(ctx context.Context, env *Env, on bool) error {
	service := filepath.Join(env.Cfg.SystemdDir, mailFetchUnit+".service")
	timer := filepath.Join(env.Cfg.SystemdDir, mailFetchUnit+".timer")
	if !on {
		if !fsx.Exists(timer) {
			return nil
		}
		_, _ = env.Exec.Run(ctx, "systemctl", "disable", "--now", mailFetchUnit+".timer")
		_ = os.Remove(timer)
		_ = os.Remove(service)
		_, err := env.Exec.Run(ctx, "systemctl", "daemon-reload")
		return err
	}
	svc := "# Managed by WSPanel agent (mail.json).\n[Unit]\nDescription=WSPanel: download external mailboxes\n" +
		"Wants=network-online.target\nAfter=network-online.target dovecot.service\n\n[Service]\nType=oneshot\n" +
		"ExecStart=" + agentCommand(env, "-mail-fetch", "all") + "\n" +
		// The first download of a full provider mailbox may take a while.
		"TimeoutStartSec=50min\nNice=10\nIOSchedulingClass=idle\n"
	tmr := "# Managed by WSPanel agent (mail.json).\n[Unit]\nDescription=WSPanel: download external mailboxes every " + mailFetchEvery + "\n\n" +
		"[Timer]\nOnBootSec=1min\nOnUnitInactiveSec=" + mailFetchEvery + "\nAccuracySec=10s\n\n[Install]\nWantedBy=timers.target\n"
	c1, err := writeIfChanged(env, service, svc)
	if err != nil {
		return err
	}
	c2, err := writeIfChanged(env, timer, tmr)
	if err != nil {
		return err
	}
	if c1 || c2 {
		if _, err := env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
			return err
		}
	}
	_, err = env.Exec.Run(ctx, "systemctl", "enable", "--now", mailFetchUnit+".timer")
	return err
}

// fetchStatus: how the last download of a mailbox went.
type fetchStatus struct {
	At        time.Time `json:"at"`
	OK        bool      `json:"ok"`
	Error     string    `json:"error,omitempty"`
	Retrieved int       `json:"retrieved"`
	// LastMail: when a run last brought something.
	LastMail *time.Time `json:"last_mail,omitempty"`
}

func fetchStatusPath(env *Env) string {
	return filepath.Join(env.Cfg.StateDir, "mailfetch-status.json")
}

func loadFetchStatus(env *Env) map[string]fetchStatus {
	out := map[string]fetchStatus{}
	if b, err := os.ReadFile(fetchStatusPath(env)); err == nil {
		_ = json.Unmarshal(b, &out)
	}
	return out
}

func saveFetchStatus(env *Env, s map[string]fetchStatus) {
	b, _ := json.MarshalIndent(s, "", "  ")
	_ = env.FS.MkdirAll(env.Cfg.StateDir, 0o700, fsx.Root)
	_ = env.FS.WriteFile(fetchStatusPath(env), b, 0o600, fsx.Root)
}

func forgetFetchStatus(env *Env, addr string) {
	_ = withMutate(env, func() error {
		s := loadFetchStatus(env)
		if _, ok := s[addr]; ok {
			delete(s, addr)
			saveFetchStatus(env, s)
		}
		return nil
	})
}

// mailFetchStatus: the last download of each mailbox of a domain (all
// without one).
func mailFetchStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	all := loadFetchStatus(env)
	out := map[string]fetchStatus{}
	for addr, s := range all {
		if p.Domain == "" || strings.HasSuffix(addr, "@"+p.Domain) {
			out[addr] = s
		}
	}
	return map[string]any{"mailboxes": out}, nil
}

var getmailRetrievedRe = regexp.MustCompile(`(?m)^\s*(\d+) messages? \(\d+ bytes?\) retrieved`)

// RunMailFetch downloads every external mailbox once (the timer's
// service, as root): getmail as vmail per mailbox, one after another.
func RunMailFetch(ctx context.Context, env *Env) error {
	st, err := loadMail(env)
	if err != nil {
		return err
	}
	var failed []string
	for _, d := range sortedKeys(st.Domains) {
		md := st.Domains[d]
		for _, lp := range sortedKeys(md.Mailboxes) {
			if md.Mailboxes[lp].Fetch == nil {
				continue
			}
			addr := lp + "@" + d
			s := fetchOne(ctx, env, addr)
			_ = withMutate(env, func() error {
				all := loadFetchStatus(env)
				if prev, ok := all[addr]; ok && s.LastMail == nil {
					s.LastMail = prev.LastMail
				}
				all[addr] = s
				saveFetchStatus(env, all)
				return nil
			})
			if !s.OK {
				failed = append(failed, addr+": "+s.Error)
			}
		}
	}
	if len(failed) > 0 {
		return errors.New(strings.Join(failed, "; "))
	}
	return nil
}

func fetchOne(ctx context.Context, env *Env, addr string) fetchStatus {
	dir := filepath.Join(env.Cfg.MailFetchDir, addr)
	ctx, cancel := context.WithTimeout(ctx, 45*time.Minute)
	defer cancel()
	var out bytes.Buffer
	args := append(userCreds("vmail"), "/usr/bin/getmail", "--getmaildir="+dir, "--rcfile="+filepath.Join(dir, "getmailrc"))
	_, err := env.Exec.RunLog(ctx, &out, "setpriv", args...)
	now := time.Now().UTC()
	s := fetchStatus{At: now, OK: err == nil}
	for _, m := range getmailRetrievedRe.FindAllStringSubmatch(out.String(), -1) {
		n, _ := strconv.Atoi(m[1])
		s.Retrieved += n
	}
	if s.Retrieved > 0 {
		s.LastMail = &now
	}
	if err != nil {
		s.Error = getmailError(out.String(), err)
	}
	return s
}

// getmailError: the line of getmail's output that says what went wrong.
func getmailError(out string, err error) string {
	lines := strings.Split(strings.TrimSpace(out), "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		l := strings.TrimSpace(lines[i])
		if l == "" || strings.HasPrefix(l, "getmail version") || strings.HasPrefix(l, "Copyright") {
			continue
		}
		if len(l) > 300 {
			l = l[:300]
		}
		return l
	}
	return err.Error()
}
