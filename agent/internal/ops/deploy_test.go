package ops

import (
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

// fakeRepo makes "git clone" in the dry-run executor create a release with
// the given files, and lets a step fail on demand.
func fakeRepo(files []string, failStep string) func(executor.Call) (executor.Result, error) {
	return func(c executor.Call) (executor.Result, error) {
		joined := strings.Join(c.Args, " ")
		if c.Name == "systemd-run" && strings.Contains(joined, "/usr/bin/git clone") {
			dir := c.Args[len(c.Args)-1]
			for _, f := range files {
				p := filepath.Join(dir, f)
				_ = os.MkdirAll(filepath.Dir(p), 0o755)
				_ = os.WriteFile(p, []byte("{}"), 0o644)
			}
		}
		if failStep != "" && c.Name == "systemd-run" && strings.Contains(joined, failStep) {
			return executor.Result{ExitCode: 1}, errors.New("exit status 1")
		}
		if c.Name == "setpriv" && strings.Contains(joined, "rev-parse") {
			return executor.Result{Stdout: "0123456789abcdef0123456789abcdef01234567\n"}, nil
		}
		return frankenVersion(c)
	}
}

func deployEnv(t *testing.T, framework string) *Env {
	env, _ := testEnv(t)
	env.Cfg.DeployLogDir = filepath.Join(t.TempDir(), "deploys")
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.com", "framework": framework, "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, deployConfigure, map[string]any{"domain": "app.example.com", "repo": "git@github.com:acme/app.git", "branch": "main",
		"migrate": true, "assets": true, "auto_rollback": true, "keep": 5}); err != nil {
		t.Fatal(err)
	}
	return env
}

func TestDeployConfigValidation(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	bad := []map[string]any{
		{"repo": "https://user:token@github.com/acme/app.git", "branch": "main"},
		{"repo": "--upload-pack=touch /tmp/x", "branch": "main"},
		{"repo": "file:///etc", "branch": "main"},
		{"repo": "https://github.com/acme/../../x", "branch": "main"},
		{"repo": "git@github.com:acme/app.git", "branch": "-b"},
		{"repo": "git@github.com:acme/app.git", "branch": "main; id"},
		{"repo": "git@github.com:acme/app.git", "branch": "main", "keep": 1},
	}
	for _, b := range bad {
		b["domain"] = "app.example.com"
		if _, ok := b["keep"]; !ok {
			b["keep"] = 5
		}
		if _, err := call(t, env, deployConfigure, b); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: %v", b, err)
		}
	}
	for _, ok := range []string{"https://github.com/acme/app.git", "git@gitlab.com:group/sub/app.git", "ssh://git@git.example.com:2222/app.git"} {
		if _, err := call(t, env, deployConfigure, map[string]any{"domain": "app.example.com", "repo": ok, "branch": "release/1.2", "keep": 5}); err != nil {
			t.Errorf("%s: %v", ok, err)
		}
	}
}

func TestDeployLaravelPipeline(t *testing.T) {
	env := deployEnv(t, "laravel")
	dr := env.Exec.(*executor.DryRun)
	dr.Responder = fakeRepo([]string{"composer.json", "package.json", "package-lock.json", "artisan"}, "")
	before := len(dr.Calls())
	out, err := call(t, env, deployRun, map[string]string{"domain": "app.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	rec := out.(deployRecord)
	if rec.Status != "success" || rec.Commit == "" {
		t.Fatalf("record: %+v", rec)
	}
	var steps []string
	for _, c := range dr.Calls()[before:] {
		joined := strings.Join(c.Args, " ")
		if c.Name == "systemd-run" {
			// Every build step: site user, sandbox, no root.
			for _, want := range []string{"--uid=web_app_example_com", "ProtectSystem=strict", "NoNewPrivileges=yes", "CapabilityBoundingSet=", "ReadWritePaths=" + env.Cfg.SitesRoot + "/app.example.com"} {
				if !strings.Contains(joined, want) {
					t.Errorf("step without %q: %s", want, joined)
				}
			}
			cmd := joined[strings.Index(joined, " -- ")+4:]
			steps = append(steps, strings.Fields(cmd)[1])
		}
		if c.Name == "git" || c.Name == "composer" || c.Name == "npm" {
			t.Errorf("build tool run directly by the agent: %s", c)
		}
	}
	want := []string{"clone", "/usr/bin/composer", "ci", "run", "artisan", "artisan", "artisan"}
	if strings.Join(steps, " ") != strings.Join(want, " ") {
		t.Errorf("steps %v, want %v", steps, want)
	}
	var switched, reloaded bool
	for _, c := range dr.Calls()[before:] {
		j := strings.Join(c.Args, " ")
		if c.Name == "setpriv" && strings.Contains(j, "/usr/bin/mv -T -f -- ") && strings.HasSuffix(j, "/current") {
			switched = true
		}
		if c.String() == "systemctl reload php8.4-fpm.service" {
			reloaded = true
		}
	}
	if !switched || !reloaded {
		t.Errorf("switched=%v reloaded=%v", switched, reloaded)
	}
	h, _ := call(t, env, deployHistory, map[string]string{"domain": "app.example.com"})
	if d := h.(map[string]any)["deploys"].([]deployRecord); len(d) != 1 || d[0].Status != "success" {
		t.Errorf("history: %+v", d)
	}
	logOut, err := call(t, env, deployLog, map[string]any{"domain": "app.example.com", "id": rec.ID, "offset": 0})
	if err != nil || !strings.Contains(logOut.(map[string]any)["content"].(string), "==> Composer") {
		t.Errorf("log: %v %v", logOut, err)
	}
}

func TestDeployFailureKeepsCurrent(t *testing.T) {
	env := deployEnv(t, "laravel")
	dr := env.Exec.(*executor.DryRun)
	dr.Responder = fakeRepo([]string{"composer.json", "artisan"}, "/usr/bin/composer")
	before := len(dr.Calls())
	if _, err := call(t, env, deployRun, map[string]string{"domain": "app.example.com"}); err == nil {
		t.Fatal("want failure")
	}
	var removed bool
	for _, c := range dr.Calls()[before:] {
		j := strings.Join(c.Args, " ")
		if c.Name == "setpriv" && strings.Contains(j, "/usr/bin/mv -T") {
			t.Fatal("current must not be switched after a failed build")
		}
		if c.Name == "setpriv" && strings.Contains(j, "/usr/bin/rm -rf -- "+env.Cfg.SitesRoot+"/app.example.com/releases/2") {
			removed = true
		}
		if strings.Contains(j, "artisan migrate") {
			t.Fatal("steps after the failure must not run")
		}
	}
	if !removed {
		t.Error("failed release not cleaned up")
	}
	if h := loadHistory(env, "app.example.com"); h[len(h)-1].Status != "failed" {
		t.Errorf("history: %+v", h)
	}
}

func TestDeploySymfonySteps(t *testing.T) {
	env := deployEnv(t, "symfony")
	dr := env.Exec.(*executor.DryRun)
	dr.Responder = fakeRepo([]string{"composer.json", "importmap.php", "config/packages/doctrine_migrations.yaml"}, "")
	before := len(dr.Calls())
	if _, err := call(t, env, deployRun, map[string]string{"domain": "app.example.com"}); err != nil {
		t.Fatal(err)
	}
	var cmds []string
	for _, c := range dr.Calls()[before:] {
		if c.Name == "systemd-run" {
			j := strings.Join(c.Args, " ")
			cmds = append(cmds, j[strings.Index(j, " -- ")+4:])
		}
	}
	all := strings.Join(cmds, "\n")
	for _, want := range []string{"bin/console doctrine:migrations:migrate --no-interaction", "bin/console asset-map:compile", "bin/console cache:warmup"} {
		if !strings.Contains(all, want) {
			t.Errorf("missing %q in\n%s", want, all)
		}
	}
	if strings.Contains(all, "artisan") {
		t.Error("artisan on a Symfony site")
	}
}

func TestOneDeployAtATime(t *testing.T) {
	env := deployEnv(t, "php")
	if !beginDeploy("app.example.com") {
		t.Fatal("first begin")
	}
	defer endDeploy("app.example.com")
	if _, err := call(t, env, deployRun, map[string]string{"domain": "app.example.com"}); code(err) != protocol.CodeConflict {
		t.Errorf("concurrent deploy: %v", err)
	}
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "app.example.com", "confirm": "app.example.com"}); code(err) != protocol.CodeConflict {
		t.Errorf("delete during deploy: %v", err)
	}
	if _, err := call(t, env, siteSetVersion, map[string]string{"domain": "app.example.com", "version": "8.3"}); code(err) != protocol.CodeConflict {
		t.Errorf("version switch during deploy: %v", err)
	}
	if _, err := call(t, env, deployRollback, map[string]string{"domain": "app.example.com"}); code(err) != protocol.CodeConflict {
		t.Errorf("rollback during deploy: %v", err)
	}
}

func TestDeleteRemovesDeployHistory(t *testing.T) {
	env := deployEnv(t, "php")
	env.Exec.(*executor.DryRun).Responder = fakeRepo(nil, "")
	if _, err := call(t, env, deployRun, map[string]string{"domain": "app.example.com"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "app.example.com", "confirm": "app.example.com"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(historyPath(env, "app.example.com")); !os.IsNotExist(err) {
		t.Error("deploy history kept after delete")
	}
	if logs, _ := filepath.Glob(filepath.Join(env.Cfg.DeployLogDir, "app.example.com-*.log")); len(logs) != 0 {
		t.Errorf("deploy logs kept: %v", logs)
	}
	if deployInProgress("app.example.com") {
		t.Error("claim leaked")
	}
}

func TestListReleasesOrder(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "r.example.com", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	_, l, _ := loadSite(env, "r.example.com")
	for _, r := range []string{"20261107T120000Z", "20261106T090000Z"} {
		_ = os.MkdirAll(filepath.Join(l.Home, "releases", r), 0o755)
	}
	if got := listReleases(l); !slices.Equal(got, []string{"initial", "20261106T090000Z", "20261107T120000Z"}) {
		t.Errorf("order: %v", got)
	}
}

func TestEnvSet(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "e.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, envSet, map[string]string{"domain": "e.example.com", "content": "A=1\x00"}); code(err) != protocol.CodeInvalidParams {
		t.Error("NUL accepted")
	}
	before := len(dr.Calls())
	if _, err := call(t, env, envSet, map[string]string{"domain": "e.example.com", "content": "APP_KEY=base64:x"}); err != nil {
		t.Fatal(err)
	}
	var written string
	for _, c := range dr.Calls()[before:] {
		if c.Name == "setpriv" && c.Args[0] == "--reuid=web_e_example_com" && strings.Contains(strings.Join(c.Args, " "), "/usr/bin/tee") {
			written = c.Stdin
		}
	}
	if written != "APP_KEY=base64:x\n" {
		t.Errorf("written %q", written)
	}
}
