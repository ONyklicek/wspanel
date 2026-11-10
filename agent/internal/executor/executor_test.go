package executor

import (
	"context"
	"io"
	"testing"
	"time"
)

// A command reading what a site user controls (a link to /dev/zero) must
// not fill the agent's memory: its captured output stops at the limit and
// the command ends.
func TestCapturedOutputStopsAtTheLimit(t *testing.T) {
	r := NewReal(map[string]string{"yes": "/usr/bin/yes"}, nil)
	start := time.Now()
	res, err := r.Run(context.Background(), "yes")
	if err == nil {
		t.Fatal("endless output reported as success")
	}
	if len(res.Stdout) != MaxCaptured {
		t.Fatalf("captured %d bytes, want %d", len(res.Stdout), MaxCaptured)
	}
	if time.Since(start) > 30*time.Second {
		t.Fatal("the command was not stopped at the limit")
	}
}

// A killed command whose output pipe a child still holds must not keep the
// caller waiting past its timeout.
func TestTimeoutIsNotHeldByAChildHoldingThePipe(t *testing.T) {
	r := NewReal(map[string]string{"sh": "/bin/sh"}, nil)
	ctx, cancel := context.WithTimeout(context.Background(), 500*time.Millisecond)
	defer cancel()
	start := time.Now()
	_, err := r.RunIO(ctx, nil, nil, "sh", "-c", "sleep 60 & sleep 60")
	if err == nil {
		t.Fatal("no error after the timeout")
	}
	if d := time.Since(start); d > waitDelay+5*time.Second {
		t.Fatalf("returned after %v", d)
	}
}

func TestLimitedBufferKeepsWhatFits(t *testing.T) {
	b := LimitedBuffer{Max: 4}
	if n, err := b.Write([]byte("abcdef")); n != 4 || err != ErrOutputLimit || b.String() != "abcd" {
		t.Fatalf("%d %v %q", n, err, b.String())
	}
}

// io.Copy prefers ReaderFrom: the limit must hold through it too.
func TestLimitedBufferHasNoReadFrom(t *testing.T) {
	var w any = &LimitedBuffer{Max: 1}
	if _, ok := w.(interface {
		ReadFrom(r io.Reader) (int64, error)
	}); ok {
		t.Fatal("LimitedBuffer has ReadFrom: io.Copy would bypass the limit")
	}
}
