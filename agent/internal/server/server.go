// Package server accepts panel connections on the Unix socket and dispatches
// requests to the whitelisted operations.
package server

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"os"
	"os/user"
	"slices"
	"strconv"
	"sync"
	"syscall"
	"time"

	"wspanel/agent/internal/audit"
	"wspanel/agent/internal/ops"
	"wspanel/agent/internal/peercred"
	"wspanel/agent/internal/protocol"
)

const (
	readTimeout = 10 * time.Second
	opTimeout   = 5 * time.Minute
)

type Server struct {
	Env   *ops.Env
	Audit *audit.Log
	Log   *slog.Logger

	ops    map[string]ops.Op
	mutate sync.Mutex // serializes mutating operations
	wg     sync.WaitGroup
}

func New(env *ops.Env, a *audit.Log, log *slog.Logger) *Server {
	return &Server{Env: env, Audit: a, Log: log, ops: ops.Registry()}
}

// Listen creates the socket with mode 0660 and group SocketGroup. The umask
// keeps it private between bind and chmod.
func (s *Server) Listen() (*net.UnixListener, error) {
	path := s.Env.Cfg.Socket
	if fi, err := os.Lstat(path); err == nil {
		if fi.Mode()&os.ModeSocket == 0 {
			return nil, fmt.Errorf("%s exists and is not a socket", path)
		}
		_ = os.Remove(path)
	}
	old := syscall.Umask(0o177)
	ln, err := net.ListenUnix("unix", &net.UnixAddr{Name: path, Net: "unix"})
	syscall.Umask(old)
	if err != nil {
		return nil, err
	}
	ln.SetUnlinkOnClose(true)
	if g := s.Env.Cfg.SocketGroup; g != "" && !s.Env.Cfg.DryRun {
		grp, err := user.LookupGroup(g)
		if err != nil {
			ln.Close()
			return nil, fmt.Errorf("socket group: %w", err)
		}
		gid, _ := strconv.Atoi(grp.Gid)
		if err := os.Chown(path, 0, gid); err != nil {
			ln.Close()
			return nil, err
		}
	}
	if err := os.Chmod(path, 0o660); err != nil {
		ln.Close()
		return nil, err
	}
	return ln, nil
}

// Serve handles connections until ctx is cancelled, then waits for in-flight requests.
func (s *Server) Serve(ctx context.Context, ln *net.UnixListener) error {
	go func() {
		<-ctx.Done()
		ln.Close()
	}()
	for {
		conn, err := ln.AcceptUnix()
		if err != nil {
			if ctx.Err() != nil {
				s.wg.Wait()
				return nil
			}
			s.Log.Error("accept", "err", err)
			continue
		}
		s.wg.Add(1)
		go func() {
			defer s.wg.Done()
			s.handle(ctx, conn)
		}()
	}
}

func (s *Server) handle(ctx context.Context, conn *net.UnixConn) {
	defer conn.Close()
	peer := int64(-1)
	if peercred.Supported {
		uid, err := peercred.UID(conn)
		if err != nil || !slices.Contains(s.Env.Cfg.AllowedUIDs(), uid) {
			s.Log.Warn("rejected peer", "uid", uid, "err", err)
			s.reply(conn, fail(protocol.Forbidden("peer not allowed")))
			return
		}
		peer = int64(uid)
	}

	_ = conn.SetReadDeadline(time.Now().Add(readTimeout))
	line, err := bufio.NewReader(io.LimitReader(conn, protocol.MaxRequestBytes)).ReadBytes('\n')
	if err != nil && !(errors.Is(err, io.EOF) && len(line) > 0) {
		s.reply(conn, fail(&protocol.OpError{Code: protocol.CodeBadRequest, Message: "read request: " + err.Error()}))
		return
	}
	var req protocol.Request
	if err := json.Unmarshal(line, &req); err != nil {
		s.reply(conn, fail(&protocol.OpError{Code: protocol.CodeBadRequest, Message: "invalid JSON"}))
		return
	}
	_ = conn.SetDeadline(time.Now().Add(opTimeout + 10*time.Second))
	s.reply(conn, s.Dispatch(ctx, req, peer))
}

// Dispatch runs one request and writes the audit entry. Exposed for tests.
func (s *Server) Dispatch(ctx context.Context, req protocol.Request, peer int64) protocol.Response {
	start := time.Now()
	op, ok := s.ops[req.Op]
	var out any
	var err error
	if !ok {
		err = &protocol.OpError{Code: protocol.CodeUnknownOp, Message: fmt.Sprintf("unknown operation %q", req.Op)}
	} else {
		if op.Mutating {
			s.mutate.Lock()
		}
		opCtx, cancel := context.WithTimeout(ctx, opTimeout)
		out, err = safeCall(op, opCtx, s.Env, req.Params)
		cancel()
		if op.Mutating {
			s.mutate.Unlock()
		}
	}

	entry := audit.Entry{
		Time: start.UTC(), Op: req.Op, Params: req.Params, PeerUID: peer, OK: err == nil,
		DurationMS: time.Since(start).Milliseconds(), DryRun: s.Env.Cfg.DryRun,
	}
	if !ok {
		entry.Params = nil // do not log arbitrary payloads of unknown ops
	}
	if err != nil {
		entry.Error = err.Error()
	}
	if s.Audit != nil {
		if aerr := s.Audit.Write(entry); aerr != nil {
			s.Log.Error("audit write failed", "err", aerr)
		}
	}
	s.Log.Info("op", "op", req.Op, "ok", err == nil, "ms", entry.DurationMS, "err", entry.Error)

	if err != nil {
		return fail(err)
	}
	return protocol.Response{OK: true, Output: out}
}

func safeCall(op ops.Op, ctx context.Context, env *ops.Env, params json.RawMessage) (out any, err error) {
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("internal error in %s: %v", op.Name, r)
		}
	}()
	return op.Handle(ctx, env, params)
}

func fail(err error) protocol.Response {
	var oe *protocol.OpError
	if errors.As(err, &oe) {
		return protocol.Response{Error: &protocol.Error{Code: oe.Code, Message: oe.Message}}
	}
	return protocol.Response{Error: &protocol.Error{Code: protocol.CodeFailed, Message: err.Error()}}
}

func (s *Server) reply(conn net.Conn, resp protocol.Response) {
	b, _ := json.Marshal(resp)
	_, _ = conn.Write(append(b, '\n'))
}
