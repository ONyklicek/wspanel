// Package executor runs external programs. It never goes through a shell:
// every call is exec.Command(path, args...) with separate arguments, and only
// binaries registered in the allowlist can be started.
package executor

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os/exec"
	"strings"
	"sync"
	"time"
)

type Result struct {
	Stdout   string `json:"stdout,omitempty"`
	Stderr   string `json:"stderr,omitempty"`
	ExitCode int    `json:"exit_code"`
}

type Executor interface {
	// Run starts the allowlisted program `name` with args. A non-zero exit is an error.
	Run(ctx context.Context, name string, args ...string) (Result, error)
}

// DefaultTimeout bounds every command.
const DefaultTimeout = 2 * time.Minute

// Real executes commands on the host.
type Real struct {
	bins map[string]string // logical name -> absolute path
	env  []string
}

func NewReal(bins map[string]string, env []string) *Real {
	return &Real{bins: bins, env: env}
}

func (r *Real) Run(ctx context.Context, name string, args ...string) (Result, error) {
	path, ok := r.bins[name]
	if !ok {
		return Result{ExitCode: -1}, fmt.Errorf("executor: %q is not an allowed binary", name)
	}
	ctx, cancel := context.WithTimeout(ctx, DefaultTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, path, args...)
	cmd.Env = r.env
	cmd.Dir = "/"
	var stdout, stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	err := cmd.Run()
	res := Result{Stdout: stdout.String(), Stderr: stderr.String()}
	if err != nil {
		var ee *exec.ExitError
		if errors.As(err, &ee) {
			res.ExitCode = ee.ExitCode()
		} else {
			res.ExitCode = -1
		}
		msg := strings.TrimSpace(res.Stderr)
		if msg == "" {
			msg = strings.TrimSpace(res.Stdout)
		}
		return res, fmt.Errorf("%s %s: %v: %s", name, strings.Join(args, " "), err, truncate(msg, 2000))
	}
	return res, nil
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "…"
}

// Call is one recorded dry-run invocation.
type Call struct {
	Name string
	Args []string
}

func (c Call) String() string { return c.Name + " " + strings.Join(c.Args, " ") }

// DryRun records calls instead of executing them. Responder (optional) can
// fake output or failures for tests.
type DryRun struct {
	mu        sync.Mutex
	calls     []Call
	Responder func(Call) (Result, error)
	Log       func(string)
}

func (d *DryRun) Run(_ context.Context, name string, args ...string) (Result, error) {
	c := Call{Name: name, Args: append([]string(nil), args...)}
	d.mu.Lock()
	d.calls = append(d.calls, c)
	d.mu.Unlock()
	if d.Log != nil {
		d.Log("dry-run: " + c.String())
	}
	if d.Responder != nil {
		return d.Responder(c)
	}
	return Result{}, nil
}

func (d *DryRun) Calls() []Call {
	d.mu.Lock()
	defer d.mu.Unlock()
	return append([]Call(nil), d.calls...)
}
