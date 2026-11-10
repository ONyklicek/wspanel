package ops

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"crypto/sha1"
	"crypto/sha256"
	"crypto/sha512"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"hash"
	"io"
	"io/fs"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Python sites. Nothing of this is on a server until the first Python is
// installed (install.sh --python=, or Server → Moduly): then uv, Astral's
// Python manager, comes from its GitHub release (pinned, sha256-checked) and
// installs the chosen CPython builds into /opt/uv/python, readable by every
// site user. Each release of a site gets its own virtualenv (.venv), built
// by uv as the site user during the deploy.

// uv release and the sha256 of its Linux archives.
const uvVersion = "0.13.6"

var uvSHA256 = map[string]string{
	"x86_64":  "f29c3e94ffabd7e6f3ba521dba169a9b7ec12100bf3257087f0f24f6123f3645",
	"aarch64": "229e194255c7958d86d56e8790d969a6f74736592133f0dfd0076f754c642c1d",
}

// InstallablePython: the CPython minor versions offered for sites.
var InstallablePython = []string{"3.12", "3.13", "3.14"}

var (
	pythonDirRe  = regexp.MustCompile(`^cpython-3\.(\d+)\.(\d+)-linux-[a-z0-9_]+-gnu$`)
	pythonLinkRe = regexp.MustCompile(`^cpython-3\.(\d+)-linux-[a-z0-9_]+-gnu$`)
)

// pythonVersions maps installed minors to their interpreter. uv keeps a
// link per minor version (cpython-3.13-linux-x86_64-gnu -> the newest
// patch), and that link is what sites get: a virtualenv built on it keeps
// working when the patch version is upgraded, or is a different one on a
// server restored from a backup. Without the link (an older uv): the newest
// patch directory. Free-threaded builds (…+freethreaded-…) are not offered.
func pythonVersions(cfg config.Config) map[string]string {
	dirs, _ := filepath.Glob(filepath.Join(cfg.PythonDir, "cpython-3.*"))
	best := map[string]int{}
	out := map[string]string{}
	linked := map[string]bool{}
	for _, d := range dirs {
		name := filepath.Base(d)
		if m := pythonLinkRe.FindStringSubmatch(name); m != nil {
			minor := "3." + m[1]
			if bin := filepath.Join(d, "bin", "python"+minor); fsx.Exists(bin) {
				out[minor], linked[minor] = bin, true
			}
			continue
		}
		m := pythonDirRe.FindStringSubmatch(name)
		if m == nil {
			continue
		}
		minor := "3." + m[1]
		bin := filepath.Join(d, "bin", "python"+minor)
		if linked[minor] || !fsx.Exists(bin) {
			continue
		}
		patch, _ := strconv.Atoi(m[2])
		if cur, ok := best[minor]; !ok || patch > cur {
			best[minor] = patch
			out[minor] = bin
		}
	}
	return out
}

func pythonMinors(cfg config.Config) []string {
	vs := pythonVersions(cfg)
	out := make([]string, 0, len(vs))
	for k := range vs {
		out = append(out, k)
	}
	slices.SortFunc(out, func(a, b string) int {
		x, _ := strconv.Atoi(strings.TrimPrefix(a, "3."))
		y, _ := strconv.Atoi(strings.TrimPrefix(b, "3."))
		return x - y
	})
	return out
}

// ---------------------------------------------------------------- the app process

// pythonAppRe: a WSGI/ASGI target, module:object (no factory calls, nothing
// a shell or systemd would read specially).
var pythonAppRe = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*){0,5}:[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*){0,3}$`)

func validPythonApp(app string) error {
	if app != "" && !pythonAppRe.MatchString(app) {
		return protocol.Invalid("python_app: module:object, e.g. mysite.wsgi:application or main:app")
	}
	return nil
}

// pythonApp is the target the server loads: the site's own, else the
// framework's convention.
func pythonApp(framework, app string) string {
	if app != "" {
		return app
	}
	switch framework {
	case "django":
		return "config.wsgi:application"
	case "fastapi":
		return "main:app"
	default: // flask
		return "app:app"
	}
}

var djangoSettingsRe = regexp.MustCompile(`DJANGO_SETTINGS_MODULE["']\s*,\s*["']([A-Za-z_][A-Za-z0-9_]*)\.settings["']`)

// djangoApp reads the project package from manage.py (as startproject
// writes it): "mysite" -> mysite.wsgi:application.
func djangoApp(manage []byte) string {
	if m := djangoSettingsRe.FindSubmatch(manage); m != nil {
		return string(m[1]) + ".wsgi:application"
	}
	return ""
}

func pythonWorkers() int { return max(2, min(runtime.NumCPU(), 4)) }

// pythonEntry: the file in the release that must exist before the app can
// start, and the command: the release's own virtualenv interpreter (which a
// Python site always has, see ensurePythonVenv), the server as a module.
func pythonEntry(l siteLayout) (entry string, argv []string) {
	py := filepath.Join(l.Home, "current", ".venv", "bin", "python")
	port := strconv.Itoa(l.Port)
	workers := strconv.Itoa(pythonWorkers())
	switch l.Framework {
	case "django", "flask":
		// Threaded workers: a slow client or upload no longer holds a whole
		// worker (Caddy does not buffer request bodies, which sync workers
		// need). Each worker is replaced after about 2000 requests (a leak
		// stays bounded); shutdown lets requests in flight finish. Idle
		// connections outlive the edge's (2 min), so the edge never sends on
		// one the app just closed. The edge logs every request already, so
		// gunicorn logs none of its own.
		return ".venv/bin/gunicorn", []string{py, "-m", "gunicorn", "--bind", "127.0.0.1:" + port,
			"--workers", workers, "--worker-class", "gthread", "--threads", "4",
			"--max-requests", "2000", "--max-requests-jitter", "200",
			"--timeout", "60", "--graceful-timeout", "25", "--keep-alive", "125",
			"--forwarded-allow-ips", "127.0.0.1", pythonApp(l.Framework, l.PythonApp)}
	case "fastapi":
		return ".venv/bin/uvicorn", []string{py, "-m", "uvicorn", "--host", "127.0.0.1", "--port", port,
			"--workers", workers, "--proxy-headers", "--forwarded-allow-ips", "127.0.0.1",
			"--timeout-keep-alive", "125", "--timeout-graceful-shutdown", "25", "--no-access-log",
			pythonApp(l.Framework, l.PythonApp)}
	default: // python: the app listens on $HOST:$PORT itself
		return "app.py", []string{py, "app.py"}
	}
}

// pythonServer is the server package a framework runs under (installed into
// the release's virtualenv when the project does not bring it).
func pythonServer(framework string) string {
	switch framework {
	case "django", "flask":
		return "gunicorn"
	case "fastapi":
		return "uvicorn"
	}
	return ""
}

func pythonUnitData(env *Env, l siteLayout) (unitData, string, error) {
	d := unitData{User: l.User, Home: l.Home, StopTimeout: 30}
	bin, ok := pythonVersions(env.Cfg)[l.PythonVersion]
	if !ok {
		return d, "", protocol.Invalid("python_version: Python " + l.PythonVersion + " is not installed")
	}
	entry, argv := pythonEntry(l)
	d.Description = "WSPanel Python app for " + l.Domain
	d.ExecStart = strings.Join(argv, " ")
	port := strconv.Itoa(l.Port)
	d.Environment = []string{
		"PATH=" + filepath.Join(l.Home, "current", ".venv", "bin") + ":" + filepath.Dir(bin) + ":/usr/bin:/bin",
		"VIRTUAL_ENV=" + filepath.Join(l.Home, "current", ".venv"),
		"PYTHONUNBUFFERED=1", "PYTHONDONTWRITEBYTECODE=1",
		"HOST=127.0.0.1", "PORT=" + port,
	}
	d.Extra = portUnitExtra(l.Port)
	return d, entry, nil
}

// pythonBuildEnv: uv as the site user in the deploy sandbox, building the
// release's own .venv and downloading nothing besides packages. The
// interpreter goes to each command explicitly (see buildPython): UV_PYTHON
// would make `uv pip` aim at the shared interpreter instead of the .venv.
func pythonBuildEnv(env *Env, l siteLayout, dir string) ([]string, error) {
	return []string{
		"UV_PYTHON_DOWNLOADS=never", "UV_CACHE_DIR=" + l.Home + "/tmp/uv",
		"UV_PROJECT_ENVIRONMENT=" + dir + "/.venv", "VIRTUAL_ENV=" + dir + "/.venv",
		"UV_NO_PROGRESS=1", "UV_NO_CONFIG=1", "PYTHONDONTWRITEBYTECODE=1",
		// The packages' bytecode is compiled once in the build, not by
		// every worker at each start (the app itself writes none).
		"UV_COMPILE_BYTECODE=1",
		"PATH=" + dir + "/.venv/bin:" + filepath.Join(env.Cfg.ToolsDir, l.User) + ":/usr/local/bin:/usr/bin:/bin",
	}, nil
}

// buildPython: dependencies (uv.lock / pyproject.toml / requirements.txt),
// the app server, then the framework's steps.
func (d *deployment) buildPython(ctx context.Context) error {
	l, dir := d.l, d.dir()
	env, err := pythonBuildEnv(d.env, l, dir)
	if err != nil {
		return err
	}
	base, ok := pythonVersions(d.env.Cfg)[l.PythonVersion]
	if !ok {
		return protocol.Invalid("python_version: Python " + l.PythonVersion + " is not installed")
	}
	uv := d.env.Cfg.UVBin
	py := filepath.Join(dir, ".venv", "bin", "python")
	if d.artifact != "" && d.exists(".venv/bin/python") {
		d.logf("\n==> Python dependencies\nskipped: the artifact brings its .venv\n")
	} else {
		switch {
		case d.exists("uv.lock"):
			err = d.step(ctx, "Python dependencies (uv.lock)", dir, env, uv, "sync", "--python", base, "--locked", "--no-dev")
		case d.exists("pyproject.toml"):
			err = d.step(ctx, "Python dependencies (pyproject.toml)", dir, env, uv, "sync", "--python", base, "--no-dev")
		default:
			if err = d.step(ctx, "Virtualenv", dir, env, uv, "venv", "--python", base, "--allow-existing", ".venv"); err == nil && d.exists("requirements.txt") {
				err = d.step(ctx, "Python dependencies (requirements.txt)", dir, env, uv, "pip", "install", "--python", py, "-r", "requirements.txt")
			}
		}
		if err != nil {
			return err
		}
	}
	if srv := pythonServer(l.Framework); srv != "" && !d.exists(".venv/bin/"+srv) {
		pkg := srv
		if srv == "uvicorn" {
			// uvloop and httptools (faster), and a WebSocket library, without
			// which FastAPI's WebSocket routes fail.
			pkg = "uvicorn[standard]"
		}
		if err := d.step(ctx, srv+" (not among the project's dependencies)", dir, env, uv, "pip", "install", "--python", py, pkg); err != nil {
			return err
		}
	}
	switch l.Framework {
	case "django":
		if l.PythonApp == "" {
			if b, err := readSiteFile(ctx, d.env, l, filepath.Join(dir, "manage.py")); err == nil {
				if app := djangoApp([]byte(b)); app != "" {
					if err := d.savePythonApp(app); err != nil {
						return err
					}
					d.logf("\nDjango project: %s\n", app)
				}
			}
		}
		d.maintenanceForMigrations(ctx)
		if d.cfg.Migrate {
			if err := d.step(ctx, "Migrations", dir, env, py, "manage.py", "migrate", "--noinput"); err != nil {
				return err
			}
		}
		if d.cfg.Assets {
			if err := d.step(ctx, "Static files", dir, env, py, "manage.py", "collectstatic", "--noinput"); err != nil {
				return err
			}
		}
		for _, cmd := range d.cfg.Commands {
			if err := d.step(ctx, "manage.py "+cmd, dir, env, append([]string{py, "manage.py"}, strings.Fields(cmd)...)...); err != nil {
				return err
			}
		}
	default:
		if d.cfg.Migrate && d.exists("alembic.ini") && d.exists(".venv/bin/alembic") {
			d.maintenanceForMigrations(ctx)
			if err := d.step(ctx, "Migrations (Alembic)", dir, env, filepath.Join(dir, ".venv", "bin", "alembic"), "upgrade", "head"); err != nil {
				return err
			}
		}
	}
	return nil
}

// savePythonApp stores the detected Django project, so the app unit loads it.
func (d *deployment) savePythonApp(app string) error {
	return withMutate(d.env, func() error {
		st, _, err := loadSite(d.env, d.l.Domain)
		if err != nil {
			return err
		}
		if st.PythonApp != "" {
			return nil
		}
		st.PythonApp = app
		if err := saveSiteState(d.env, st); err != nil {
			return err
		}
		d.l.PythonApp = app
		return nil
	})
}

// createPythonPlaceholder: until the first deploy a plain Python site serves
// a page from the standard library (in the virtualenv ensurePythonVenv makes).
func createPythonPlaceholder(env *Env, l siteLayout, o fsx.Owner) error {
	if l.Framework != "python" {
		return nil
	}
	py, err := render("placeholder-app.py.tmpl", l)
	if err != nil {
		return err
	}
	return env.FS.WriteFile(filepath.Join(l.Home, "releases/initial/app.py"), py, 0o640, o)
}

// ensurePythonVenv gives the current release a virtualenv of the site's
// Python when it has none (a new site, a site switched to python mode): the
// app unit runs its interpreter, and systemd refuses a unit whose program
// does not exist. As the site user, like everything inside its home.
func ensurePythonVenv(ctx context.Context, env *Env, l siteLayout) error {
	release, err := currentRelease(l)
	if err != nil {
		return err
	}
	dir := filepath.Join(l.Home, "releases", release, ".venv")
	if fsx.Exists(filepath.Join(dir, "bin", "python")) {
		return nil
	}
	bin, ok := pythonVersions(env.Cfg)[l.PythonVersion]
	if !ok {
		return protocol.Invalid("python_version: Python " + l.PythonVersion + " is not installed")
	}
	return asUser(ctx, env, l.User, env.Cfg.UVBin, "--no-config", "--cache-dir", filepath.Join(l.Home, "tmp", "uv"), "--quiet",
		"venv", "--python", bin, dir)
}

// ---------------------------------------------------------------- Celery workers

// celeryAppRe: Celery's -A, a module (Celery finds its app, or module.celery)
// or module:object.
var celeryAppRe = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*){0,5}(:[A-Za-z_][A-Za-z0-9_]*)?$`)

// celeryApp is the app the site's Celery workers run: its own, else for
// Django the project package (mysite, whose celery.py the Celery docs have
// every project write). Other frameworks have no convention: "" = not set.
func celeryApp(l siteLayout) string {
	if l.PythonCelery != "" {
		return l.PythonCelery
	}
	if l.Framework == "django" {
		pkg, _, _ := strings.Cut(pythonApp("django", l.PythonApp), ".")
		return pkg
	}
	return ""
}

// setCeleryApp stores the site's Celery app when one is given (the workers
// it already has are rewritten to it) and returns the layout with it.
func setCeleryApp(ctx context.Context, env *Env, st siteState, app string, rb *undo) (siteLayout, error) {
	if app != "" && !celeryAppRe.MatchString(app) {
		return siteLayout{}, protocol.Invalid("app: the Celery app, a module or module:object, e.g. tasks or myapp.celery:app")
	}
	if app != "" && app != st.PythonCelery {
		prev := st
		st.PythonCelery = app
		if err := saveSiteState(env, st); err != nil {
			return siteLayout{}, err
		}
		rb.add(func() {
			_ = saveSiteState(env, prev)
			if pl, err := layout(env.Cfg, prev); err == nil {
				_ = refreshWorkers(context.WithoutCancel(ctx), env, pl)
			}
		})
		l, err := layout(env.Cfg, st)
		if err != nil {
			return l, err
		}
		if err := refreshWorkers(ctx, env, l); err != nil {
			return l, err
		}
	}
	l, err := layout(env.Cfg, st)
	if err != nil {
		return l, err
	}
	if celeryApp(l) == "" {
		return l, protocol.Invalid("app: the site's Celery app is needed, a module or module:object (e.g. tasks or myapp.celery:app)")
	}
	return l, nil
}

// pythonWorkerUnitData: a Celery process from the release's own virtualenv
// (releases/%i, so an old worker finishes its tasks on the code it started with).
func pythonWorkerUnitData(env *Env, l siteLayout, typ string, spec workerSpec) unitData {
	venv := filepath.Join(l.Home, "releases", "%i", ".venv")
	args := strings.NewReplacer("{celery}", celeryApp(l), "{home}", l.Home,
		"{concurrency}", strconv.Itoa(pythonWorkers())).Replace(strings.Join(spec.Args, " "))
	path := filepath.Join(venv, "bin")
	if bin, ok := pythonVersions(env.Cfg)[l.PythonVersion]; ok {
		path += ":" + filepath.Dir(bin)
	}
	return unitData{
		Description: fmt.Sprintf("WSPanel %s worker for %s", typ, l.Domain),
		User:        l.User,
		Home:        l.Home,
		ExecStart:   filepath.Join(venv, "bin", "python") + " " + args,
		Environment: []string{"PATH=" + path + ":/usr/bin:/bin", "VIRTUAL_ENV=" + venv, "PYTHONUNBUFFERED=1", "PYTHONDONTWRITEBYTECODE=1"},
		Extra:       noBindExtra,
		StopTimeout: spec.StopTimeout,
	}
}

// relinkPythonVenv points a restored release's .venv at the site's Python
// again. Its bin/python is a link to the shared interpreter, outside the
// site's home, and an unpack restores no link that leaves the home (fileop
// untar): without it the app and its workers cannot start. As the site user.
func relinkPythonVenv(ctx context.Context, env *Env, l siteLayout, release string) error {
	if l.Mode != "python" {
		return nil
	}
	venv := filepath.Join(l.Home, "releases", release, ".venv")
	if !fsx.Exists(filepath.Join(venv, "pyvenv.cfg")) {
		return nil
	}
	bin, ok := pythonVersions(env.Cfg)[l.PythonVersion]
	if !ok {
		return protocol.Invalid("python_version: Python " + l.PythonVersion + " is not installed")
	}
	return asUser(ctx, env, l.User, "/usr/bin/ln", "-sfn", "--", bin, filepath.Join(venv, "bin", "python"))
}

// ---------------------------------------------------------------- site.python_app

// sitePythonApp sets the WSGI/ASGI target of a Python site ("" = the
// framework's convention) and restarts the app with it.
func sitePythonApp(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p struct {
		Domain string `json:"domain"`
		App    string `json:"app"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	if err := validPythonApp(p.App); err != nil {
		return nil, err
	}
	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.Mode != "python" || st.Framework == "python" {
		return nil, protocol.Invalid("app: only Django, Flask and FastAPI sites load a module:object")
	}
	st.PythonApp = p.App
	l, err := layout(env.Cfg, st)
	if err != nil {
		return nil, err
	}
	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()
	started := false
	if !l.Disabled {
		data, entry, derr := appUnitData(env, l)
		if derr != nil {
			return nil, derr
		}
		if started, err = installUnit(ctx, env, l.AppUnitFile, data, entry, &rollback); err != nil {
			return nil, err
		}
	}
	if err = saveSiteState(env, st); err != nil {
		return nil, err
	}
	// Django's Celery app is its project package unless the site names one.
	if err = refreshWorkers(ctx, env, l); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "app": pythonApp(st.Framework, st.PythonApp), "started": started}, nil
}

// ---------------------------------------------------------------- python.install

// pythonInstall installs a CPython minor version (and uv with the first one).
func pythonInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Version string `json:"version"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("version", p.Version, InstallablePython); err != nil {
		return nil, err
	}
	if _, ok := pythonVersions(env.Cfg)[p.Version]; ok {
		return map[string]any{"version": p.Version, "changed": false}, nil
	}
	if env.Cfg.DryRun {
		return map[string]any{"version": p.Version, "changed": true, "dry_run": true}, nil
	}
	if err := ensureUV(ctx, env); err != nil {
		return nil, err
	}
	if err := env.FS.MkdirAll(env.Cfg.PythonDir, 0o755, fsx.Root); err != nil {
		return nil, err
	}
	cache := filepath.Join(filepath.Dir(env.Cfg.PythonDir), "cache")
	if _, err := env.Exec.RunIO(ctx, nil, nil, "uv", "--no-config", "--no-progress", "--cache-dir", cache,
		"python", "install", "--install-dir", env.Cfg.PythonDir, "--no-bin", p.Version); err != nil {
		return nil, err
	}
	_ = os.RemoveAll(cache)
	if err := worldReadable(env.Cfg.PythonDir); err != nil {
		return nil, err
	}
	if _, ok := pythonVersions(env.Cfg)[p.Version]; !ok {
		return nil, fmt.Errorf("uv installed Python %s, but no interpreter turned up in %s", p.Version, env.Cfg.PythonDir)
	}
	return map[string]any{"version": p.Version, "changed": true, "installed": pythonMinors(env.Cfg)}, nil
}

// ---------------------------------------------------------------- python.upgrade

// pythonPatch is the patch build the minor version's link points at
// ("cpython-3.13.18-linux-x86_64-gnu"), or "" without a link.
func pythonPatch(cfg config.Config, minor string) string {
	bin, ok := pythonVersions(cfg)[minor]
	if !ok {
		return ""
	}
	dir := filepath.Dir(filepath.Dir(bin))
	if !pythonLinkRe.MatchString(filepath.Base(dir)) {
		return ""
	}
	target, err := filepath.EvalSymlinks(dir)
	if err != nil {
		return ""
	}
	return filepath.Base(target)
}

// pythonPatches: installed minor -> its exact version ("3.13" -> "3.13.18").
func pythonPatches(cfg config.Config) map[string]string {
	out := map[string]string{}
	for minor, bin := range pythonVersions(cfg) {
		target, err := filepath.EvalSymlinks(filepath.Dir(filepath.Dir(bin)))
		if err != nil {
			continue
		}
		if v := patchVersion(filepath.Base(target)); v != "" {
			out[minor] = v
		}
	}
	return out
}

func patchVersion(dir string) string {
	if m := pythonDirRe.FindStringSubmatch(dir); m != nil {
		return "3." + m[1] + "." + m[2]
	}
	return ""
}

// pythonUpgrade moves an installed minor version to its newest patch
// release (security fixes). uv repoints the minor's link, which every
// site's virtualenv is built on; the sites on it are restarted onto the new
// interpreter, then the old patch build is removed.
func pythonUpgrade(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Version string `json:"version"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("version", p.Version, pythonMinors(env.Cfg)); err != nil {
		return nil, err
	}
	before := pythonPatch(env.Cfg, p.Version)
	if before == "" {
		return nil, protocol.Conflict("Python " + p.Version + " has no version link (installed by an older uv): reinstall it")
	}
	out := map[string]any{"version": p.Version, "from": patchVersion(before)}
	if env.Cfg.DryRun {
		out["changed"], out["dry_run"] = false, true
		return out, nil
	}
	if err := ensureUV(ctx, env); err != nil {
		return nil, err
	}
	cache := filepath.Join(filepath.Dir(env.Cfg.PythonDir), "cache")
	if _, err := env.Exec.RunIO(ctx, nil, nil, "uv", "--no-config", "--no-progress", "--cache-dir", cache,
		"python", "install", "--install-dir", env.Cfg.PythonDir, "--no-bin", "--upgrade", p.Version); err != nil {
		return nil, err
	}
	_ = os.RemoveAll(cache)
	if err := worldReadable(env.Cfg.PythonDir); err != nil {
		return nil, err
	}
	after := pythonPatch(env.Cfg, p.Version)
	out["to"] = patchVersion(after)
	out["changed"] = after != before
	if after == before || after == "" {
		return out, nil
	}
	out["restarted"], out["removed"] = afterPythonUpgrade(ctx, env, p.Version, before)
	return out, nil
}

// afterPythonUpgrade restarts the sites on the minor version (their running
// processes keep the old interpreter until then) and removes the old patch
// build unless a virtualenv names it.
func afterPythonUpgrade(ctx context.Context, env *Env, minor, old string) (restarted []string, removed string) {
	for _, st := range allSiteStates(env.Cfg) {
		if st.Mode != "python" || st.PythonVersion != minor {
			continue
		}
		l, err := layout(env.Cfg, st)
		if err != nil || l.Disabled {
			continue
		}
		for _, u := range append([]string{l.AppUnit}, siteWorkerInstances(env, l)...) {
			if _, err := env.Exec.Run(ctx, "systemctl", "try-restart", u); err == nil {
				restarted = append(restarted, u)
			}
		}
	}
	if pythonPatchInUse(env.Cfg, old) {
		return restarted, ""
	}
	if err := os.RemoveAll(filepath.Join(env.Cfg.PythonDir, old)); err != nil {
		return restarted, ""
	}
	return restarted, patchVersion(old)
}

// pythonPatchInUse: some site's virtualenv names the patch build directly
// (built before uv kept the minor link), so it must stay.
func pythonPatchInUse(cfg config.Config, patch string) bool {
	cfgs, _ := filepath.Glob(filepath.Join(cfg.SitesRoot, "*", "releases", "*", ".venv", "pyvenv.cfg"))
	for _, c := range cfgs {
		b, err := readVenvCfg(c)
		if err != nil || strings.Contains(string(b), "/"+patch+"/") {
			return true // unreadable: keep the build rather than break a site
		}
	}
	return false
}

// readVenvCfg reads a site's pyvenv.cfg as root without trusting it: no
// symlink (O_NOFOLLOW), no FIFO or device (O_NONBLOCK, regular files only),
// and at most 64 KiB.
func readVenvCfg(path string) ([]byte, error) {
	f, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	if st, err := f.Stat(); err != nil || !st.Mode().IsRegular() {
		return nil, fmt.Errorf("%s: not a regular file", path)
	}
	return io.ReadAll(io.LimitReader(f, 64<<10))
}

// worldReadable: site users run these interpreters (the agent's umask is 077).
func worldReadable(root string) error {
	return filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.Type()&fs.ModeSymlink != 0 {
			return err
		}
		fi, err := d.Info()
		if err != nil {
			return err
		}
		mode := fi.Mode().Perm() | 0o444
		if d.IsDir() || fi.Mode().Perm()&0o100 != 0 {
			mode |= 0o111
		}
		return os.Chmod(path, mode&^0o022)
	})
}

func uvArch() string {
	if runtime.GOARCH == "arm64" {
		return "aarch64"
	}
	return "x86_64"
}

// ensureUV puts the pinned uv into place (unless it is there already).
func ensureUV(ctx context.Context, env *Env) error {
	if res, err := env.Exec.Run(ctx, "uv", "--version"); err == nil && strings.Contains(res.Stdout, " "+uvVersion) {
		return nil
	}
	arch := uvArch()
	name := "uv-" + arch + "-unknown-linux-gnu"
	url := "https://github.com/astral-sh/uv/releases/download/" + uvVersion + "/" + name + ".tar.gz"
	archive, err := downloadVerified(ctx, url, uvSHA256[arch], 100<<20)
	if err != nil {
		return fmt.Errorf("uv %s: %w", uvVersion, err)
	}
	defer os.Remove(archive)
	f, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		return err
	}
	tr := tar.NewReader(gz)
	found := 0
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return err
		}
		tool := strings.TrimPrefix(h.Name, name+"/")
		if h.Typeflag != tar.TypeReg || (tool != "uv" && tool != "uvx") {
			continue
		}
		dst := filepath.Join(filepath.Dir(env.Cfg.UVBin), tool)
		tmp := dst + ".new"
		out, err := os.OpenFile(tmp, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
		if err != nil {
			return err
		}
		if _, err := io.Copy(out, io.LimitReader(tr, 200<<20)); err != nil {
			out.Close()
			return err
		}
		if err := out.Close(); err != nil {
			return err
		}
		if err := os.Chmod(tmp, 0o755); err != nil {
			return err
		}
		if err := os.Rename(tmp, dst); err != nil {
			return err
		}
		found++
	}
	if found != 2 {
		return fmt.Errorf("uv %s: the archive does not hold uv and uvx", uvVersion)
	}
	return nil
}

// downloadVerified fetches url (with retries: GitHub answers 5xx now and
// then) into a temporary file and checks its sha256.
func downloadVerified(ctx context.Context, url, sum string, limit int64) (string, error) {
	var last error
	for attempt := 0; attempt < 6; attempt++ {
		if attempt > 0 {
			select {
			case <-ctx.Done():
				return "", ctx.Err()
			case <-time.After(time.Duration(attempt*5) * time.Second):
			}
		}
		path, err := downloadOnce(ctx, url, sum, limit)
		if err == nil {
			return path, nil
		}
		last = err
		var bad checksumError
		if errors.As(err, &bad) {
			return "", err
		}
	}
	return "", last
}

type checksumError struct{ got string }

func (e checksumError) Error() string {
	return "checksum mismatch (got " + e.got + "): refusing the download"
}

// noPublishedChecksum: a download whose publisher gives no checksum and
// whose content is verified later by the program itself (SteamCMD's
// bootstrap); HTTPS only. Never for anything root runs.
const noPublishedChecksum = "none:https"

// downloadUnverified: see noPublishedChecksum.
func downloadUnverified(ctx context.Context, url string, limit int64) (string, error) {
	if !strings.HasPrefix(url, "https://") {
		return "", fmt.Errorf("%s: an unverified download needs HTTPS", url)
	}
	return downloadVerified(ctx, url, noPublishedChecksum, limit)
}

func downloadOnce(ctx context.Context, url, sum string, limit int64) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("User-Agent", "wspanel-agent/"+Version)
	res, err := (&http.Client{Timeout: 10 * time.Minute}).Do(req)
	if err != nil {
		return "", err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return "", fmt.Errorf("%s: HTTP %d", url, res.StatusCode)
	}
	f, err := os.CreateTemp("", "wspanel-download-*")
	if err != nil {
		return "", err
	}
	var h hash.Hash = sha256.New()
	want := sum
	if hex1, ok := strings.CutPrefix(sum, "sha1:"); ok { // Mojang publishes SHA-1 only
		h, want = sha1.New(), hex1
	} else if hex512, ok := strings.CutPrefix(sum, "sha512:"); ok { // Modrinth
		h, want = sha512.New(), hex512
	}
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(res.Body, limit+1))
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err == nil && n > limit {
		err = fmt.Errorf("%s: larger than %d bytes", url, limit)
	}
	if err != nil {
		os.Remove(f.Name())
		return "", err
	}
	if got := hex.EncodeToString(h.Sum(nil)); sum != noPublishedChecksum && (want == "" || got != want) {
		os.Remove(f.Name())
		return "", checksumError{got}
	}
	return f.Name(), nil
}
