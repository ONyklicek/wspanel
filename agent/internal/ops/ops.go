// Package ops is the whitelist: the finite set of operations the agent knows.
// There is no "run command" operation and there never will be.
package ops

import (
	"bytes"
	"context"
	"embed"
	"encoding/json"
	"fmt"
	"os/user"
	"strconv"
	"text/template"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

//go:embed templates/*.tmpl
var templateFS embed.FS

var templates = template.Must(template.New("").Option("missingkey=error").ParseFS(templateFS, "templates/*.tmpl"))

// Env is what every operation gets to work with.
type Env struct {
	Cfg        config.Config
	Exec       executor.Executor
	FS         *fsx.FS
	ConfigPath string // to persist config changes (engine.install)
}

type Handler func(ctx context.Context, env *Env, params json.RawMessage) (any, error)

type Op struct {
	Name     string
	Mutating bool // mutating ops are serialized
	Handle   Handler
	Async    bool // runs as a background job; the request returns a job id
}

// asyncOps are long-running (dumps, restores): they answer with a job id and
// the panel polls job.status.
var asyncOps = map[string]bool{"db.backup": true, "db.restore": true, "engine.install": true}

// Registry returns the whitelist.
func Registry() map[string]Op {
	list := []struct {
		Name     string
		Mutating bool
		Handle   Handler
	}{
		{"agent.ping", false, ping},
		{"site.create", true, siteCreate},
		{"site.delete", true, siteDelete},
		{"site.switch_mode", true, siteSwitchMode},
		{"site.set_version", true, siteSetVersion},
		{"site.health", false, siteHealth},
		{"ssh.set", true, sshSet},
		{"db.list", false, dbList},
		{"db.create", true, dbCreate},
		{"db.drop", true, dbDrop},
		{"db.password", true, dbPassword},
		{"db.backup", false, dbBackup},
		{"db.backups", false, dbBackups},
		{"db.backup_delete", true, dbBackupDelete},
		{"db.restore", true, dbRestore},
		{"db.schedule", true, dbSchedule},
		{"db.access", true, dbAccess},
		{"engine.install", true, engineInstall},
		{"ssl.status", false, sslStatus},
		{"ssl.check", false, sslCheck},
		{"sys.resources", false, sysResources},
		{"cron.list", false, cronList},
		{"cron.add", true, cronAdd},
		{"cron.edit", true, cronEdit},
		{"cron.remove", true, cronRemove},
		{"service.status", false, serviceStatus},
		{"service.start", true, serviceStart},
		{"service.stop", true, serviceStop},
		{"service.reload", true, serviceReload},
		{"service.restart", true, serviceRestart},
		{"service.reset", true, serviceReset},
		{"worker.create", true, workerCreate},
		{"worker.delete", true, workerDelete},
		{"fw.list", false, fwList},
		{"fw.allow", true, fwAllow},
		{"fw.deny", true, fwDeny},
		{"fw.delete", true, fwDelete},
		{"config.validate", false, configValidate},
		{"sys.services", false, sysServices},
		{"sys.versions", false, sysVersions},
	}
	m := make(map[string]Op, len(list))
	for _, o := range list {
		m[o.Name] = Op{Name: o.Name, Mutating: o.Mutating, Handle: o.Handle, Async: asyncOps[o.Name]}
	}
	return m
}

// decode parses params strictly: unknown fields are rejected.
func decode(raw json.RawMessage, v any) error {
	if len(raw) == 0 {
		raw = []byte("{}")
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(v); err != nil {
		return protocol.Invalid("params: " + err.Error())
	}
	return nil
}

func render(name string, data any) ([]byte, error) {
	var buf bytes.Buffer
	if err := templates.ExecuteTemplate(&buf, name, data); err != nil {
		return nil, fmt.Errorf("render %s: %w", name, err)
	}
	return buf.Bytes(), nil
}

// lookupOwner resolves a user's uid/gid. In dry-run, unknown users map to -1.
func lookupOwner(env *Env, name string) (fsx.Owner, error) {
	u, err := user.Lookup(name)
	if err != nil {
		if env.Cfg.DryRun {
			return fsx.Owner{UID: -1, GID: -1}, nil
		}
		return fsx.Owner{}, err
	}
	uid, _ := strconv.Atoi(u.Uid)
	gid, _ := strconv.Atoi(u.Gid)
	return fsx.Owner{UID: uid, GID: gid}, nil
}

func ping(context.Context, *Env, json.RawMessage) (any, error) {
	return map[string]string{"pong": "ok"}, nil
}

// undo collects compensating actions run in reverse order when an op fails halfway.
type undo []func()

func (u *undo) add(f func()) { *u = append(*u, f) }
func (u undo) run() {
	for i := len(u) - 1; i >= 0; i-- {
		u[i]()
	}
}
