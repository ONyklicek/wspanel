package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/protocol"
)

// Custom web-server rules of a site: a Caddyfile snippet placed in the
// site's edge block, inside its route, right before the request goes to the
// site's backend (so in written order, after the panel's own access rules).
//
// The edge serves every site and the panel, so the snippet may only use
// directives that shape the site's own requests and responses. Anything that
// could reach elsewhere is refused: proxying (other sites' sockets, Mailpit,
// the panel), files and file placeholders, environment, imports, changing the
// Host the backend routes by.

const maxDirectivesBytes = 16 << 10

// Directives allowed where a directive is expected. Containers hold more
// directives; leaves may have a block of their own settings.
var (
	directiveContainers = []string{"handle", "handle_path", "route"}
	directiveLeaves     = []string{"header", "request_header", "encode", "rewrite", "uri", "redir", "respond", "error", "abort", "request_body", "vars", "map", "log_skip", "skip_log"}
	matcherNames        = []string{"path", "path_regexp", "header", "header_regexp", "method", "query", "remote_ip", "client_ip", "protocol", "not", "host"}
	// Placeholders that read the server's files or environment.
	forbiddenPlaceholders = []string{"{file.", "{env.", "{$", "{system."}
)

type cfLine struct {
	num   int
	toks  []string
	block []cfLine
	open  bool // ended with "{"
}

// lexCaddy splits a Caddyfile snippet into lines of tokens. Quoted and
// backtick strings stay one token (without the quotes); # starts a comment.
func lexCaddy(src string) ([]cfLine, error) {
	var lines []cfLine
	var toks []string
	var cur strings.Builder
	inTok, num := false, 1
	flush := func() {
		if inTok {
			toks = append(toks, cur.String())
			cur.Reset()
			inTok = false
		}
	}
	endLine := func() {
		flush()
		if len(toks) > 0 {
			lines = append(lines, cfLine{num: num, toks: toks})
		}
		toks = nil
	}
	rs := []rune(src)
	for i := 0; i < len(rs); i++ {
		c := rs[i]
		switch {
		case c == '\n':
			endLine()
			num++
		case c == ' ' || c == '\t' || c == '\r':
			flush()
		case c == '#' && !inTok:
			for i < len(rs) && rs[i] != '\n' {
				i++
			}
			i--
		case (c == '"' || c == '`') && !inTok:
			quote := c
			inTok = true
			i++
			for ; i < len(rs) && rs[i] != quote; i++ {
				if rs[i] == '\\' && quote == '"' && i+1 < len(rs) {
					i++
				}
				if rs[i] == '\n' {
					num++
				}
				cur.WriteRune(rs[i])
			}
			if i >= len(rs) {
				return nil, fmt.Errorf("line %d: unterminated quote", num)
			}
			toks = append(toks, cur.String())
			cur.Reset()
			inTok = false
		case c == '<' && i+1 < len(rs) && rs[i+1] == '<' && !inTok:
			return nil, fmt.Errorf("line %d: heredocs are not allowed", num)
		default:
			cur.WriteRune(c)
			inTok = true
		}
	}
	endLine()
	return lines, nil
}

// treeCaddy nests lines into blocks ("x {" … "}").
func treeCaddy(lines []cfLine) ([]cfLine, error) {
	var parse func(i int, depth int) ([]cfLine, int, error)
	parse = func(i, depth int) ([]cfLine, int, error) {
		var out []cfLine
		for i < len(lines) {
			l := lines[i]
			if len(l.toks) == 1 && l.toks[0] == "}" {
				if depth == 0 {
					return nil, i, fmt.Errorf("line %d: unexpected }", l.num)
				}
				return out, i + 1, nil
			}
			for _, t := range l.toks {
				if t == "}" || (t == "{" && t != l.toks[len(l.toks)-1]) {
					return nil, i, fmt.Errorf("line %d: put { at the end of a line and } on its own line", l.num)
				}
			}
			if l.toks[len(l.toks)-1] == "{" {
				l.toks = l.toks[:len(l.toks)-1]
				l.open = true
				if len(l.toks) == 0 {
					return nil, i, fmt.Errorf("line %d: a block needs a directive", l.num)
				}
				children, next, err := parse(i+1, depth+1)
				if err != nil {
					return nil, next, err
				}
				l.block = children
				out = append(out, l)
				i = next
				continue
			}
			out = append(out, l)
			i++
		}
		if depth > 0 {
			return nil, i, fmt.Errorf("missing }")
		}
		return out, i, nil
	}
	tree, _, err := parse(0, 0)
	return tree, err
}

// checkDirectives validates a snippet; the error says which line and why.
func checkDirectives(src string) error {
	if len(src) > maxDirectivesBytes {
		return fmt.Errorf("at most %d KB", maxDirectivesBytes>>10)
	}
	lines, err := lexCaddy(src)
	if err != nil {
		return err
	}
	for _, l := range lines {
		for _, t := range l.toks {
			low := strings.ToLower(t)
			for _, f := range forbiddenPlaceholders {
				if strings.Contains(low, f) {
					return fmt.Errorf("line %d: placeholder %s… is not allowed", l.num, f)
				}
			}
		}
	}
	for _, l := range lines {
		// Parse-time constructs work in any block, so they are refused anywhere.
		if first := strings.ToLower(l.toks[0]); first == "import" || strings.HasPrefix(first, "(") {
			return fmt.Errorf("line %d: imports and snippets are not allowed", l.num)
		}
	}
	tree, err := treeCaddy(lines)
	if err != nil {
		return err
	}
	return checkDirectiveLines(tree)
}

// What Caddy itself makes of the rules is checked too: the snippet is adapted
// by Caddy's own parser (as nobody, in a wrapper of nothing but the snippet)
// and every handler and matcher in the result must be allowed. This is the
// decisive check: the hand-written one above only gives early, readable
// errors, and a difference between the two parsers cannot let anything pass.
var (
	allowedHandlers       = []string{"headers", "encode", "rewrite", "static_response", "request_body", "vars", "map", "subroute", "error"}
	allowedMatchers       = []string{"path", "path_regexp", "header", "header_regexp", "method", "query", "remote_ip", "client_ip", "protocol", "not", "host"}
	directivesWrapperHead = "{\n\tadmin off\n}\nhttp://wsp-check.invalid {\n\troute {\n"
	adaptLineRe           = regexp.MustCompile(`:(\d+)\b`)
)

func adaptDirectives(ctx context.Context, env *Env, src string) error {
	if env.Cfg.DryRun {
		return nil
	}
	dir, err := os.MkdirTemp("", "wsp-rules-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(dir)
	if err := os.Chmod(dir, 0o755); err != nil {
		return err
	}
	file := filepath.Join(dir, "Caddyfile")
	if err := os.WriteFile(file, []byte(directivesWrapperHead+src+"\n\t}\n}\n"), 0o644); err != nil {
		return err
	}
	// Explicitly: the agent's umask (0077) would leave it unreadable for nobody.
	if err := os.Chmod(file, 0o644); err != nil {
		return err
	}
	res, err := env.Exec.Run(ctx, "setpriv", "--reuid=nobody", "--regid=nogroup", "--clear-groups", "--",
		env.Cfg.FrankenPHPBin, "adapt", "--config", file, "--adapter", "caddyfile")
	if err != nil {
		return fmt.Errorf("%s", adaptError(res.Stdout+"\n"+res.Stderr, file))
	}
	var cfg any
	if err := json.Unmarshal([]byte(firstJSON(res.Stdout)), &cfg); err != nil {
		return fmt.Errorf("the web server could not read them")
	}
	return checkAdapted(cfg)
}

// adaptError keeps only Caddy's reason and the line within the rules.
func adaptError(out, file string) string {
	head := strings.Count(directivesWrapperHead, "\n")
	for _, line := range strings.Split(out, "\n") {
		if i := strings.Index(line, file); i >= 0 {
			msg := strings.TrimSpace(line[i+len(file):])
			if m := adaptLineRe.FindStringSubmatch(msg); m != nil {
				n, _ := strconv.Atoi(m[1])
				msg = strings.TrimSpace(strings.TrimLeft(strings.TrimPrefix(msg, ":"+m[1]), ":"))
				return fmt.Sprintf("line %d: %s", max(n-head, 1), msg)
			}
			return msg
		}
	}
	return "the web server could not read them"
}

func firstJSON(s string) string {
	if i := strings.Index(s, "{"); i >= 0 {
		if j := strings.Index(s[i:], "\n"); j >= 0 {
			return s[i : i+j]
		}
		return s[i:]
	}
	return s
}

func checkAdapted(v any) error {
	switch t := v.(type) {
	case map[string]any:
		if h, ok := t["handler"].(string); ok {
			if !slices.Contains(allowedHandlers, h) {
				return fmt.Errorf("handler %q is not allowed", h)
			}
			if h == "headers" {
				if req, ok := t["request"].(map[string]any); ok {
					if err := checkRequestFields(req); err != nil {
						return err
					}
				}
			}
		}
		if m, ok := t["match"]; ok {
			if err := checkAdaptedMatchers(m); err != nil {
				return err
			}
		}
		for _, child := range t {
			if err := checkAdapted(child); err != nil {
				return err
			}
		}
	case []any:
		for _, child := range t {
			if err := checkAdapted(child); err != nil {
				return err
			}
		}
	case string:
		low := strings.ToLower(t)
		for _, f := range forbiddenPlaceholders {
			if strings.Contains(low, f) {
				return fmt.Errorf("placeholder %s… is not allowed", f)
			}
		}
	}
	return nil
}

func checkAdaptedMatchers(m any) error {
	sets, ok := m.([]any)
	if !ok {
		return fmt.Errorf("unexpected matcher")
	}
	for _, s := range sets {
		set, ok := s.(map[string]any)
		if !ok {
			return fmt.Errorf("unexpected matcher")
		}
		for name, val := range set {
			if !slices.Contains(allowedMatchers, name) {
				return fmt.Errorf("matcher %q is not allowed", name)
			}
			if name == "not" {
				if err := checkAdaptedMatchers(val); err != nil {
					return err
				}
			}
		}
	}
	return nil
}

func checkRequestFields(req map[string]any) error {
	for _, op := range []string{"set", "add", "delete", "replace"} {
		var fields []string
		switch v := req[op].(type) {
		case map[string]any:
			for k := range v {
				fields = append(fields, k)
			}
		case []any:
			for _, k := range v {
				if s, ok := k.(string); ok {
					fields = append(fields, s)
				}
			}
		}
		for _, f := range fields {
			f = strings.ToLower(f)
			if f == "host" || strings.HasPrefix(f, "x-forwarded-") || f == "x-real-ip" || f == "forwarded" {
				return fmt.Errorf("request header %s is set by the edge", f)
			}
		}
	}
	return nil
}

func checkDirectiveLines(lines []cfLine) error {
	for _, l := range lines {
		name := strings.ToLower(l.toks[0])
		switch {
		case strings.HasPrefix(name, "@"):
			if strings.HasPrefix(name, "@wsp_") {
				return fmt.Errorf("line %d: matcher names starting with @wsp_ are the panel's", l.num)
			}
			if l.open {
				if err := checkMatcherLines(l.block); err != nil {
					return err
				}
			} else if len(l.toks) < 2 || !slices.Contains(matcherNames, strings.ToLower(l.toks[1])) {
				return fmt.Errorf("line %d: matcher %s needs one of: %s", l.num, l.toks[0], strings.Join(matcherNames, ", "))
			}
		case slices.Contains(directiveContainers, name):
			if l.open {
				if err := checkDirectiveLines(l.block); err != nil {
					return err
				}
			}
		case slices.Contains(directiveLeaves, name):
			if name == "request_header" {
				if err := checkRequestHeader(l); err != nil {
					return err
				}
			}
		default:
			return fmt.Errorf("line %d: directive %q is not allowed (allowed: %s)", l.num, l.toks[0],
				strings.Join(append(append([]string{}, directiveContainers...), directiveLeaves...), ", "))
		}
	}
	return nil
}

func checkMatcherLines(lines []cfLine) error {
	for _, l := range lines {
		name := strings.ToLower(l.toks[0])
		if !slices.Contains(matcherNames, name) {
			return fmt.Errorf("line %d: matcher %q is not allowed (allowed: %s)", l.num, l.toks[0], strings.Join(matcherNames, ", "))
		}
		if name == "not" && l.open {
			if err := checkMatcherLines(l.block); err != nil {
				return err
			}
		}
	}
	return nil
}

// The backend routes by Host and trusts the edge's X-Forwarded-*: a site
// must not point its requests at another site or spoof its client address.
func checkRequestHeader(l cfLine) error {
	fields := []string{}
	if l.open {
		for _, c := range l.block {
			fields = append(fields, c.toks[0])
		}
	} else {
		for _, t := range l.toks[1:] {
			if !strings.HasPrefix(t, "@") && !strings.HasPrefix(t, "*") && t != "/" && !strings.HasPrefix(t, "/") {
				fields = append(fields, t)
				break
			}
		}
	}
	for _, f := range fields {
		f = strings.ToLower(strings.TrimLeft(f, "+-?>"))
		if f == "host" || strings.HasPrefix(f, "x-forwarded-") || f == "x-real-ip" || f == "forwarded" {
			return fmt.Errorf("line %d: request header %s is set by the edge", l.num, f)
		}
	}
	return nil
}

// siteDirectives stores a site's custom rules and rewrites its edge block;
// a snippet Caddy rejects puts the previous block back.
func siteDirectives(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p struct {
		Domain     string `json:"domain"`
		Directives string `json:"directives"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	src := strings.TrimSpace(strings.ReplaceAll(p.Directives, "\r\n", "\n"))
	if src != "" {
		if err := checkDirectives(src); err != nil {
			return nil, protocol.Invalid("directives: " + err.Error())
		}
		if err := adaptDirectives(ctx, env, src); err != nil {
			return nil, protocol.Invalid("directives: " + err.Error())
		}
	}
	st.Directives = src
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
		// The whole edge config was checked: its errors may name other sites. Logged, not shown.
		log.Printf("site.directives %s: edge rejected: %v", p.Domain, err)
		return nil, protocol.Invalid("directives: the web server refused them")
	}
	if err = saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "directives": src}, nil
}

var hookIDRe = regexp.MustCompile(`^[a-f0-9]{32}$`)

// siteHook sets (or removes, with an empty id) the route that brings this
// site's deploy webhook in through its own domain. The panel decides when:
// only for a site with webhook deploys, when webhooks from the internet are
// allowed and the internet does not reach the panel itself.
func siteHook(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p struct {
		Domain string `json:"domain"`
		HookID string `json:"hook_id"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.HookID != "" && !hookIDRe.MatchString(p.HookID) {
		return nil, protocol.Invalid("hook_id: 32 hex characters")
	}
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.HookID == p.HookID {
		return map[string]any{"domain": p.Domain, "hook": p.HookID != "", "changed": false}, nil
	}
	st.HookID = p.HookID
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
	return map[string]any{"domain": p.Domain, "hook": p.HookID != "", "changed": true}, nil
}
