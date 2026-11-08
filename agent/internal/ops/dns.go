package ops

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Local authoritative DNS: PowerDNS (SQLite backend) answers on port 53; the
// agent manages it through its HTTP API on loopback with a key only root can
// read. Records are handled as RRsets (name + type -> values), like PowerDNS.

var DNSRecordTypes = []string{"A", "AAAA", "CNAME", "MX", "TXT", "SRV", "CAA", "NS"}

type RRset struct {
	Name    string   `json:"name"` // FQDN without the trailing dot
	Type    string   `json:"type"`
	TTL     int      `json:"ttl"`
	Records []string `json:"records"`
}

func pdnsEnabled(env *Env) error {
	if env.Cfg.PowerDNSAPI == "" || env.Cfg.PowerDNSKey == "" {
		return protocol.NotFound("local DNS (PowerDNS) is not installed on this server (install.sh --dns=powerdns)")
	}
	return nil
}

// pdns calls the PowerDNS API. Paths are built from validated names only.
var pdns = func(ctx context.Context, env *Env, method, path string, body any) ([]byte, int, error) {
	var rd io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		rd = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, strings.TrimRight(env.Cfg.PowerDNSAPI, "/")+"/api/v1/servers/localhost"+path, rd)
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("X-API-Key", env.Cfg.PowerDNSKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := (&http.Client{Timeout: 15 * time.Second}).Do(req)
	if err != nil {
		return nil, 0, fmt.Errorf("PowerDNS: %w", err)
	}
	defer resp.Body.Close()
	out, _ := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	if resp.StatusCode >= 300 {
		var e struct {
			Error string `json:"error"`
		}
		_ = json.Unmarshal(out, &e)
		if e.Error == "" {
			e.Error = strings.TrimSpace(string(out))
		}
		return out, resp.StatusCode, fmt.Errorf("PowerDNS: HTTP %d: %s", resp.StatusCode, e.Error)
	}
	return out, resp.StatusCode, nil
}

func fqdn(name string) string { return strings.TrimSuffix(name, ".") + "." }

func dnsZones(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	if err := pdnsEnabled(env); err != nil {
		return nil, err
	}
	b, _, err := pdns(ctx, env, http.MethodGet, "/zones", nil)
	if err != nil {
		return nil, err
	}
	var zones []struct {
		Name   string `json:"name"`
		Serial int    `json:"serial"`
	}
	if err := json.Unmarshal(b, &zones); err != nil {
		return nil, err
	}
	out := make([]map[string]any, 0, len(zones))
	for _, z := range zones {
		out = append(out, map[string]any{"domain": strings.TrimSuffix(z.Name, "."), "serial": z.Serial})
	}
	return out, nil
}

type dnsZoneParams struct {
	Domain      string   `json:"domain"`
	Nameservers []string `json:"nameservers,omitempty"` // create: NS of the zone (FQDNs)
}

func dnsZoneCreate(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dnsZoneParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if err := pdnsEnabled(env); err != nil {
		return nil, err
	}
	if len(p.Nameservers) == 0 || len(p.Nameservers) > 6 {
		return nil, protocol.Invalid("nameservers: 1–6 host names")
	}
	ns := make([]string, 0, len(p.Nameservers))
	for _, n := range p.Nameservers {
		if err := validate.Domain(strings.TrimSuffix(n, ".")); err != nil {
			return nil, protocol.Invalid("nameservers: " + n + " is not a host name")
		}
		ns = append(ns, fqdn(n))
	}
	_, code, err := pdns(ctx, env, http.MethodPost, "/zones", map[string]any{
		// Master: secondary servers get NOTIFY on each change (and may transfer
		// the zone if they are in allow-axfr-ips, dns.secondary).
		"name": fqdn(p.Domain), "kind": "Master", "nameservers": ns, "soa_edit_api": "DEFAULT",
	})
	if code == http.StatusConflict {
		return nil, protocol.Conflict("zone " + p.Domain + " already exists")
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "nameservers": ns}, nil
}

func dnsZoneDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain  string `json:"domain"`
		Confirm string `json:"confirm"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if p.Confirm != p.Domain {
		return nil, protocol.Invalid("confirm: must equal domain")
	}
	if err := pdnsEnabled(env); err != nil {
		return nil, err
	}
	if _, code, err := pdns(ctx, env, http.MethodDelete, "/zones/"+fqdn(p.Domain), nil); err != nil && code != http.StatusNotFound {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "deleted": true}, nil
}

func dnsRecords(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if err := pdnsEnabled(env); err != nil {
		return nil, err
	}
	b, code, err := pdns(ctx, env, http.MethodGet, "/zones/"+fqdn(p.Domain), nil)
	if code == http.StatusNotFound || code == http.StatusUnprocessableEntity {
		return nil, protocol.NotFound("zone " + p.Domain + " does not exist")
	}
	if err != nil {
		return nil, err
	}
	var z struct {
		Serial int `json:"serial"`
		RRsets []struct {
			Name    string `json:"name"`
			Type    string `json:"type"`
			TTL     int    `json:"ttl"`
			Records []struct {
				Content string `json:"content"`
			} `json:"records"`
		} `json:"rrsets"`
	}
	if err := json.Unmarshal(b, &z); err != nil {
		return nil, err
	}
	sets := make([]RRset, 0, len(z.RRsets))
	for _, r := range z.RRsets {
		s := RRset{Name: strings.TrimSuffix(r.Name, "."), Type: r.Type, TTL: r.TTL}
		for _, rec := range r.Records {
			s.Records = append(s.Records, rec.Content)
		}
		sets = append(sets, s)
	}
	slices.SortFunc(sets, func(a, b RRset) int {
		if c := strings.Compare(a.Name, b.Name); c != 0 {
			return c
		}
		return strings.Compare(a.Type, b.Type)
	})
	return map[string]any{"domain": p.Domain, "serial": z.Serial, "rrsets": sets}, nil
}

var (
	dnsLabelRe = regexp.MustCompile(`^(\*|[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?)$`)
	mxRe       = regexp.MustCompile(`^(\d{1,5}) (\S+)$`)
	srvRe      = regexp.MustCompile(`^(\d{1,5}) (\d{1,5}) (\d{1,5}) (\S+)$`)
	caaRe      = regexp.MustCompile(`^(0|128) (issue|issuewild|iodef) "[\x21-\x7e ]{0,255}"$`)
)

// validRecordName: the zone apex or a name inside the zone.
func validRecordName(name, zone string) error {
	name = strings.ToLower(strings.TrimSuffix(name, "."))
	if name == zone {
		return nil
	}
	rel, ok := strings.CutSuffix(name, "."+zone)
	if !ok || rel == "" {
		return protocol.Invalid("name: must be " + zone + " or end with ." + zone)
	}
	for _, l := range strings.Split(rel, ".") {
		if !dnsLabelRe.MatchString(l) {
			return protocol.Invalid("name: invalid label " + strconv.Quote(l))
		}
	}
	return nil
}

func hostOK(h string) bool {
	h = strings.ToLower(strings.TrimSuffix(h, "."))
	return validate.Domain(h) == nil
}

// normalizeRecord validates one value for its type and returns it in the
// PowerDNS presentation format (FQDN targets, quoted TXT).
func normalizeRecord(typ, v string) (string, error) {
	v = strings.TrimSpace(v)
	bad := func(msg string) (string, error) {
		return "", protocol.Invalid(typ + " " + strconv.Quote(v) + ": " + msg)
	}
	switch typ {
	case "A":
		if ip := net.ParseIP(v); ip == nil || ip.To4() == nil {
			return bad("IPv4 address expected")
		}
		return v, nil
	case "AAAA":
		if ip := net.ParseIP(v); ip == nil || ip.To4() != nil {
			return bad("IPv6 address expected")
		}
		return v, nil
	case "CNAME", "NS":
		if !hostOK(v) {
			return bad("host name expected")
		}
		return fqdn(strings.ToLower(v)), nil
	case "MX":
		m := mxRe.FindStringSubmatch(v)
		if m == nil || !hostOK(m[2]) {
			return bad(`"priority host" expected, e.g. "10 mail.example.com"`)
		}
		return m[1] + " " + fqdn(strings.ToLower(m[2])), nil
	case "SRV":
		m := srvRe.FindStringSubmatch(v)
		if m == nil || !hostOK(m[4]) {
			return bad(`"priority weight port target" expected`)
		}
		return m[1] + " " + m[2] + " " + m[3] + " " + fqdn(strings.ToLower(m[4])), nil
	case "CAA":
		if !caaRe.MatchString(v) {
			return bad(`e.g. 0 issue "letsencrypt.org"`)
		}
		return v, nil
	case "TXT":
		v = strings.Trim(v, `"`)
		if len(v) > 2048 || strings.ContainsAny(v, "\n\r\"\\") {
			return bad("max 2048 characters, no quotes, backslashes or line breaks")
		}
		// Strings over 255 bytes are split into several quoted strings (DKIM keys).
		var parts []string
		for len(v) > 255 {
			parts = append(parts, `"`+v[:255]+`"`)
			v = v[255:]
		}
		return strings.Join(append(parts, `"`+v+`"`), " "), nil
	}
	return bad("unsupported type")
}

type dnsSetParams struct {
	Domain  string   `json:"domain"`
	Name    string   `json:"name"`
	Type    string   `json:"type"`
	TTL     int      `json:"ttl,omitempty"`
	Records []string `json:"records"` // empty = delete the RRset
}

// dnsRecordSet replaces (or with no records deletes) one RRset.
func dnsRecordSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dnsSetParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if err := validate.OneOf("type", p.Type, DNSRecordTypes); err != nil {
		return nil, err
	}
	if err := validRecordName(p.Name, p.Domain); err != nil {
		return nil, err
	}
	if err := pdnsEnabled(env); err != nil {
		return nil, err
	}
	name := fqdn(strings.ToLower(p.Name))
	if p.TTL == 0 {
		p.TTL = 3600
	}
	if p.TTL < 60 || p.TTL > 604800 {
		return nil, protocol.Invalid("ttl: 60–604800 seconds")
	}
	if len(p.Records) > 50 {
		return nil, protocol.Invalid("records: at most 50 values")
	}
	if p.Type == "CNAME" && len(p.Records) > 1 {
		return nil, protocol.Invalid("CNAME: exactly one target")
	}
	if p.Type == "CNAME" && name == fqdn(p.Domain) {
		return nil, protocol.Invalid("CNAME is not allowed at the zone apex")
	}
	rrset := map[string]any{"name": name, "type": p.Type}
	if len(p.Records) == 0 {
		rrset["changetype"] = "DELETE"
	} else {
		recs := make([]map[string]any, 0, len(p.Records))
		for _, v := range p.Records {
			c, err := normalizeRecord(p.Type, v)
			if err != nil {
				return nil, err
			}
			recs = append(recs, map[string]any{"content": c, "disabled": false})
		}
		rrset["changetype"], rrset["ttl"], rrset["records"] = "REPLACE", p.TTL, recs
	}
	if _, _, err := pdns(ctx, env, http.MethodPatch, "/zones/"+fqdn(p.Domain), map[string]any{"rrsets": []any{rrset}}); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "name": strings.TrimSuffix(name, "."), "type": p.Type, "records": len(p.Records)}, nil
}
