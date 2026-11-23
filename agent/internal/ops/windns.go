package ops

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// The LAN's DNS on a Windows server (Active Directory zones): the active
// node keeps the A records of its sites pointing at itself, so a failover
// moves the LAN's clients within a TTL. Written over OpenSSH (built into
// Windows) to an account of its own that may only run wspanel-dns.ps1
// (ForceCommand): the script takes one JSON request on stdin, changes only
// A/AAAA records of the zones it was set up for, and never deletes a name
// it was not asked about. The host key is pinned when the connection is set
// up; the private key never leaves this node. Each node has its own key and
// its own settings (the standby reaches the Windows host by another address
// from inside Docker).

type winDNSConfig struct {
	Host    string   `json:"host"`
	Port    int      `json:"port"`
	User    string   `json:"user"`
	Zones   []string `json:"zones"`
	TTL     int      `json:"ttl"`
	Extra   []string `json:"extra,omitempty"` // more names that follow the active node (the panel, the mail host)
	HostKey string   `json:"host_key,omitempty"`
}

func (c *winDNSConfig) public() map[string]any {
	return map[string]any{"host": c.Host, "port": c.Port, "user": c.User, "zones": c.Zones, "ttl": c.TTL, "extra": c.Extra, "trusted": c.HostKey != ""}
}

var winUserRe = regexp.MustCompile(`^[A-Za-z0-9._-]{1,64}(@[A-Za-z0-9.-]{1,190})?$`)

func winKeyPath(env *Env) string { return mirrorFile(env, "windns_ed25519") }

// mirrorWinDNSSet stores the Windows DNS settings and returns this node's
// public key (for the account's authorized_keys on Windows).
func mirrorWinDNSSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Host  string   `json:"host"`
		Port  int      `json:"port"`
		User  string   `json:"user"`
		Zones []string `json:"zones"`
		TTL   int      `json:"ttl"`
		Extra []string `json:"extra"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	host, err := mirrorAddr("host", p.Host)
	if err != nil {
		return nil, err
	}
	if p.Port == 0 {
		p.Port = 22
	}
	if p.Port < 1 || p.Port > 65535 {
		return nil, protocol.Invalid("port: 1-65535")
	}
	if !winUserRe.MatchString(p.User) {
		return nil, protocol.Invalid("user: an account name (or name@domain)")
	}
	if len(p.Zones) == 0 || len(p.Zones) > 50 {
		return nil, protocol.Invalid("zones: 1-50 zones")
	}
	for i, z := range p.Zones {
		z = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(z), "."))
		if err := validate.Domain(z); err != nil && !mirrorHostname(z) {
			return nil, protocol.Invalid("zones: " + z + " is not a zone name")
		}
		p.Zones[i] = z
	}
	for i, n := range p.Extra {
		n = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(n), "."))
		if !mirrorHostname(n) {
			return nil, protocol.Invalid("extra: " + n + " is not a host name")
		}
		p.Extra[i] = n
	}
	if p.TTL == 0 {
		p.TTL = 60
	}
	if p.TTL < 30 || p.TTL > 86400 {
		return nil, protocol.Invalid("ttl: 30-86400 seconds")
	}
	st, err := updateMirror(env, func(s *mirrorState) error {
		if s.Role == roleNone {
			return protocol.Conflict("set the mirror up first")
		}
		keep := ""
		if s.WinDNS != nil && s.WinDNS.Host == host && s.WinDNS.Port == p.Port {
			keep = s.WinDNS.HostKey
		}
		s.WinDNS = &winDNSConfig{Host: host, Port: p.Port, User: p.User, Zones: p.Zones, TTL: p.TTL, Extra: p.Extra, HostKey: keep}
		return nil
	})
	if err != nil {
		return nil, err
	}
	pub, err := winPublicKey(ctx, env, st.NodeID)
	if err != nil {
		return nil, err
	}
	return map[string]any{"windns": st.WinDNS.public(), "public_key": pub}, nil
}

// winPublicKey: this node's key for Windows (made once).
func winPublicKey(ctx context.Context, env *Env, nodeID string) (string, error) {
	if env.Cfg.DryRun {
		return "ssh-ed25519 AAAA… wspanel-" + nodeID, nil
	}
	if !fsx.Exists(winKeyPath(env)) {
		if err := env.FS.MkdirAll(mirrorDir(env), 0o700, fsx.Root); err != nil {
			return "", err
		}
		if _, err := env.Exec.Run(ctx, "ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-C", "wspanel-"+nodeID, "-f", winKeyPath(env)); err != nil {
			return "", err
		}
	}
	b, err := os.ReadFile(winKeyPath(env) + ".pub")
	return strings.TrimSpace(string(b)), err
}

// mirrorWinDNSTrust reads the Windows host's key and pins it; the panel
// shows its fingerprint to compare with the one on Windows.
func mirrorWinDNSTrust(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	if st.WinDNS == nil {
		return nil, protocol.Conflict("set the Windows DNS up first")
	}
	c := st.WinDNS
	res, err := env.Exec.Run(ctx, "ssh-keyscan", "-T", "5", "-t", "ed25519", "-p", strconv.Itoa(c.Port), c.Host)
	if err != nil {
		return nil, err
	}
	var line string
	for _, l := range strings.Split(res.Stdout, "\n") {
		if f := strings.Fields(l); len(f) == 3 && f[1] == "ssh-ed25519" {
			line = f[1] + " " + f[2]
		}
	}
	if line == "" && !env.Cfg.DryRun {
		return nil, errors.New("the Windows host offers no ed25519 key (OpenSSH Server)")
	}
	if _, err := updateMirror(env, func(s *mirrorState) error {
		if s.WinDNS != nil {
			s.WinDNS.HostKey = line
		}
		return nil
	}); err != nil {
		return nil, err
	}
	fp := ""
	if r, err := env.Exec.RunIO(ctx, strings.NewReader(c.Host+" "+line+"\n"), nil, "ssh-keygen", "-l", "-f", "-"); err == nil {
		fp = strings.TrimSpace(r.Stdout)
	}
	return map[string]any{"host_key": line, "fingerprint": fp}, nil
}

type winRecord struct {
	Zone   string   `json:"zone"`
	Name   string   `json:"name"` // relative to the zone, "@" = the zone itself
	Type   string   `json:"type"` // A | AAAA
	TTL    int      `json:"ttl"`
	Values []string `json:"values"`
}

type winAnswer struct {
	OK      bool        `json:"ok"`
	Error   string      `json:"error,omitempty"`
	Changed []string    `json:"changed,omitempty"`
	Errors  []string    `json:"errors,omitempty"`
	Records []winRecord `json:"records,omitempty"`
}

// winCall: one request to the script on Windows.
func winCall(ctx context.Context, env *Env, c *winDNSConfig, req map[string]any) (winAnswer, error) {
	var ans winAnswer
	if c.HostKey == "" {
		return ans, errors.New("the Windows host's key is not trusted yet (Zrcadlo → Windows DNS → Ověřit hostitele)")
	}
	known := mirrorFile(env, "windns_known_hosts")
	hostLabel := c.Host
	if c.Port != 22 {
		hostLabel = "[" + c.Host + "]:" + strconv.Itoa(c.Port)
	}
	if err := writeMirrorFile(env, "windns_known_hosts", []byte(hostLabel+" "+c.HostKey+"\n")); err != nil {
		return ans, err
	}
	body, _ := json.Marshal(req)
	ctx, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()
	res, err := env.Exec.RunIO(ctx, strings.NewReader(string(body)+"\n"), nil, "ssh",
		"-i", winKeyPath(env), "-p", strconv.Itoa(c.Port), "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes",
		"-o", "StrictHostKeyChecking=yes", "-o", "UserKnownHostsFile="+known, "-o", "ConnectTimeout=10",
		"-o", "ServerAliveInterval=10", "-T", c.User+"@"+c.Host)
	if err != nil {
		return ans, err
	}
	out := strings.TrimSpace(res.Stdout)
	// PowerShell may print a BOM or a banner line before the answer.
	if i := strings.LastIndex(out, "\n{"); i >= 0 {
		out = out[i+1:]
	}
	out = strings.TrimPrefix(out, "\xef\xbb\xbf")
	if err := json.Unmarshal([]byte(out), &ans); err != nil {
		return ans, fmt.Errorf("the Windows script's answer: %v (%s)", err, truncateStr(out, 300))
	}
	if !ans.OK {
		return ans, errors.New("Windows DNS: " + ans.Error)
	}
	return ans, nil
}

func truncateStr(s string, n int) string {
	if len(s) > n {
		return s[:n] + "…"
	}
	return s
}

// mirrorWinDNSTest lists the records of the first zone (the connection works).
func mirrorWinDNSTest(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	if st.WinDNS == nil {
		return nil, protocol.Conflict("set the Windows DNS up first")
	}
	ans, err := winCall(ctx, env, st.WinDNS, map[string]any{"action": "list", "zone": st.WinDNS.Zones[0]})
	if err != nil {
		return nil, err
	}
	return map[string]any{"zone": st.WinDNS.Zones[0], "records": ans.Records}, nil
}

func mirrorWinDNSRemove(_ context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	_, err := updateMirror(env, func(s *mirrorState) error { s.WinDNS = nil; return nil })
	return map[string]any{"removed": true}, err
}

// zoneOf: the configured zone a host name belongs to (the longest suffix).
func zoneOf(zones []string, host string) (zone, name string, ok bool) {
	for _, z := range zones {
		if host == z || strings.HasSuffix(host, "."+z) {
			if len(z) > len(zone) {
				zone = z
			}
		}
	}
	if zone == "" {
		return "", "", false
	}
	if host == zone {
		return zone, "@", true
	}
	return zone, strings.TrimSuffix(host, "."+zone), true
}

// winDesired: the records that point at this node while it is primary.
func winDesired(env *Env, st *mirrorState) []winRecord {
	c := st.WinDNS
	addr := st.LANAddress
	if addr == "" {
		addr = st.Address
	}
	ip := net.ParseIP(addr)
	if ip == nil {
		return nil
	}
	typ := "A"
	if ip.To4() == nil {
		typ = "AAAA"
	}
	var hosts []string
	for _, s := range allSiteStates(env.Cfg) {
		hosts = append(hosts, s.Domain)
		if s.Edge != nil {
			hosts = append(hosts, s.Edge.Aliases...)
			hosts = append(hosts, s.Edge.RedirectHosts...)
		}
	}
	hosts = append(hosts, c.Extra...)
	slices.Sort(hosts)
	hosts = slices.Compact(hosts)
	var out []winRecord
	for _, h := range hosts {
		if zone, name, ok := zoneOf(c.Zones, strings.ToLower(h)); ok {
			out = append(out, winRecord{Zone: zone, Name: name, Type: typ, TTL: c.TTL, Values: []string{ip.String()}})
		}
	}
	return out
}

// reconcileWinDNS: the primary's records point at it (called every minute
// and right after a promotion).
func reconcileWinDNS(ctx context.Context, env *Env, logf func(string, ...any)) {
	st := currentMirror(env)
	if st.Role != rolePrimary || st.WinDNS == nil || st.WinDNS.Host == "" {
		return
	}
	// Only while the other node confirms it is the standby: a primary that
	// comes back after an outage must not point the LAN at itself before it
	// learns that the standby took over.
	if st.Peer != nil && !peerConfirmsStandby() {
		return
	}
	recs := winDesired(env, st)
	if len(recs) == 0 {
		mirrorRuntime.layer("windns", nil, "no site in the zones "+strings.Join(st.WinDNS.Zones, ", "), nil)
		return
	}
	ans, err := winCall(ctx, env, st.WinDNS, map[string]any{"action": "apply", "records": recs})
	if err == nil && len(ans.Errors) > 0 {
		err = errors.New(strings.Join(ans.Errors, "; "))
	}
	detail := fmt.Sprintf("%d records point here", len(recs))
	if len(ans.Changed) > 0 {
		detail += ", changed: " + strings.Join(ans.Changed, ", ")
		mirrorRuntime.event("info", "Windows DNS teď směruje sem: %s", strings.Join(ans.Changed, ", "))
		logf("mirror: Windows DNS changed %s", strings.Join(ans.Changed, ", "))
	}
	mirrorRuntime.layer("windns", err, detail, nil)
}
