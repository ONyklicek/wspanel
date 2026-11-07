// Package server accepts panel connections on the Unix socket and dispatches
// requests to the whitelisted operations.
package server

import (
	"bufio"
	"context"
	"crypto/rand"
	"encoding/hex"
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

	jobsMu sync.Mutex
	jobs   map[string]*job
}

// job is a background run of an async operation.
type job struct {
	ID       string    `json:"id"`
	Op       string    `json:"op"`
	Status   string    `json:"status"` // running | done | failed
	Output   any       `json:"output,omitempty"`
	Error    string    `json:"error,omitempty"`
	Started  time.Time `json:"started"`
	Finished time.Time `json:"finished,omitzero"`
}

const (
	jobTimeout   = 3 * time.Hour
	jobRetention = 24 * time.Hour
)

func New(env *ops.Env, a *audit.Log, log *slog.Logger) *Server {
	return &Server{Env: env, Audit: a, Log: log, ops: ops.Registry(), jobs: map[string]*job{}}
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
	if req.Op == "job.status" {
		return s.jobStatus(req.Params)
	}
	if op, ok := s.ops[req.Op]; ok && op.Async {
		return s.startJob(ctx, op, req, peer)
	}
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

// startJob runs an async op in the background. The audit entry is written
// when it finishes; the response carries only the job id.
func (s *Server) startJob(ctx context.Context, op ops.Op, req protocol.Request, peer int64) protocol.Response {
	idb := make([]byte, 8)
	_, _ = rand.Read(idb)
	j := &job{ID: hex.EncodeToString(idb), Op: op.Name, Status: "running", Started: time.Now().UTC()}
	s.jobsMu.Lock()
	for id, old := range s.jobs { // forget finished jobs after a day
		if !old.Finished.IsZero() && time.Since(old.Finished) > jobRetention {
			delete(s.jobs, id)
		}
	}
	s.jobs[j.ID] = j
	s.jobsMu.Unlock()

	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		if op.Mutating {
			s.mutate.Lock()
			defer s.mutate.Unlock()
		}
		jctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), jobTimeout)
		out, err := safeCall(op, jctx, s.Env, req.Params)
		cancel()
		s.jobsMu.Lock()
		j.Finished = time.Now().UTC()
		if err != nil {
			j.Status, j.Error = "failed", err.Error()
		} else {
			j.Status, j.Output = "done", out
		}
		s.jobsMu.Unlock()
		entry := audit.Entry{Time: j.Started, Op: op.Name, Params: req.Params, PeerUID: peer, OK: err == nil,
			DurationMS: j.Finished.Sub(j.Started).Milliseconds(), DryRun: s.Env.Cfg.DryRun, Error: j.Error}
		if s.Audit != nil {
			_ = s.Audit.Write(entry)
		}
		s.Log.Info("job", "op", op.Name, "id", j.ID, "status", j.Status, "ms", entry.DurationMS, "err", j.Error)
	}()
	id := j.ID
	return protocol.Response{OK: true, Output: map[string]string{"job_id": id, "status": "running"}, JobID: &id}
}

func (s *Server) jobStatus(raw json.RawMessage) protocol.Response {
	var p struct {
		ID string `json:"id"`
	}
	if err := json.Unmarshal(raw, &p); err != nil || len(p.ID) != 16 {
		return fail(protocol.Invalid("id: required"))
	}
	s.jobsMu.Lock()
	defer s.jobsMu.Unlock()
	j, ok := s.jobs[p.ID]
	if !ok {
		return fail(protocol.NotFound("job " + p.ID + " not found (finished jobs are kept for a day; restarting the agent forgets them)"))
	}
	cp := *j
	return protocol.Response{OK: true, Output: cp}
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
