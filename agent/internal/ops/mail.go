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
// (port 25) or Mailpit.
func mailEnv(cfg config.Config, l siteLayout) ([]string, map[string]string) {
	host, port := "127.0.0.1", "25"
	if l.MailMode == "capture" {
		h, p, ok := strings.Cut(cfg.MailpitSMTP, ":")
		if ok {
			host, port = h, p
		}
	}
	switch l.Framework {
	case "laravel", "php":
		return []string{"MAIL_MAILER", "MAIL_HOST", "MAIL_PORT", "MAIL_FROM_ADDRESS"},
			map[string]string{"MAIL_MAILER": "smtp", "MAIL_HOST": host, "MAIL_PORT": port, "MAIL_FROM_ADDRESS": "noreply@" + l.Domain}
	case "symfony":
		return []string{"MAILER_DSN"}, map[string]string{"MAILER_DSN": "smtp://" + host + ":" + port}
	default: // node
		return []string{"SMTP_HOST", "SMTP_PORT"}, map[string]string{"SMTP_HOST": host, "SMTP_PORT": port}
	}
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
		keys, values := mailEnv(env.Cfg, l)
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
		fmt.Fprintf(&stb, "*@%s %s\n*@*.%s %s\n", k.Domain, id, k.Domain, id)
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
