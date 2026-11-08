package ops

import (
	"context"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Outgoing mail. Postfix listens on loopback only and relays mail from the
// sites (directly to the recipients' MX, or through a configured smarthost);
// OpenDKIM signs it for every domain with a key. In "capture" mode a site's
// mail goes to Mailpit instead and nothing leaves the server.

var MailModes = []string{"send", "capture"}

func sendmailPath(cfg config.Config, l siteLayout) string {
	if l.MailMode == "capture" {
		return cfg.MailpitBin + " sendmail -S " + cfg.MailpitSMTP
	}
	// -f sets the envelope sender, so SPF and DMARC align with the domain.
	return "/usr/sbin/sendmail -t -i -f noreply@" + l.Domain
}

// mailEnv: the variables a framework reads for SMTP, pointing at Postfix
// (port 25, with the site's own login) or Mailpit.
func mailEnv(cfg config.Config, l siteLayout, password string) ([]string, map[string]string) {
	host, port, user := "127.0.0.1", "25", smtpLogin(cfg, l)
	if l.MailMode == "capture" {
		h, p, ok := strings.Cut(cfg.MailpitSMTP, ":")
		if ok {
			host, port = h, p
		}
		user, password = "", ""
	}
	switch l.Framework {
	case "laravel", "php":
		return []string{"MAIL_MAILER", "MAIL_HOST", "MAIL_PORT", "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS"},
			map[string]string{"MAIL_MAILER": "smtp", "MAIL_HOST": host, "MAIL_PORT": port, "MAIL_USERNAME": user,
				"MAIL_PASSWORD": password, "MAIL_FROM_ADDRESS": "noreply@" + l.Domain}
	case "symfony":
		auth := ""
		if user != "" {
			auth = url.QueryEscape(user) + ":" + url.QueryEscape(password) + "@"
		}
		return []string{"MAILER_DSN"}, map[string]string{"MAILER_DSN": "smtp://" + auth + host + ":" + port}
	default: // node
		return []string{"SMTP_HOST", "SMTP_PORT", "SMTP_USER", "SMTP_PASS"},
			map[string]string{"SMTP_HOST": host, "SMTP_PORT": port, "SMTP_USER": user, "SMTP_PASS": password}
	}
}

// smtpLogin is the site's SASL login at the local Postfix.
func smtpLogin(cfg config.Config, l siteLayout) string {
	return l.User + "@" + saslRealm(cfg)
}

func saslRealm(cfg config.Config) string {
	if cfg.PanelDomain != "" {
		return cfg.PanelDomain
	}
	return "localhost"
}

// setSMTPLogin creates or resets the site's SMTP login (password over stdin)
// and returns the password, which then lives only in the site's env file.
func setSMTPLogin(ctx context.Context, env *Env, l siteLayout) (string, error) {
	pw := newPassword()
	if _, err := env.Exec.RunIO(ctx, strings.NewReader(pw), nil, "saslpasswd2", "-p", "-c", "-f", env.Cfg.SASLDB, "-u", saslRealm(env.Cfg), l.User); err != nil {
		return "", fmt.Errorf("creating the SMTP login failed: %w", err)
	}
	return pw, nil
}

func removeSMTPLogin(ctx context.Context, env *Env, user string) {
	_, _ = env.Exec.Run(ctx, "saslpasswd2", "-d", "-f", env.Cfg.SASLDB, "-u", saslRealm(env.Cfg), user)
}

// writeSenderMaps ties every site to its domain: over SMTP only the site's
// login may send as @domain (reject_sender_login_mismatch); with sendmail
// the site's Unix user may only use @domain as the envelope sender. OpenDKIM
// signs by the envelope sender, so a site can never get mail signed for
// another site's domain.
func writeSenderMaps(ctx context.Context, env *Env) error {
	var logins, senders strings.Builder
	logins.WriteString("# Managed by WSPanel agent.\n")
	senders.WriteString("# Managed by WSPanel agent.\n")
	for _, st := range allSiteStates(env.Cfg) {
		l, err := layout(env.Cfg, st)
		if err != nil {
			continue
		}
		fmt.Fprintf(&logins, "@%s %s\n", st.Domain, smtpLogin(env.Cfg, l))
		fmt.Fprintf(&senders, "%s @%s\n", l.User, st.Domain)
	}
	for _, f := range []struct{ path, content string }{{env.Cfg.SenderLogins, logins.String()}, {env.Cfg.LoginSenders, senders.String()}} {
		if err := env.FS.WriteFile(f.path, []byte(f.content), 0o644, fsx.Root); err != nil {
			return err
		}
		if _, err := env.Exec.Run(ctx, "postmap", f.path); err != nil {
			return err
		}
	}
	return writeSRSExclude(ctx, env)
}

// writeSRSExclude lists the domains that send from this server: the sites
// (each may send only as its own domain), the mail domains, the panel's and
// the mail host's. Postfix asks this regexp map before PostSRSd
// (sender_canonical_maps, first match wins; each line maps the address to
// itself — a hash "@domain @domain" does not stop the search), so only a
// foreign sender is rewritten: mail forwarded to another server then passes
// SPF there as the mail host's, and bounces come back through it.
func writeSRSExclude(ctx context.Context, env *Env) error {
	if env.Cfg.SRSExclude == "" {
		return nil
	}
	domains := []string{}
	add := func(d string) {
		d = strings.ToLower(strings.TrimSpace(d))
		if d != "" && validate.Domain(d) == nil && !slices.Contains(domains, d) {
			domains = append(domains, d)
		}
	}
	for _, st := range allSiteStates(env.Cfg) {
		add(st.Domain)
	}
	if st, err := loadMail(env); err == nil {
		for d := range st.Domains {
			add(d)
		}
	}
	add(env.Cfg.PanelDomain)
	add(mailHostName(env))
	slices.Sort(domains)
	var b strings.Builder
	b.WriteString("# Managed by WSPanel agent: senders never SRS-rewritten.\n")
	for _, d := range domains {
		// Validated domains: only the dots need escaping.
		fmt.Fprintf(&b, "/^(.+@%s)$/ ${1}\n", strings.ReplaceAll(d, ".", `\.`))
	}
	// A regexp table is read when a Postfix process starts: reload.
	if err := env.FS.WriteFile(env.Cfg.SRSExclude, []byte(b.String()), 0o644, fsx.Root); err != nil {
		return err
	}
	_, err := env.Exec.Run(ctx, "postfix", "reload")
	return err
}

type siteMailParams struct {
	Domain string `json:"domain"`
	Mode   string `json:"mode"`
}

// siteMail switches a site between real sending and capture: FPM pool
// (PHP mail()) and the SMTP settings in its env file.
func siteMail(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p siteMailParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("mode", p.Mode, MailModes); err != nil {
		return nil, err
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
	st.MailMode = p.Mode
	l, err := layout(env.Cfg, st)
	if err != nil {
		return nil, err
	}
	if l.Mode == "classic" && l.PoolFile != "" {
		old, _ := os.ReadFile(l.PoolFile)
		pool, err := render("fpm-pool.conf.tmpl", l)
		if err != nil {
			return nil, err
		}
		if err := env.FS.WriteFile(l.PoolFile, pool, 0o644, fsx.Root); err != nil {
			return nil, err
		}
		if err := validateFPM(ctx, env, l.PHPVersion); err != nil {
			_ = env.FS.WriteFile(l.PoolFile, old, 0o644, fsx.Root)
			return nil, err
		}
		if _, err := env.Exec.Run(ctx, "systemctl", "reload", config.ForVersion(env.Cfg.FPMUnit, l.PHPVersion)); err != nil {
			return nil, err
		}
	}
	envFile := envFilePath(l)
	if envFile != "" {
		old, err := readSiteFile(ctx, env, l, envFile)
		if err != nil {
			return nil, err
		}
		pw := ""
		if p.Mode == "send" {
			if pw, err = setSMTPLogin(ctx, env, l); err != nil {
				return nil, err
			}
		}
		keys, values := mailEnv(env.Cfg, l, pw)
		if err := writeSiteFile(ctx, env, l, envFile, mergeEnv(old, keys, values)); err != nil {
			return nil, err
		}
	}
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "mode": p.Mode, "env_file": envFile,
		"note": "app processes and workers pick up the env change after a restart"}, nil
}

// ---------------------------------------------------------------- server

func mailStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	out := map[string]any{"default_mode": env.Cfg.MailDefault, "relay": "", "queue": 0}
	if res, err := env.Exec.Run(ctx, "postconf", "-h", "relayhost"); err == nil {
		out["relay"] = strings.TrimSpace(res.Stdout)
	}
	if res, err := env.Exec.Run(ctx, "postconf", "-h", "myhostname"); err == nil {
		out["hostname"] = strings.TrimSpace(res.Stdout)
	}
	if res, err := env.Exec.Run(ctx, "postqueue", "-j"); err == nil {
		n := 0
		for _, line := range strings.Split(res.Stdout, "\n") {
			if strings.TrimSpace(line) != "" {
				n++
			}
		}
		out["queue"] = n
	}
	units, err := unitStates(ctx, env, []string{"postfix.service", "opendkim.service", "mailpit.service"})
	if err == nil {
		out["services"] = units
	}
	modes := map[string]string{}
	for _, st := range allSiteStates(env.Cfg) {
		m := st.MailMode
		if m == "" {
			m = env.Cfg.MailDefault
		}
		modes[st.Domain] = m
	}
	out["sites"] = modes
	keys, _ := dkimDomains(env)
	out["dkim"] = keys
	return out, nil
}

var relayHostRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$`)

type mailRelayParams struct {
	Host     string `json:"host"` // empty = deliver directly
	Port     int    `json:"port,omitempty"`
	Username string `json:"username,omitempty"`
	Password string `json:"password,omitempty"`
}

// mailRelay sets or clears the smarthost (Mailgun, SES, Postmark, the ISP…).
// Credentials go to a root-only sasl_passwd map, never on a command line.
func mailRelay(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p mailRelayParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	p.Host = strings.ToLower(strings.TrimSpace(p.Host))
	if p.Host == "" {
		for _, kv := range []string{"relayhost=", "smtp_sasl_auth_enable=no", "smtp_tls_security_level=may"} {
			if _, err := env.Exec.Run(ctx, "postconf", "-e", kv); err != nil {
				return nil, err
			}
		}
		_ = os.Remove(env.Cfg.PostfixSASL)
		_ = os.Remove(env.Cfg.PostfixSASL + ".db")
		_, err := env.Exec.Run(ctx, "systemctl", "reload", "postfix.service")
		return map[string]any{"relay": ""}, err
	}
	if !relayHostRe.MatchString(p.Host) {
		return nil, protocol.Invalid("host: hostname of the SMTP relay")
	}
	if p.Port == 0 {
		p.Port = 587
	}
	if !slices.Contains([]int{25, 465, 587, 2525}, p.Port) {
		return nil, protocol.Invalid("port: 25, 465, 587 or 2525")
	}
	for _, v := range []string{p.Username, p.Password} {
		if len(v) > 256 || strings.ContainsAny(v, "\n\r\x00 ") {
			return nil, protocol.Invalid("username/password: no spaces or line breaks, max 256 characters")
		}
	}
	relay := "[" + p.Host + "]:" + strconv.Itoa(p.Port)
	settings := []string{"relayhost=" + relay, "smtp_tls_security_level=encrypt"}
	if p.Port == 465 {
		settings = append(settings, "smtp_tls_wrappermode=yes")
	} else {
		settings = append(settings, "smtp_tls_wrappermode=no")
	}
	if p.Username != "" {
		if err := env.FS.WriteFile(env.Cfg.PostfixSASL, []byte(relay+" "+p.Username+":"+p.Password+"\n"), 0o600, fsx.Root); err != nil {
			return nil, err
		}
		if _, err := env.Exec.Run(ctx, "postmap", env.Cfg.PostfixSASL); err != nil {
			return nil, err
		}
		settings = append(settings, "smtp_sasl_auth_enable=yes", "smtp_sasl_password_maps=hash:"+env.Cfg.PostfixSASL,
			"smtp_sasl_security_options=noanonymous", "smtp_sasl_tls_security_options=noanonymous")
	} else {
		settings = append(settings, "smtp_sasl_auth_enable=no")
	}
	for _, kv := range settings {
		if _, err := env.Exec.Run(ctx, "postconf", "-e", kv); err != nil {
			return nil, err
		}
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "reload", "postfix.service"); err != nil {
		return nil, err
	}
	return map[string]any{"relay": relay, "auth": p.Username != ""}, nil
}

// ---------------------------------------------------------------- DKIM

type dkimKey struct {
	Domain   string `json:"domain"`
	Selector string `json:"selector"`
	Name     string `json:"name"`  // DNS record name
	Value    string `json:"value"` // TXT record value
}

func dkimKeyPath(env *Env, domain string) string {
	return filepath.Join(env.Cfg.DKIMDir, "keys", domain, env.Cfg.DKIMSelector+".private")
}

func dkimRecord(env *Env, domain string, key *rsa.PrivateKey) (dkimKey, error) {
	der, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		return dkimKey{}, err
	}
	return dkimKey{
		Domain: domain, Selector: env.Cfg.DKIMSelector,
		Name:  env.Cfg.DKIMSelector + "._domainkey." + domain,
		Value: "v=DKIM1; k=rsa; p=" + base64.StdEncoding.EncodeToString(der),
	}, nil
}

func readDKIM(env *Env, domain string) (dkimKey, error) {
	b, err := os.ReadFile(dkimKeyPath(env, domain))
	if err != nil {
		return dkimKey{}, err
	}
	block, _ := pem.Decode(b)
	if block == nil {
		return dkimKey{}, fmt.Errorf("%s: no PEM key", domain)
	}
	k, err := x509.ParsePKCS1PrivateKey(block.Bytes)
	if err != nil {
		return dkimKey{}, err
	}
	return dkimRecord(env, domain, k)
}

func dkimDomains(env *Env) ([]dkimKey, error) {
	dirs, _ := filepath.Glob(filepath.Join(env.Cfg.DKIMDir, "keys", "*"))
	out := []dkimKey{}
	for _, d := range dirs {
		domain := filepath.Base(d)
		if validate.Domain(domain) != nil {
			continue
		}
		if k, err := readDKIM(env, domain); err == nil {
			out = append(out, k)
		}
	}
	return out, nil
}

// writeDKIMTables regenerates OpenDKIM's key and signing tables from the keys on disk.
func writeDKIMTables(ctx context.Context, env *Env) error {
	keys, _ := dkimDomains(env)
	var kt, stb strings.Builder
	kt.WriteString("# Managed by WSPanel agent.\n")
	stb.WriteString("# Managed by WSPanel agent.\n")
	for _, k := range keys {
		id := k.Selector + "._domainkey." + k.Domain
		fmt.Fprintf(&kt, "%s %s:%s:%s\n", id, k.Domain, k.Selector, dkimKeyPath(env, k.Domain))
		fmt.Fprintf(&stb, "*@%s %s\n", k.Domain, id)
	}
	if err := env.FS.WriteFile(filepath.Join(env.Cfg.DKIMDir, "KeyTable"), []byte(kt.String()), 0o644, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(filepath.Join(env.Cfg.DKIMDir, "SigningTable"), []byte(stb.String()), 0o644, fsx.Root); err != nil {
		return err
	}
	_, err := env.Exec.Run(ctx, "systemctl", "restart", "opendkim.service")
	return err
}

func mailDKIM(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		Action string `json:"action"` // create | delete | get
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if err := validate.OneOf("action", p.Action, []string{"create", "delete", "get"}); err != nil {
		return nil, err
	}
	path := dkimKeyPath(env, p.Domain)
	switch p.Action {
	case "get":
		k, err := readDKIM(env, p.Domain)
		if err != nil {
			return nil, protocol.NotFound("no DKIM key for " + p.Domain)
		}
		return k, nil
	case "delete":
		if err := os.RemoveAll(filepath.Dir(path)); err != nil {
			return nil, err
		}
		return map[string]any{"domain": p.Domain, "deleted": true}, writeDKIMTables(ctx, env)
	}
	if k, err := readDKIM(env, p.Domain); err == nil {
		return k, nil // idempotent: keep the published key
	}
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		return nil, err
	}
	owner, err := lookupOwner(env, "opendkim")
	if err != nil {
		return nil, fmt.Errorf("OpenDKIM is not installed: %w", err)
	}
	if err := env.FS.MkdirAll(filepath.Dir(path), 0o750, owner); err != nil {
		return nil, err
	}
	pemBytes := pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(key)})
	if err := env.FS.WriteFile(path, pemBytes, 0o600, owner); err != nil {
		return nil, err
	}
	if err := writeDKIMTables(ctx, env); err != nil {
		return nil, err
	}
	return dkimRecord(env, p.Domain, key)
}

var queueIDRe = regexp.MustCompile(`^[0-9A-Za-z]{6,20}$`)

type queuedMail struct {
	ID         string   `json:"id"`
	Queue      string   `json:"queue"` // active | deferred | hold | incoming
	Arrival    int64    `json:"arrival"`
	Size       int64    `json:"size"`
	Sender     string   `json:"sender"`
	Recipients []string `json:"recipients"`
	Reason     string   `json:"reason,omitempty"` // why delivery was deferred
}

// mailQueue lists Postfix's queue, sends it again now, or deletes messages.
func mailQueue(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Action string   `json:"action"` // list | flush | delete
		IDs    []string `json:"ids,omitempty"`
		All    bool     `json:"all,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("action", p.Action, []string{"list", "flush", "delete"}); err != nil {
		return nil, err
	}
	switch p.Action {
	case "flush":
		if _, err := env.Exec.Run(ctx, "postqueue", "-f"); err != nil {
			return nil, err
		}
		return map[string]any{"flushed": true}, nil
	case "delete":
		if p.All {
			if _, err := env.Exec.Run(ctx, "postsuper", "-d", "ALL"); err != nil {
				return nil, err
			}
			return map[string]any{"deleted": "all"}, nil
		}
		if len(p.IDs) == 0 || len(p.IDs) > 500 {
			return nil, protocol.Invalid("ids: 1-500 queue ids, or all")
		}
		for _, id := range p.IDs {
			if !queueIDRe.MatchString(id) {
				return nil, protocol.Invalid("ids: not a queue id: " + id)
			}
		}
		// postsuper -d - reads the ids from stdin, one per line.
		if _, err := env.Exec.RunIO(ctx, strings.NewReader(strings.Join(p.IDs, "\n")+"\n"), nil, "postsuper", "-d", "-"); err != nil {
			return nil, err
		}
		return map[string]any{"deleted": len(p.IDs)}, nil
	}
	res, err := env.Exec.Run(ctx, "postqueue", "-j")
	if err != nil {
		return nil, err
	}
	out := []queuedMail{}
	for _, line := range strings.Split(res.Stdout, "\n") {
		var m struct {
			Queue      string `json:"queue_name"`
			ID         string `json:"queue_id"`
			Arrival    int64  `json:"arrival_time"`
			Size       int64  `json:"message_size"`
			Sender     string `json:"sender"`
			Recipients []struct {
				Address string `json:"address"`
				Reason  string `json:"delay_reason"`
			} `json:"recipients"`
		}
		if strings.TrimSpace(line) == "" || json.Unmarshal([]byte(line), &m) != nil {
			continue
		}
		q := queuedMail{ID: m.ID, Queue: m.Queue, Arrival: m.Arrival, Size: m.Size, Sender: m.Sender}
		for _, r := range m.Recipients {
			q.Recipients = append(q.Recipients, r.Address)
			if q.Reason == "" {
				q.Reason = r.Reason
			}
		}
		out = append(out, q)
		if len(out) >= 1000 {
			break
		}
	}
	return map[string]any{"messages": out}, nil
}

// mailLog: the tail of Postfix's log (deliveries, bounces, deferrals).
func mailLog(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Lines int `json:"lines,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Lines <= 0 || p.Lines > 2000 {
		p.Lines = 200
	}
	res, err := env.Exec.Run(ctx, "journalctl", "--unit", "postfix@-.service", "--unit", "opendkim.service",
		"--lines", strconv.Itoa(p.Lines), "--no-pager", "--output", "short-iso", "--quiet")
	if err != nil {
		return nil, err
	}
	return map[string]any{"text": res.Stdout}, nil
}
