package ops

import (
	"bufio"
	"context"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fsx"
)

// The link between the two nodes: one TLS connection per request. A request
// is one JSON line; the answer is one JSON line, and for a stream (a
// snapshot, a dump, rsync, a tunnel) the raw bytes follow it. The
// operations here are the mirror's only: nothing of the agent's whitelist is
// reachable over the network.

const (
	peerDialTimeout = 5 * time.Second
	peerMaxRequest  = 1 << 20
)

type peerRequest struct {
	Op     string          `json:"op"`
	Params json.RawMessage `json:"params,omitempty"`
}

type peerAnswer struct {
	OK     bool            `json:"ok"`
	Error  string          `json:"error,omitempty"`
	Output json.RawMessage `json:"output,omitempty"`
}

// peerRoundTrip sends one request on conn and reads the answer into out.
func peerRoundTrip(conn net.Conn, op string, params any, out any) error {
	_, err := peerSend(conn, op, params, out)
	return err
}

// peerSend writes the request and reads the answer line; the reader holds
// what came after it (a stream).
func peerSend(conn net.Conn, op string, params any, out any) (*bufio.Reader, error) {
	raw, err := json.Marshal(params)
	if err != nil {
		return nil, err
	}
	req, _ := json.Marshal(peerRequest{Op: op, Params: raw})
	// Quick requests answer at once; a promotion or the preparation of the
	// databases (a restart) takes its time.
	wait := 30 * time.Second
	if op == "promote" || op == "repl_prepare" || op == "snapshot" {
		wait = 30 * time.Minute
	}
	_ = conn.SetDeadline(time.Now().Add(wait))
	if _, err := conn.Write(append(req, '\n')); err != nil {
		return nil, err
	}
	br := bufio.NewReader(conn)
	line, err := br.ReadBytes('\n')
	if err != nil {
		return nil, fmt.Errorf("the peer did not answer %s: %w", op, err)
	}
	_ = conn.SetDeadline(time.Time{})
	var ans peerAnswer
	if err := json.Unmarshal(line, &ans); err != nil {
		return nil, fmt.Errorf("the peer's answer to %s: %w", op, err)
	}
	if !ans.OK {
		return nil, fmt.Errorf("the peer refused %s: %s", op, ans.Error)
	}
	if out != nil && len(ans.Output) > 0 {
		if err := json.Unmarshal(ans.Output, out); err != nil {
			return nil, err
		}
	}
	return br, nil
}

// peerDial opens a verified connection to the paired node.
func peerDial(ctx context.Context, env *Env, st *mirrorState) (*tls.Conn, error) {
	if st.Peer == nil {
		return nil, errors.New("no paired node")
	}
	cfg, err := mirrorTLS(env, st.Peer.NodeID, false)
	if err != nil {
		return nil, err
	}
	d := &tls.Dialer{NetDialer: &net.Dialer{Timeout: peerDialTimeout, KeepAlive: 15 * time.Second}, Config: cfg}
	c, err := d.DialContext(ctx, "tcp", st.Peer.dial())
	if err != nil {
		return nil, err
	}
	return c.(*tls.Conn), nil
}

// peerCall: a request with a JSON answer.
func peerCall(ctx context.Context, env *Env, op string, params any, out any) error {
	st := currentMirror(env)
	conn, err := peerDial(ctx, env, st)
	if err != nil {
		return err
	}
	defer conn.Close()
	stop := context.AfterFunc(ctx, func() { conn.Close() })
	defer stop()
	return peerRoundTrip(conn, op, params, out)
}

// peerStream: a request whose answer is followed by a stream; the caller
// reads it from the returned reader and closes the connection.
func peerStream(ctx context.Context, env *Env, op string, params any, out any) (io.Reader, io.Closer, error) {
	st := currentMirror(env)
	conn, err := peerDial(ctx, env, st)
	if err != nil {
		return nil, nil, err
	}
	stop := context.AfterFunc(ctx, func() { conn.Close() })
	br, err := peerSend(conn, op, params, out)
	if err != nil {
		stop()
		conn.Close()
		return nil, nil, err
	}
	return br, closerFunc(func() error { stop(); return conn.Close() }), nil
}

type closerFunc func() error

func (f closerFunc) Close() error { return f() }

// ---------------------------------------------------------------- the listener

type mirrorListener struct {
	mu   sync.Mutex
	ln   net.Listener
	port int
}

var peerListener mirrorListener

// ensure binds the mirror's port while this node is in a mirror, and lets
// it go when it is not.
func (m *mirrorListener) ensure(ctx context.Context, env *Env, logf func(string, ...any)) {
	st := currentMirror(env)
	m.mu.Lock()
	defer m.mu.Unlock()
	want := 0
	if st.Role != roleNone && fsx.Exists(mirrorFile(env, "node.crt")) {
		want = st.Port
	}
	if want == m.port && (want == 0 || m.ln != nil) {
		return
	}
	if m.ln != nil {
		m.ln.Close()
		m.ln, m.port = nil, 0
	}
	if want == 0 || env.Cfg.DryRun {
		return
	}
	cfg, err := mirrorTLS(env, "", true)
	if err != nil {
		logf("mirror: %v", err)
		return
	}
	ln, err := tls.Listen("tcp", ":"+strconv.Itoa(want), cfg)
	if err != nil {
		logf("mirror: listening on %d: %v", want, err)
		return
	}
	m.ln, m.port = ln, want
	logf("mirror: listening on %d (%s)", want, st.Role)
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go servePeer(ctx, env, c.(*tls.Conn), logf)
		}
	}()
}

func peerReply(conn net.Conn, out any, err error) {
	ans := peerAnswer{OK: err == nil}
	if err != nil {
		ans.Error = err.Error()
	} else if out != nil {
		ans.Output, _ = json.Marshal(out)
	}
	b, _ := json.Marshal(ans)
	_, _ = conn.Write(append(b, '\n'))
}

func servePeer(ctx context.Context, env *Env, conn *tls.Conn, logf func(string, ...any)) {
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(30 * time.Second))
	if err := conn.HandshakeContext(ctx); err != nil {
		return
	}
	br := bufio.NewReader(io.LimitReader(conn, peerMaxRequest))
	line, err := br.ReadBytes('\n')
	if err != nil {
		return
	}
	var req peerRequest
	if json.Unmarshal(line, &req) != nil {
		peerReply(conn, nil, errors.New("bad request"))
		return
	}
	st := currentMirror(env)
	if req.Op == "join" {
		out, err := peerJoin(ctx, env, conn, req.Params)
		peerReply(conn, out, err)
		if err != nil {
			logf("mirror: pairing refused from %s: %v", conn.RemoteAddr(), err)
		} else {
			logf("mirror: paired with %s", conn.RemoteAddr())
			mirrorWake()
		}
		return
	}
	if st.Peer == nil || !peerVerified(conn.ConnectionState(), st.Peer.NodeID) {
		peerReply(conn, nil, errors.New("not the paired node"))
		return
	}
	_ = conn.SetDeadline(time.Time{})
	switch req.Op {
	case "hello":
		out, err := peerHello(env, req.Params)
		peerReply(conn, out, err)
	case "nudge":
		peerReply(conn, map[string]any{}, nil)
		mirrorNudged()
	case "fence":
		out, err := peerFence(ctx, env, req.Params)
		peerReply(conn, out, err)
	case "repl_prepare":
		if !mirrorSource(env) {
			peerReply(conn, nil, errors.New("only the primary is a replication source"))
			return
		}
		out, err := prepareReplicationSource(ctx, env)
		peerReply(conn, out, err)
	case "promote":
		if currentMirror(env).Role != roleStandby {
			peerReply(conn, nil, errors.New("this node is not a standby"))
			return
		}
		out, err := peerPromote(ctx, env, req.Params)
		peerReply(conn, out, err)
	case "snapshot":
		peerSnapshot(ctx, env, conn)
	case "dump":
		peerDump(ctx, env, conn, req.Params)
	case "rsync":
		peerRsync(ctx, env, conn, req.Params, logf)
	case "tunnel":
		peerTunnel(ctx, env, conn, req.Params)
	default:
		peerReply(conn, nil, fmt.Errorf("unknown request %q", req.Op))
	}
}

// peerJoin: the standby pairs (the only request without a certificate).
func peerJoin(_ context.Context, env *Env, _ *tls.Conn, raw json.RawMessage) (any, error) {
	var p struct {
		Secret string     `json:"secret"`
		CSR    string     `json:"csr"`
		Peer   mirrorPeer `json:"peer"`
	}
	if err := json.Unmarshal(raw, &p); err != nil {
		return nil, err
	}
	secret, err := hex.DecodeString(p.Secret)
	if err != nil {
		return nil, errors.New("bad secret")
	}
	if !strings.HasPrefix(p.Peer.NodeID, "node-") || len(p.Peer.NodeID) > 40 {
		return nil, errors.New("bad node id")
	}
	var out map[string]any
	_, err = updateMirror(env, func(st *mirrorState) error {
		if st.Role != rolePrimary || st.Peer != nil {
			return errors.New("this server is not waiting for a standby")
		}
		if !pairingSecretOK(st, secret) {
			return errors.New("the pairing code is wrong or expired")
		}
		if p.Peer.NodeID == st.NodeID {
			return errors.New("a server cannot pair with itself")
		}
		cert, err := signCSR(env, []byte(p.CSR), p.Peer.NodeID)
		if err != nil {
			return err
		}
		ca, err := os.ReadFile(mirrorFile(env, "ca.crt"))
		if err != nil {
			return err
		}
		key, err := signingKey(env)
		if err != nil {
			return err
		}
		peer := p.Peer
		if a, err := mirrorAddr("address", peer.Address); err == nil {
			peer.Address = a
		} else {
			return err
		}
		if peer.Port <= 0 || peer.Port > 65535 {
			peer.Port = mirrorDefaultPort
		}
		st.Peer, st.Pairing = &peer, nil
		out = map[string]any{
			"ca": string(ca), "cert": string(cert), "epoch": st.Epoch, "recovery_key": hex.EncodeToString(key),
			"repl": st.Repl, "version": Version,
			"primary": mirrorPeer{NodeID: st.NodeID, Name: st.Name, Address: st.Address, Port: st.Port, PublicIPv4: st.PublicIPv4},
		}
		return nil
	})
	return out, err
}

type helloInfo struct {
	NodeID     string    `json:"node_id"`
	Name       string    `json:"name"`
	Role       string    `json:"role"`
	Epoch      int64     `json:"epoch"`
	Version    string    `json:"version"`
	Time       time.Time `json:"time"`
	LANAddress string    `json:"lan_address"`
	PublicIPv4 string    `json:"public_ipv4"`
	Fenced     string    `json:"fenced,omitempty"`
	Sites      int       `json:"sites"`
	DBEngines  []string  `json:"db_engines"`
	PHP        []string  `json:"php"`
	Python     []string  `json:"python,omitempty"`
	Redis      bool      `json:"redis"`
	// Valkey: nil from a primary before 1.14.2, which always had it.
	Valkey *bool `json:"valkey,omitempty"`
	Mail   bool  `json:"mail"`
}

func localHello(env *Env) helloInfo {
	st := currentMirror(env)
	return helloInfo{
		NodeID: st.NodeID, Name: st.Name, Role: st.Role, Epoch: st.Epoch, Version: Version, Time: time.Now().UTC(),
		LANAddress: st.LANAddress, PublicIPv4: st.PublicIPv4, Fenced: st.Fenced, Sites: len(allSiteStates(env.Cfg)),
		DBEngines: env.Cfg.DBEngines, PHP: env.Cfg.PHPVersions, Python: pythonMinors(env.Cfg),
		Redis: !env.Cfg.DryRun && redisInstalled(env), Valkey: boolPtr(valkeyInstalled(env)), Mail: mailHostingOn(env),
	}
}

// peerHello answers with this node and settles the epoch: a node that hears
// of a primary newer than itself stops being one.
func peerHello(env *Env, raw json.RawMessage) (any, error) {
	var them helloInfo
	if len(raw) > 0 {
		_ = json.Unmarshal(raw, &them)
	}
	settleEpoch(env, them)
	return localHello(env), nil
}

// ---------------------------------------------------------------- streams

// peerSnapshot: the state and configuration, fresh (primary only).
func peerSnapshot(ctx context.Context, env *Env, conn net.Conn) {
	if !mirrorSource(env) {
		peerReply(conn, nil, errors.New("only the primary hands out its state"))
		return
	}
	path, size, err := makeMirrorSnapshot(ctx, env)
	if err != nil {
		peerReply(conn, nil, err)
		return
	}
	defer os.Remove(path)
	f, err := os.Open(path)
	if err != nil {
		peerReply(conn, nil, err)
		return
	}
	defer f.Close()
	peerReply(conn, map[string]any{"size": size}, nil)
	_, _ = io.Copy(conn, f)
}

// rsync's server side, as rrsync does it: only --server --sender (this node
// sends, never receives), only options that read, only below the sites' or
// the mail's root, and never following links out of them.
var (
	rsyncShortRe = regexp.MustCompile(`^-[A-Za-z]+(\.[A-Za-z]*)?$`)
	rsyncLong    = []string{"--delete", "--delete-delay", "--delete-after", "--delete-excluded", "--partial", "--inplace",
		"--ignore-errors", "--force", "--numeric-ids", "--hard-links", "--acls", "--xattrs", "--sparse", "--whole-file", "--no-inc-recursive", "--safe-links",
		"--backup"} // the receiver's own (a rejoin keeps what it alone had); the sender ignores it
	rsyncBackupDirRe = regexp.MustCompile(`^/[A-Za-z0-9/._-]*$`)
	rsyncLongValueRe = regexp.MustCompile(`^--(compress-choice|checksum-choice|compress-level|log-format|out-format|timeout|bwlimit)=[A-Za-z0-9%.:_-]*$|^--backup-dir=/[A-Za-z0-9/._-]*$`)
)

func rsyncModules(cfg config.Config) map[string]string {
	return map[string]string{"sites": cfg.SitesRoot, "mail": cfg.VmailRoot}
}

// rsyncServerArgs checks the remote command rsync asked for and returns it
// with the module's path in place of the requested one.
func rsyncServerArgs(cfg config.Config, args []string) ([]string, error) {
	if len(args) < 5 || args[0] != "rsync" || args[1] != "--server" || args[2] != "--sender" {
		return nil, errors.New("only a sending rsync server")
	}
	out := []string{"--server", "--sender"}
	i := 3
	for ; i < len(args) && args[i] != "."; i++ {
		a := args[i]
		// --backup-dir <dir>: the receiver's (a rejoin); the sender ignores it.
		if a == "--backup-dir" && i+1 < len(args) && rsyncBackupDirRe.MatchString(args[i+1]) {
			out = append(out, a, args[i+1])
			i++
			continue
		}
		switch {
		case rsyncShortRe.MatchString(a):
			letters, _, _ := strings.Cut(a[1:], ".")
			if strings.ContainsAny(letters, "Lk") { // follow links: out of the module
				return nil, errors.New("rsync: following links is not allowed")
			}
		case slices.Contains(rsyncLong, a), rsyncLongValueRe.MatchString(a):
		default:
			return nil, fmt.Errorf("rsync option %q is not allowed", a)
		}
		out = append(out, a)
	}
	if i+2 != len(args) {
		return nil, errors.New("rsync: one source path")
	}
	src := args[i+1]
	module, rest, _ := strings.Cut(strings.TrimPrefix(src, "/"), "/")
	root, ok := rsyncModules(cfg)[module]
	if !ok || root == "" {
		return nil, fmt.Errorf("rsync: unknown module %q", module)
	}
	clean := path.Clean("/" + rest)
	if strings.Contains(clean, "/..") {
		return nil, errors.New("rsync: bad path")
	}
	target := filepath.Join(root, clean)
	if strings.HasSuffix(src, "/") {
		target += "/"
	}
	return append(out, ".", target), nil
}

func peerRsync(ctx context.Context, env *Env, conn net.Conn, raw json.RawMessage, logf func(string, ...any)) {
	var p struct {
		Args []string `json:"args"`
	}
	if err := json.Unmarshal(raw, &p); err != nil {
		peerReply(conn, nil, err)
		return
	}
	if !mirrorSource(env) {
		peerReply(conn, nil, errors.New("only the primary sends its files"))
		return
	}
	args, err := rsyncServerArgs(env.Cfg, p.Args)
	if err != nil {
		peerReply(conn, nil, err)
		return
	}
	peerReply(conn, map[string]any{}, nil)
	ctx, cancel := context.WithTimeout(ctx, 6*time.Hour)
	defer cancel()
	// rsync's stdin is a pipe this side feeds from the connection: when
	// rsync ends, closing the connection ends the feeding too (a read on
	// the connection itself could not be interrupted).
	pr, pw, err := os.Pipe()
	if err != nil {
		logf("mirror: rsync: %v", err)
		return
	}
	go func() {
		_, _ = io.Copy(pw, conn)
		pw.Close()
	}()
	_, err = env.Exec.RunIO(ctx, pr, conn, "rsync", args...)
	pr.Close()
	conn.Close()
	if err != nil {
		logf("mirror: rsync: %v", err)
	}
}

// tunnel targets: the primary's replication sources, on loopback.
func tunnelTarget(env *Env, target string) (network, addr string, err error) {
	switch {
	case target == "mariadb" && slices.Contains(env.Cfg.DBEngines, "mariadb"):
		return "tcp", "127.0.0.1:3306", nil
	case target == "postgresql" && slices.Contains(env.Cfg.DBEngines, "postgresql"):
		return "tcp", "127.0.0.1:5432", nil
	case strings.HasPrefix(target, "kv:"):
		st, _, err := loadSite(env, strings.TrimPrefix(target, "kv:"))
		if err != nil {
			return "", "", err
		}
		if st.Valkey == nil {
			return "", "", errors.New("the site has no Valkey/Redis")
		}
		return "tcp", "127.0.0.1:" + strconv.Itoa(st.Valkey.Port), nil
	}
	return "", "", fmt.Errorf("unknown tunnel %q", target)
}

func peerTunnel(ctx context.Context, env *Env, conn net.Conn, raw json.RawMessage) {
	var p struct {
		Target string `json:"target"`
	}
	if err := json.Unmarshal(raw, &p); err != nil {
		peerReply(conn, nil, err)
		return
	}
	if !mirrorSource(env) {
		peerReply(conn, nil, errors.New("tunnels lead to the primary only"))
		return
	}
	network, addr, err := tunnelTarget(env, p.Target)
	if err != nil {
		peerReply(conn, nil, err)
		return
	}
	up, err := (&net.Dialer{Timeout: peerDialTimeout}).DialContext(ctx, network, addr)
	if err != nil {
		peerReply(conn, nil, err)
		return
	}
	defer up.Close()
	peerReply(conn, map[string]any{}, nil)
	pipeBoth(conn, up)
}

// mirrorSource: this node hands out its data: the primary, or a primary
// fenced for a planned switchover (the standby catches up with it).
func mirrorSource(env *Env) bool {
	st := currentMirror(env)
	return st.Role == rolePrimary || (st.Role == roleFenced && st.Fenced == "switchover")
}

// pipeBoth copies both ways until either side ends.
func pipeBoth(a, b net.Conn) {
	done := make(chan struct{}, 2)
	cp := func(dst, src net.Conn) {
		_, _ = io.Copy(dst, src)
		if cw, ok := dst.(interface{ CloseWrite() error }); ok {
			_ = cw.CloseWrite()
		}
		done <- struct{}{}
	}
	go cp(a, b)
	go cp(b, a)
	<-done
	<-done
}

// ---------------------------------------------------------------- rsync's transport

// RunMirrorPipe is the transport rsync runs on the standby (-e "panel-agent
// -mirror-pipe"): it dials the primary and relays rsync's stdin/stdout over
// the link. args: [host, rsync, --server, …] as rsync passes them.
func RunMirrorPipe(cfgPath string, args []string) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return err
	}
	if len(args) < 2 {
		return errors.New("usage: panel-agent -mirror-pipe <host> rsync --server …")
	}
	env := &Env{Cfg: cfg, Exec: &executor.DryRun{}, FS: &fsx.FS{DryRun: cfg.DryRun}}
	ctx := context.Background()
	st := currentMirror(env)
	conn, err := peerDial(ctx, env, st)
	if err != nil {
		return err
	}
	defer conn.Close()
	br, err := peerSend(conn, "rsync", map[string]any{"args": args[1:]}, nil)
	if err != nil {
		return err
	}
	done := make(chan struct{})
	go func() {
		_, _ = io.Copy(conn, os.Stdin)
		_ = conn.CloseWrite()
		close(done)
	}()
	_, err = io.Copy(os.Stdout, br)
	return err
}
