package ops

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Blocking by country: an nftables table of its own beside UFW's, with one
// interval set of the countries' address ranges (ipdeny.com aggregated
// zones) and a rule dropping new connections from them, to every port or
// only to the server's administration ports. Established connections and
// the loopback are never touched, and the addresses in "allow" (the
// administrator's own, the panel's allowlist) never blocked. The ruleset is
// a file a systemd unit loads at boot.

type geoState struct {
	Countries []string `json:"countries"` // ISO 3166-1 alpha-2, lowercase
	Scope     string   `json:"scope"`     // all | admin
	Allow     []string `json:"allow"`     // never blocked (addresses or prefixes)
	Updated   string   `json:"updated,omitempty"`
}

var (
	countryRe = regexp.MustCompile(`^[a-z]{2}$`)
	// The lists' source; a var so tests can point it at a fake.
	geoSource = func(cc string, v6 bool) string {
		if v6 {
			return "https://www.ipdeny.com/ipv6/ipaddresses/aggregated/" + cc + "-aggregated.zone"
		}
		return "https://www.ipdeny.com/ipblocks/data/aggregated/" + cc + "-aggregated.zone"
	}
)

func geoStatePath(env *Env) string { return filepath.Join(env.Cfg.StateDir, "geoblock.json") }
func geoListDir(env *Env) string   { return filepath.Join(env.Cfg.StateDir, "geoblock") }

func loadGeo(env *Env) (*geoState, error) {
	st := &geoState{Scope: "all"}
	b, err := os.ReadFile(geoStatePath(env))
	if os.IsNotExist(err) {
		return st, nil
	}
	if err != nil {
		return nil, err
	}
	return st, json.Unmarshal(b, st)
}

// fwGeoblock reads (action get), sets or refreshes (downloads the lists
// again; the panel does it weekly) the blocked countries.
func fwGeoblock(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Action    string   `json:"action"` // get | set | refresh
		Countries []string `json:"countries,omitempty"`
		Scope     string   `json:"scope,omitempty"`
		Allow     []string `json:"allow,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, err := loadGeo(env)
	if err != nil {
		return nil, err
	}
	switch p.Action {
	case "get":
		return st, nil
	case "refresh":
		if len(st.Countries) == 0 {
			return st, nil
		}
	case "set":
		if len(p.Countries) > 60 {
			return nil, protocol.Invalid("countries: at most 60")
		}
		var cc []string
		for _, c := range p.Countries {
			c = strings.ToLower(strings.TrimSpace(c))
			if !countryRe.MatchString(c) {
				return nil, protocol.Invalid("countries: not a country code: " + c)
			}
			cc = append(cc, c)
		}
		if p.Scope != "all" && p.Scope != "admin" {
			return nil, protocol.Invalid("scope: all or admin")
		}
		var allow []string
		for _, a := range p.Allow {
			pfx, err := parsePrefix(a)
			if err != nil {
				return nil, protocol.Invalid("allow: not an address or network: " + a)
			}
			allow = append(allow, pfx.String())
		}
		slices.Sort(cc)
		st.Countries, st.Scope, st.Allow = slices.Compact(cc), p.Scope, allow
	default:
		return nil, protocol.Invalid("action: get, set or refresh")
	}
	if len(st.Countries) > 0 {
		if err := fetchGeoLists(ctx, env, st.Countries); err != nil {
			return nil, err
		}
		st.Updated = time.Now().UTC().Format(time.RFC3339)
	}
	if err := applyGeoblock(ctx, env, st); err != nil {
		return nil, err
	}
	b, _ := json.MarshalIndent(st, "", "  ")
	if err := env.FS.MkdirAll(env.Cfg.StateDir, 0o700, fsx.Root); err != nil {
		return nil, err
	}
	if err := env.FS.WriteFile(geoStatePath(env), b, 0o600, fsx.Root); err != nil {
		return nil, err
	}
	return st, nil
}

func parsePrefix(s string) (netip.Prefix, error) {
	s = strings.TrimSpace(s)
	if pfx, err := netip.ParsePrefix(s); err == nil {
		return pfx.Masked(), nil
	}
	addr, err := netip.ParseAddr(s)
	if err != nil {
		return netip.Prefix{}, err
	}
	return netip.PrefixFrom(addr, addr.BitLen()), nil
}

// fetchGeoLists downloads each country's ranges; every line is parsed as a
// prefix (nothing else reaches the ruleset). A country already downloaded
// keeps its old list when the source is unreachable.
func fetchGeoLists(ctx context.Context, env *Env, countries []string) error {
	if err := env.FS.MkdirAll(geoListDir(env), 0o700, fsx.Root); err != nil {
		return err
	}
	client := &http.Client{Timeout: 60 * time.Second}
	for _, cc := range countries {
		for _, v6 := range []bool{false, true} {
			path := filepath.Join(geoListDir(env), cc+map[bool]string{false: ".v4", true: ".v6"}[v6])
			list, err := downloadPrefixes(ctx, client, geoSource(cc, v6), v6)
			if err != nil {
				if _, statErr := os.Stat(path); statErr == nil {
					continue
				}
				return fmt.Errorf("country %s: %w", strings.ToUpper(cc), err)
			}
			if err := env.FS.WriteFile(path, []byte(strings.Join(list, "\n")+"\n"), 0o600, fsx.Root); err != nil {
				return err
			}
		}
	}
	return nil
}

func downloadPrefixes(ctx context.Context, client *http.Client, url string, v6 bool) ([]string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusNotFound {
		return nil, nil // a country without (v6) ranges
	}
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s: HTTP %d", url, resp.StatusCode)
	}
	var out []string
	sc := bufio.NewScanner(io.LimitReader(resp.Body, 32<<20))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		pfx, err := netip.ParsePrefix(line)
		if err != nil || pfx.Addr().Is6() != v6 {
			return nil, fmt.Errorf("%s: not a list of networks", url)
		}
		out = append(out, pfx.Masked().String())
	}
	return out, sc.Err()
}

// adminPorts: the protected ports that are not the sites' (80, 443): SSH
// and a panel on a port of its own.
func adminPorts(env *Env) []int {
	var ports []int
	for _, p := range env.Cfg.ProtectedPorts {
		if p != 80 && p != 443 && !slices.Contains(ports, p) {
			ports = append(ports, p)
		}
	}
	if env.Cfg.PanelPort > 0 && env.Cfg.PanelPort != 443 && !slices.Contains(ports, env.Cfg.PanelPort) {
		ports = append(ports, env.Cfg.PanelPort)
	}
	slices.Sort(ports)
	return ports
}

// geoRuleset renders the table (or only its removal when nothing is blocked).
func geoRuleset(env *Env, st *geoState) (string, error) {
	var b strings.Builder
	b.WriteString("# Managed by WSPanel agent (geoblock.json).\n")
	// Replace atomically: create (no-op when it exists), delete, define.
	b.WriteString("table inet wspanel_geo\ndelete table inet wspanel_geo\n")
	if len(st.Countries) == 0 {
		return b.String(), nil
	}
	var v4, v6 []string
	for _, cc := range st.Countries {
		for _, fam := range []string{"v4", "v6"} {
			data, err := os.ReadFile(filepath.Join(geoListDir(env), cc+"."+fam))
			if err != nil {
				return "", err
			}
			for _, l := range strings.Fields(string(data)) {
				if _, err := netip.ParsePrefix(l); err != nil {
					return "", fmt.Errorf("stored list of %s is damaged", cc)
				}
				if fam == "v4" {
					v4 = append(v4, l)
				} else {
					v6 = append(v6, l)
				}
			}
		}
	}
	var a4, a6 []string
	for _, a := range st.Allow {
		pfx, err := netip.ParsePrefix(a)
		if err != nil {
			continue
		}
		if pfx.Addr().Is4() {
			a4 = append(a4, pfx.String())
		} else {
			a6 = append(a6, pfx.String())
		}
	}
	set := func(name, typ string, elems []string) {
		fmt.Fprintf(&b, "  set %s {\n    type %s\n    flags interval\n    auto-merge\n", name, typ)
		if len(elems) > 0 {
			fmt.Fprintf(&b, "    elements = { %s }\n", strings.Join(elems, ", "))
		}
		b.WriteString("  }\n")
	}
	b.WriteString("table inet wspanel_geo {\n")
	set("blocked4", "ipv4_addr", v4)
	set("blocked6", "ipv6_addr", v6)
	set("allow4", "ipv4_addr", a4)
	set("allow6", "ipv6_addr", a6)
	b.WriteString("  chain input {\n    type filter hook input priority -5; policy accept;\n")
	b.WriteString("    iif \"lo\" accept\n    ct state established,related accept\n")
	b.WriteString("    ip saddr @allow4 accept\n    ip6 saddr @allow6 accept\n")
	ports := ""
	if st.Scope == "admin" {
		var ps []string
		for _, p := range adminPorts(env) {
			ps = append(ps, fmt.Sprint(p))
		}
		if len(ps) == 0 {
			return "", protocol.Conflict("no administration ports to block (SSH)")
		}
		ports = "meta l4proto { tcp, udp } th dport { " + strings.Join(ps, ", ") + " } "
	}
	fmt.Fprintf(&b, "    ip saddr @blocked4 %sdrop\n    ip6 saddr @blocked6 %sdrop\n  }\n}\n", ports, ports)
	return b.String(), nil
}

// applyGeoblock writes the ruleset, loads it and keeps the boot unit in step.
func applyGeoblock(ctx context.Context, env *Env, st *geoState) error {
	rules, err := geoRuleset(env, st)
	if err != nil {
		return err
	}
	file := filepath.Join(filepath.Dir(geoStatePath(env)), "geoblock.nft")
	if err := env.FS.WriteFile(file, []byte(rules), 0o600, fsx.Root); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "nft", "-f", file); err != nil {
		return fmt.Errorf("loading the country rules: %w", err)
	}
	unit := filepath.Join(env.Cfg.SystemdDir, "wspanel-geoblock.service")
	content := "# Managed by WSPanel agent.\n[Unit]\nDescription=WSPanel: blocking by country\nAfter=network-pre.target ufw.service\n\n" +
		"[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/sbin/nft -f " + file + "\n\n[Install]\nWantedBy=multi-user.target\n"
	if len(st.Countries) == 0 {
		if _, err := os.Stat(unit); err == nil {
			_, _ = env.Exec.Run(ctx, "systemctl", "disable", "wspanel-geoblock.service")
			if err := os.Remove(unit); err != nil {
				return err
			}
			_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
		}
		return nil
	}
	if changed, err := writeIfChanged(env, unit, content); err != nil {
		return err
	} else if changed {
		_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
		_, _ = env.Exec.Run(ctx, "systemctl", "enable", "wspanel-geoblock.service")
	}
	return nil
}
