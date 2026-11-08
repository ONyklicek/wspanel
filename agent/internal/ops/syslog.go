package ops

import (
	"context"
	"encoding/json"
	"strconv"
	"strings"

	"wspanel/agent/internal/protocol"
)

// sysLog shows the server's logs to the administrator (Server → Logy): one
// of a fixed set of sources, a time window, optionally only warnings and
// errors, and a text filter applied here (never handed to journalctl as a
// pattern).
func sysLog(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Source   string `json:"source"`
		Since    string `json:"since,omitempty"`    // 15m | 1h | 24h | 7d
		Priority string `json:"priority,omitempty"` // all | warning | err
		Filter   string `json:"filter,omitempty"`
		Lines    int    `json:"lines,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	units := map[string][]string{
		"mail":     {"postfix@-.service", "dovecot.service", "rspamd.service", "opendkim.service", "postsrsd.service"},
		"proxy":    {env.Cfg.ProxyUnit, env.Cfg.WebUnit},
		"ssh":      {"ssh.service"},
		"security": {"fail2ban.service"},
		"dns":      {"pdns.service"},
		"agent":    {"panel-agent.service"},
		"system":   nil, // the whole journal
	}
	list, ok := units[p.Source]
	if !ok {
		return nil, protocol.Invalid("source: mail, proxy, ssh, security, dns, agent or system")
	}
	since := map[string]string{"": "-1h", "15m": "-15min", "1h": "-1h", "24h": "-1day", "7d": "-7days"}[p.Since]
	if since == "" {
		return nil, protocol.Invalid("since: 15m, 1h, 24h or 7d")
	}
	if p.Lines <= 0 || p.Lines > 2000 {
		p.Lines = 500
	}
	if len(p.Filter) > 200 || strings.ContainsAny(p.Filter, "\x00\n") {
		return nil, protocol.Invalid("filter: one line, at most 200 characters")
	}
	args := []string{"--since", since, "--no-pager", "--output", "short-iso", "--quiet"}
	for _, u := range list {
		if u != "" {
			args = append(args, "--unit", u)
		}
	}
	switch p.Priority {
	case "", "all":
		if p.Source == "system" {
			args = append(args, "--priority", "warning") // everything would be every site's noise
		}
	case "warning", "err":
		args = append(args, "--priority", p.Priority)
	default:
		return nil, protocol.Invalid("priority: all, warning or err")
	}
	// Without a filter journalctl cuts the tail itself; with one, read more and filter here.
	n := p.Lines
	if p.Filter != "" {
		n = 50000
	}
	args = append(args, "--lines", strconv.Itoa(n))
	res, err := env.Exec.Run(ctx, "journalctl", args...)
	if err != nil {
		return nil, err
	}
	lines := strings.Split(strings.TrimRight(res.Stdout, "\n"), "\n")
	if p.Filter != "" {
		f := strings.ToLower(p.Filter)
		kept := lines[:0]
		for _, l := range lines {
			if strings.Contains(strings.ToLower(l), f) {
				kept = append(kept, l)
			}
		}
		lines = kept
		if len(lines) > p.Lines {
			lines = lines[len(lines)-p.Lines:]
		}
	}
	if len(lines) == 1 && lines[0] == "" {
		lines = nil
	}
	return map[string]any{"source": p.Source, "lines": lines}, nil
}
