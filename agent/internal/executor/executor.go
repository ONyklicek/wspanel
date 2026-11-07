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
	var stdout, stderr bytes.Buffer
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
