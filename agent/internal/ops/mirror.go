package ops

import (
	"context"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"net"
	"os"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"

	"wspanel/agent/internal/protocol"
)

// The mirror (Server → Zrcadlo): two WSPanel servers, a primary that serves
// the sites and a standby that keeps a copy of everything and takes over when
// the primary fails. Active/passive: only the primary takes writes.
//
// Every copy flows from the standby's side: it dials the primary over
// mutual TLS on one port (open to the standby's address only) and pulls
//
//   - the server's state and configuration (a server backup's archive,
//     made fresh each cycle), applied when it is promoted,
//   - the sites' files and the mail (rsync through the TLS link),
//   - the shared MariaDB (GTID replication) and PostgreSQL (streaming
//     replication with a slot) through tunnels in the same link,
//   - MongoDB and the dedicated database servers (dumps every few minutes;
//     two nodes have no majority for a replica set),
//   - each site's Valkey/Redis (a replica on the standby).
//
// The standby's agent decides about a failover, not a panel: the primary's
// panel may be what failed. It promotes on its own only with automatic
// failover on, when the primary does not answer at all (the mirror link,
// HTTPS and HTTP) and a witness (the LAN's gateway, a domain controller)
// does, so a standby cut off from the network never promotes. The primary
// fences itself (stops serving) when it sees neither the standby nor the
// witness; an epoch counter settles who is primary once both are back.

// Roles.
const (
	roleNone    = ""
	rolePrimary = "primary"
	roleStandby = "standby"
	roleFenced  = "fenced" // was primary, the peer took over (or cut off): serves nothing
)

const (
	mirrorDefaultPort     = 7443
	mirrorDefaultFailover = 60 // seconds without the primary before an automatic failover
	mirrorDefaultSync     = 60 // seconds between sync cycles
	mirrorMinFailover     = 20
	mirrorMaxFailover     = 3600
)

type mirrorPeer struct {
	NodeID     string `json:"node_id"`
	Name       string `json:"name"`
	Address    string `json:"address"` // host or IP the other node dials
	Port       int    `json:"port"`
	PublicIPv4 string `json:"public_ipv4,omitempty"`
}

func (p *mirrorPeer) dial() string {
	return net.JoinHostPort(p.Address, strconv.Itoa(p.Port))
}

type mirrorPairing struct {
	TokenHash string    `json:"token_hash"`
	Expires   time.Time `json:"expires"`
	PeerAddr  string    `json:"peer_address"` // the standby's address (allowed through the firewall)
}

// mirrorRepl: the replication accounts' passwords, the same on both nodes
// (either may be the source after a failover).
type mirrorRepl struct {
	MariaDB  string `json:"mariadb,omitempty"`
	Postgres string `json:"postgres,omitempty"`
}

type mirrorState struct {
	Role          string         `json:"role"`
	NodeID        string         `json:"node_id"`
	Name          string         `json:"name"`
	Epoch         int64          `json:"epoch"`
	Port          int            `json:"port"`
	Address       string         `json:"address"`               // how the peer reaches this node
	LANAddress    string         `json:"lan_address,omitempty"` // this node's IP for local DNS (sites point here when active)
	PublicIPv4    string         `json:"public_ipv4,omitempty"`
	Peer          *mirrorPeer    `json:"peer,omitempty"`
	Pairing       *mirrorPairing `json:"pairing,omitempty"`
	Witness       []string       `json:"witness,omitempty"`
	Auto          bool           `json:"auto_failover"`
	FailoverAfter int            `json:"failover_after"`
	SyncEvery     int            `json:"sync_every"`
	Repl          mirrorRepl     `json:"repl"`
	Fenced        string         `json:"fenced,omitempty"` // why this node stopped serving
	Since         time.Time      `json:"since"`            // of the current role
	PromotedAt    time.Time      `json:"promoted_at,omitzero"`
	WinDNS        *winDNSConfig  `json:"windns,omitempty"`
	VIP           *mirrorVIP     `json:"vip,omitempty"` // the floating IP the serving node holds
}

var mirrorMu sync.Mutex // the state file

func loadMirror(env *Env) (*mirrorState, error) {
	b, err := os.ReadFile(mirrorFile(env, "mirror.json"))
	if errors.Is(err, os.ErrNotExist) {
		return &mirrorState{Port: mirrorDefaultPort, FailoverAfter: mirrorDefaultFailover, SyncEvery: mirrorDefaultSync}, nil
	}
	if err != nil {
		return nil, err
	}
	var st mirrorState
	if err := json.Unmarshal(b, &st); err != nil {
		return nil, fmt.Errorf("mirror.json: %w", err)
	}
	if st.Port == 0 {
		st.Port = mirrorDefaultPort
	}
	if st.FailoverAfter == 0 {
		st.FailoverAfter = mirrorDefaultFailover
	}
	if st.SyncEvery == 0 {
		st.SyncEvery = mirrorDefaultSync
	}
	return &st, nil
}

func saveMirror(env *Env, st *mirrorState) error {
	b, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return err
	}
	return writeMirrorFile(env, "mirror.json", append(b, '\n'))
}

// updateMirror loads, changes and saves the state under the lock.
func updateMirror(env *Env, fn func(*mirrorState) error) (*mirrorState, error) {
	mirrorMu.Lock()
	defer mirrorMu.Unlock()
	st, err := loadMirror(env)
	if err != nil {
		return nil, err
	}
	if err := fn(st); err != nil {
		return nil, err
	}
	return st, saveMirror(env, st)
}

func currentMirror(env *Env) *mirrorState {
	mirrorMu.Lock()
	defer mirrorMu.Unlock()
	st, err := loadMirror(env)
	if err != nil {
		return &mirrorState{}
	}
	return st
}

// mirrorAddr: an address the other node dials (IP or host name).
func mirrorAddr(field, s string) (string, error) {
	s = strings.TrimSpace(s)
	if ip := net.ParseIP(s); ip != nil {
		return ip.String(), nil
	}
	if mirrorHostname(s) {
		return strings.ToLower(s), nil
	}
	return "", protocol.Invalid(field + ": an IP address or host name")
}

func mirrorHostname(s string) bool {
	if len(s) == 0 || len(s) > 253 {
		return false
	}
	for _, label := range strings.Split(s, ".") {
		if len(label) == 0 || len(label) > 63 || label[0] == '-' || label[len(label)-1] == '-' {
			return false
		}
		for _, r := range label {
			if !(r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r >= '0' && r <= '9' || r == '-') {
				return false
			}
		}
	}
	return true
}

func validWitness(w string) bool {
	host, port, err := net.SplitHostPort(w)
	if err != nil {
		return net.ParseIP(w) != nil // ICMP
	}
	n, err := strconv.Atoi(port)
	return err == nil && n > 0 && n < 65536 && (net.ParseIP(host) != nil || mirrorHostname(host))
}

func addrIPs(addrs ...string) []net.IP {
	var ips []net.IP
	for _, a := range addrs {
		if ip := net.ParseIP(a); ip != nil {
			ips = append(ips, ip)
		}
	}
	return ips
}

// ---------------------------------------------------------------- panel ops

// mirrorInit makes this server the primary of a new mirror and returns the
// code the standby pairs with.
func mirrorInit(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Address     string `json:"address"`      // this server, as the standby dials it
		PeerAddress string `json:"peer_address"` // the standby (its connections come from there)
		LANAddress  string `json:"lan_address"`  // this server for local DNS (default: address)
		PublicIPv4  string `json:"public_ipv4"`
		Port        int    `json:"port"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	addr, err := mirrorAddr("address", p.Address)
	if err != nil {
		return nil, err
	}
	peerAddr, err := mirrorAddr("peer_address", p.PeerAddress)
	if err != nil {
		return nil, err
	}
	lan := addr
	if p.LANAddress != "" {
		if net.ParseIP(p.LANAddress) == nil {
			return nil, protocol.Invalid("lan_address: an IP address")
		}
		lan = p.LANAddress
	}
	if p.PublicIPv4 != "" && net.ParseIP(p.PublicIPv4).To4() == nil {
		return nil, protocol.Invalid("public_ipv4: an IPv4 address")
	}
	if p.Port == 0 {
		p.Port = mirrorDefaultPort
	}
	if p.Port < 1024 || p.Port > 65535 || slices.Contains(env.Cfg.ProtectedPorts, p.Port) {
		return nil, protocol.Invalid("port: 1024-65535, not a port the server uses")
	}
	var code string
	st, err := updateMirror(env, func(st *mirrorState) error {
		if st.Role == roleStandby || st.Role == roleFenced || (st.Role == rolePrimary && st.Peer != nil) {
			return protocol.Conflict("this server is already in a mirror (" + st.Role + "): remove the mirror first")
		}
		if st.NodeID == "" {
			st.NodeID = newNodeID()
		}
		st.Name, _ = os.Hostname()
		st.Address, st.LANAddress, st.PublicIPv4, st.Port = addr, lan, p.PublicIPv4, p.Port
		if err := makeMirrorCA(env, st.NodeID, addrIPs(addr, lan)); err != nil {
			return err
		}
		c, hash, err := newPairingCode(env)
		if err != nil {
			return err
		}
		code = c
		st.Pairing = &mirrorPairing{TokenHash: hash, Expires: time.Now().Add(mirrorPairingTTL), PeerAddr: peerAddr}
		if st.Repl.MariaDB == "" {
			st.Repl.MariaDB = randomHex(24)
		}
		if st.Repl.Postgres == "" {
			st.Repl.Postgres = randomHex(24)
		}
		if st.Role == roleNone {
			st.Role, st.Since, st.Epoch = rolePrimary, time.Now().UTC(), 1
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	if err := mirrorFirewall(ctx, env, peerAddr, st.Port, true); err != nil {
		return nil, err
	}
	mirrorWake()
	return map[string]any{"role": st.Role, "node_id": st.NodeID, "code": code, "expires": st.Pairing.Expires, "port": st.Port}, nil
}

// mirrorPairingCode: a fresh code (the old one stops working).
func mirrorPairingCode(_ context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	var code string
	st, err := updateMirror(env, func(st *mirrorState) error {
		if st.Role != rolePrimary || st.Peer != nil || st.Pairing == nil {
			return protocol.Conflict("a pairing code exists only on a primary waiting for its standby")
		}
		c, hash, err := newPairingCode(env)
		if err != nil {
			return err
		}
		code = c
		st.Pairing.TokenHash, st.Pairing.Expires = hash, time.Now().Add(mirrorPairingTTL)
		return nil
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{"code": code, "expires": st.Pairing.Expires}, nil
}

// mirrorJoin pairs this (fresh) server as the standby of a primary.
func mirrorJoin(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Primary    string `json:"primary"`     // address of the primary
		Port       int    `json:"port"`        // its mirror port
		Code       string `json:"code"`        // from the primary's Zrcadlo page
		Address    string `json:"address"`     // this server, as the primary dials it (Docker: the Windows host's IP)
		LANAddress string `json:"lan_address"` // this server for local DNS once it is primary
		PublicIPv4 string `json:"public_ipv4"`
		LocalPort  int    `json:"local_port"` // this server's mirror port (published by Docker)
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	primary, err := mirrorAddr("primary", p.Primary)
	if err != nil {
		return nil, err
	}
	addr, err := mirrorAddr("address", p.Address)
	if err != nil {
		return nil, err
	}
	lan := addr
	if p.LANAddress != "" {
		if net.ParseIP(p.LANAddress) == nil {
			return nil, protocol.Invalid("lan_address: an IP address")
		}
		lan = p.LANAddress
	}
	if p.PublicIPv4 != "" && net.ParseIP(p.PublicIPv4).To4() == nil {
		return nil, protocol.Invalid("public_ipv4: an IPv4 address")
	}
	if p.Port == 0 {
		p.Port = mirrorDefaultPort
	}
	if p.LocalPort == 0 {
		p.LocalPort = mirrorDefaultPort
	}
	fp, secret, err := parsePairingCode(p.Code)
	if err != nil {
		return nil, protocol.Invalid("code: " + err.Error())
	}
	cur := currentMirror(env)
	if cur.Role != roleNone {
		return nil, protocol.Conflict("this server is already in a mirror (" + cur.Role + ")")
	}
	if len(allSiteStates(env.Cfg)) > 0 {
		return nil, protocol.Conflict("this server has sites: a standby starts empty (it becomes a copy of the primary)")
	}
	nodeID := cur.NodeID
	if nodeID == "" {
		nodeID = newNodeID()
	}
	csr, err := makeCSR(env, nodeID, addrIPs(addr, lan))
	if err != nil {
		return nil, err
	}
	name, _ := os.Hostname()
	conn, err := (&tls.Dialer{NetDialer: &net.Dialer{Timeout: 10 * time.Second}, Config: pinnedTLS(fp)}).DialContext(ctx, "tcp", net.JoinHostPort(primary, strconv.Itoa(p.Port)))
	if err != nil {
		return nil, protocol.Conflict("the primary does not answer on " + net.JoinHostPort(primary, strconv.Itoa(p.Port)) + ": " + err.Error())
	}
	defer conn.Close()
	var ans struct {
		CA          string     `json:"ca"`
		Cert        string     `json:"cert"`
		Primary     mirrorPeer `json:"primary"`
		Epoch       int64      `json:"epoch"`
		RecoveryKey string     `json:"recovery_key"`
		Repl        mirrorRepl `json:"repl"`
		Version     string     `json:"version"`
	}
	err = peerRoundTrip(conn, "join", map[string]any{
		"secret": hex.EncodeToString(secret), "csr": string(csr),
		"peer": mirrorPeer{NodeID: nodeID, Name: name, Address: addr, Port: p.LocalPort, PublicIPv4: p.PublicIPv4},
	}, &ans)
	if err != nil {
		return nil, err
	}
	if blk, _ := pem.Decode([]byte(ans.Cert)); blk == nil {
		return nil, errors.New("the primary sent no certificate")
	}
	key, err := hex.DecodeString(ans.RecoveryKey)
	if err != nil || len(key) != 32 {
		return nil, errors.New("the primary sent no recovery key")
	}
	for f, data := range map[string][]byte{"ca.crt": []byte(ans.CA), "node.crt": []byte(ans.Cert), "peer-recovery.key": []byte(ans.RecoveryKey + "\n")} {
		if err := writeMirrorFile(env, f, data); err != nil {
			return nil, err
		}
	}
	ans.Primary.Address = primary // as this node reached it
	ans.Primary.Port = p.Port
	st, err := updateMirror(env, func(st *mirrorState) error {
		st.Role, st.NodeID, st.Name, st.Epoch, st.Since = roleStandby, nodeID, name, ans.Epoch, time.Now().UTC()
		st.Address, st.LANAddress, st.PublicIPv4, st.Port = addr, lan, p.PublicIPv4, p.LocalPort
		st.Peer, st.Repl = &ans.Primary, ans.Repl
		return nil
	})
	if err != nil {
		return nil, err
	}
	if err := mirrorFirewall(ctx, env, primary, st.Port, true); err != nil {
		return nil, err
	}
	mirrorWake()
	out := map[string]any{"role": st.Role, "primary": st.Peer, "epoch": st.Epoch}
	if ans.Version != Version {
		out["warning"] = fmt.Sprintf("the primary runs WSPanel %s, this server %s: upgrade the older one", ans.Version, Version)
	}
	return out, nil
}

// mirrorSettings: what can change on a running mirror.
func mirrorSettings(_ context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Witness       *[]string `json:"witness"`
		Auto          *bool     `json:"auto_failover"`
		FailoverAfter *int      `json:"failover_after"`
		SyncEvery     *int      `json:"sync_every"`
		LANAddress    *string   `json:"lan_address"`
		PublicIPv4    *string   `json:"public_ipv4"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, err := updateMirror(env, func(st *mirrorState) error {
		if st.Role == roleNone {
			return protocol.Conflict("this server is not in a mirror")
		}
		if p.Witness != nil {
			if len(*p.Witness) > 5 {
				return protocol.Invalid("witness: at most 5")
			}
			for _, w := range *p.Witness {
				if !validWitness(w) {
					return protocol.Invalid("witness: " + w + " is not an IP address or host:port")
				}
			}
			st.Witness = *p.Witness
		}
		if p.FailoverAfter != nil {
			if *p.FailoverAfter < mirrorMinFailover || *p.FailoverAfter > mirrorMaxFailover {
				return protocol.Invalid(fmt.Sprintf("failover_after: %d-%d seconds", mirrorMinFailover, mirrorMaxFailover))
			}
			st.FailoverAfter = *p.FailoverAfter
		}
		if p.SyncEvery != nil {
			if *p.SyncEvery < 15 || *p.SyncEvery > 3600 {
				return protocol.Invalid("sync_every: 15-3600 seconds")
			}
			st.SyncEvery = *p.SyncEvery
		}
		if p.LANAddress != nil {
			if net.ParseIP(*p.LANAddress) == nil {
				return protocol.Invalid("lan_address: an IP address")
			}
			st.LANAddress = *p.LANAddress
		}
		if p.PublicIPv4 != nil {
			if *p.PublicIPv4 != "" && net.ParseIP(*p.PublicIPv4).To4() == nil {
				return protocol.Invalid("public_ipv4: an IPv4 address")
			}
			st.PublicIPv4 = *p.PublicIPv4
		}
		if p.Auto != nil {
			st.Auto = *p.Auto
		}
		// Without a witness a standby cannot tell "the primary is down"
		// from "I am cut off": no automatic failover then. Checked on the
		// result, whichever fields this request changed.
		if st.Auto && len(st.Witness) == 0 {
			return protocol.Conflict("automatic failover needs a witness (the LAN's gateway or another server both nodes see): turn it off before removing the last witness")
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	mirrorWake()
	return mirrorPublic(st), nil
}

// mirrorPublic: the state without secrets.
func mirrorPublic(st *mirrorState) map[string]any {
	out := map[string]any{
		"role": st.Role, "node_id": st.NodeID, "name": st.Name, "epoch": st.Epoch, "port": st.Port,
		"address": st.Address, "lan_address": st.LANAddress, "public_ipv4": st.PublicIPv4,
		"peer": st.Peer, "witness": st.Witness, "auto_failover": st.Auto, "failover_after": st.FailoverAfter,
		"sync_every": st.SyncEvery, "fenced": st.Fenced, "since": st.Since, "promoted_at": st.PromotedAt,
	}
	if st.Pairing != nil {
		out["pairing"] = map[string]any{"expires": st.Pairing.Expires, "peer_address": st.Pairing.PeerAddr}
	}
	if st.WinDNS != nil {
		out["windns"] = st.WinDNS.public()
	}
	if st.VIP != nil {
		out["vip"] = map[string]any{"address": st.VIP.Address, "prefix": st.VIP.Prefix, "interface": st.VIP.Interface, "held": vipHeld(st.VIP)}
	}
	return out
}

func mirrorStatusOp(_ context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	out := mirrorPublic(st)
	out["status"] = mirrorRuntime.snapshot()
	out["version"] = Version
	out["panel_url"] = panelURL(env)
	// For the panel's public DNS (Cloudflare): the same guard as Windows
	// DNS, and only the names that follow the serving node.
	out["may_point_dns"] = st.Role == rolePrimary && mayPointDNS(st)
	var extra []string
	if st.WinDNS != nil {
		extra = st.WinDNS.Extra
	}
	out["dns_hosts"] = servedHosts(env, extra)
	return out, nil
}

// mirrorUnpair takes this server out of the mirror. On a standby the copies
// stay as they are (nothing is deleted) and replication stops.
func mirrorUnpair(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Confirm string `json:"confirm"` // the node id
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st := currentMirror(env)
	if st.Role == roleNone {
		return map[string]any{"changed": false}, nil
	}
	if p.Confirm != st.NodeID {
		return nil, protocol.Invalid("confirm: this node's id (" + st.NodeID + ")")
	}
	if st.Role == roleStandby {
		stopReplication(ctx, env)
	}
	// Outside a mirror nobody would hand the floating IP on: it goes. The
	// loop must not take it again before the state is gone.
	vipMu.Lock()
	defer vipMu.Unlock()
	if err := releaseVIP(ctx, env, st.VIP); err != nil {
		return nil, err
	}
	if st.Role == roleFenced {
		_ = unfenceLocal(ctx, env)
	}
	if st.Peer != nil {
		_ = mirrorFirewall(ctx, env, st.Peer.Address, st.Port, false)
	}
	if st.Pairing != nil {
		_ = mirrorFirewall(ctx, env, st.Pairing.PeerAddr, st.Port, false)
	}
	for _, f := range []string{"mirror.json", "ca.crt", "ca.key", "node.crt", "node.key", "peer-recovery.key", "snapshot.wspbak", "status.json"} {
		_ = os.Remove(mirrorFile(env, f))
	}
	mirrorWake()
	return map[string]any{"changed": true, "role": roleNone}, nil
}

// mirrorFirewall opens (or closes) the mirror's port for the peer only.
func mirrorFirewall(ctx context.Context, env *Env, peer string, port int, open bool) error {
	ips := []string{peer}
	if net.ParseIP(peer) == nil {
		// A host name: UFW wants addresses (the TLS certificate still
		// guards the port).
		lctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		addrs, err := net.DefaultResolver.LookupHost(lctx, peer)
		cancel()
		if err != nil || len(addrs) == 0 {
			if !open {
				return nil
			}
			return fmt.Errorf("%s does not resolve, so the mirror's port cannot be opened for it: use its IP address", peer)
		}
		ips = addrs
	}
	for _, ip := range ips {
		args := []string{"allow", "from", ip, "to", "any", "port", strconv.Itoa(port), "proto", "tcp", "comment", "wspanel mirror"}
		if !open {
			args = append([]string{"delete"}, args...)
		}
		if _, err := env.Exec.Run(ctx, "ufw", args...); err != nil && open {
			return err
		}
	}
	return nil
}

// mirrorSyncNow: the standby syncs at once (the panel's button).
func mirrorSyncNow(_ context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	if currentMirror(env).Role != roleStandby {
		return nil, protocol.Conflict("only a standby syncs")
	}
	mirrorNudged()
	return map[string]any{"started": true}, nil
}
