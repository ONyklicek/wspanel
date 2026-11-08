package server

import (
	"bufio"
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/audit"
	"wspanel/agent/internal/config"
	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/ops"
	"wspanel/agent/internal/protocol"
)

func TestSocketRoundTrip(t *testing.T) {
	dir, err := os.MkdirTemp("", "pa") // short path: Unix socket paths are limited to ~104 bytes on macOS
	if err != nil {
		t.Fatal(err)
	}
	defer os.RemoveAll(dir)
	cfg := config.Defaults()
	cfg.DryRun = true
	cfg.AllowUIDs(uint32(os.Getuid())) // on Linux the agent checks who connects
	cfg.Socket = filepath.Join(dir, "a.sock")
	cfg.AuditLog = filepath.Join(dir, "audit.log")

	a, err := audit.Open(cfg.AuditLog)
	if err != nil {
		t.Fatal(err)
	}
	env := &ops.Env{Cfg: cfg, Exec: &executor.DryRun{}, FS: &fsx.FS{DryRun: true}}
	srv := New(env, a, slog.New(slog.NewTextHandler(io.Discard, nil)))
	ln, err := srv.Listen()
	if err != nil {
		t.Fatal(err)
	}
	if fi, _ := os.Stat(cfg.Socket); fi.Mode().Perm() != 0o660 {
		t.Fatalf("socket mode %v", fi.Mode().Perm())
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { _ = srv.Serve(ctx, ln); close(done) }()

	send := func(line string) protocol.Response {
		c, err := net.Dial("unix", cfg.Socket)
		if err != nil {
			t.Fatal(err)
		}
		defer c.Close()
		_, _ = c.Write([]byte(line + "\n"))
		var r protocol.Response
		b, _ := bufio.NewReader(c).ReadBytes('\n')
		if err := json.Unmarshal(b, &r); err != nil {
			t.Fatalf("decode %q: %v", b, err)
		}
		return r
	}

	if r := send(`{"op":"agent.ping"}`); !r.OK {
		t.Fatalf("ping: %+v", r)
	}
	if r := send(`{"op":"shell.exec","params":{"cmd":"id"}}`); r.OK || r.Error.Code != protocol.CodeUnknownOp {
		t.Fatalf("unknown op: %+v", r)
	}
	if r := send(`not json`); r.OK || r.Error.Code != protocol.CodeBadRequest {
		t.Fatalf("bad json: %+v", r)
	}

	cancel()
	<-done
	a.Close()
	log, _ := os.ReadFile(cfg.AuditLog)
	lines := strings.Split(strings.TrimSpace(string(log)), "\n")
	if len(lines) != 2 || !strings.Contains(lines[1], `"op":"shell.exec"`) || strings.Contains(lines[1], "cmd") {
		t.Fatalf("audit log:\n%s", log)
	}
}
