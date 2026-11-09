package ops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// fakePythons lays out uv-managed interpreters as `uv python install` does.
func fakePythons(t *testing.T, env *Env, dirs ...string) {
	t.Helper()
	env.Cfg.PythonDir = t.TempDir()
	for _, d := range dirs {
		minor := strings.SplitN(strings.TrimPrefix(d, "cpython-"), ".", 3)
		bin := filepath.Join(env.Cfg.PythonDir, d, "bin", "python"+minor[0]+"."+minor[1])
		if err := os.MkdirAll(filepath.Dir(bin), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(bin, nil, 0o755); err != nil {
			t.Fatal(err)
		}
	}
}

func TestPythonVersions(t *testing.T) {
	env, _ := testEnv(t)
	fakePythons(t, env, "cpython-3.13.1-linux-x86_64-gnu", "cpython-3.13.9-linux-x86_64-gnu",
		"cpython-3.12.4-linux-aarch64-gnu", "cpython-3.14.0+freethreaded-linux-x86_64-gnu")
	vs := pythonVersions(env.Cfg)
	if !strings.Contains(vs["3.13"], "cpython-3.13.9-") || !strings.HasSuffix(vs["3.13"], "/bin/python3.13") || len(vs) != 2 {
		t.Fatalf("versions: %v", vs)
	}
	if got := strings.Join(pythonMinors(env.Cfg), ","); got != "3.12,3.13" {
		t.Fatalf("minors: %s", got)
	}
	// uv's link per minor version wins: virtualenvs survive patch upgrades.
	link := filepath.Join(env.Cfg.PythonDir, "cpython-3.13-linux-x86_64-gnu")
	if err := os.Symlink(filepath.Join(env.Cfg.PythonDir, "cpython-3.13.1-linux-x86_64-gnu"), link); err != nil {
		t.Fatal(err)
	}
	if vs := pythonVersions(env.Cfg); vs["3.13"] != link+"/bin/python3.13" {
		t.Fatalf("with the minor link: %v", vs)
	}
}

func TestPythonApp(t *testing.T) {
	for app, ok := range map[string]bool{
		"mysite.wsgi:application": true, "main:app": true, "app.api:create": true,
		"main:create_app()": false, "a:b c": false, "../x:app": false, "main": false, "main:app;rm": false, "%h:app": false,
	} {
		if err := validPythonApp(app); (err == nil) != ok {
			t.Errorf("%q: %v", app, err)
		}
	}
	manage := []byte("def main():\n    os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'mysite.settings')\n")
	if got := djangoApp(manage); got != "mysite.wsgi:application" {
		t.Errorf("django app: %q", got)
	}
	if got := djangoApp([]byte(`os.environ["DJANGO_SETTINGS_MODULE"] = "x"`)); got != "" {
		t.Errorf("no project: %q", got)
	}
}

func TestPythonModeSite(t *testing.T) {
	env, dr := testEnv(t)
	fakePythons(t, env, "cpython-3.13.9-linux-x86_64-gnu", "cpython-3.12.4-linux-x86_64-gnu")
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "p.example.com", "framework": "laravel", "mode": "python", "python_version": "3.13"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("laravel in python mode: %v", err)
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "p.example.com", "framework": "django", "mode": "python", "python_version": "3.11"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("Python not installed: %v", err)
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "p.example.com", "framework": "php", "python_version": "3.13"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("python_version on a PHP site: %v", err)
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "p.example.com", "framework": "flask", "mode": "python", "python_version": "3.13", "python_app": "x:y()"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("bad python_app: %v", err)
	}

	// Plain Python: a placeholder app.py in its own virtualenv serves at once.
	dr.Calls()
	out, err := call(t, env, siteCreate, map[string]string{"domain": "p.example.com", "framework": "python", "mode": "python", "python_version": "3.13"})
	if err != nil {
		t.Fatal(err)
	}
	o := out.(map[string]any)
	port := o["port"].(int)
	if port < env.Cfg.NodePortMin || port > env.Cfg.NodePortMax || o["python_version"] != "3.13" {
		t.Fatalf("output: %v", o)
	}
	home := filepath.Join(env.Cfg.SitesRoot, "p.example.com")
	user := "web_p_example_com"
	if !fsx.Exists(filepath.Join(home, "releases/initial/app.py")) || fsx.Exists(filepath.Join(home, "releases/initial/public")) {
		t.Error("placeholder: app.py, and no public/")
	}
	venv := false
	for _, c := range dr.Calls() {
		a := strings.Join(c.Args, " ")
		if c.Name == "setpriv" && strings.Contains(a, "--reuid="+user) && strings.Contains(a, env.Cfg.UVBin+" ") &&
			strings.Contains(a, "venv --python "+env.Cfg.PythonDir+"/cpython-3.13.9-linux-x86_64-gnu/bin/python3.13 "+home+"/releases/initial/.venv") {
			venv = true
		}
	}
	if !venv {
		t.Error("the placeholder's virtualenv is not created as the site user")
	}
	unit := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-app.service"))
	for _, want := range []string{
		"ExecStart=" + home + "/current/.venv/bin/python app.py", "User=" + user, "Environment=PORT=", "Environment=HOST=127.0.0.1",
		"SocketBindDeny=any", "ProtectSystem=strict",
	} {
		if !strings.Contains(unit, want) {
			t.Errorf("unit missing %q:\n%s", want, unit)
		}
	}
	if edge := read(t, filepath.Join(env.Cfg.ProxySitesDir, "p.example.com.caddy")); !strings.Contains(edge, "reverse_proxy 127.0.0.1:") {
		t.Errorf("edge:\n%s", edge)
	}

	// Django: gunicorn from the release's virtualenv, the project's WSGI app.
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "d.example.com", "framework": "django", "mode": "python", "python_version": "3.12"}); err != nil {
		t.Fatal(err)
	}
	duser := "web_d_example_com"
	dunit := filepath.Join(env.Cfg.SystemdDir, "wspanel-"+duser+"-app.service")
	if u := read(t, dunit); !strings.Contains(u, "/current/.venv/bin/python -m gunicorn --bind 127.0.0.1:") || !strings.Contains(u, " config.wsgi:application") {
		t.Errorf("django unit:\n%s", u)
	}
	if _, err := call(t, env, sitePythonApp, map[string]string{"domain": "d.example.com", "app": "shop.wsgi:application"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, dunit); !strings.Contains(u, " shop.wsgi:application") {
		t.Errorf("after site.python_app:\n%s", u)
	}
	if _, err := call(t, env, sitePythonApp, map[string]string{"domain": "p.example.com", "app": "main:app"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("python_app on a plain Python site: %v", err)
	}

	// FastAPI runs under uvicorn.
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "f.example.com", "framework": "fastapi", "mode": "python", "python_version": "3.13"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-web_f_example_com-app.service")); !strings.Contains(u, "/current/.venv/bin/python -m uvicorn --host 127.0.0.1 --port ") || !strings.Contains(u, " main:app") {
		t.Errorf("fastapi unit:\n%s", u)
	}

	// Version switch rewrites the unit's PATH.
	if _, err := call(t, env, siteSetVersion, map[string]string{"domain": "d.example.com", "version": "3.13"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, dunit); !strings.Contains(u, "cpython-3.13.9-linux-x86_64-gnu/bin") {
		t.Errorf("after set_version:\n%s", u)
	}

	// No PHP here: settings, PHP tasks.
	if _, err := call(t, env, sitePHP, map[string]any{"domain": "d.example.com", "settings": map[string]string{"memory_limit": "256M"}}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("PHP settings on a Python site: %v", err)
	}
	if _, err := call(t, env, cronAdd, map[string]any{"domain": "d.example.com", "kind": "artisan", "args": []string{"inspire"}, "schedule": "* * * * *"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("artisan task on a Python site: %v", err)
	}
	if _, err := call(t, env, cronAdd, map[string]any{"domain": "d.example.com", "kind": "manage", "args": []string{"clearsessions"}, "schedule": "0 3 * * *"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+duser+"-cron-1.service")); !strings.Contains(u, "ExecStart="+env.Cfg.SitesRoot+"/d.example.com/current/.venv/bin/python manage.py clearsessions") {
		t.Errorf("manage task:\n%s", u)
	}
	if _, err := call(t, env, cronAdd, map[string]any{"domain": "f.example.com", "kind": "manage", "args": []string{"x"}, "schedule": "0 3 * * *"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("manage on FastAPI: %v", err)
	}
	if _, err := call(t, env, cronAdd, map[string]any{"domain": "f.example.com", "kind": "python", "args": []string{"../x.py"}, "schedule": "0 3 * * *"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("script outside the release: %v", err)
	}
	if _, err := call(t, env, cronAdd, map[string]any{"domain": "f.example.com", "kind": "python", "args": []string{"tasks/cleanup.py"}, "schedule": "0 3 * * *"}); err != nil {
		t.Fatal(err)
	}

	// SSH: python is the release's virtualenv.
	if l, err := os.Readlink(filepath.Join(env.Cfg.ToolsDir, duser, "python")); err != nil || l != env.Cfg.SitesRoot+"/d.example.com/current/.venv/bin/python" {
		t.Errorf("python link: %q %v", l, err)
	}

	// Python -> Node: the app unit stays (other command), slots go with the mode.
	if _, err := call(t, env, siteSwitchMode, map[string]string{"domain": "p.example.com", "mode": "node", "framework": "node", "node_version": "22"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+user+"-app.service")); !strings.Contains(u, "/bin/node server.js") {
		t.Errorf("after switch to node:\n%s", u)
	}
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "d.example.com", "confirm": "d.example.com"}); err != nil {
		t.Fatal(err)
	}
	if fsx.Exists(dunit) {
		t.Error("app unit left after delete")
	}
}

func TestPythonInstallValidates(t *testing.T) {
	env, _ := testEnv(t)
	fakePythons(t, env, "cpython-3.13.9-linux-x86_64-gnu")
	if _, err := call(t, env, pythonInstall, map[string]string{"version": "2.7"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("python 2.7: %v", err)
	}
	out, err := call(t, env, pythonInstall, map[string]string{"version": "3.13"})
	if err != nil || out.(map[string]any)["changed"] != false {
		t.Errorf("already installed: %v %v", out, err)
	}
	if len(uvSHA256) != 2 || len(uvSHA256["x86_64"]) != 64 || len(uvSHA256["aarch64"]) != 64 {
		t.Error("uv checksums")
	}
}

func TestRestoreBringsBackTheSitesPython(t *testing.T) {
	env, _ := testEnv(t)
	fakePythons(t, env, "cpython-3.13.9-linux-x86_64-gnu", "cpython-3.12.4-linux-x86_64-gnu")
	for _, d := range []string{"a.example.com", "b.example.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "flask", "mode": "python", "python_version": "3.12"}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "c.example.com", "framework": "fastapi", "mode": "python", "python_version": "3.13"}); err != nil {
		t.Fatal(err)
	}
	// A new server whose installer brought only 3.13.
	fakePythons(t, env, "cpython-3.13.10-linux-x86_64-gnu")
	if got := strings.Join(missingPythons(env), ","); got != "3.12" {
		t.Fatalf("missing: %q", got)
	}
}

func TestPythonCeleryWorkers(t *testing.T) {
	env, dr := testEnv(t)
	fakePythons(t, env, "cpython-3.13.9-linux-x86_64-gnu")
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "d.example.com", "framework": "django", "mode": "python", "python_version": "3.13"}); err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(env.Cfg.SitesRoot, "d.example.com")
	duser := "web_d_example_com"
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "d.example.com", "worker": "queue"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("a Laravel queue on Django: %v", err)
	}
	dr.Calls()
	out, err := call(t, env, workerCreate, map[string]string{"domain": "d.example.com", "worker": "celery"})
	if err != nil {
		t.Fatal(err)
	}
	if out.(map[string]any)["app"] != "config" {
		t.Errorf("Django's Celery app: %v", out)
	}
	tmpl := filepath.Join(env.Cfg.SystemdDir, "wspanel-"+duser+"-celery@.service")
	u := read(t, tmpl)
	for _, want := range []string{
		"ExecStart=" + home + "/releases/%i/.venv/bin/python -m celery -A config worker --loglevel=INFO --concurrency=",
		"WorkingDirectory=" + home + "/releases/%i", "Environment=VIRTUAL_ENV=" + home + "/releases/%i/.venv",
		"TimeoutStopSec=3600", "SocketBindDeny=any",
	} {
		if !strings.Contains(u, want) {
			t.Errorf("celery unit missing %q:\n%s", want, u)
		}
	}
	// systemd checks a template as instance "test_instance", which has no
	// .venv: the current release's instance is checked instead.
	verified := false
	for _, c := range dr.Calls() {
		if c.Name == "systemd-analyze" && strings.HasSuffix(strings.Join(c.Args, " "), "wspanel-"+duser+"-celery@initial.service") {
			verified = true
		}
	}
	if !verified {
		t.Error("the celery template is not verified through the current release's instance")
	}
	// The project named on the site moves Celery along.
	if _, err := call(t, env, sitePythonApp, map[string]string{"domain": "d.example.com", "app": "shop.wsgi:application"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, tmpl); !strings.Contains(u, " -A shop worker ") {
		t.Errorf("after site.python_app:\n%s", u)
	}

	// Flask has no convention: the app is needed, then kept for the site.
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "f.example.com", "framework": "flask", "mode": "python", "python_version": "3.13"}); err != nil {
		t.Fatal(err)
	}
	fhome := filepath.Join(env.Cfg.SitesRoot, "f.example.com")
	fuser := "web_f_example_com"
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "f.example.com", "worker": "celery"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("Flask celery without an app: %v", err)
	}
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "f.example.com", "worker": "celery", "app": "x; rm -rf /"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("bad app: %v", err)
	}
	if fsx.Exists(filepath.Join(env.Cfg.SystemdDir, "wspanel-"+fuser+"-celery@.service")) {
		t.Error("a refused worker left its unit")
	}
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "f.example.com", "worker": "celery", "app": "tasks:celery"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "f.example.com", "worker": "celerybeat"}); err != nil {
		t.Fatal(err)
	}
	beat := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+fuser+"-celerybeat@.service"))
	if !strings.Contains(beat, " -A tasks:celery beat --loglevel=INFO --schedule="+fhome+"/tmp/celerybeat-schedule") || strings.Contains(beat, "TimeoutStopSec=3600") {
		t.Errorf("beat unit:\n%s", beat)
	}
	// Another app given: both workers follow it.
	if _, err := call(t, env, workerDelete, map[string]string{"domain": "f.example.com", "worker": "celerybeat"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, workerCreate, map[string]string{"domain": "f.example.com", "worker": "celerybeat", "app": "app.worker"}); err != nil {
		t.Fatal(err)
	}
	if u := read(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-"+fuser+"-celery@.service")); !strings.Contains(u, " -A app.worker worker ") {
		t.Errorf("celery after the app changed:\n%s", u)
	}
	st, _, _ := loadSite(env, "f.example.com")
	if st.PythonCelery != "app.worker" {
		t.Errorf("stored app: %q", st.PythonCelery)
	}
	// The workers go with the site.
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "f.example.com", "confirm": "f.example.com"}); err != nil {
		t.Fatal(err)
	}
	if fsx.Exists(filepath.Join(env.Cfg.SystemdDir, "wspanel-"+fuser+"-celery@.service")) {
		t.Error("celery unit left after delete")
	}
}

func TestPythonUpgrade(t *testing.T) {
	env, dr := testEnv(t)
	fakePythons(t, env, "cpython-3.13.1-linux-x86_64-gnu", "cpython-3.13.9-linux-x86_64-gnu", "cpython-3.12.4-linux-x86_64-gnu")
	link := filepath.Join(env.Cfg.PythonDir, "cpython-3.13-linux-x86_64-gnu")
	if err := os.Symlink(filepath.Join(env.Cfg.PythonDir, "cpython-3.13.1-linux-x86_64-gnu"), link); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, pythonUpgrade, map[string]string{"version": "3.14"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("not installed: %v", err)
	}
	if _, err := call(t, env, pythonUpgrade, map[string]string{"version": "3.12"}); code(err) != protocol.CodeConflict {
		t.Errorf("no minor link: %v", err)
	}
	out, err := call(t, env, pythonUpgrade, map[string]string{"version": "3.13"})
	if err != nil || out.(map[string]any)["from"] != "3.13.1" {
		t.Fatalf("dry run: %v %v", out, err)
	}
	for _, d := range []string{"a.example.com", "b.example.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "flask", "mode": "python", "python_version": "3.13"}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "c.example.com", "framework": "flask", "mode": "python", "python_version": "3.12"}); err != nil {
		t.Fatal(err)
	}
	// uv repointed the link at 3.13.9.
	if err := os.Remove(link); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(env.Cfg.PythonDir, "cpython-3.13.9-linux-x86_64-gnu"), link); err != nil {
		t.Fatal(err)
	}
	if got := pythonPatch(env.Cfg, "3.13"); got != "cpython-3.13.9-linux-x86_64-gnu" {
		t.Fatalf("patch: %q", got)
	}
	dr.Calls()
	restarted, removed := afterPythonUpgrade(context.Background(), env, "3.13", "cpython-3.13.1-linux-x86_64-gnu")
	if strings.Join(restarted, ",") != "wspanel-web_a_example_com-app.service,wspanel-web_b_example_com-app.service" || removed != "3.13.1" {
		t.Errorf("restarted %v, removed %q", restarted, removed)
	}
	if fsx.Exists(filepath.Join(env.Cfg.PythonDir, "cpython-3.13.1-linux-x86_64-gnu")) {
		t.Error("the old patch build stays")
	}
	// A virtualenv that names a patch build directly keeps it.
	venv := filepath.Join(env.Cfg.SitesRoot, "c.example.com", "releases", "initial", ".venv")
	if err := os.MkdirAll(venv, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(venv, "pyvenv.cfg"), []byte("home = "+env.Cfg.PythonDir+"/cpython-3.12.4-linux-x86_64-gnu/bin\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, removed := afterPythonUpgrade(context.Background(), env, "3.12", "cpython-3.12.4-linux-x86_64-gnu"); removed != "" {
		t.Errorf("a patch build in use was removed")
	}
}

// A restored release lost .venv/bin/python (a link outside the home) and the
// old server's boot links name releases that are not here.
func TestRestoredPythonReleaseRuns(t *testing.T) {
	env, dr := testEnv(t)
	fakePythons(t, env, "cpython-3.13.9-linux-x86_64-gnu")
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "d.example.com", "framework": "django", "mode": "python", "python_version": "3.13"}); err != nil {
		t.Fatal(err)
	}
	_, l, _ := loadSite(env, "d.example.com")
	venv := filepath.Join(l.Home, "releases", "20261109T125026Z", ".venv")
	if err := os.MkdirAll(filepath.Join(venv, "bin"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(venv, "pyvenv.cfg"), []byte("home = x\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	dr.Calls()
	if err := relinkPythonVenv(context.Background(), env, l, "20261109T125026Z"); err != nil {
		t.Fatal(err)
	}
	relinked := false
	for _, c := range dr.Calls() {
		a := strings.Join(c.Args, " ")
		if c.Name == "setpriv" && strings.Contains(a, "--reuid="+l.User) &&
			strings.HasSuffix(a, "/usr/bin/ln -sfn -- "+env.Cfg.PythonDir+"/cpython-3.13.9-linux-x86_64-gnu/bin/python3.13 "+venv+"/bin/python") {
			relinked = true
		}
	}
	if !relinked {
		t.Error("the restored .venv is not pointed at the site's Python")
	}
	// A release without a virtualenv, or another mode: nothing to do.
	before := len(dr.Calls())
	if err := relinkPythonVenv(context.Background(), env, l, "initial"); err != nil || len(dr.Calls()) != before {
		t.Errorf("no .venv: %v", err)
	}

	wants := filepath.Join(env.Cfg.SystemdDir, "multi-user.target.wants")
	if err := os.MkdirAll(wants, 0o755); err != nil {
		t.Fatal(err)
	}
	for _, rel := range []string{"20261109T124306Z", "initial"} {
		if err := os.Symlink("../x", filepath.Join(wants, "wspanel-"+l.User+"-celery@"+rel+".service")); err != nil {
			t.Fatal(err)
		}
	}
	before = len(dr.Calls())
	dropGoneReleases(context.Background(), env, l, "celery", "wspanel-"+l.User+"-celery@20261109T125026Z.service")
	var disabled []string
	for _, c := range dr.Calls()[before:] {
		if c.Name == "systemctl" && len(c.Args) == 2 && c.Args[0] == "disable" {
			disabled = append(disabled, c.Args[1])
		}
	}
	if strings.Join(disabled, ",") != "wspanel-"+l.User+"-celery@20261109T124306Z.service" {
		t.Errorf("disabled %v: only the instance of the release that is gone", disabled)
	}
}
