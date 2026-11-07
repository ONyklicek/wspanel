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
	"sync"
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
	// Mutate is the server's lock for mutating operations. Long async jobs
	// (deploys) are not mutating as a whole and take it only for the short
	// privileged part. Nil in tests.
	Mutate *sync.Mutex
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
var asyncOps = map[string]bool{"db.backup": true, "db.restore": true, "engine.install": true, "deploy.run": true, "frankenphp.update": true,
	"php.extension": true, "php.install": true, "site.backup": true, "site.restore": true}

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
		{"site.enable", true, siteEnable},
		{"site.disable", true, siteDisable},
		{"site.restart", true, siteRestart},
		{"site.processes", false, siteProcesses},
		{"site.logs", false, siteLogs},
		{"site.mail", true, siteMail},
		{"mail.status", false, mailStatus},
		{"mail.relay", true, mailRelay},
		{"mail.dkim", true, mailDKIM},
		{"mail.log", false, mailLog},
		{"dns.zones", false, dnsZones},
		{"dns.zone_create", true, dnsZoneCreate},
		{"dns.zone_delete", true, dnsZoneDelete},
		{"dns.records", false, dnsRecords},
		{"dns.record_set", true, dnsRecordSet},
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
		{"db.server_status", false, dbServerStatus},
		{"site.php", true, sitePHP},
		{"site.edge", true, siteEdge},
		{"site.files", true, siteFiles},
		{"site.backup", false, siteBackup}, // async; holds the site, not the global lock
		{"site.backups", false, siteBackups},
		{"site.backup_delete", true, siteBackupDelete},
		{"site.backup_schedule", true, siteBackupSchedule},
		{"site.restore", false, siteRestore}, // async; locks Mutate only to switch
		{"php.status", false, phpStatus},
		{"php.extension", true, phpExtension},
		{"php.install", true, phpInstall},
		{"db.root_password", true, dbRootPassword},
		{"db.remote", true, dbRemote},
		{"engine.install", true, engineInstall},
		{"ssl.status", false, sslStatus},
		{"ssl.check", false, sslCheck},
		{"ssl.root_ca", false, sslRootCA},
		{"site.tls", true, siteTLS},
		{"sys.resources", false, sysResources},
		{"sys.metrics", false, sysMetrics},
		{"sys.site_disk", false, sysSiteDisk},
		{"cron.list", false, cronList},
		{"cron.add", true, cronAdd},
		{"cron.edit", true, cronEdit},
		{"cron.remove", true, cronRemove},
		{"deploy.config", true, deployConfigure},
		{"deploy.key", true, deployKey},
		{"deploy.run", false, deployRun}, // async; locks Mutate only to activate
		{"deploy.log", false, deployLog},
		{"deploy.history", false, deployHistory},
		{"deploy.rollback", true, deployRollback},
		{"deploy.remote_ref", false, deployRemoteRef},
		{"panel.access", true, panelAccess},
		{"env.get", false, envGet},
		{"env.set", true, envSet},
		{"audit.tail", false, auditTail},
		{"site.opcache", true, siteOpcache},
		{"frankenphp.update", true, frankenphpUpdate},
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
