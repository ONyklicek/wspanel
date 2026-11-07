package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
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
	tree, err := treeCaddy(lines)
	if err != nil {
		return err
	}
	return checkDirectiveLines(tree)
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
		return nil, protocol.Invalid("directives: the web server refused them: " + err.Error())
	}
	if err = saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "directives": src}, nil
}
