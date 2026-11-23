package ops

import (
	"context"
	"encoding/binary"
	"fmt"
	"net"
	"os"
	"slices"
	"sync"
	"time"
)

// The mirror's own loop in the agent: the listener, the health of the
// other node, the standby's sync cycles, the decision to fail over, the
// primary's fencing and its DNS records. Everything a failover needs runs
// here, so neither node's panel has to be alive.

const mirrorTick = 5 * time.Second

type layerStatus struct {
	OK     bool      `json:"ok"`
	At     time.Time `json:"at"`
	Error  string    `json:"error,omitempty"`
	Detail string    `json:"detail,omitempty"`
	Lag    *float64  `json:"lag_seconds,omitempty"`
}

type mirrorEvent struct {
	At      time.Time `json:"at"`
	Level   string    `json:"level"` // info | warning | danger
	Message string    `json:"message"`
}

type mirrorRuntimeState struct {
	mu               sync.Mutex
	PeerUp           bool                    `json:"peer_up"`
	PeerSeen         time.Time               `json:"peer_seen,omitzero"`
	PeerError        string                  `json:"peer_error,omitempty"`
	Peer             *helloInfo              `json:"peer,omitempty"`
	Probes           map[string]bool         `json:"probes,omitempty"`
	PrimaryDownSince time.Time               `json:"primary_down_since,omitzero"`
	IsolatedSince    time.Time               `json:"isolated_since,omitzero"`
	Witness          map[string]bool         `json:"witness,omitempty"`
	WitnessOK        bool                    `json:"witness_ok"`
	Layers           map[string]*layerStatus `json:"layers"`
	Action           string                  `json:"action,omitempty"` // a running promotion, rejoin, switchover
	Syncing          bool                    `json:"syncing"`
	LastSync         time.Time               `json:"last_sync,omitzero"`
	Events           []mirrorEvent           `json:"events"`
}

var mirrorRuntime = &mirrorRuntimeState{Layers: map[string]*layerStatus{}}

func (m *mirrorRuntimeState) snapshot() map[string]any {
	m.mu.Lock()
	defer m.mu.Unlock()
	layers := map[string]layerStatus{}
	for k, v := range m.Layers {
		layers[k] = *v
	}
	return map[string]any{
		"peer_up": m.PeerUp, "peer_seen": m.PeerSeen, "peer_error": m.PeerError, "peer": m.Peer, "probes": m.Probes,
		"primary_down_since": m.PrimaryDownSince, "isolated_since": m.IsolatedSince, "witness": m.Witness,
		"witness_ok": m.WitnessOK, "layers": layers, "action": m.Action, "syncing": m.Syncing, "last_sync": m.LastSync,
		"events": slices.Clone(m.Events),
	}
}

func (m *mirrorRuntimeState) layer(name string, err error, detail string, lag *float64) {
	m.mu.Lock()
	defer m.mu.Unlock()
	l := &layerStatus{OK: err == nil, At: time.Now().UTC(), Detail: detail, Lag: lag}
	if err != nil {
		l.Error = err.Error()
	}
	m.Layers[name] = l
}

func (m *mirrorRuntimeState) dropLayers(keep func(string) bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	for k := range m.Layers {
		if !keep(k) {
			delete(m.Layers, k)
		}
	}
}

func (m *mirrorRuntimeState) event(level, format string, args ...any) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.Events = append(m.Events, mirrorEvent{At: time.Now().UTC(), Level: level, Message: fmt.Sprintf(format, args...)})
	if n := len(m.Events); n > 100 {
		m.Events = m.Events[n-100:]
	}
}

func (m *mirrorRuntimeState) setAction(a string) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	if a != "" && m.Action != "" {
		return false
	}
	m.Action = a
	return true
}

var (
	mirrorWakeCh  = make(chan struct{}, 1)
	mirrorNudgeCh = make(chan struct{}, 1)
)

// mirrorWake: the state changed (a pairing, settings): look again now.
func mirrorWake() {
	select {
	case mirrorWakeCh <- struct{}{}:
	default:
	}
}

// mirrorNudged: the primary changed something: sync soon.
func mirrorNudged() {
	select {
	case mirrorNudgeCh <- struct{}{}:
	default:
	}
}

// MirrorNudgePeer tells the standby that something changed here (after a
// mutating operation succeeded on the primary). Best effort, in the background.
func MirrorNudgePeer(env *Env, op string) {
	if env.Cfg.DryRun || op == "job.status" {
		return
	}
	st := currentMirror(env)
	if st.Role != rolePrimary || st.Peer == nil {
		return
	}
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = peerCall(ctx, env, "nudge", map[string]any{"op": op}, nil)
	}()
}

// MirrorRun is the loop; started by the agent.
func MirrorRun(ctx context.Context, env *Env, logf func(string, ...any)) {
	if env.Cfg.DryRun {
		return
	}
	tick := time.NewTicker(mirrorTick)
	defer tick.Stop()
	var nextSync time.Time
	var nextDNS time.Time
	nudged := false
	for {
		peerListener.ensure(ctx, env, logf)
		st := currentMirror(env)
		switch st.Role {
		case roleStandby:
			if standbyTick(ctx, env, st, logf) && (nudged || time.Now().After(nextSync)) {
				nudged = false
				nextSync = time.Now().Add(time.Duration(st.SyncEvery) * time.Second)
				startSyncCycle(ctx, env, logf)
			}
		case rolePrimary:
			primaryTick(ctx, env, st, logf)
			if st.WinDNS != nil && st.WinDNS.Host != "" && time.Now().After(nextDNS) {
				nextDNS = time.Now().Add(time.Minute)
				go reconcileWinDNS(ctx, env, logf)
			}
		case roleFenced:
			fencedTick(ctx, env, st, logf)
		}
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		case <-mirrorWakeCh:
		case <-mirrorNudgeCh:
			// A burst of changes (a deploy) becomes one cycle a little later.
			nudged = true
			if d := time.Until(nextSync); d > 10*time.Second {
				nextSync = time.Now().Add(10 * time.Second)
			}
		}
	}
}

// helloPeer: one hello both ways (this node's state goes along).
func helloPeer(ctx context.Context, env *Env) (*helloInfo, error) {
	ctx, cancel := context.WithTimeout(ctx, 4*time.Second)
	defer cancel()
	var them helloInfo
	if err := peerCall(ctx, env, "hello", localHello(env), &them); err != nil {
		return nil, err
	}
	settleEpoch(env, them)
	return &them, nil
}

func recordPeer(them *helloInfo, err error) {
	m := mirrorRuntime
	m.mu.Lock()
	defer m.mu.Unlock()
	if err != nil {
		if m.PeerUp {
			m.Events = append(m.Events, mirrorEvent{At: time.Now().UTC(), Level: "warning", Message: "druhý server přestal odpovídat: " + err.Error()})
		}
		m.PeerUp, m.PeerError = false, err.Error()
		return
	}
	if !m.PeerUp && !m.PeerSeen.IsZero() {
		m.Events = append(m.Events, mirrorEvent{At: time.Now().UTC(), Level: "info", Message: "druhý server znovu odpovídá"})
	}
	m.PeerUp, m.PeerError, m.PeerSeen, m.Peer = true, "", time.Now().UTC(), them
}

// standbyTick: watches the primary; true when it is up (sync may run).
func standbyTick(ctx context.Context, env *Env, st *mirrorState, logf func(string, ...any)) bool {
	them, err := helloPeer(ctx, env)
	recordPeer(them, err)
	if err == nil {
		mirrorRuntime.mu.Lock()
		mirrorRuntime.PrimaryDownSince, mirrorRuntime.Probes = time.Time{}, nil
		mirrorRuntime.mu.Unlock()
		return them.Role == rolePrimary
	}
	// The link failed: is the primary down, or only its agent?
	probes := probePrimary(ctx, st)
	witness, wOK := probeWitness(ctx, st.Witness)
	m := mirrorRuntime
	m.mu.Lock()
	m.Probes, m.Witness, m.WitnessOK = probes, witness, wOK
	anyUp := false
	for _, up := range probes {
		anyUp = anyUp || up
	}
	if anyUp {
		// It serves sites, only the mirror link fails: no failover.
		m.PrimaryDownSince = time.Time{}
		m.mu.Unlock()
		return false
	}
	if m.PrimaryDownSince.IsZero() {
		m.PrimaryDownSince = time.Now().UTC()
		m.Events = append(m.Events, mirrorEvent{At: time.Now().UTC(), Level: "danger", Message: "primár neodpovídá (spojení zrcadla, HTTPS, HTTP)"})
	}
	down := time.Since(m.PrimaryDownSince)
	m.mu.Unlock()
	if st.Auto && down >= time.Duration(st.FailoverAfter)*time.Second {
		if !wOK {
			mirrorRuntime.event("warning", "primár neodpovídá, ale ani žádný svědek: tento server je možná odříznutý, automatické přepnutí neproběhne")
			return false
		}
		go func() {
			logf("mirror: the primary has been down for %s, promoting this node", down.Round(time.Second))
			if _, err := promoteLocal(context.WithoutCancel(ctx), env, "automatické přepnutí: primár neodpovídal "+down.Round(time.Second).String(), true); err != nil {
				logf("mirror: promotion failed: %v", err)
			}
		}()
	}
	return false
}

// primaryTick: sees the standby; fences itself when cut off from it and
// from the witness (automatic failover only: then the standby may promote).
func primaryTick(ctx context.Context, env *Env, st *mirrorState, logf func(string, ...any)) {
	if st.Peer == nil {
		return
	}
	them, err := helloPeer(ctx, env)
	recordPeer(them, err)
	m := mirrorRuntime
	if err == nil {
		m.mu.Lock()
		m.IsolatedSince = time.Time{}
		m.mu.Unlock()
		return
	}
	if !st.Auto {
		return
	}
	witness, wOK := probeWitness(ctx, st.Witness)
	m.mu.Lock()
	m.Witness, m.WitnessOK = witness, wOK
	if wOK {
		m.IsolatedSince = time.Time{}
		m.mu.Unlock()
		return
	}
	if m.IsolatedSince.IsZero() {
		m.IsolatedSince = time.Now().UTC()
	}
	alone := time.Since(m.IsolatedSince)
	m.mu.Unlock()
	// Half the standby's wait: this node stops before the other may start.
	if alone >= time.Duration(st.FailoverAfter)*time.Second/2 {
		logf("mirror: neither the standby nor a witness answers for %s: fencing this node", alone.Round(time.Second))
		if err := fenceLocal(context.WithoutCancel(ctx), env, "isolated"); err != nil {
			logf("mirror: fencing failed: %v", err)
		}
	}
}

// fencedTick: an isolated node comes back by itself when the standby did
// not take over; one the standby replaced waits to rejoin (by hand).
func fencedTick(ctx context.Context, env *Env, st *mirrorState, logf func(string, ...any)) {
	them, err := helloPeer(ctx, env)
	recordPeer(them, err)
	if err != nil || st.Fenced != "isolated" {
		return
	}
	if them.Role == roleStandby && them.Epoch <= st.Epoch {
		logf("mirror: the standby is back and did not take over: serving again")
		if err := unfenceToPrimary(context.WithoutCancel(ctx), env); err != nil {
			logf("mirror: unfencing failed: %v", err)
		}
	}
}

// settleEpoch: a primary that hears of a newer primary stops being one.
func settleEpoch(env *Env, them helloInfo) {
	if them.NodeID == "" {
		return
	}
	st := currentMirror(env)
	if st.Peer == nil || them.NodeID != st.Peer.NodeID {
		return
	}
	newer := them.Epoch > st.Epoch || (them.Epoch == st.Epoch && them.NodeID > st.NodeID)
	if them.Role == rolePrimary && st.Role == rolePrimary && newer {
		mirrorRuntime.event("danger", "druhý server je primár s novější epochou (%d): tento server se odstavuje", them.Epoch)
		go func() { _ = fenceLocal(context.Background(), env, "replaced") }()
		return
	}
	if (st.Role == roleStandby || st.Role == roleFenced) && them.Role == rolePrimary && them.Epoch > st.Epoch {
		_, _ = updateMirror(env, func(s *mirrorState) error { s.Epoch = them.Epoch; return nil })
	}
	// Addresses the other node reports for itself (its DNS address may change).
	if them.LANAddress != "" || them.PublicIPv4 != "" {
		_, _ = updateMirror(env, func(s *mirrorState) error {
			if s.Peer != nil {
				if them.PublicIPv4 != "" {
					s.Peer.PublicIPv4 = them.PublicIPv4
				}
			}
			return nil
		})
	}
}

// ---------------------------------------------------------------- probes

func tcpUp(ctx context.Context, addr string) bool {
	ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	c, err := (&net.Dialer{}).DialContext(ctx, "tcp", addr)
	if err != nil {
		return false
	}
	c.Close()
	return true
}

// probePrimary: does the primary serve anything (its sites)?
func probePrimary(ctx context.Context, st *mirrorState) map[string]bool {
	out := map[string]bool{}
	if st.Peer == nil {
		return out
	}
	for _, port := range []string{"443", "80"} {
		out[port] = tcpUp(ctx, net.JoinHostPort(st.Peer.Address, port))
	}
	return out
}

// probeWitness: a witness is an IP (ICMP echo) or host:port (TCP). The
// majority must answer.
func probeWitness(ctx context.Context, list []string) (map[string]bool, bool) {
	out := map[string]bool{}
	if len(list) == 0 {
		return out, false
	}
	var mu sync.Mutex
	var wg sync.WaitGroup
	for _, w := range list {
		wg.Add(1)
		go func() {
			defer wg.Done()
			var up bool
			if _, _, err := net.SplitHostPort(w); err == nil {
				up = tcpUp(ctx, w)
			} else {
				up = pingUp(w)
			}
			mu.Lock()
			out[w] = up
			mu.Unlock()
		}()
	}
	wg.Wait()
	n := 0
	for _, up := range out {
		if up {
			n++
		}
	}
	return out, n*2 > len(list)
}

var pingSeq uint32

// pingUp sends one ICMP echo (the agent is root) and waits two seconds.
func pingUp(host string) bool {
	ip := net.ParseIP(host)
	if ip == nil || ip.To4() == nil {
		return false
	}
	c, err := net.ListenPacket("ip4:icmp", "0.0.0.0") // the IPv4 header is stripped on read
	if err != nil {
		return false
	}
	defer c.Close()
	id := os.Getpid() & 0xffff
	seq := int(pingSeqNext() & 0xffff)
	msg := []byte{8, 0, 0, 0, byte(id >> 8), byte(id), byte(seq >> 8), byte(seq), 'w', 's', 'p'}
	binary.BigEndian.PutUint16(msg[2:], icmpChecksum(msg))
	if _, err := c.WriteTo(msg, &net.IPAddr{IP: ip}); err != nil {
		return false
	}
	_ = c.SetReadDeadline(time.Now().Add(2 * time.Second))
	buf := make([]byte, 1500)
	for {
		n, from, err := c.ReadFrom(buf)
		if err != nil {
			return false
		}
		if n >= 8 && buf[0] == 0 && from.String() == ip.String() && int(binary.BigEndian.Uint16(buf[4:])) == id && int(binary.BigEndian.Uint16(buf[6:])) == seq {
			return true
		}
	}
}

func pingSeqNext() uint32 {
	mirrorMu.Lock()
	defer mirrorMu.Unlock()
	pingSeq++
	return pingSeq
}

func icmpChecksum(b []byte) uint16 {
	var sum uint32
	for i := 0; i+1 < len(b); i += 2 {
		sum += uint32(b[i])<<8 | uint32(b[i+1])
	}
	if len(b)%2 == 1 {
		sum += uint32(b[len(b)-1]) << 8
	}
	for sum>>16 != 0 {
		sum = sum&0xffff + sum>>16
	}
	return ^uint16(sum)
}
