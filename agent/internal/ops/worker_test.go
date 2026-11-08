package ops

import "testing"

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
