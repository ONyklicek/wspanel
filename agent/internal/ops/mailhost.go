package ops

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Mail hosting: the domains the server receives mail for, their mailboxes
// (Dovecot) and aliases. The agent's mail.json is the source; every change
// renders Postfix's maps and Dovecot's users file from it, so they can never
// disagree. Passwords arrive hashed (SHA512-CRYPT, made by the panel): the
// agent never sees one.

type mailbox struct {
	Hash    string `json:"hash"`
	QuotaMB int    `json:"quota_mb"`
	Enabled bool   `json:"enabled"`
}

type mailDomain struct {
	Mailboxes map[string]mailbox  `json:"mailboxes"` // local part -> mailbox
	Aliases   map[string][]string `json:"aliases"`   // local part ("" = catch-all) -> destinations
	// webmail.<domain> (Roundcube) and autoconfig./autodiscover.<domain> on the edge.
	Webmail    bool      `json:"webmail"`
	Autoconfig bool      `json:"autoconfig"`
	Spam       *mailSpam `json:"spam,omitempty"` // nil = the server's levels
}

// mailSpam: a domain's own spam levels (Rspamd scores) and sender lists.
type mailSpam struct {
	Reject float64  `json:"reject"` // refuse at or above
	Junk   float64  `json:"junk"`   // mark (into Junk) at or above
	Allow  []string `json:"allow,omitempty"`
	Block  []string `json:"block,omitempty"`
}

type mailState struct {
	Domains map[string]*mailDomain `json:"domains"`
}

var (
	localPartRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9._+-]{0,62}[a-z0-9])?$`)
	sha512Re    = regexp.MustCompile(`^\$6\$(rounds=[0-9]{4,7}\$)?[./A-Za-z0-9]{1,16}\$[./A-Za-z0-9]{86}$`)
	emailRe     = regexp.MustCompile(`^[A-Za-z0-9._%+-]{1,64}@[a-z0-9.-]{1,253}$`)
)

func mailStatePath(env *Env) string { return filepath.Join(env.Cfg.StateDir, "mail.json") }

// lookupGroupOwnerByGroup: root as owner, the named group as group.
func lookupGroupOwnerByGroup(name string) (fsx.Owner, error) {
	g, err := user.LookupGroup(name)
	if err != nil {
		return fsx.Owner{}, err
	}
	gid, _ := strconv.Atoi(g.Gid)
	return fsx.Owner{UID: 0, GID: gid}, nil
}

func mailHostingOn(env *Env) bool {
	return fileIn(env.Cfg.MailDir, "hosting")
}

func loadMail(env *Env) (*mailState, error) {
	st := &mailState{Domains: map[string]*mailDomain{}}
	b, err := os.ReadFile(mailStatePath(env))
	if os.IsNotExist(err) {
		return st, nil
	}
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(b, st); err != nil {
		return nil, err
	}
	if st.Domains == nil {
		st.Domains = map[string]*mailDomain{}
	}
	// Domains from before webmail had neither switch: both start on.
	var raw struct {
		Domains map[string]map[string]json.RawMessage `json:"domains"`
	}
	if json.Unmarshal(b, &raw) == nil {
		for d, fields := range raw.Domains {
			if _, ok := fields["webmail"]; !ok && st.Domains[d] != nil {
				st.Domains[d].Webmail, st.Domains[d].Autoconfig = true, true
			}
		}
	}
	return st, nil
}

// RenderMailOnStart renders mail.json once when the agent starts, so what an
// upgrade adds to the rendering (new files, new hosts) is there at once.
func RenderMailOnStart(ctx context.Context, env *Env, logf func(string, ...any)) {
	if env.Cfg.DryRun || !mailHostingOn(env) {
		return
	}
	_ = withMutate(env, func() error {
		st, err := loadMail(env)
		if err == nil {
			err = saveMail(ctx, env, st)
		}
		if err != nil {
			logf("rendering mail hosting: %v", err)
		}
		return err
	})
}

// saveMail stores the state and renders everything from it.
func saveMail(ctx context.Context, env *Env, st *mailState) error {
	b, _ := json.MarshalIndent(st, "", "  ")
	if err := env.FS.MkdirAll(env.Cfg.StateDir, 0o700, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(mailStatePath(env), b, 0o600, fsx.Root); err != nil {
		return err
	}
	return renderMail(ctx, env, st)
}

func renderMail(ctx context.Context, env *Env, st *mailState) error {
	var domains, boxes, aliases, senders, users, blocked bytes.Buffer
	for _, w := range []*bytes.Buffer{&domains, &boxes, &aliases, &senders} {
		w.WriteString("# Managed by WSPanel agent (mail.json).\n")
	}
	names := make([]string, 0, len(st.Domains))
	for d := range st.Domains {
		names = append(names, d)
	}
	sort.Strings(names)
	for _, d := range names {
		md := st.Domains[d]
		fmt.Fprintf(&domains, "%s OK\n", d)
		locals := sortedKeys(md.Mailboxes)
		for _, lp := range locals {
			mb := md.Mailboxes[lp]
			addr := lp + "@" + d
			fmt.Fprintf(&boxes, "%s OK\n", addr)
			// A mailbox may send as itself (and as the aliases that end in it, below).
			fmt.Fprintf(&senders, "%s %s\n", addr, addr)
			extra := "userdb_quota/user/quota_storage_size=" + strconv.Itoa(mb.QuotaMB) + "M"
			// Blocked: a password nothing matches (the real hash stays in
			// mail.json for unblocking); mail still arrives (userdb).
			password := "{SHA512-CRYPT}" + mb.Hash
			if !mb.Enabled {
				password = "{CRYPT}*"
				// The deny passdb also stops the webmail's master login for it.
				fmt.Fprintf(&blocked, "%s:\n", addr)
			}
			fmt.Fprintf(&users, "%s:%s::::::%s\n", addr, password, extra)
		}
		// A catch-all in virtual_alias_maps would also match the mailboxes
		// (and what aliases resolve to): every mailbox maps to itself first.
		if _, catchAll := md.Aliases[""]; catchAll {
			for _, lp := range locals {
				fmt.Fprintf(&aliases, "%s@%s %s@%s\n", lp, d, lp, d)
			}
		}
		for _, lp := range sortedKeys(md.Aliases) {
			to := md.Aliases[lp]
			fmt.Fprintf(&aliases, "%s@%s %s\n", lp, d, strings.Join(to, ","))
			// A mailbox the alias delivers to may send as the alias.
			for _, t := range to {
				if tl, td, ok := strings.Cut(t, "@"); ok && st.Domains[td] != nil {
					if _, isBox := st.Domains[td].Mailboxes[tl]; isBox && lp != "" {
						fmt.Fprintf(&senders, "%s@%s %s\n", lp, d, t)
					}
				}
			}
		}
	}
	for _, f := range []struct {
		path    string
		content []byte
		postmap bool
	}{
		{env.Cfg.VDomains, domains.Bytes(), true},
		{env.Cfg.VMailboxes, boxes.Bytes(), true},
		{env.Cfg.VAliases, aliases.Bytes(), true},
		{env.Cfg.MailboxSenders, senders.Bytes(), true},
	} {
		if err := env.FS.WriteFile(f.path, f.content, 0o644, fsx.Root); err != nil {
			return err
		}
		if _, err := env.Exec.Run(ctx, "postmap", f.path); err != nil {
			return err
		}
	}
	// Dovecot reads the users file on each login: readable by its group only.
	dovecot := fsx.Root
	if o, err := lookupGroupOwnerByGroup("dovecot"); err == nil {
		dovecot = o
	}
	if err := env.FS.WriteFile(env.Cfg.DovecotUsers, users.Bytes(), 0o640, dovecot); err != nil {
		return err
	}
	if err := env.FS.WriteFile(env.Cfg.DovecotBlocked, blocked.Bytes(), 0o640, dovecot); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "postfix", "reload"); err != nil {
		return err
	}
	if err := writeSpamSettings(ctx, env, st, names); err != nil {
		return err
	}
	return writeMailEdge(ctx, env, st, names)
}

// writeSpamSettings renders Rspamd's settings: per recipient domain, an
// allowed sender skips the checks, a blocked one is refused, and the
// domain's own levels apply to the rest.
func writeSpamSettings(ctx context.Context, env *Env, st *mailState, names []string) error {
	var b strings.Builder
	b.WriteString("# Managed by WSPanel agent (mail.json).\n")
	q := func(list []string) string {
		quoted := make([]string, len(list))
		for i, s := range list {
			quoted[i] = strconv.Quote(s)
		}
		return "[" + strings.Join(quoted, ", ") + "]"
	}
	for _, d := range names {
		sp := st.Domains[d].Spam
		if sp == nil {
			continue
		}
		id := "wspanel_" + strings.NewReplacer(".", "_", "-", "_").Replace(d)
		rcpt := strconv.Quote("@" + d)
		if len(sp.Allow) > 0 {
			fmt.Fprintf(&b, "%s_allow {\n  priority = high;\n  rcpt = %s;\n  from = %s;\n  want_spam = yes;\n}\n", id, rcpt, q(sp.Allow))
		}
		if len(sp.Block) > 0 {
			fmt.Fprintf(&b, "%s_block {\n  priority = high;\n  rcpt = %s;\n  from = %s;\n  apply {\n    actions {\n      reject = -1000;\n    }\n  }\n}\n", id, rcpt, q(sp.Block))
		}
		fmt.Fprintf(&b, "%s {\n  priority = low;\n  rcpt = %s;\n  apply {\n    actions {\n      reject = %s;\n      \"add header\" = %s;\n    }\n  }\n}\n",
			id, rcpt, strconv.FormatFloat(sp.Reject, 'f', -1, 64), strconv.FormatFloat(sp.Junk, 'f', -1, 64))
	}
	old, err := os.ReadFile(env.Cfg.RspamdSettings)
	if err == nil && string(old) == b.String() {
		return nil
	}
	if err := env.FS.WriteFile(env.Cfg.RspamdSettings, []byte(b.String()), 0o644, fsx.Root); err != nil {
		return err
	}
	_, err = env.Exec.Run(ctx, "systemctl", "reload", "rspamd")
	return err
}

var spamSenderRe = regexp.MustCompile(`^([A-Za-z0-9._%+-]{1,64})?@[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$`)

// mailSpamSet sets a domain's spam levels and sender lists (nil levels =
// back to the server's).
func mailSpamSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string    `json:"domain"`
		Spam   *mailSpam `json:"spam"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if sp := p.Spam; sp != nil {
		if sp.Reject < 5 || sp.Reject > 100 || sp.Junk < 1 || sp.Junk >= sp.Reject {
			return nil, protocol.Invalid("spam: reject 5-100, junk from 1 and below reject")
		}
		if len(sp.Allow) > 500 || len(sp.Block) > 500 {
			return nil, protocol.Invalid("spam: at most 500 senders per list")
		}
		for i, list := range [][]string{sp.Allow, sp.Block} {
			for j, s := range list {
				s = strings.ToLower(strings.TrimSpace(s))
				if !spamSenderRe.MatchString(s) {
					return nil, protocol.Invalid("spam: not an address or @domain: " + s)
				}
				if i == 0 {
					sp.Allow[j] = s
				} else {
					sp.Block[j] = s
				}
			}
		}
	}
	if err := requireHosting(env); err != nil {
		return nil, err
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	md := st.Domains[p.Domain]
	if md == nil {
		return nil, protocol.NotFound("mail domain " + p.Domain + " is not set up")
	}
	md.Spam = p.Spam
	if err := saveMail(ctx, env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "spam": md.Spam}, nil
}

type mailEdgeDomain struct {
	Name                string
	Webmail, Autoconfig bool
}

// writeMailEdge renders the edge's mail hosts. Checked before the edge
// reloads; a host another block already serves puts the old file back.
func writeMailEdge(ctx context.Context, env *Env, st *mailState, names []string) error {
	data := struct {
		Host    string
		Domains []mailEdgeDomain
	}{Host: mailHostName(env)}
	for _, d := range names {
		md := st.Domains[d]
		data.Domains = append(data.Domains, mailEdgeDomain{Name: d, Webmail: md.Webmail, Autoconfig: md.Autoconfig})
	}
	block, err := render("mail-edge.caddy.tmpl", data)
	if err != nil {
		return err
	}
	old, readErr := os.ReadFile(env.Cfg.MailEdgeFile)
	if readErr == nil && bytes.Equal(old, block) {
		return nil
	}
	if err := env.FS.WriteFile(env.Cfg.MailEdgeFile, block, 0o644, fsx.Root); err != nil {
		return err
	}
	if err := validateEdge(ctx, env); err != nil {
		if readErr == nil {
			_ = env.FS.WriteFile(env.Cfg.MailEdgeFile, old, 0o644, fsx.Root)
		} else {
			_ = os.Remove(env.Cfg.MailEdgeFile)
		}
		return err
	}
	_, err = env.Exec.Run(ctx, "systemctl", "reload", env.Cfg.ProxyUnit)
	return err
}

// mailHostName is the host mail clients use (MX, IMAP, SMTP).
func mailHostName(env *Env) string {
	if env.Cfg.MailHost != "" {
		return env.Cfg.MailHost
	}
	return env.Cfg.PanelDomain
}

func sortedKeys[V any](m map[string]V) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

func splitAddress(field, addr string) (string, string, error) {
	lp, d, ok := strings.Cut(strings.ToLower(strings.TrimSpace(addr)), "@")
	if !ok || !localPartRe.MatchString(lp) || strings.Contains(lp, "..") {
		return "", "", protocol.Invalid(field + ": not an address")
	}
	if err := validate.Domain(d); err != nil {
		return "", "", protocol.Invalid(field + ": " + err.Error())
	}
	return lp, d, nil
}

func requireHosting(env *Env) error {
	if !mailHostingOn(env) && !env.Cfg.DryRun {
		return protocol.Conflict("mail hosting is not installed (Server → Moduly)")
	}
	return nil
}

// ---------------------------------------------------------------- ops

func mailInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	host := mailHostName(env)
	res, err := env.Exec.Run(ctx, "mail-hosting", host)
	if err != nil {
		return nil, err
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	if err := saveMail(ctx, env, st); err != nil {
		return nil, err
	}
	_ = syncMailCert(ctx, env)
	return map[string]any{"installed": true, "host": host, "log": tail(res.Stdout, 2000)}, nil
}

func tail(s string, n int) string {
	if len(s) > n {
		return s[len(s)-n:]
	}
	return s
}

func mailHostingStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	out := map[string]any{"installed": mailHostingOn(env), "host": env.Cfg.MailHost}
	if !mailHostingOn(env) {
		return out, nil
	}
	if states, err := unitStates(ctx, env, []string{"dovecot.service", "rspamd.service", "postfix.service"}); err == nil {
		out["services"] = states
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	out["domains"] = len(st.Domains)
	return out, nil
}

func mailDomainSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain     string `json:"domain"`
		Enabled    bool   `json:"enabled"`
		Webmail    *bool  `json:"webmail,omitempty"`    // default on for a new domain
		Autoconfig *bool  `json:"autoconfig,omitempty"` // default on for a new domain
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if err := requireHosting(env); err != nil {
		return nil, err
	}
	if p.Domain == env.Cfg.MailHost {
		return nil, protocol.Invalid("domain: the mail host itself cannot be a mail domain")
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	if p.Enabled {
		md := st.Domains[p.Domain]
		if md == nil {
			md = &mailDomain{Mailboxes: map[string]mailbox{}, Aliases: map[string][]string{}, Webmail: true, Autoconfig: true}
			st.Domains[p.Domain] = md
		}
		if p.Webmail != nil {
			md.Webmail = *p.Webmail
		}
		if p.Autoconfig != nil {
			md.Autoconfig = *p.Autoconfig
		}
	} else if md := st.Domains[p.Domain]; md != nil {
		if len(md.Mailboxes) > 0 {
			return nil, protocol.Conflict("domain " + p.Domain + " still has mailboxes")
		}
		delete(st.Domains, p.Domain)
	}
	if err := saveMail(ctx, env, st); err != nil {
		return nil, err
	}
	out := map[string]any{"domain": p.Domain, "enabled": p.Enabled}
	if md := st.Domains[p.Domain]; md != nil {
		out["webmail"], out["autoconfig"] = md.Webmail, md.Autoconfig
	}
	return out, nil
}

func mailMailboxSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Address string `json:"address"`
		Hash    string `json:"password_hash,omitempty"` // empty = keep
		QuotaMB int    `json:"quota_mb"`
		Enabled bool   `json:"enabled"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	lp, d, err := splitAddress("address", p.Address)
	if err != nil {
		return nil, err
	}
	if p.Hash != "" && !sha512Re.MatchString(p.Hash) {
		return nil, protocol.Invalid("password_hash: SHA512-CRYPT ($6$…) expected")
	}
	if p.QuotaMB < 10 || p.QuotaMB > 1<<20 {
		return nil, protocol.Invalid("quota_mb: 10-1048576")
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	md := st.Domains[d]
	if md == nil {
		return nil, protocol.NotFound("mail domain " + d + " is not set up")
	}
	if _, isAlias := md.Aliases[lp]; isAlias {
		return nil, protocol.Conflict(lp + "@" + d + " is an alias")
	}
	mb, exists := md.Mailboxes[lp]
	if !exists && p.Hash == "" {
		return nil, protocol.Invalid("password_hash: a new mailbox needs a password")
	}
	if p.Hash != "" {
		mb.Hash = p.Hash
	}
	mb.QuotaMB, mb.Enabled = p.QuotaMB, p.Enabled
	md.Mailboxes[lp] = mb
	if err := saveMail(ctx, env, st); err != nil {
		return nil, err
	}
	if !p.Enabled || p.Hash != "" {
		// Blocked or a new password: sessions logged in before end now.
		_, _ = env.Exec.Run(ctx, "doveadm", "kick", lp+"@"+d)
	}
	return map[string]any{"address": lp + "@" + d, "created": !exists}, nil
}

func mailMailboxDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Address    string `json:"address"`
		RemoveData bool   `json:"remove_data"`
	}
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
	md := st.Domains[d]
	if md == nil || md.Mailboxes[lp] == (mailbox{}) {
		return nil, protocol.NotFound("mailbox " + lp + "@" + d + " does not exist")
	}
	delete(md.Mailboxes, lp)
	if err := saveMail(ctx, env, st); err != nil {
		return nil, err
	}
	_, _ = env.Exec.Run(ctx, "doveadm", "kick", lp+"@"+d)
	if p.RemoveData {
		// As vmail: the store is vmail's, and the path is ours (validated parts).
		dir := filepath.Join(env.Cfg.VmailRoot, d, lp)
		if fsx.Within(env.Cfg.VmailRoot, dir) {
			_, _ = env.Exec.Run(ctx, "setpriv", append(userCreds("vmail"), "/usr/bin/rm", "-rf", "--", dir)...)
		}
	}
	return map[string]any{"address": lp + "@" + d, "deleted": true}, nil
}

// mailAliasSet replaces a domain's aliases. Local part "" is the catch-all.
func mailAliasSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain  string              `json:"domain"`
		Aliases map[string][]string `json:"aliases"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	md := st.Domains[p.Domain]
	if md == nil {
		return nil, protocol.NotFound("mail domain " + p.Domain + " is not set up")
	}
	clean := map[string][]string{}
	for lp, to := range p.Aliases {
		lp = strings.ToLower(strings.TrimSpace(lp))
		if lp != "" && !localPartRe.MatchString(lp) {
			return nil, protocol.Invalid("aliases: " + lp + " is not a local part")
		}
		if _, isBox := md.Mailboxes[lp]; isBox {
			return nil, protocol.Conflict(lp + "@" + p.Domain + " is a mailbox")
		}
		if len(to) == 0 || len(to) > 50 {
			return nil, protocol.Invalid("aliases: 1-50 destinations")
		}
		var dests []string
		for _, t := range to {
			t = strings.ToLower(strings.TrimSpace(t))
			if !emailRe.MatchString(t) {
				return nil, protocol.Invalid("aliases: " + t + " is not an address")
			}
			if !slices.Contains(dests, t) {
				dests = append(dests, t)
			}
		}
		clean[lp] = dests
	}
	if len(clean) > 1000 {
		return nil, protocol.Invalid("aliases: at most 1000")
	}
	md.Aliases = clean
	if err := saveMail(ctx, env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "aliases": len(clean)}, nil
}

// mailUsage: the storage each mailbox of a domain uses (KB).
func mailUsage(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	md := st.Domains[p.Domain]
	if md == nil {
		return nil, protocol.NotFound("mail domain " + p.Domain + " is not set up")
	}
	out := map[string]int64{}
	for _, lp := range sortedKeys(md.Mailboxes) {
		res, err := env.Exec.Run(ctx, "doveadm", "-f", "tab", "quota", "get", "-u", lp+"@"+p.Domain)
		if err != nil {
			continue
		}
		for _, line := range strings.Split(res.Stdout, "\n") {
			f := strings.Split(line, "\t")
			if len(f) >= 3 && f[1] == "STORAGE" {
				n, _ := strconv.ParseInt(f[2], 10, 64)
				out[lp+"@"+p.Domain] = n
			}
		}
	}
	return map[string]any{"domain": p.Domain, "usage_kb": out}, nil
}

// mailAutoreply sets or removes a mailbox's vacation reply (Sieve).
func mailAutoreply(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Address string `json:"address"`
		Enabled bool   `json:"enabled"`
		Subject string `json:"subject"`
		Body    string `json:"body"`
		Days    int    `json:"days"`
	}
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
	if md := st.Domains[d]; md == nil || md.Mailboxes[lp] == (mailbox{}) {
		return nil, protocol.NotFound("mailbox " + lp + "@" + d + " does not exist")
	}
	p.Subject = strings.NewReplacer("\r", " ", "\n", " ").Replace(p.Subject) // one header line
	if len(p.Subject) > 200 || len(p.Body) > 4000 {
		return nil, protocol.Invalid("subject at most 200, body at most 4000 characters")
	}
	if p.Days == 0 {
		p.Days = 1
	}
	if p.Days < 1 || p.Days > 30 {
		return nil, protocol.Invalid("days: 1-30")
	}
	home := filepath.Join(env.Cfg.VmailRoot, d, lp)
	if !fsx.Within(env.Cfg.VmailRoot, home) {
		return nil, protocol.Invalid("address")
	}
	vmail := userCreds("vmail")
	// An "after" script (Dovecot): it runs after the mailbox's own filters,
	// which the webmail manages, so neither replaces the other.
	script := filepath.Join(home, "wspanel-autoreply.sieve")
	dropLegacyAutoreply(ctx, env, vmail, home)
	if !p.Enabled {
		_, _ = env.Exec.Run(ctx, "setpriv", append(vmail, "/usr/bin/rm", "-f", "--", script, strings.TrimSuffix(script, ".sieve")+".svbin")...)
		return map[string]any{"address": lp + "@" + d, "autoreply": false}, nil
	}
	q := func(s string) string {
		return `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`).Replace(s) + `"`
	}
	text := "require [\"vacation\"];\n" +
		"vacation :days " + strconv.Itoa(p.Days) + " :subject " + q(p.Subject) + " " + q(p.Body) + ";\n"
	if _, err := env.Exec.Run(ctx, "setpriv", append(vmail, "/usr/bin/install", "-d", "-m", "0700", home)...); err != nil {
		return nil, err
	}
	if _, err := env.Exec.RunIO(ctx, strings.NewReader(text), nil, "setpriv", append(vmail, "/usr/bin/tee", script)...); err != nil {
		return nil, err
	}
	return map[string]any{"address": lp + "@" + d, "autoreply": true}, nil
}

// dropLegacyAutoreply removes the autoreply of before the webmail: it was
// the mailbox's active personal script (~/sieve/wspanel.sieve).
func dropLegacyAutoreply(ctx context.Context, env *Env, vmail []string, home string) {
	link := filepath.Join(home, ".dovecot.sieve")
	if target, err := os.Readlink(link); err == nil && target == "sieve/wspanel.sieve" {
		_, _ = env.Exec.Run(ctx, "setpriv", append(vmail, "/usr/bin/rm", "-f", "--", link, filepath.Join(home, "sieve", "wspanel.sieve"))...)
	}
}

// mailWebmailLogin signs a mailbox in to its domain's webmail from the panel:
// a one-time token (60 s) the Roundcube plugin consumes.
func mailWebmailLogin(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Address string `json:"address"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	lp, d, err := splitAddress("address", p.Address)
	if err != nil {
		return nil, err
	}
	if err := requireHosting(env); err != nil {
		return nil, err
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	md := st.Domains[d]
	if md == nil {
		return nil, protocol.NotFound("mail domain " + d + " is not set up")
	}
	mb, ok := md.Mailboxes[lp]
	if !ok {
		return nil, protocol.NotFound("mailbox " + lp + "@" + d + " does not exist")
	}
	if !mb.Enabled {
		return nil, protocol.Conflict("mailbox " + lp + "@" + d + " is blocked")
	}
	if !md.Webmail {
		return nil, protocol.Conflict("webmail is off for " + d)
	}
	owner, err := lookupOwner(env, env.Cfg.WebmailUser)
	if err != nil {
		return nil, err
	}
	if err := env.FS.MkdirAll(env.Cfg.WebmailTokens, 0o700, owner); err != nil {
		return nil, err
	}
	token := randomHex(32)
	payload, _ := json.Marshal(map[string]any{"address": lp + "@" + d, "expires": time.Now().Add(60 * time.Second).Unix()})
	if err := env.FS.WriteFile(filepath.Join(env.Cfg.WebmailTokens, token+".json"), payload, 0o600, owner); err != nil {
		return nil, err
	}
	// The panel POSTs the token (never in a URL, so never in an access log).
	return map[string]any{"address": lp + "@" + d, "url": "https://webmail." + d + "/?_task=login", "token": token}, nil
}

// syncMailCert puts the edge's certificate for the mail host where Postfix
// and Dovecot read it, and reloads them when it changed.
func syncMailCert(ctx context.Context, env *Env) error {
	if !mailHostingOn(env) {
		return nil
	}
	host := mailHostName(env)
	crts, _ := filepath.Glob(filepath.Join(env.Cfg.ProxyStorage, "certificates", "*", host, host+".crt"))
	if len(crts) == 0 {
		return nil
	}
	crt, err := os.ReadFile(crts[0])
	if err != nil {
		return err
	}
	key, err := os.ReadFile(strings.TrimSuffix(crts[0], ".crt") + ".key")
	if err != nil {
		return err
	}
	cur, _ := os.ReadFile(filepath.Join(env.Cfg.MailDir, "cert.pem"))
	if bytes.Equal(cur, crt) {
		return nil
	}
	if err := env.FS.WriteFile(filepath.Join(env.Cfg.MailDir, "key.pem"), key, 0o600, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(filepath.Join(env.Cfg.MailDir, "cert.pem"), crt, 0o644, fsx.Root); err != nil {
		return err
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "reload", "postfix", "dovecot")
	return nil
}

func mailCerts(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	if err := syncMailCert(ctx, env); err != nil {
		return nil, err
	}
	return map[string]any{"ok": true}, nil
}
