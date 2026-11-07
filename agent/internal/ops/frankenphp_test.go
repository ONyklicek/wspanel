package ops

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

func frankenEnv(t *testing.T, payload []byte, digest string, failUnit string) (*Env, *executor.DryRun) {
	env, dr := testEnv(t)
	env.Cfg.FrankenPHPBin = filepath.Join(t.TempDir(), "frankenphp")
	if err := os.WriteFile(env.Cfg.FrankenPHPBin, []byte("OLD"), 0o755); err != nil {
		t.Fatal(err)
	}
	oldFetch, oldDownload := fetchRelease, download
	t.Cleanup(func() { fetchRelease, download = oldFetch, oldDownload })
	fetchRelease = func(context.Context, string, string) (releaseAsset, error) {
		return releaseAsset{URL: "https://github.com/php/frankenphp/releases/download/v1.14.0/frankenphp-linux-x86_64", SHA256: digest}, nil
	}
	download = func(_ context.Context, _ string, w io.Writer) error { _, err := w.Write(payload); return err }
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch {
		case c.Name == "frankenphp" && c.Args[0] == "version":
			return executor.Result{Stdout: "FrankenPHP v1.13.5 PHP 8.5.12 Caddy v2.11.9"}, nil
		case c.Name == "frankenphp-new" && c.Args[0] == "version":
			return executor.Result{Stdout: "FrankenPHP v1.14.0 PHP 8.5.13 Caddy v2.11.10"}, nil
		case c.Name == "systemctl" && c.Args[0] == "is-active":
			if c.Args[1] == failUnit {
				return executor.Result{Stdout: "failed\n"}, errors.New("exit status 3")
			}
			return executor.Result{Stdout: "active\n"}, nil
		}
		return executor.Result{}, nil
	}
	return env, dr
}

func sum(b []byte) string { s := sha256.Sum256(b); return hex.EncodeToString(s[:]) }

func TestFrankenphpUpdateSwapsVerifiedBinary(t *testing.T) {
	env, dr := frankenEnv(t, []byte("NEW"), sum([]byte("NEW")), "")
	if _, err := call(t, env, frankenphpUpdate, map[string]string{"version": "1.14.0"}); code(err) != protocol.CodeInvalidParams {
		t.Fatal("version without v accepted")
	}
	out, err := call(t, env, frankenphpUpdate, map[string]string{"version": "v1.14.0"})
	if err != nil {
		t.Fatal(err)
	}
	if read(t, env.Cfg.FrankenPHPBin) != "NEW" || read(t, env.Cfg.FrankenPHPBin+".prev") != "OLD" {
		t.Fatal("binary not swapped / old not kept")
	}
	var validated, restartedEdge bool
	for _, c := range dr.Calls() {
		j := c.String()
		if strings.HasPrefix(j, "frankenphp-new validate --config /etc/panel-proxy/Caddyfile") {
			validated = true
		}
		if j == "systemctl restart panel-proxy.service" {
			restartedEdge = true
		}
	}
	if !validated || !restartedEdge || out.(map[string]any)["changed"] != true {
		t.Errorf("validated=%v restarted=%v out=%v", validated, restartedEdge, out)
	}
}

func TestFrankenphpUpdateRejectsChecksumMismatch(t *testing.T) {
	env, _ := frankenEnv(t, []byte("TAMPERED"), sum([]byte("NEW")), "")
	if _, err := call(t, env, frankenphpUpdate, map[string]string{"version": "v1.14.0"}); err == nil || !strings.Contains(err.Error(), "checksum mismatch") {
		t.Fatalf("got %v", err)
	}
	if read(t, env.Cfg.FrankenPHPBin) != "OLD" {
		t.Fatal("binary changed despite mismatch")
	}
	if _, err := os.Stat(env.Cfg.FrankenPHPBin + ".new"); !os.IsNotExist(err) {
		t.Error("candidate left behind")
	}
}

func TestFrankenphpUpdateRollsBackWhenEdgeFails(t *testing.T) {
	env, _ := frankenEnv(t, []byte("NEW"), sum([]byte("NEW")), "panel-proxy.service")
	if _, err := call(t, env, frankenphpUpdate, map[string]string{"version": "v1.14.0"}); err == nil || !strings.Contains(err.Error(), "restored") {
		t.Fatalf("got %v", err)
	}
	if read(t, env.Cfg.FrankenPHPBin) != "OLD" {
		t.Fatal("old binary not restored")
	}
}

func TestFrankenUnitsSkipScheduledTasks(t *testing.T) {
	env, _ := testEnv(t)
	for name, exec := range map[string]string{
		"wspanel-web_a_com-app.service":     "ExecStart=/usr/local/bin/frankenphp run --config x",
		"wspanel-web_a_com-horizon.service": "ExecStart=/usr/local/bin/frankenphp php-cli artisan horizon",
		"wspanel-web_a_com-cron-1.service":  "ExecStart=/usr/local/bin/frankenphp php-cli artisan inspire",
		"wspanel-web_b_com-queue.service":   "ExecStart=/usr/bin/php8.4 artisan queue:work",
	} {
		if err := os.WriteFile(filepath.Join(env.Cfg.SystemdDir, name), []byte(exec+"\n"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	got := strings.Join(frankenUnits(env), " ")
	if !strings.Contains(got, "-app.service") || !strings.Contains(got, "-horizon.service") || strings.Contains(got, "cron") || strings.Contains(got, "web_b_com") {
		t.Errorf("units: %s", got)
	}
}
