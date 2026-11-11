package ops

import (
	"os"
	"strings"
	"testing"
)

func TestRequestTerminateTimeoutFollowsMaxExecutionTime(t *testing.T) {
	for _, c := range []struct {
		lines []phpLine
		want  int
	}{
		{nil, 300},
		{[]phpLine{{Key: "max_execution_time", Value: "30"}}, 300},
		{[]phpLine{{Key: "max_execution_time", Value: "600"}}, 660},
		{[]phpLine{{Key: "max_execution_time", Value: "0"}}, 3600},
	} {
		if got := (siteLayout{PHPValues: c.lines}).RequestTerminateTimeout(); got != c.want {
			t.Errorf("%v: %d, want %d", c.lines, got, c.want)
		}
	}
}

func TestLaravelSiteIsServedFastAndKeptUpThroughRestarts(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "fast.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	l, _ := layoutFor(t, env, "fast.example.com")
	read := func(p string) string { b, _ := os.ReadFile(p); return string(b) }
	checks := map[string][]string{
		l.PoolFile: {"pm.process_idle_timeout = 120s", "request_terminate_timeout = 300s"},
		l.WebFile:  {"path /build/assets/*", `Cache-Control "public, max-age=31536000, immutable"`, "precompressed zstd br gzip", "lb_try_duration 5s"},
		l.EdgeFile: {"lb_try_duration 10s", "stream_close_delay 5m"},
	}
	for file, wants := range checks {
		got := read(file)
		for _, w := range wants {
			if !strings.Contains(got, w) {
				t.Errorf("%s lacks %q", file, w)
			}
		}
	}
	if strings.Contains(read(l.PoolFile), "\nslowlog =") {
		t.Error("a slowlog in the site's tree is opened by FPM's master as root")
	}
}

func TestPoolsAreRefreshedWithOneReloadPerVersion(t *testing.T) {
	env, dr := testEnv(t)
	for _, d := range []string{"p1.example.com", "p2.example.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "laravel", "php_version": "8.4"}); err != nil {
			t.Fatal(err)
		}
		l, _ := layoutFor(t, env, d)
		if err := os.WriteFile(l.PoolFile, []byte("; an older rendering\n"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	n := len(dr.Calls())
	changed, err := renderPools(t.Context(), env)
	if err != nil || changed != 2 {
		t.Fatalf("renderPools: %d, %v", changed, err)
	}
	reloads := 0
	for _, c := range dr.Calls()[n:] {
		if strings.Contains(c.String(), "reload php8.4-fpm") {
			reloads++
		}
	}
	if reloads != 1 {
		t.Errorf("%d reloads, want one", reloads)
	}
	if changed, _ := renderPools(t.Context(), env); changed != 0 {
		t.Errorf("unchanged pools rewritten: %d", changed)
	}
}

func TestNodeLoadsEnvFileFromVersion22_9(t *testing.T) {
	for dir, want := range map[string]bool{"v20.18.0": false, "v22.8.0": false, "v22.9.0": true, "v24.1.0": true} {
		if got := nodeLoadsEnvFile("/opt/fnm/node-versions/" + dir + "/installation/bin/node"); got != want {
			t.Errorf("%s: %v", dir, got)
		}
	}
}

// A client's site has no group named after its user (its primary group is
// the client's): the deploy sandbox must not name one.
func TestDeploySandboxNamesNoGroup(t *testing.T) {
	env, _ := testEnv(t)
	args := strings.Join(sandboxRunArgs(env, siteLayout{User: "web_x", Home: "/srv/sites/x"}, "/srv/sites/x", nil), " ")
	if strings.Contains(args, "--gid=") || !strings.Contains(args, "--uid=web_x") {
		t.Fatalf("sandbox: %s", args)
	}
}

func TestSSHAccessKeepsTheSitesOtherGroups(t *testing.T) {
	prev := supplementaryGroups
	supplementaryGroups = func(string) []string { return []string{"wspanel-sftp", "wsd_3"} }
	t.Cleanup(func() { supplementaryGroups = prev })
	if got := strings.Join(sshGroupsFor("web_x", "wspanel-ssh", "wspanel-ssh", "wspanel-sftp"), ","); got != "wsd_3,wspanel-ssh" {
		t.Errorf("shell: %s", got)
	}
	if got := strings.Join(sshGroupsFor("web_x", "", "wspanel-ssh", "wspanel-sftp"), ","); got != "wsd_3" {
		t.Errorf("off: %s", got)
	}
}
