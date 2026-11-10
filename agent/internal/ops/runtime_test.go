package ops

import (
	"context"
	"testing"

	"wspanel/agent/internal/executor"
)

// FrankenPHP 1.13 prints "PHP 8.5" (no patch) in some builds, "PHP 8.5.12" in others.
func TestWorkerPHPVersionReadsBothForms(t *testing.T) {
	for out, want := range map[string]string{
		"FrankenPHP v1.13.5 PHP 8.5.12 Caddy v2.11.9\n":                                            "8.5",
		"FrankenPHP v1.13.5 PHP 8.5 Caddy v2.11.9 h1:bhZtcRKQOq0fuLvEgCdPYvUxXyGn9kzjG4lh2332DTE=": "8.5",
	} {
		env, dr := testEnv(t)
		dr.Responder = func(executor.Call) (executor.Result, error) { return executor.Result{Stdout: out}, nil }
		got, err := workerPHPVersion(context.Background(), env)
		if err != nil || got != want {
			t.Errorf("%q: got %q, %v; want %q", out, got, err, want)
		}
	}
}
