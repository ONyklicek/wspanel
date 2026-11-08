package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strings"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Secondary DNS and DNSSEC for the local PowerDNS.
//
// One server answering for a zone is one outage away from the domain (and
// its mail) disappearing. Secondary servers (another provider's, or a second
// machine) keep a copy: they may transfer the zones (AXFR, only from the
// addresses listed here) and get a NOTIFY on each change. Their names become
// the default nameservers of new zones beside this server's.

type dnsState struct {
	Secondary dnsSecondary `json:"secondary"`
}

type dnsSecondary struct {
	IPs         []string `json:"ips"`         // may transfer, get NOTIFY
	Nameservers []string `json:"nameservers"` // their NS names (default NS of new zones)
}

func dnsStatePath(env *Env) string { return filepath.Join(env.Cfg.StateDir, "dns.json") }

func loadDNS(env *Env) (*dnsState, error) {
	st := &dnsState{}
	b, err := os.ReadFile(dnsStatePath(env))
	if os.IsNotExist(err) {
		return st, nil
	}
	if err != nil {
		return nil, err
	}
	return st, json.Unmarshal(b, st)
}

// writePowerDNSConf renders the agent's PowerDNS settings; restarts PowerDNS
// when they changed. DNSSEC (signing in the SQLite backend) is always on: a
// zone is signed only once it has a key (dns.dnssec).
func writePowerDNSConf(ctx context.Context, env *Env, st *dnsState) error {
	if env.Cfg.PowerDNSConf == "" {
		return nil
	}
	var b strings.Builder
	b.WriteString("# Managed by WSPanel agent (dns.json).\ngsqlite3-dnssec=yes\nprimary=yes\n")
	axfr := append([]string{"127.0.0.0/8", "::1"}, st.Secondary.IPs...)
	fmt.Fprintf(&b, "allow-axfr-ips=%s\n", strings.Join(axfr, ", "))
	if len(st.Secondary.IPs) > 0 {
		fmt.Fprintf(&b, "also-notify=%s\n", strings.Join(st.Secondary.IPs, ", "))
	}
	if old, err := os.ReadFile(env.Cfg.PowerDNSConf); err == nil && string(old) == b.String() {
		return nil
	}
	owner := fsx.Root
	if o, err := lookupGroupOwnerByGroup("pdns"); err == nil {
		owner = o
	}
	if err := env.FS.MkdirAll(filepath.Dir(env.Cfg.PowerDNSConf), 0o755, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(env.Cfg.PowerDNSConf, []byte(b.String()), 0o640, owner); err != nil {
		return err
	}
	_, err := env.Exec.Run(ctx, "systemctl", "restart", "pdns.service")
	return err
}

// dnsSecondarySet sets (or with ips omitted, reads) the secondary servers.
// Zones created before become Master, so they notify too.
func dnsSecondarySet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		IPs         *[]string `json:"ips,omitempty"`
		Nameservers []string  `json:"nameservers,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := pdnsEnabled(env); err != nil {
		return nil, err
	}
	st, err := loadDNS(env)
	if err != nil {
		return nil, err
	}
	if p.IPs == nil {
		return st.Secondary, nil
	}
	if len(*p.IPs) > 10 || len(p.Nameservers) > 6 {
		return nil, protocol.Invalid("at most 10 addresses and 6 nameservers")
	}
	var ips, names []string
	for _, ip := range *p.IPs {
		ip = strings.TrimSpace(ip)
		parsed := net.ParseIP(ip)
		if parsed == nil || parsed.IsLoopback() || parsed.IsUnspecified() {
			return nil, protocol.Invalid("ips: not a server address: " + ip)
		}
		ips = append(ips, parsed.String())
	}
	for _, n := range p.Nameservers {
		n = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(n), "."))
		if err := validate.Domain(n); err != nil {
			return nil, protocol.Invalid("nameservers: " + n + " is not a host name")
		}
		names = append(names, n)
	}
	slices.Sort(ips)
	st.Secondary = dnsSecondary{IPs: slices.Compact(ips), Nameservers: slices.Compact(names)}
	b, _ := json.MarshalIndent(st, "", "  ")
	if err := env.FS.MkdirAll(env.Cfg.StateDir, 0o700, fsx.Root); err != nil {
		return nil, err
	}
	if err := env.FS.WriteFile(dnsStatePath(env), b, 0o600, fsx.Root); err != nil {
		return nil, err
	}
	if err := writePowerDNSConf(ctx, env, st); err != nil {
		return nil, err
	}
	if len(st.Secondary.IPs) > 0 {
		if err := zonesToMaster(ctx, env); err != nil {
			return nil, err
		}
	}
	return st.Secondary, nil
}

// zonesToMaster turns Native zones (made before secondaries existed) into
// Master ones and asks PowerDNS to notify their secondaries now.
func zonesToMaster(ctx context.Context, env *Env) error {
	b, _, err := pdns(ctx, env, http.MethodGet, "/zones", nil)
	if err != nil {
		return err
	}
	var zones []struct {
		Name string `json:"name"`
		Kind string `json:"kind"`
	}
	if err := json.Unmarshal(b, &zones); err != nil {
		return err
	}
	for _, z := range zones {
		if err := validate.Domain(strings.TrimSuffix(z.Name, ".")); err != nil {
			continue
		}
		if z.Kind == "Native" {
			if _, _, err := pdns(ctx, env, http.MethodPut, "/zones/"+z.Name, map[string]any{"kind": "Master"}); err != nil {
				return err
			}
		}
		_, _, _ = pdns(ctx, env, http.MethodPut, "/zones/"+z.Name+"/notify", nil)
	}
	return nil
}

// dnsDNSSEC reads, enables or disables a zone's signing. Enabling adds one
// active CSK (ECDSA P-256); the registrar then needs the DS record shown.
// Disabling removes the keys: with a DS still at the registrar the domain
// stops resolving, so the panel asks for that first.
func dnsDNSSEC(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		Action string `json:"action"` // get | enable | disable
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if !slices.Contains([]string{"get", "enable", "disable"}, p.Action) {
		return nil, protocol.Invalid("action: get, enable or disable")
	}
	if err := pdnsEnabled(env); err != nil {
		return nil, err
	}
	zone := "/zones/" + fqdn(p.Domain)
	keys := func() ([]map[string]any, error) {
		b, code, err := pdns(ctx, env, http.MethodGet, zone+"/cryptokeys", nil)
		if code == http.StatusNotFound || code == http.StatusUnprocessableEntity {
			return nil, protocol.NotFound("zone " + p.Domain + " does not exist")
		}
		if err != nil {
			return nil, err
		}
		var out []map[string]any
		return out, json.Unmarshal(b, &out)
	}
	if p.Action != "get" {
		st, err := loadDNS(env)
		if err != nil {
			return nil, err
		}
		if err := writePowerDNSConf(ctx, env, st); err != nil {
			return nil, err
		}
	}
	existing, err := keys()
	if err != nil {
		return nil, err
	}
	switch {
	case p.Action == "enable" && len(existing) == 0:
		if _, _, err := pdns(ctx, env, http.MethodPost, zone+"/cryptokeys", map[string]any{"keytype": "csk", "active": true, "algorithm": "ECDSAP256SHA256"}); err != nil {
			return nil, err
		}
	case p.Action == "disable":
		for _, k := range existing {
			if id, ok := k["id"].(float64); ok {
				if _, _, err := pdns(ctx, env, http.MethodDelete, fmt.Sprintf("%s/cryptokeys/%d", zone, int(id)), nil); err != nil {
					return nil, err
				}
			}
		}
	}
	if p.Action != "get" {
		_, _, _ = pdns(ctx, env, http.MethodPut, zone+"/rectify", nil)
		// Answers signed (or not) a moment ago would linger in the packet cache.
		_, _, _ = pdns(ctx, env, http.MethodPut, "/cache/flush?domain="+fqdn(p.Domain), nil)
		if existing, err = keys(); err != nil {
			return nil, err
		}
	}
	var ds []string
	for _, k := range existing {
		if active, _ := k["active"].(bool); !active {
			continue
		}
		list, _ := k["ds"].([]any)
		for _, d := range list {
			if s, ok := d.(string); ok {
				ds = append(ds, s)
			}
		}
	}
	return map[string]any{"domain": p.Domain, "enabled": len(existing) > 0, "ds": ds}, nil
}
