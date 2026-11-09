package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// The site's console: quick commands (composer install, npm run build,
// artisan migrate…) and, when the panel allows it, a command of the
// operator's own. Each runs as the site user in the same sandbox as a
// deploy's steps (its tools on PATH, its own home writable only), in the
// current release; its output goes to a log the panel follows.

type quickCommand struct {
	Label string
	Argv  func(e *Env, l siteLayout) []string
}

func toolPHP(e *Env, l siteLayout) string { return filepath.Join(e.Cfg.ToolsDir, l.User, "php") }
func toolNPM(e *Env, l siteLayout) string { return filepath.Join(e.Cfg.ToolsDir, l.User, "npm") }

// quickCommands: what each kind of site offers (by id).
func quickCommands(l siteLayout) map[string]quickCommand {
	php := func(args ...string) func(*Env, siteLayout) []string {
		return func(e *Env, l siteLayout) []string { return append([]string{toolPHP(e, l)}, args...) }
	}
	npm := func(args ...string) func(*Env, siteLayout) []string {
		return func(e *Env, l siteLayout) []string { return append([]string{toolNPM(e, l)}, args...) }
	}
	py := func(args ...string) func(*Env, siteLayout) []string {
		return func(e *Env, l siteLayout) []string {
			return append([]string{filepath.Join(l.Home, "current", ".venv", "bin", "python")}, args...)
		}
	}
	out := map[string]quickCommand{
		"npm-ci":    {"npm ci", npm("ci")},
		"npm-build": {"npm run build", npm("run", "build")},
	}
	if l.Mode == "classic" || l.Mode == "worker" {
		out["composer-install"] = quickCommand{"composer install --no-dev -o", php("/usr/bin/composer", "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--no-progress")}
		out["composer-dump"] = quickCommand{"composer dump-autoload -o", php("/usr/bin/composer", "dump-autoload", "--optimize", "--no-interaction")}
		out["composer-outdated"] = quickCommand{"composer outdated --direct", php("/usr/bin/composer", "outdated", "--direct", "--no-interaction")}
	}
	switch l.Framework {
	case "laravel":
		out["artisan-migrate"] = quickCommand{"php artisan migrate --force", php("artisan", "migrate", "--force")}
		out["artisan-optimize"] = quickCommand{"php artisan optimize", php("artisan", "optimize")}
		out["artisan-optimize-clear"] = quickCommand{"php artisan optimize:clear", php("artisan", "optimize:clear")}
		out["artisan-config-cache"] = quickCommand{"php artisan config:cache", php("artisan", "config:cache")}
		out["artisan-queue-restart"] = quickCommand{"php artisan queue:restart", php("artisan", "queue:restart")}
		out["artisan-storage-link"] = quickCommand{"php artisan storage:link", php("artisan", "storage:link")}
		out["artisan-about"] = quickCommand{"php artisan about", php("artisan", "about")}
	case "symfony":
		out["console-cache-clear"] = quickCommand{"php bin/console cache:clear", php("bin/console", "cache:clear")}
		out["console-migrate"] = quickCommand{"php bin/console doctrine:migrations:migrate", php("bin/console", "doctrine:migrations:migrate", "--no-interaction")}
	case "django":
		out["manage-migrate"] = quickCommand{"python manage.py migrate", py("manage.py", "migrate", "--noinput")}
		out["manage-collectstatic"] = quickCommand{"python manage.py collectstatic", py("manage.py", "collectstatic", "--noinput")}
		out["manage-check"] = quickCommand{"python manage.py check --deploy", py("manage.py", "check", "--deploy")}
	}
	return out
}

var runIDRe = regexp.MustCompile(`^[0-9a-f]{16}$`)

func runDir(env *Env, domain string) string {
	return filepath.Join(env.Cfg.DeployLogDir, "runs", domain)
}

type runRecord struct {
	ID       string `json:"id"`
	Command  string `json:"command"`
	Started  string `json:"started"`
	Finished string `json:"finished,omitempty"`
	Exit     *int   `json:"exit,omitempty"`
	Error    string `json:"error,omitempty"`
}

var runMu sync.Mutex

func writeRunRecord(env *Env, domain string, r runRecord) {
	runMu.Lock()
	defer runMu.Unlock()
	b, _ := json.Marshal(r)
	_ = env.FS.WriteFile(filepath.Join(runDir(env, domain), r.ID+".json"), b, 0o600, fsx.Root)
}

// lockedWriter: the command's output, written as it comes.
type lockedWriter struct {
	mu sync.Mutex
	w  io.Writer
	n  int64
}

func (w *lockedWriter) Write(b []byte) (int, error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.n > 8<<20 { // past 8 MiB the rest is dropped (a runaway loop)
		return len(b), nil
	}
	w.n += int64(len(b))
	return w.w.Write(b)
}

// siteExec starts a quick command or (custom) a command line of the
// operator's; it answers at once with the run's id, the output follows.
func siteExec(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain  string `json:"domain"`
		Command string `json:"command,omitempty"` // a quick command's id
		Custom  string `json:"custom,omitempty"`  // a command line (bash -lc)
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	var argv []string
	label := ""
	switch {
	case p.Command != "" && p.Custom == "":
		q, ok := quickCommands(l)[p.Command]
		if !ok {
			return nil, protocol.Invalid("command: not a quick command of this site")
		}
		argv, label = q.Argv(env, l), q.Label
	case p.Custom != "" && p.Command == "":
		if len(p.Custom) > 4000 || !utf8.ValidString(p.Custom) || strings.ContainsAny(p.Custom, "\x00") {
			return nil, protocol.Invalid("custom: a command line of at most 4000 characters")
		}
		argv, label = []string{"/bin/bash", "-lc", p.Custom}, p.Custom
	default:
		return nil, protocol.Invalid("command or custom")
	}
	if err := env.FS.MkdirAll(runDir(env, p.Domain), 0o700, fsx.Root); err != nil {
		return nil, err
	}
	pruneRuns(env, p.Domain, 30)
	id := randomHex(8)
	logFile, err := os.OpenFile(filepath.Join(runDir(env, p.Domain), id+".log"), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return nil, err
	}
	rec := runRecord{ID: id, Command: label, Started: time.Now().UTC().Format(time.RFC3339)}
	writeRunRecord(env, p.Domain, rec)
	fmt.Fprintf(logFile, "$ %s\n", label)
	workdir := filepath.Join(l.Home, "current")
	if fi, err := os.Stat(workdir); err != nil || !fi.IsDir() {
		workdir = l.Home
	}
	args := append(sandboxRunArgs(env, l, workdir, nil), argv...)
	go func() {
		defer logFile.Close()
		rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Minute)
		defer cancel()
		w := &lockedWriter{w: logFile}
		res, err := env.Exec.RunLog(rctx, w, "systemd-run", args...)
		code := res.ExitCode
		rec.Finished, rec.Exit = time.Now().UTC().Format(time.RFC3339), &code
		if err != nil && code == 0 {
			rec.Error = err.Error()
		}
		fmt.Fprintf(w, "\n[exit %d]\n", code)
		writeRunRecord(env, p.Domain, rec)
	}()
	return map[string]any{"run": id, "command": label}, nil
}

func pruneRuns(env *Env, domain string, keep int) {
	logs, _ := filepath.Glob(filepath.Join(runDir(env, domain), "*.json"))
	if len(logs) < keep {
		return
	}
	type ent struct {
		id string
		t  time.Time
	}
	var list []ent
	for _, f := range logs {
		if fi, err := os.Stat(f); err == nil {
			list = append(list, ent{strings.TrimSuffix(filepath.Base(f), ".json"), fi.ModTime()})
		}
	}
	for len(list) >= keep {
		oldest := 0
		for i := range list {
			if list[i].t.Before(list[oldest].t) {
				oldest = i
			}
		}
		_ = os.Remove(filepath.Join(runDir(env, domain), list[oldest].id+".json"))
		_ = os.Remove(filepath.Join(runDir(env, domain), list[oldest].id+".log"))
		list = append(list[:oldest], list[oldest+1:]...)
	}
}

// siteExecOutput: the run's output from offset (at most 256 KiB at a time)
// and whether it finished.
func siteExecOutput(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		Run    string `json:"run"`
		Offset int64  `json:"offset,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if _, _, err := loadSite(env, p.Domain); err != nil {
		return nil, err
	}
	if !runIDRe.MatchString(p.Run) || p.Offset < 0 {
		return nil, protocol.Invalid("run: a run of this site")
	}
	b, err := os.ReadFile(filepath.Join(runDir(env, p.Domain), p.Run+".json"))
	if err != nil {
		return nil, protocol.NotFound("run " + p.Run + " not found")
	}
	var rec runRecord
	_ = json.Unmarshal(b, &rec)
	f, err := os.Open(filepath.Join(runDir(env, p.Domain), p.Run+".log"))
	if err != nil {
		return nil, err
	}
	defer f.Close()
	if _, err := f.Seek(p.Offset, io.SeekStart); err != nil {
		return nil, err
	}
	chunk, _ := io.ReadAll(io.LimitReader(f, 256<<10))
	return map[string]any{"output": strings.ToValidUTF8(string(chunk), "?"), "offset": p.Offset + int64(len(chunk)),
		"done": rec.Exit != nil, "exit": rec.Exit, "command": rec.Command, "error": rec.Error}, nil
}

// siteQuickCommands: the quick commands of the site and its recent runs.
func siteQuickCommands(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	cmds := []map[string]string{}
	for id, q := range quickCommands(l) {
		cmds = append(cmds, map[string]string{"id": id, "label": q.Label})
	}
	sortByKey(cmds, "id")
	runs := []runRecord{}
	files, _ := filepath.Glob(filepath.Join(runDir(env, p.Domain), "*.json"))
	for _, f := range files {
		if b, err := os.ReadFile(f); err == nil {
			var r runRecord
			if json.Unmarshal(b, &r) == nil {
				runs = append(runs, r)
			}
		}
	}
	sortRunsDesc(runs)
	if len(runs) > 15 {
		runs = runs[:15]
	}
	return map[string]any{"commands": cmds, "runs": runs}, nil
}

func sortByKey(rows []map[string]string, key string) {
	for i := 1; i < len(rows); i++ {
		for j := i; j > 0 && rows[j][key] < rows[j-1][key]; j-- {
			rows[j], rows[j-1] = rows[j-1], rows[j]
		}
	}
}

func sortRunsDesc(runs []runRecord) {
	for i := 1; i < len(runs); i++ {
		for j := i; j > 0 && runs[j].Started > runs[j-1].Started; j-- {
			runs[j], runs[j-1] = runs[j-1], runs[j]
		}
	}
}
