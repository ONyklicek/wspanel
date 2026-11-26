package ops

import (
	"context"
	"encoding/json"
	"net"
	"path/filepath"

	"wspanel/agent/internal/protocol"
)

// panelEnvFile: the panel's own settings (a variable for the tests).
var panelEnvFile = filepath.Join(panelShared, ".env")

// panelPublicAddress (Server → Systém): the addresses the internet reaches
// this server at, which every suggested DNS record points to. Behind NAT
// they are the router's, not the interface's; installed from the route's
// source, they were the internal one. Node-local (a mirror keeps its own).
func panelPublicAddress(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		IPv4 string `json:"ipv4"`
		IPv6 string `json:"ipv6"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.IPv4 != "" {
		if ip := net.ParseIP(p.IPv4); ip == nil || ip.To4() == nil {
			return nil, protocol.Invalid("ipv4: an IPv4 address")
		}
	}
	if p.IPv6 != "" {
		if ip := net.ParseIP(p.IPv6); ip == nil || ip.To4() != nil {
			return nil, protocol.Invalid("ipv6: an IPv6 address")
		}
	}
	if err := writeEnvKeys(env, panelEnvFile, map[string]string{"PANEL_PUBLIC_IPV4": p.IPv4, "PANEL_PUBLIC_IPV6": p.IPv6}); err != nil {
		return nil, err
	}
	// The panel reads its configuration from the cache; the queue worker
	// keeps it in memory until it restarts.
	rep := &RestoreReport{}
	artisan(ctx, env, rep, "config:cache")
	artisan(ctx, env, rep, "queue:restart")
	return map[string]any{"ipv4": p.IPv4, "ipv6": p.IPv6, "problems": rep.Problems}, nil
}
