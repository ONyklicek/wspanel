package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"net/netip"
	"regexp"
	"slices"
	"strings"

	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// What the edge proxy does for a site before passing a request on: more
// domains, redirects, password and IP protection, response headers. Every
// value lands in a Caddyfile, so each is checked against a strict pattern.

type edgeRedirect struct {
	From string `json:"from"` // path, "/old" or a prefix "/blog/*"
	To   string `json:"to"`   // "/new", or an https:// URL
	Code int    `json:"code"` // 301 | 302 | 307 | 308
}

type edgeHeader struct {
	Name  string `json:"name"`
	Value string `json:"value"`
}

type edgeSettings struct {
	Aliases       []string       `json:"aliases,omitempty"`        // served by the site as well
	RedirectHosts []string       `json:"redirect_hosts,omitempty"` // answer with a redirect to the main domain (www / old domains)
	Redirects     []edgeRedirect `json:"redirects,omitempty"`
	AuthPath      string         `json:"auth_path,omitempty"` // "" = no password; "/" = whole site; "/admin" = that prefix
	AuthUser      string         `json:"auth_user,omitempty"`
	AuthHash      string         `json:"auth_hash,omitempty"`  // bcrypt, made by Caddy; the password itself is never kept
	AllowIPs      []string       `json:"allow_ips,omitempty"`  // only these may open AllowPath
	AllowPath     string         `json:"allow_path,omitempty"` // "/" = whole site
	HSTS          bool           `json:"hsts,omitempty"`
	Security      bool           `json:"security_headers,omitempty"`
	Headers       []edgeHeader   `json:"headers,omitempty"`
}

var (
	edgePathRe    = regexp.MustCompile(`^/[A-Za-z0-9._~!$&'()+,;=:@%/-]{0,200}(/\*)?$`)
	edgeToRe      = regexp.MustCompile(`^(/[A-Za-z0-9._~!$&'()*+,;=:@%/?#-]{0,300}|https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~!$&'()*+,;=:@%/?#-]{0,300})?)$`)
	edgeUserRe    = regexp.MustCompile(`^[A-Za-z0-9._-]{1,32}$`)
	edgeHashRe    = regexp.MustCompile(`^\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}$`)
	headerNameRe  = regexp.MustCompile(`^[A-Za-z0-9-]{1,64}$`)
	headerValueRe = regexp.MustCompile(`^[ !#-\[\]-z|~]{1,500}$`) // printable ASCII without " \ { }
)

// Headers the edge sets itself or that would break the site.
var reservedHeaders = []string{"content-length", "content-type", "transfer-encoding", "connection", "host", "set-cookie", "location", "strict-transport-security"}

func normalizePrefixList(in []string, max int) ([]string, error) {
	if len(in) > max {
		return nil, protocol.Invalid(fmt.Sprintf("at most %d addresses", max))
	}
	var out []string
	for _, a := range in {
		a = strings.TrimSpace(a)
		if a == "" {
			continue
		}
		var pfx netip.Prefix
		if p, err := netip.ParsePrefix(a); err == nil {
			pfx = p.Masked()
		} else if addr, err := netip.ParseAddr(a); err == nil {
			pfx = netip.PrefixFrom(addr, addr.BitLen())
		} else {
			return nil, protocol.Invalid("allow_ips: " + a + " is not an IP address or network")
		}
		if pfx.Bits() == 0 {
			return nil, protocol.Invalid("allow_ips: " + a + " means everyone")
		}
		if !slices.Contains(out, pfx.String()) {
			out = append(out, pfx.String())
		}
	}
	return out, nil
}

// siteEdge replaces the site's edge settings. A new password comes as
// auth_password (hashed here, never stored); without it the previous hash stays.
func siteEdge(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain       string `json:"domain"`
		edgeSettings `json:",inline"`
		AuthPassword string `json:"auth_password,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	in := p.edgeSettings
	in.AuthHash = "" // only ever made here

	// Domains: valid, not the site's own, not the panel's, not another site's.
	taken := map[string]string{}
	for _, other := range allSiteStates(env.Cfg) {
		if other.Domain == st.Domain {
			continue
		}
		taken[other.Domain] = other.Domain
		if other.Edge != nil {
			for _, h := range append(append([]string{}, other.Edge.Aliases...), other.Edge.RedirectHosts...) {
				taken[h] = other.Domain
			}
		}
	}
	seen := map[string]bool{st.Domain: true}
	checkHosts := func(field string, hosts []string) ([]string, error) {
		var out []string
		for _, h := range hosts {
			h = strings.ToLower(strings.TrimSpace(h))
			if h == "" {
				continue
			}
			if err := validate.Domain(h); err != nil {
				return nil, protocol.Invalid(field + ": " + h + " is not a valid domain")
			}
			if h == env.Cfg.PanelDomain {
				return nil, protocol.Invalid(field + ": " + h + " is the panel's domain")
			}
			if owner, ok := taken[h]; ok {
				return nil, protocol.Conflict(field + ": " + h + " already belongs to " + owner)
			}
			if seen[h] {
				return nil, protocol.Invalid(field + ": " + h + " is listed twice")
			}
			seen[h] = true
			out = append(out, h)
		}
		if len(out) > 20 {
			return nil, protocol.Invalid(field + ": at most 20 domains")
		}
		return out, nil
	}
	if in.Aliases, err = checkHosts("aliases", in.Aliases); err != nil {
		return nil, err
	}
	if in.RedirectHosts, err = checkHosts("redirect_hosts", in.RedirectHosts); err != nil {
		return nil, err
	}

	if len(in.Redirects) > 50 {
		return nil, protocol.Invalid("redirects: at most 50 rules")
	}
	for i, r := range in.Redirects {
		if !edgePathRe.MatchString(r.From) {
			return nil, protocol.Invalid("redirects: " + r.From + " is not a path such as /old or /blog/*")
		}
		if !edgeToRe.MatchString(r.To) {
			return nil, protocol.Invalid("redirects: " + r.To + " is not a path or an https:// address")
		}
		if r.Code == 0 {
			in.Redirects[i].Code = 301
		} else if !slices.Contains([]int{301, 302, 307, 308}, r.Code) {
			return nil, protocol.Invalid("redirects: code 301, 302, 307 or 308")
		}
	}

	switch {
	case in.AuthPath == "":
		in.AuthUser = ""
	default:
		if !edgePathRe.MatchString(in.AuthPath) {
			return nil, protocol.Invalid("auth_path: a path such as / or /admin")
		}
		if !edgeUserRe.MatchString(in.AuthUser) {
			return nil, protocol.Invalid("auth_user: letters, digits, dot, dash or underscore, at most 32")
		}
		if p.AuthPassword != "" {
			if len(p.AuthPassword) < 8 || len(p.AuthPassword) > 128 || strings.ContainsAny(p.AuthPassword, "\r\n") {
				return nil, protocol.Invalid("auth_password: 8-128 characters on one line")
			}
			var out strings.Builder
			// On stdin, never in argv (readable by any user in /proc).
			if _, err := env.Exec.RunIO(ctx, strings.NewReader(p.AuthPassword+"\n"), &out, "frankenphp", "hash-password", "--algorithm", "bcrypt"); err != nil {
				return nil, fmt.Errorf("hashing the password failed: %w", err)
			}
			in.AuthHash = strings.TrimSpace(lastLine(out.String()))
		} else if st.Edge != nil && st.Edge.AuthUser == in.AuthUser {
			in.AuthHash = st.Edge.AuthHash
		}
		if !edgeHashRe.MatchString(in.AuthHash) {
			return nil, protocol.Invalid("auth_password: required for a new user")
		}
	}

	if in.AllowIPs, err = normalizePrefixList(in.AllowIPs, 50); err != nil {
		return nil, err
	}
	if len(in.AllowIPs) == 0 {
		in.AllowPath = ""
	} else {
		if in.AllowPath == "" {
			in.AllowPath = "/"
		}
		if !edgePathRe.MatchString(in.AllowPath) {
			return nil, protocol.Invalid("allow_path: a path such as / or /admin")
		}
	}

	if len(in.Headers) > 20 {
		return nil, protocol.Invalid("headers: at most 20")
	}
	for _, h := range in.Headers {
		if !headerNameRe.MatchString(h.Name) || slices.Contains(reservedHeaders, strings.ToLower(h.Name)) {
			return nil, protocol.Invalid("headers: " + h.Name + " cannot be set")
		}
		if !headerValueRe.MatchString(h.Value) {
			return nil, protocol.Invalid("headers: the value of " + h.Name + " may not contain quotes, backslashes or braces")
		}
	}

	prev := st
	st.Edge = &in
	if in.empty() {
		st.Edge = nil
	}
	l, err := layout(env.Cfg, st)
	if err != nil {
		return nil, err
	}
	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()
	if err = writeEdge(ctx, env, l, &rollback); err != nil {
		return nil, err
	}
	if err = saveSiteState(env, st); err != nil {
		return nil, err
	}
	rollback.add(func() { _ = saveSiteState(env, prev) })
	out := in
	out.AuthHash = ""
	return map[string]any{"domain": p.Domain, "edge": out, "auth": in.AuthHash != ""}, nil
}

func (e edgeSettings) empty() bool {
	return len(e.Aliases) == 0 && len(e.RedirectHosts) == 0 && len(e.Redirects) == 0 && e.AuthPath == "" &&
		len(e.AllowIPs) == 0 && !e.HSTS && !e.Security && len(e.Headers) == 0
}

// edgePathMatcher is the matcher (for a named matcher block) of a protected
// path. "/admin" covers /admin and everything under it, case-insensitively,
// and also the front-controller form /index.php/admin (PATH_INFO): Laravel and
// Symfony route that to the same page, so a plain path matcher could be walked
// around. "/" is the whole site.
func edgePathMatcher(p string) string {
	p = strings.TrimSuffix(strings.TrimSuffix(p, "*"), "/")
	if p == "" {
		return "path *"
	}
	return `path_regexp (?i)^(/[^/]*\.php)?` + regexp.QuoteMeta(p) + `(/.*)?$`
}
