package ops

import (
	"context"
	"net"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestARPFrames(t *testing.T) {
	own := net.HardwareAddr{0x00, 0x15, 0x5d, 0x01, 0x02, 0x03}
	other := net.HardwareAddr{0x00, 0x15, 0x5d, 0x0a, 0x0b, 0x0c}
	vip := net.ParseIP("192.168.222.10")

	f := arpFrame(2, other, vip, vip, net.HardwareAddr{0xff, 0xff, 0xff, 0xff, 0xff, 0xff})
	if len(f) != 42 || f[12] != 0x08 || f[13] != 0x06 || f[21] != 2 {
		t.Fatalf("frame % x", f)
	}
	if got := arpAnswerFor(f, vip.To4(), own); got != other.String() {
		t.Errorf("another device's reply: %q", got)
	}
	// This node's own announcement, a reply for another address, a probe
	// (sender 0.0.0.0): nobody else holds the address.
	if got := arpAnswerFor(arpFrame(2, own, vip, vip, other), vip.To4(), own); got != "" {
		t.Errorf("own frame counted: %q", got)
	}
	if got := arpAnswerFor(arpFrame(2, other, net.ParseIP("192.168.222.11"), vip, own), vip.To4(), own); got != "" {
		t.Errorf("another address counted: %q", got)
	}
	if got := arpAnswerFor(arpFrame(1, other, net.IPv4zero, vip, make(net.HardwareAddr, 6)), vip.To4(), own); got != "" {
		t.Errorf("a probe counted: %q", got)
	}
}

func TestStandbyRefusesNewSites(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.PanelDomain = "ldc2.elektrotrend.local"
	if err := StandbyRefuses(env, "site.create", true); err != nil {
		t.Fatalf("outside a mirror: %v", err)
	}
	if err := saveMirror(env, &mirrorState{Role: roleStandby, NodeID: "n2"}); err != nil {
		t.Fatal(err)
	}
	reg := Registry()
	for _, op := range []string{"site.create", "site.delete", "site.enable", "db.create", "db.drop", "db.password", "db.restore",
		"mail.mailbox.set", "mail.mailbox.delete", "dns.record_set", "dns.zone_delete", "client.delete", "deploy.run", "server.backup", "cron.remove"} {
		o, ok := reg[op]
		if !ok {
			t.Fatalf("no op %s", op)
		}
		if err := StandbyRefuses(env, op, o.Mutating); err == nil || !strings.Contains(err.Error(), "managed on the serving server") {
			t.Errorf("%s on a standby: %v", op, err)
		}
	}
	// The node itself stays manageable, and reading works.
	for _, op := range []string{"sys.upgrade", "mirror.settings", "mail.certs", "mail.install", "dns.install", "db.list", "site.health", "game.create"} {
		o, ok := reg[op]
		if !ok {
			t.Fatalf("no op %s", op)
		}
		if err := StandbyRefuses(env, op, o.Mutating); err != nil {
			t.Errorf("%s on a standby: %v", op, err)
		}
	}
	if err := saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n2"}); err != nil {
		t.Fatal(err)
	}
	if err := StandbyRefuses(env, "site.create", true); err != nil {
		t.Errorf("on the primary: %v", err)
	}
	if got := panelURL(env); got != "https://ldc2.elektrotrend.local" {
		t.Errorf("panel url %q", got)
	}
	env.Cfg.PanelPort = 8443
	if got := panelURL(env); got != "https://ldc2.elektrotrend.local:8443" {
		t.Errorf("panel url %q", got)
	}
}

func TestWinDNSFollowsTheFloatingIP(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "net.elektrotrend.cz", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	st := &mirrorState{LANAddress: "192.168.222.6", WinDNS: &winDNSConfig{Zones: []string{"net.elektrotrend.cz"}, TTL: 60},
		VIP: &mirrorVIP{Address: "192.168.222.10", Prefix: 24, Interface: "eth0"}}
	recs := winDesired(env, st)
	if len(recs) != 1 || recs[0].Name != "@" || recs[0].Values[0] != "192.168.222.10" {
		t.Fatalf("records %+v", recs)
	}
}

// vipFakes: ARP answers and announcements without a raw socket.
func vipFakes(t *testing.T, answer string) *int {
	t.Helper()
	announced := 0
	probe, announce := arpProbe, arpAnnounce
	arpProbe = func(string, net.IP) (string, error) { return answer, nil }
	arpAnnounce = func(string, net.IP) error { announced++; return nil }
	t.Cleanup(func() { arpProbe, arpAnnounce = probe, announce })
	return &announced
}

func ipCalls(calls []string) []string {
	var out []string
	for _, c := range calls {
		if strings.HasPrefix(c, "ip ") {
			out = append(out, c)
		}
	}
	return out
}

func TestFloatingIPOnlyOnTheServingNode(t *testing.T) {
	vip := &mirrorVIP{Address: "192.168.222.10", Prefix: 24, Interface: "wsptest0"}
	mirrorRuntime.mu.Lock()
	mirrorRuntime.PeerUp, mirrorRuntime.Peer = false, nil
	mirrorRuntime.mu.Unlock()
	promotedEpoch.Store(0)

	// A standby never adds it.
	env, dry := testEnv(t)
	vipFakes(t, "")
	_ = saveMirror(env, &mirrorState{Role: roleStandby, NodeID: "n2", VIP: vip, Peer: &mirrorPeer{NodeID: "n1", Address: "192.168.222.2"}})
	reconcileVIP(context.Background(), env)
	if got := ipCalls(callStrings(dry)); len(got) != 0 {
		t.Errorf("standby: %v", got)
	}

	// An unconfirmed primary (back after an outage) waits while another
	// machine answers ARP for the address.
	env, dry = testEnv(t)
	vipFakes(t, "00:15:5d:0a:0b:0c")
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1", Epoch: 3, VIP: vip, Peer: &mirrorPeer{NodeID: "n2", Address: "192.168.222.6"}})
	reconcileVIP(context.Background(), env)
	if got := ipCalls(callStrings(dry)); len(got) != 0 {
		t.Errorf("unconfirmed primary took a held address: %v", got)
	}

	// Nobody answers: it takes it.
	env, dry = testEnv(t)
	vipFakes(t, "")
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1", Epoch: 3, VIP: vip, Peer: &mirrorPeer{NodeID: "n2", Address: "192.168.222.6"}})
	reconcileVIP(context.Background(), env)
	if got := ipCalls(callStrings(dry)); len(got) != 1 || got[0] != "ip -4 addr add 192.168.222.10/24 dev wsptest0" {
		t.Errorf("free address: %v", got)
	}

	// Just promoted (this epoch): it takes it without asking.
	env, dry = testEnv(t)
	vipFakes(t, "00:15:5d:0a:0b:0c")
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n2", Epoch: 4, VIP: vip, Peer: &mirrorPeer{NodeID: "n1", Address: "192.168.222.2"}})
	promotedEpoch.Store(4)
	t.Cleanup(func() { promotedEpoch.Store(0) })
	reconcileVIP(context.Background(), env)
	if got := ipCalls(callStrings(dry)); len(got) != 1 {
		t.Errorf("promoted node: %v", got)
	}
}

func TestFloatingIPSettings(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, mirrorVIPSet, map[string]any{"address": "192.168.222.10"}); err == nil || !strings.Contains(err.Error(), "not in a mirror") {
		t.Errorf("outside a mirror: %v", err)
	}
	_ = saveMirror(env, &mirrorState{Role: rolePrimary, NodeID: "n1", Address: "192.168.222.2", LANAddress: "192.168.222.2", Peer: &mirrorPeer{Address: "192.168.222.6"}})
	for addr, want := range map[string]string{
		"192.168.222.2":  "this server's own address",
		"192.168.222.6":  "the other server's address",
		"2001:db8::1":    "an IPv4 address",
		"203.0.113.250":  "in none of this server's networks",
		"not-an-address": "an IPv4 address",
	} {
		if _, err := call(t, env, mirrorVIPSet, map[string]any{"address": addr}); err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%s: %v, want %q", addr, err, want)
		}
	}
}

func callStrings(d *executor.DryRun) []string {
	var out []string
	for _, c := range d.Calls() {
		out = append(out, c.String())
	}
	return out
}
