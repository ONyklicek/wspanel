package ops

import (
	"context"
	"fmt"
	"io"
	"net"
	"os/user"
	"slices"
	"strconv"
	"strings"
	"sync"
)

// The standby's tunnels: a listener on loopback per replication source
// (MariaDB, PostgreSQL, each site's Valkey/Redis), each connection carried to
// the primary over the mirror link. nftables lets only the replica that
// needs a tunnel use it (by the connecting process's uid): another local
// process cannot reach the primary's servers through them.

type tunnel struct {
	ln     net.Listener
	target string
	uids   []int
}

var tunnels = struct {
	sync.Mutex
	m map[int]*tunnel
}{m: map[int]*tunnel{}}

// ensureTunnel listens on 127.0.0.1:port for the target.
func ensureTunnel(ctx context.Context, env *Env, port int, target string, uids []int) error {
	tunnels.Lock()
	defer tunnels.Unlock()
	if t, ok := tunnels.m[port]; ok {
		if t.target == target && slices.Equal(t.uids, uids) {
			return nil
		}
		t.ln.Close()
		delete(tunnels.m, port)
	}
	if env.Cfg.DryRun {
		tunnels.m[port] = &tunnel{ln: nopListener{}, target: target, uids: uids}
		return applyTunnelGuard(ctx, env)
	}
	ln, err := net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(port))
	if err != nil {
		return fmt.Errorf("tunnel %s on %d: %w", target, port, err)
	}
	tunnels.m[port] = &tunnel{ln: ln, target: target, uids: uids}
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go carryTunnel(ctx, env, c, target)
		}
	}()
	return applyTunnelGuard(ctx, env)
}

func dropTunnel(ctx context.Context, env *Env, port int) {
	tunnels.Lock()
	defer tunnels.Unlock()
	if t, ok := tunnels.m[port]; ok {
		t.ln.Close()
		delete(tunnels.m, port)
		_ = applyTunnelGuard(ctx, env)
	}
}

func stopTunnels() {
	tunnels.Lock()
	defer tunnels.Unlock()
	for p, t := range tunnels.m {
		t.ln.Close()
		delete(tunnels.m, p)
	}
}

func tunnelPorts() map[int]string {
	tunnels.Lock()
	defer tunnels.Unlock()
	out := map[int]string{}
	for p, t := range tunnels.m {
		out[p] = t.target
	}
	return out
}

func carryTunnel(ctx context.Context, env *Env, local net.Conn, target string) {
	defer local.Close()
	st := currentMirror(env)
	conn, err := peerDial(ctx, env, st)
	if err != nil {
		return
	}
	defer conn.Close()
	br, err := peerSend(conn, "tunnel", map[string]any{"target": target}, nil)
	if err != nil {
		return
	}
	done := make(chan struct{}, 2)
	go func() {
		_, _ = io.Copy(conn, local)
		_ = conn.CloseWrite()
		done <- struct{}{}
	}()
	go func() {
		_, _ = io.Copy(local, br)
		if tc, ok := local.(*net.TCPConn); ok {
			_ = tc.CloseWrite()
		}
		done <- struct{}{}
	}()
	<-done
	<-done
}

// applyTunnelGuard: only the listed uids may connect to each tunnel port.
func applyTunnelGuard(ctx context.Context, env *Env) error {
	var elems []string
	for port, t := range tunnels.m {
		for _, uid := range t.uids {
			elems = append(elems, fmt.Sprintf("%d . %d", port, uid))
		}
	}
	slices.Sort(elems)
	var b strings.Builder
	b.WriteString("# Managed by WSPanel agent (mirror tunnels). Do not edit.\n")
	b.WriteString("table inet wspanel_mirror\ndelete table inet wspanel_mirror\ntable inet wspanel_mirror {\n")
	b.WriteString("\tset allowed {\n\t\ttype inet_service . uid\n")
	if len(elems) > 0 {
		b.WriteString("\t\telements = { " + strings.Join(elems, ", ") + " }\n")
	}
	b.WriteString("\t}\n\tchain output {\n\t\ttype filter hook output priority 0; policy accept;\n")
	fmt.Fprintf(&b, "\t\toifname \"lo\" tcp dport . meta skuid @allowed accept\n")
	fmt.Fprintf(&b, "\t\toifname \"lo\" tcp dport %d-%d reject with tcp reset\n", tunnelPortMariaDB, tunnelPortKVMax)
	b.WriteString("\t}\n}\n")
	if err := writeMirrorFile(env, "tunnels.nft", []byte(b.String())); err != nil {
		return err
	}
	if env.Cfg.DryRun {
		return nil
	}
	_, err := env.Exec.Run(ctx, "nft", "-f", mirrorFile(env, "tunnels.nft"))
	return err
}

func uidOf(name string) (int, bool) {
	u, err := user.Lookup(name)
	if err != nil {
		return 0, false
	}
	n, err := strconv.Atoi(u.Uid)
	return n, err == nil
}

// replicaUIDs: root and the account the replica of an engine runs as.
func replicaUIDs(account string) []int {
	out := []int{0}
	if uid, ok := uidOf(account); ok {
		out = append(out, uid)
	}
	return out
}

type nopListener struct{}

func (nopListener) Accept() (net.Conn, error) { return nil, net.ErrClosed }
func (nopListener) Close() error              { return nil }
func (nopListener) Addr() net.Addr            { return &net.TCPAddr{} }
