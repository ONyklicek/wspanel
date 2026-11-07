package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"net/netip"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// fwComment tags rules created through the panel.
const fwComment = "wspanel"

type fwParams struct {
	Action string `json:"action,omitempty"` // fw.delete only: allow|deny
	Port   int    `json:"port"`
	Proto  string `json:"proto"`
	From   string `json:"from,omitempty"` // IP or CIDR; empty = anywhere
}

func (p *fwParams) validate(needAction bool) error {
	if needAction {
		if err := validate.OneOf("action", p.Action, []string{"allow", "deny"}); err != nil {
			return err
		}
	} else if p.Action != "" {
		return protocol.Invalid("action: not accepted here")
	}
	if p.Port < 1 || p.Port > 65535 {
		return protocol.Invalid("port: must be 1-65535")
	}
	if err := validate.OneOf("proto", p.Proto, []string{"tcp", "udp"}); err != nil {
		return err
	}
	if p.From != "" {
		// Normalize: a bare address becomes a host prefix; prefixes are masked.
		if pfx, err := netip.ParsePrefix(p.From); err == nil {
			p.From = pfx.Masked().String()
		} else if addr, err := netip.ParseAddr(p.From); err == nil {
			p.From = addr.String()
		} else {
			return protocol.Invalid("from: must be an IP address or CIDR")
		}
	}
	return nil
}

// ruleArgs builds the ufw rule spec. Values are validated, and each one is a
// separate argv element.
func (p fwParams) ruleArgs() []string {
	if p.From == "" {
		return []string{fmt.Sprintf("%d/%s", p.Port, p.Proto)}
	}
	return []string{"proto", p.Proto, "from", p.From, "to", "any", "port", strconv.Itoa(p.Port)}
}

func (p fwParams) guardProtected(env *Env) error {
	if slices.Contains(env.Cfg.ProtectedPorts, p.Port) {
		return protocol.Forbidden(fmt.Sprintf("port %d is protected (SSH/HTTP/HTTPS); change it on the server directly", p.Port))
	}
	return nil
}

func fwAllow(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return fwAdd(ctx, env, raw, "allow")
}

func fwDeny(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return fwAdd(ctx, env, raw, "deny")
}

func fwAdd(ctx context.Context, env *Env, raw json.RawMessage, action string) (any, error) {
	var p fwParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := p.validate(false); err != nil {
		return nil, err
	}
	if action == "deny" {
		if err := p.guardProtected(env); err != nil {
			return nil, err
		}
	}
	args := append([]string{action}, p.ruleArgs()...)
	args = append(args, "comment", fwComment)
	res, err := env.Exec.Run(ctx, "ufw", args...)
	if err != nil {
		return nil, err
	}
	return map[string]any{"action": action, "port": p.Port, "proto": p.Proto, "from": p.From, "result": strings.TrimSpace(res.Stdout)}, nil
}

func fwDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p fwParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := p.validate(true); err != nil {
		return nil, err
	}
	if p.Action == "allow" {
		if err := p.guardProtected(env); err != nil {
			return nil, err
		}
	}
	args := append([]string{"delete", p.Action}, p.ruleArgs()...)
	res, err := env.Exec.Run(ctx, "ufw", args...)
	if err != nil {
		return nil, err
	}
	return map[string]any{"deleted": true, "result": strings.TrimSpace(res.Stdout)}, nil
}

// FWRule is one row of `ufw status`, with IPv4/IPv6 duplicates merged.
type FWRule struct {
	To      string `json:"to"`
	Port    int    `json:"port,omitempty"`
	Proto   string `json:"proto,omitempty"`
	Action  string `json:"action"`
	From    string `json:"from"`
	Comment string `json:"comment,omitempty"`
	IPv6    bool   `json:"ipv6"`
	IPv4    bool   `json:"ipv4"`
	Managed bool   `json:"managed"` // created through the panel
}

var ufwRow = regexp.MustCompile(`^(.+?)\s{2,}(ALLOW|DENY|REJECT|LIMIT)(?: (?:IN|OUT|FWD))?\s{2,}(.+?)(?:\s+# (.*))?$`)

func parseUFWStatus(out string) (bool, []FWRule) {
	active := strings.Contains(out, "Status: active")
	var rules []FWRule
	index := map[string]int{}
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimRight(line, " ")
		m := ufwRow.FindStringSubmatch(line)
		if m == nil || m[1] == "To" {
			continue
		}
		to, from := strings.TrimSpace(m[1]), strings.TrimSpace(m[3])
		v6 := strings.HasSuffix(to, " (v6)")
		to = strings.TrimSuffix(to, " (v6)")
		from = strings.TrimSuffix(from, " (v6)")
		r := FWRule{To: to, Action: strings.ToLower(m[2]), From: from, Comment: m[4], Managed: m[4] == fwComment}
		if port, proto, ok := strings.Cut(to, "/"); ok {
			if n, err := strconv.Atoi(port); err == nil {
				r.Port, r.Proto = n, proto
			}
		} else if n, err := strconv.Atoi(to); err == nil {
			r.Port = n
		}
		key := r.To + "|" + r.Action + "|" + r.From
		if i, seen := index[key]; seen {
			if v6 {
				rules[i].IPv6 = true
			} else {
				rules[i].IPv4 = true
			}
			continue
		}
		r.IPv6, r.IPv4 = v6, !v6
		index[key] = len(rules)
		rules = append(rules, r)
	}
	return active, rules
}

func fwList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	res, err := env.Exec.Run(ctx, "ufw", "status")
	if err != nil {
		return nil, err
	}
	active, rules := parseUFWStatus(res.Stdout)
	if rules == nil {
		rules = []FWRule{}
	}
	return map[string]any{"active": active, "rules": rules, "protected_ports": env.Cfg.ProtectedPorts}, nil
}
