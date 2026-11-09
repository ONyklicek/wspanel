package ops

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"strings"
	"syscall"

	"wspanel/agent/internal/protocol"
)

// procIdent: what sys.kill checks before signalling a process.
type procIdent struct {
	Start  uint64 // clock ticks after boot
	PPID   int
	Cmd    string // empty for kernel threads
	Cgroup string
}

// procIdentity reads a process (Linux: /proc); tests replace it.
var procIdentity = readProcIdentity

// signalProcess sends the signal (tests replace it).
var signalProcess = func(pid int, sig syscall.Signal) error { return syscall.Kill(pid, sig) }

// sysKill ends one process from Server → Procesy. The process is named by its
// pid and start time, so a pid reused since the list was shown is not hit.
// Never PID 1, kernel threads, or the agent and what it runs.
func sysKill(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		PID    int    `json:"pid"`
		Start  uint64 `json:"start"`
		Signal string `json:"signal,omitempty"` // TERM (default) | KILL
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	sig := syscall.SIGTERM
	switch p.Signal {
	case "", "TERM":
	case "KILL":
		sig = syscall.SIGKILL
	default:
		return nil, protocol.Invalid("signal: TERM or KILL")
	}
	if p.PID <= 1 || p.PID == os.Getpid() || p.PID == os.Getppid() {
		return nil, protocol.Invalid("pid: this process cannot be ended from the panel")
	}
	id, err := procIdentity(p.PID)
	if err != nil || id.Start != p.Start {
		return nil, protocol.Invalid("pid: the process has already ended")
	}
	if id.Cmd == "" || id.PPID == 2 {
		return nil, protocol.Invalid("pid: a kernel thread")
	}
	if strings.Contains(id.Cgroup, "/panel-agent.service") {
		return nil, protocol.Invalid("pid: the agent or a task it runs (end the task in the panel)")
	}
	if env.Cfg.DryRun {
		return map[string]any{"pid": p.PID, "signal": p.Signal}, nil
	}
	if err := signalProcess(p.PID, sig); err != nil {
		if errors.Is(err, syscall.ESRCH) {
			return nil, protocol.Invalid("pid: the process has already ended")
		}
		return nil, err
	}
	return map[string]any{"pid": p.PID, "signal": sig.String()}, nil
}
