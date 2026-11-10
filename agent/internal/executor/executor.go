// Package executor runs external programs. It never goes through a shell:
// every call is exec.Command(path, args...) with separate arguments, and only
// binaries registered in the allowlist can be started.
package executor

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
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
	// RunIO is Run with stdin and stdout streams (dumps, restores, file
	// contents). stdout may be nil to capture it in Result. No timeout beyond ctx.
	RunIO(ctx context.Context, stdin io.Reader, stdout io.Writer, name string, args ...string) (Result, error)
	// RunLog streams stdout and stderr, interleaved, into w (build logs).
	RunLog(ctx context.Context, w io.Writer, name string, args ...string) (Result, error)
}

// DefaultTimeout bounds every command.
const DefaultTimeout = 2 * time.Minute

// MaxCaptured is the most output a command may leave in a Result (stdout and
// stderr each). The agent runs as root and many commands read what a site
// user controls: a file made a link to /dev/zero would otherwise fill the
// agent's memory until the kernel killed it, and every job with it. Past the
// limit the pipe is closed, the command ends (SIGPIPE) and Run reports it.
const MaxCaptured = 32 << 20

// waitDelay bounds how long Wait waits for the output pipes once the command
// was killed or exited: a child that handed the pipe on (systemd-run --pipe,
// a forked process) must not hold the caller past its timeout.
const waitDelay = 10 * time.Second

// ErrOutputLimit is returned when a command's output passed its limit.
var ErrOutputLimit = errors.New("the command's output is too large")

// LimitedBuffer collects up to Max bytes; a write beyond it fails. The
// buffer is a named field, not embedded: an embedded bytes.Buffer would lend
// it ReadFrom, and io.Copy (which os/exec uses) would read through that
// past the limit without ever calling Write.
type LimitedBuffer struct {
	buf bytes.Buffer
	Max int
}

func (b *LimitedBuffer) Write(p []byte) (int, error) {
	room := b.Max - b.buf.Len()
	if len(p) > room {
		if room > 0 {
			b.buf.Write(p[:room])
		}
		return max(room, 0), ErrOutputLimit
	}
	return b.buf.Write(p)
}

func (b *LimitedBuffer) Len() int       { return b.buf.Len() }
func (b *LimitedBuffer) String() string { return b.buf.String() }

// Real executes commands on the host.
type Real struct {
	bins map[string]string // logical name -> absolute path
	env  []string
}

func NewReal(bins map[string]string, env []string) *Real {
	return &Real{bins: bins, env: env}
}

func (r *Real) Run(ctx context.Context, name string, args ...string) (Result, error) {
	ctx, cancel := context.WithTimeout(ctx, DefaultTimeout)
	defer cancel()
	return r.RunIO(ctx, nil, nil, name, args...)
}

func (r *Real) RunLog(ctx context.Context, w io.Writer, name string, args ...string) (Result, error) {
	path, ok := r.bins[name]
	if !ok {
		return Result{ExitCode: -1}, fmt.Errorf("executor: %q is not an allowed binary", name)
	}
	cmd := exec.CommandContext(ctx, path, args...)
	cmd.Env = r.env
	cmd.Dir = "/"
	cmd.WaitDelay = waitDelay
	cmd.Stdout, cmd.Stderr = w, w
	err := cmd.Run()
	res := Result{}
	if err != nil {
		var ee *exec.ExitError
		if errors.As(err, &ee) {
			res.ExitCode = ee.ExitCode()
		} else {
			res.ExitCode = -1
		}
		return res, fmt.Errorf("%s: %v", name, err)
	}
	return res, nil
}

func (r *Real) RunIO(ctx context.Context, stdin io.Reader, out io.Writer, name string, args ...string) (Result, error) {
	path, ok := r.bins[name]
	if !ok {
		return Result{ExitCode: -1}, fmt.Errorf("executor: %q is not an allowed binary", name)
	}
	cmd := exec.CommandContext(ctx, path, args...)
	cmd.Env = r.env
	cmd.Dir = "/"
	cmd.WaitDelay = waitDelay
	stdout, stderr := LimitedBuffer{Max: MaxCaptured}, LimitedBuffer{Max: MaxCaptured}
	cmd.Stdin = stdin
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	if out != nil {
		cmd.Stdout = out
	}
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
	Name  string
	Args  []string
	Stdin string // RunIO only
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

func (d *DryRun) Run(ctx context.Context, name string, args ...string) (Result, error) {
	return d.RunIO(ctx, nil, nil, name, args...)
}

func (d *DryRun) RunLog(ctx context.Context, w io.Writer, name string, args ...string) (Result, error) {
	return d.RunIO(ctx, nil, w, name, args...)
}

// RunIO records stdin (for tests); Responder output is written to stdout.
func (d *DryRun) RunIO(_ context.Context, stdin io.Reader, out io.Writer, name string, args ...string) (Result, error) {
	c := Call{Name: name, Args: append([]string(nil), args...)}
	if stdin != nil {
		b, _ := io.ReadAll(stdin)
		c.Stdin = string(b)
	}
	d.mu.Lock()
	d.calls = append(d.calls, c)
	d.mu.Unlock()
	if d.Log != nil {
		d.Log("dry-run: " + c.String())
	}
	if d.Responder != nil {
		res, err := d.Responder(c)
		if out != nil && res.Stdout != "" {
			_, _ = io.WriteString(out, res.Stdout)
			res.Stdout = ""
		}
		return res, err
	}
	return Result{}, nil
}

func (d *DryRun) Calls() []Call {
	d.mu.Lock()
	defer d.mu.Unlock()
	return append([]Call(nil), d.calls...)
}
