package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// FrankenPHP's php-cli crashes when a PHP signal handler runs, so workers of a
// worker-mode site use a system CLI: the site's version, else the newest one.
func TestWorkerPHPInWorkerMode(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.PHPVersions = []string{"8.3", "8.10", "8.4"}
	cases := map[string]siteLayout{
		"/usr/bin/php8.3":  {Mode: "worker", PHPVersion: "8.3"},
		"/usr/bin/php8.10": {Mode: "worker", PHPVersion: "8.2"},
		"/usr/bin/php8.2":  {Mode: "classic", PHPVersion: "8.2"},
	}
	for want, l := range cases {
		if got := workerPHP(env, l); got != want {
			t.Errorf("%+v: %s, want %s", l, got, want)
		}
	}
	env.Cfg.PHPVersions = nil
	if got := workerPHP(env, siteLayout{Mode: "worker"}); got != env.Cfg.FrankenPHPBin+" php-cli" {
		t.Errorf("no system PHP: %s", got)
	}
}

// With FrankenPHP from its packages, a worker-mode site's workers, tasks and
// shell `php` are its own PHP CLI (php-zts): the version and extensions the
// site runs, and no `frankenphp php-cli` (which crashes on PHP signals).
func TestFrankenPHPCLIFromPackages(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.PHPVersions = []string{"8.4"}
	cli := filepath.Join(t.TempDir(), "php-zts")
	if err := os.WriteFile(cli, []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	env.Cfg.FrankenPHPCLI = cli
	l := siteLayout{Mode: "worker", PHPVersion: "8.4", User: "web_a_com", Framework: "laravel"}
	if got := workerPHP(env, l); got != cli {
		t.Errorf("worker: %s, want %s", got, cli)
	}
	exec, _, err := cronExecStart(env, l, cronParams{Kind: "artisan", Args: []string{"inspire"}, Schedule: "* * * * *"})
	if err != nil || !strings.HasPrefix(exec, cli+" ") {
		t.Errorf("cron: %q %v", exec, err)
	}
	if got := workerPHP(env, siteLayout{Mode: "classic", PHPVersion: "8.4"}); got != "/usr/bin/php8.4" {
		t.Errorf("classic sites keep their own version: %s", got)
	}
}
