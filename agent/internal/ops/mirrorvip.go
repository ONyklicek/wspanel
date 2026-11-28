package ops

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"strconv"
	"strings"
	"sync"
	"time"

	"wspanel/agent/internal/protocol"
)

// The floating IP of a mirror (Server → Zrcadlo → Plovoucí IP): one
// address in the LAN that the serving node holds, so the LAN's DNS and the
// router's port forward point at it once and never change. The mirror's
// agent decides who holds it, as it decides everything else of a failover
// (the epoch, the witness, fencing): no second election (VRRP) that could
// disagree with it. Like Pacemaker's IPaddr2 the address is added to the
// node's own interface as a secondary address (its real MAC: nothing for a
// hypervisor's MAC spoofing protection to block) and announced with
// gratuitous ARP, so switches and clients move to the new node within
// a second or two.
//
// The address is never configured permanently: after a reboot a node holds
// it only when the mirror says it serves. A primary that comes back before
// the standby confirms it is the standby takes it only when nobody else
// answers ARP for it.

type mirrorVIP struct {
	Address   string `json:"address"`   // IPv4
	Prefix    int    `json:"prefix"`    // 32: a host address of its own (never the LAN's primary address, never a source)
	Interface string `json:"interface"` // where it is added
}

// vipConfigured: the floating IP of this node's settings ("" none).
func vipConfigured(st *mirrorState) string {
	if st.VIP == nil {
		return ""
	}
	return st.VIP.Address
}

func (v *mirrorVIP) cidr() string { return v.Address + "/" + strconv.Itoa(v.Prefix) }

// arpProber and arpAnnouncer: raw ARP (arp_linux.go); tests replace them.
var (
	arpProbe    = arpProbeRaw
	arpAnnounce = arpAnnounceRaw
)

// vipIface: the interface whose IPv4 network holds ip, and that network's prefix.
func vipIface(name string, ip net.IP) (string, int, error) {
	ifaces, err := net.Interfaces()
	if err != nil {
		return "", 0, err
	}
	for _, ifi := range ifaces {
		if name != "" && ifi.Name != name {
			continue
		}
		if ifi.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, _ := ifi.Addrs()
		for _, a := range addrs {
			n, ok := a.(*net.IPNet)
			if !ok || n.IP.To4() == nil || n.IP.Equal(ip) {
				continue
			}
			if n.Contains(ip) {
				ones, _ := n.Mask.Size()
				return ifi.Name, ones, nil
			}
		}
	}
	if name != "" {
		return "", 0, protocol.Invalid("interface: " + name + " has no IPv4 network that contains " + ip.String())
	}
	return "", 0, protocol.Invalid("address: " + ip.String() + " is in none of this server's networks (it must be in the LAN both servers are in)")
}

// vipHeld: the address is on this node's interface now.
func vipHeld(v *mirrorVIP) bool {
	if v == nil {
		return false
	}
	ifi, err := net.InterfaceByName(v.Interface)
	if err != nil {
		return false
	}
	addrs, _ := ifi.Addrs()
	for _, a := range addrs {
		if n, ok := a.(*net.IPNet); ok && n.IP.Equal(net.ParseIP(v.Address)) {
			return true
		}
	}
	return false
}

// servesVIP: this node is the primary and holds the address now: only then
// may it announce it (a fencing may have released it a moment ago).
func servesVIP(env *Env, v *mirrorVIP) bool {
	return currentMirror(env).Role == rolePrimary && vipHeld(v)
}

// heldVIP: the address this node holds, for the hello ("" none).
func heldVIP(st *mirrorState) string {
	if st.VIP != nil && vipHeld(st.VIP) {
		return st.VIP.Address
	}
	return ""
}

func claimVIP(ctx context.Context, env *Env, v *mirrorVIP) error {
	if !vipHeld(v) {
		if _, err := env.Exec.Run(ctx, "ip", "-4", "addr", "add", v.cidr(), "dev", v.Interface); err != nil {
			return fmt.Errorf("adding %s to %s: %w", v.cidr(), v.Interface, err)
		}
	}
	// Switches and the clients' ARP caches learn the new owner: three
	// announcements a second apart (one may be lost while a port comes up).
	vipClaimedAt, vipAnnouncedAt = time.Now(), time.Now()
	go func() {
		for i := 0; i < 3; i++ {
			if !servesVIP(env, v) {
				return // released meanwhile
			}
			if err := arpAnnounce(v.Interface, net.ParseIP(v.Address)); err != nil {
				mirrorRuntime.event("warning", "plovoucí IP %s: oznámení ARP selhalo: %v", v.Address, err)
				return
			}
			time.Sleep(time.Second)
		}
	}()
	return nil
}

func releaseVIP(ctx context.Context, env *Env, v *mirrorVIP) error {
	if v == nil || !vipHeld(v) {
		return nil
	}
	if _, err := env.Exec.Run(ctx, "ip", "-4", "addr", "del", v.cidr(), "dev", v.Interface); err != nil {
		return fmt.Errorf("removing %s from %s: %w", v.cidr(), v.Interface, err)
	}
	return nil
}

var vipMu sync.Mutex // one reconcile, or change of the setting, at a time

var (
	// vipWaitLogged: the "someone else holds it" warning once per episode.
	vipWaitLogged bool
	// vipClaimedAt: when this node took the address (an agent that finds
	// it held at its start counts it as an old claim). vipAnnouncedAt: the last
	// gratuitous ARP. vipCheckedAt: the last conflict check.
	vipClaimedAt, vipAnnouncedAt, vipCheckedAt time.Time
)

const (
	vipReannounce   = time.Minute      // switches and ARP caches keep the holder
	vipConflictScan = 30 * time.Second // does anyone else answer for it?
	vipFreshClaim   = time.Minute      // a claim this recent is the newer one in a conflict
)

// reconcileVIP: the serving primary holds the address, every other role
// does not. Called every tick of the mirror's loop and right after a
// promotion or fencing.
func reconcileVIP(ctx context.Context, env *Env) {
	vipMu.Lock()
	defer vipMu.Unlock()
	st := currentMirror(env)
	v := st.VIP
	if v == nil {
		return
	}
	held := vipHeld(v)
	if st.Role != rolePrimary {
		vipWaitLogged = false
		if held {
			err := releaseVIP(ctx, env, v)
			if err == nil {
				mirrorRuntime.event("info", "plovoucí IP %s uvolněna (tento server je %s)", v.Address, orNone(st.Role))
			}
			mirrorRuntime.layer("vip", err, "uvolněna", nil)
			return
		}
		detail := "drží ji druhý server"
		if peerHoldsVIP(v) == "" {
			detail = "tento server ji nedrží"
		}
		mirrorRuntime.layer("vip", nil, detail, nil)
		return
	}
	if held {
		vipWaitLogged = false
		if vipClaimedAt.IsZero() {
			// Held since before this agent started: an old claim.
			vipClaimedAt = time.Now().Add(-2 * vipFreshClaim)
		}
		if vipHoldConflict(ctx, env, st, v) {
			return
		}
		if time.Since(vipAnnouncedAt) >= vipReannounce && servesVIP(env, v) {
			vipAnnouncedAt = time.Now()
			_ = arpAnnounce(v.Interface, net.ParseIP(v.Address))
		}
		mirrorRuntime.layer("vip", nil, v.Address+" na "+v.Interface+": drží tento server", nil)
		return
	}
	// A primary that has not been confirmed (it came back after an outage,
	// the standby does not answer) takes the address only when nobody
	// answers ARP for it: the standby may have taken over meanwhile.
	if !mayPointDNS(st) {
		mac, err := arpProbe(v.Interface, net.ParseIP(v.Address))
		if err != nil {
			mirrorRuntime.layer("vip", err, "nelze ověřit, zda adresu někdo drží", nil)
			return
		}
		if mac != "" {
			if !vipWaitLogged {
				vipWaitLogged = true
				mirrorRuntime.event("warning", "plovoucí IP %s drží jiný stroj (%s): tento server ji nepřevezme, dokud ji neuvolní", v.Address, mac)
			}
			mirrorRuntime.layer("vip", errors.New("adresu drží jiný stroj ("+mac+")"), "čeká", nil)
			return
		}
	}
	err := claimVIP(ctx, env, v)
	if err == nil {
		vipWaitLogged = false
		mirrorRuntime.event("info", "plovoucí IP %s teď drží tento server", v.Address)
	}
	mirrorRuntime.layer("vip", err, v.cidr()+" na "+v.Interface+": drží tento server", nil)
}

// vipHoldConflict: another machine answers ARP for the address this node
// holds (RFC 5227's ongoing conflict detection, every half minute). When it
// is the other node and this node's claim is the older one, the other node
// took over (a promotion this node did not hear of: a partition): this node
// steps down. A newer claim, or a stranger, keeps it and announces again.
// True when this node stepped down.
func vipHoldConflict(ctx context.Context, env *Env, st *mirrorState, v *mirrorVIP) bool {
	if time.Since(vipCheckedAt) < vipConflictScan {
		return false
	}
	vipCheckedAt = time.Now()
	mac, err := arpProbe(v.Interface, net.ParseIP(v.Address))
	if err != nil || mac == "" {
		return false
	}
	peerMAC := peerHostAnswers(st)
	older := !vipClaimedAt.IsZero() && time.Since(vipClaimedAt) > vipFreshClaim
	if peerMAC != "" && strings.EqualFold(peerMAC, mac) && older {
		mirrorRuntime.event("danger", "plovoucí IP %s drží i druhý server (%s): převzal provoz, tento server se odstavuje", v.Address, mac)
		mirrorRuntime.layer("vip", errors.New("adresu drží i druhý server ("+mac+")"), "odstavení", nil)
		go func() { _ = fenceLocal(context.WithoutCancel(ctx), env, "replaced") }()
		return true
	}
	who := "jiný stroj"
	if peerMAC != "" && strings.EqualFold(peerMAC, mac) {
		who = "i druhý server"
	}
	mirrorRuntime.event("danger", "plovoucí IP %s drží %s (%s): konflikt adres, tento server ji znovu oznamuje", v.Address, who, mac)
	mirrorRuntime.layer("vip", errors.New("konflikt: adresu drží "+who+" ("+mac+")"), "", nil)
	if servesVIP(env, v) {
		vipAnnouncedAt = time.Now()
		_ = arpAnnounce(v.Interface, net.ParseIP(v.Address))
	}
	return false
}

// peerHoldsVIP: the address the other node reported it holds.
func peerHoldsVIP(v *mirrorVIP) string {
	m := mirrorRuntime
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.Peer == nil || !m.PeerUp || v == nil || m.Peer.VIP != v.Address {
		return ""
	}
	return m.Peer.VIP
}

// ---------------------------------------------------------------- panel ops

// mirrorVIPSet: the floating IP (the same on both nodes; the interface is
// this node's own).
func mirrorVIPSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Address   string `json:"address"`
		Interface string `json:"interface"` // default: the one whose network holds the address
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	ip := net.ParseIP(p.Address).To4()
	if ip == nil || !ip.IsGlobalUnicast() {
		return nil, protocol.Invalid("address: an IPv4 address in the LAN")
	}
	st := currentMirror(env)
	if st.Role == roleNone {
		return nil, protocol.Conflict("this server is not in a mirror")
	}
	for _, own := range []string{st.Address, st.LANAddress} {
		if own != "" && net.ParseIP(own).Equal(ip) {
			return nil, protocol.Invalid("address: this is this server's own address; the floating IP is a free address of its own")
		}
	}
	if st.Peer != nil && net.ParseIP(st.Peer.Address).Equal(ip) {
		return nil, protocol.Invalid("address: this is the other server's address; the floating IP is a free address of its own")
	}
	iface, prefix, err := vipIface(p.Interface, ip)
	if err != nil {
		return nil, err
	}
	// Not the LAN's network or broadcast address.
	mask := net.CIDRMask(prefix, 32)
	network := ip.Mask(mask)
	bcast := make(net.IP, 4)
	for i := range 4 {
		bcast[i] = network[i] | ^mask[i]
	}
	if prefix < 31 && (ip.Equal(network) || ip.Equal(bcast)) {
		return nil, protocol.Invalid("address: " + ip.String() + " is the network's or the broadcast address of " + network.String() + "/" + strconv.Itoa(prefix))
	}
	// A host address of its own (/32): never the LAN's primary address
	// (were the server's own address removed, a /24 secondary would be
	// promoted to it and become the source of the mirror's traffic).
	v := &mirrorVIP{Address: ip.String(), Prefix: 32, Interface: iface}
	// Nobody else may hold it: a printer's address taken over would break
	// both. The other node may (it is the primary and set it up first).
	if !vipHeld(v) {
		mac, err := arpProbe(iface, ip)
		if err != nil {
			return nil, fmt.Errorf("checking whether %s is free: %w", ip, err)
		}
		if mac != "" {
			if them, herr := helloPeer(ctx, env); herr != nil || them.VIP != v.Address {
				return nil, protocol.Conflict(fmt.Sprintf("address: %s is in use by another device (MAC %s); choose a free address in the LAN (outside the DHCP range)", ip, mac))
			}
		}
	}
	// The new setting and letting go of the old address together: the loop
	// must not take the old one again in between.
	vipMu.Lock()
	old := st.VIP
	st, err = updateMirror(env, func(s *mirrorState) error {
		if s.Role == roleNone {
			return protocol.Conflict("this server is not in a mirror")
		}
		s.VIP = v
		return nil
	})
	if err == nil && old != nil && (old.Address != v.Address || old.Interface != v.Interface || old.Prefix != v.Prefix) {
		err = releaseVIP(ctx, env, old)
	}
	vipMu.Unlock()
	if err != nil {
		return nil, err
	}
	reconcileVIP(ctx, env)
	mirrorWake()
	return mirrorPublic(st), nil
}

// mirrorVIPRemove: no floating IP (this node lets go of it).
func mirrorVIPRemove(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	if st.VIP == nil {
		return map[string]any{"changed": false}, nil
	}
	vipMu.Lock()
	defer vipMu.Unlock()
	if _, err := updateMirror(env, func(s *mirrorState) error { s.VIP = nil; return nil }); err != nil {
		return nil, err
	}
	if err := releaseVIP(ctx, env, st.VIP); err != nil {
		return nil, err
	}
	mirrorRuntime.dropLayers(func(k string) bool { return k != "vip" })
	return map[string]any{"changed": true}, nil
}

// mirrorInterfaces: this node's interfaces with IPv4 networks (the form's choice).
func mirrorInterfaces(_ context.Context, _ *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	ifaces, err := net.Interfaces()
	if err != nil {
		return nil, err
	}
	out := []map[string]any{}
	for _, ifi := range ifaces {
		if ifi.Flags&net.FlagLoopback != 0 || ifi.Flags&net.FlagUp == 0 {
			continue
		}
		addrs, _ := ifi.Addrs()
		var nets []string
		for _, a := range addrs {
			if n, ok := a.(*net.IPNet); ok && n.IP.To4() != nil {
				nets = append(nets, n.String())
			}
		}
		if len(nets) > 0 {
			out = append(out, map[string]any{"name": ifi.Name, "addresses": nets})
		}
	}
	return out, nil
}
