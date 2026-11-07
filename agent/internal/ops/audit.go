package ops

import (
	"bufio"
	"context"
	"encoding/json"
	"os"
	"strings"

	"wspanel/agent/internal/audit"
	"wspanel/agent/internal/protocol"
)

// auditTail returns the newest audit entries (newest first), optionally only
// for operations starting with a prefix ("site.", "db.", ...).
func auditTail(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Limit  int    `json:"limit"`
		Prefix string `json:"prefix,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Limit < 1 || p.Limit > 1000 {
		return nil, protocol.Invalid("limit: 1-1000")
	}
	if len(p.Prefix) > 32 || strings.ContainsAny(p.Prefix, " \n") {
		return nil, protocol.Invalid("prefix")
	}
	f, err := os.Open(env.Cfg.AuditLog)
	if err != nil {
		return []audit.Entry{}, nil
	}
	defer f.Close()
	ring := make([]audit.Entry, 0, p.Limit)
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		var e audit.Entry
		if json.Unmarshal(sc.Bytes(), &e) != nil || !strings.HasPrefix(e.Op, p.Prefix) {
			continue
		}
		if len(ring) == p.Limit {
			ring = ring[1:]
		}
		ring = append(ring, e)
	}
	for i, j := 0, len(ring)-1; i < j; i, j = i+1, j-1 {
		ring[i], ring[j] = ring[j], ring[i]
	}
	return ring, nil
}
