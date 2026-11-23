package ops

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync/atomic"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/validate"
)

// The sites' own servers listen on loopback ports (a Node or Python app and
// its second slot, Reverb, the Inertia SSR server, the site's Valkey). The
// edge reaches them there, and so could any other user of this server: a
// site's shell user would bypass the edge (its password, IP rules, the
// maintenance page) and talk to another site's app directly, as the trusted
// local proxy. Only the edge, the agent and the site's own user may connect;
// the rule covers loopback only, so a site's outgoing connection to a port
// of the same number elsewhere is untouched.
const siteGuardUnit = "wspanel-site-guard.service"

// sitePorts are the loopback ports a site's own servers use.
func sitePorts(st siteState) []int {
	var out []int
	for _, p := range []int{st.Port, st.PortB, st.ReverbPort, st.SSRPort, st.ValkeyPort} {
		if p > 0 {
			out = append(out, p)
		}
	}
	if st.Valkey != nil && st.Valkey.Port > 0 {
		out = append(out, st.Valkey.Port)
	}
	slices.Sort(out)
	return slices.Compact(out)
}

// Windows' own services (Kerberos, RPC, NetBIOS, LDAP, SMB, RDP, WinRM, AD
// web services, dynamic RPC): on WSL with mirrored networking this server's
// addresses are the Windows host's, often a domain controller. A site's or a
// game's process must not reach them there.
const winServicePorts = "88, 135, 137-139, 389, 445, 464, 593, 636, 3268-3269, 3389, 5985-5986, 9389, 47001, 49152-65535"

// onWSL: the agent runs in WSL2 (Windows); a variable for the tests.
var onWSL = func() bool {
	b, err := os.ReadFile("/proc/sys/kernel/osrelease")
	return err == nil && strings.Contains(strings.ToLower(string(b)), "microsoft")
}

// confinedUID: a user's id (a variable for the tests).
var confinedUID = func(env *Env, name string) (int, bool) {
	o, err := lookupOwner(env, name)
	return o.UID, err == nil && o.UID > 0
}

// confinedUIDs: the users that run sites' and games' code (extra: one
// being created whose state is not saved yet).
func confinedUIDs(env *Env, extra ...int) []string {
	var out []string
	for _, uid := range extra {
		if uid > 0 {
			out = append(out, strconv.Itoa(uid))
		}
	}
	for _, st := range allSiteStates(env.Cfg) {
		if uid, ok := confinedUID(env, validate.SiteUser(st.Domain)); ok {
			out = append(out, strconv.Itoa(uid))
		}
	}
	for _, g := range allGames(env) {
		if uid, ok := confinedUID(env, g.user()); ok {
			out = append(out, strconv.Itoa(uid))
		}
	}
	slices.Sort(out)
	return slices.Compact(out)
}

// siteGuardRules renders the table: who may connect to each guarded port.
func siteGuardRules(env *Env, extraConfined ...int) (string, error) {
	rules := "table inet wspanel_sites\ndelete table inet wspanel_sites\n"
	proxy, err := lookupOwner(env, env.Cfg.ProxyGroup)
	if err != nil {
		return "", fmt.Errorf("the edge's user: %w", err)
	}
	var guarded, allowed []string
	for _, st := range allSiteStates(env.Cfg) {
		ports := sitePorts(st)
		if len(ports) == 0 {
			continue
		}
		owner, err := lookupOwner(env, validate.SiteUser(st.Domain))
		if err != nil {
			// Not created yet or already gone: nobody but the edge and root.
			owner.UID = 0
		}
		for _, p := range ports {
			port := strconv.Itoa(p)
			guarded = append(guarded, port)
			for _, uid := range []int{0, proxy.UID, owner.UID} {
				allowed = append(allowed, port+" . "+strconv.Itoa(uid))
			}
		}
	}
	var wsl []string
	if onWSL() {
		if uids := confinedUIDs(env, extraConfined...); len(uids) > 0 {
			// fib: whatever addresses the server has right now (IPv4, IPv6,
			// loopback), never a list that a new DHCP lease makes stale.
			wsl = []string{
				"  set confined {\n    type uid\n    elements = { " + strings.Join(uids, ", ") + " }\n  }\n",
				"    meta skuid @confined fib daddr type local tcp dport { " + winServicePorts + " } reject with tcp reset\n" +
					"    meta skuid @confined fib daddr type local udp dport { 88, 137-138, 389, 464 } reject\n",
			}
		}
	}
	if len(guarded) == 0 && wsl == nil {
		return rules, nil
	}
	slices.Sort(guarded)
	guarded = slices.Compact(guarded)
	slices.Sort(allowed)
	allowed = slices.Compact(allowed)
	rules += "table inet wspanel_sites {\n"
	if len(guarded) > 0 {
		rules += "  set guarded {\n    type inet_service\n    elements = { " + strings.Join(guarded, ", ") + " }\n  }\n" +
			"  set allowed {\n    type inet_service . uid\n    elements = { " + strings.Join(allowed, ", ") + " }\n  }\n"
	}
	if wsl != nil {
		rules += wsl[0]
	}
	rules += "  chain output {\n    type filter hook output priority 0; policy accept;\n"
	if wsl != nil {
		// Windows' services on this server's own addresses: before anything else.
		rules += wsl[1]
	}
	if len(guarded) > 0 {
		rules += "    oifname \"lo\" tcp dport . meta skuid @allowed accept\n" +
			"    oifname \"lo\" tcp dport @guarded reject with tcp reset\n"
	}
	rules += "  }\n}\n"
	return rules, nil
}

// siteGuardStale: the last load failed, so the rules in force may be older
// than the ports the sites hold; every state save tries again until it works.
var siteGuardStale atomic.Bool

// applySiteGuard loads the rules now and at every boot (before the network,
// so before any site's server starts).
func applySiteGuard(ctx context.Context, env *Env, extraConfined ...int) (err error) {
	defer func() { siteGuardStale.Store(err != nil) }()
	rules, err := siteGuardRules(env, extraConfined...)
	if err != nil {
		return err
	}
	file := filepath.Join(env.Cfg.StateDir, "site-guard.nft")
	if err := env.FS.MkdirAll(env.Cfg.StateDir, 0o700, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(file, []byte(rules), 0o600, fsx.Root); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "nft", "-f", file); err != nil {
		return fmt.Errorf("loading the sites' port guard: %w", err)
	}
	unit := filepath.Join(env.Cfg.SystemdDir, siteGuardUnit)
	content := "# Managed by WSPanel agent.\n[Unit]\nDescription=WSPanel: the sites' own ports for the edge and the site only\n" +
		"DefaultDependencies=no\nBefore=network-pre.target\nWants=network-pre.target\n\n" +
		"[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/sbin/nft -f " + file + "\n\n[Install]\nWantedBy=multi-user.target\n"
	if changed, err := writeIfChanged(env, unit, content); err != nil {
		return err
	} else if changed {
		_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
		_, _ = env.Exec.Run(ctx, "systemctl", "enable", siteGuardUnit)
	}
	return nil
}

// ApplySiteGuardOnStart brings the guard up to the sites there are (an
// upgrade from a version without it, a state edited by hand).
func ApplySiteGuardOnStart(ctx context.Context, env *Env, logf func(string, ...any)) {
	if env.Cfg.DryRun {
		return
	}
	if err := applySiteGuard(ctx, env); err != nil {
		logf("the sites' port guard: %v", err)
	}
}

// samePorts tells whether a state change moves any guarded port.
func samePorts(a, b siteState) bool { return slices.Equal(sitePorts(a), sitePorts(b)) }
