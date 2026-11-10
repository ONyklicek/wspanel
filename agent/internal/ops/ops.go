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
var asyncOps = map[string]bool{"db.backup": true, "db.restore": true, "db.instance_create": true, "db.instance_update": true, "engine.install": true, "deploy.run": true, "server.backup": true,
	"php.extension": true, "php.install": true, "python.install": true, "python.upgrade": true, "site.backup": true, "site.restore": true,
	"offsite.upload": true, "offsite.fetch": true, "site.set_group": true, "mail.install": true, "mail.uninstall": true, "xhprof.install": true, "xhprof.uninstall": true, "dns.install": true, "dns.uninstall": true, "python.remove": true, "sys.upgrade": true, "pkg.install": true, "pkg.remove": true, "game.create": true, "game.update": true, "game.backup": true, "game.restore": true, "game.plugin_install": true, "game.mod_install": true, "site.app_install": true, "site.pack": true, "site.copy_shared": true, "db.copy": true, "site.import": true, "mail.filters.set": true, "fw.geoblock": true, "site.mount": true}

// Registry returns the whitelist.
func Registry() map[string]Op {
	list := []struct {
		Name     string
		Mutating bool
		Handle   Handler
	}{
		{"agent.ping", false, ping},
		{"server.backup", false, serverBackup}, // async; reads, takes no lock
		{"server.backups", false, serverBackups},
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
		{"dns.install", true, dnsInstall},
		{"dns.uninstall", true, dnsUninstall},
		{"dns.zones", false, dnsZones},
		{"dns.zone_create", true, dnsZoneCreate},
		{"dns.zone_delete", true, dnsZoneDelete},
		{"dns.records", false, dnsRecords},
		{"dns.record_set", true, dnsRecordSet},
		{"dns.secondary", true, dnsSecondarySet},
		{"dns.dnssec", true, dnsDNSSEC},
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
		{"db.instances", false, dbInstances},
		{"db.instance_create", true, dbInstanceCreate},
		{"db.instance_update", true, dbInstanceUpdate},
		{"db.instance_action", true, dbInstanceAction},
		{"db.instance_delete", true, dbInstanceDelete},
		{"site.php", true, sitePHP},
		{"site.edge", true, siteEdge},
		{"site.hook", true, siteHook},
		{"site.files", true, siteFiles},
		{"site.backup", false, siteBackup}, // async; holds the site, not the global lock
		{"site.backups", false, siteBackups},
		{"site.backup_delete", true, siteBackupDelete},
		{"site.backup_schedule", true, siteBackupSchedule},
		{"site.restore", false, siteRestore}, // async; locks Mutate only to switch
		{"offsite.get", false, offsiteGet},
		{"offsite.set", true, offsiteSet},
		{"offsite.signing_key", false, offsiteSigningKey},
		{"offsite.list", false, offsiteList},
		{"offsite.upload", false, offsiteUpload},
		{"offsite.fetch", false, offsiteFetch},
		{"php.status", false, phpStatus},
		{"php.extension", true, phpExtension},
		{"php.install", true, phpInstall},
		{"python.install", true, pythonInstall},
		{"python.upgrade", true, pythonUpgrade},
		{"python.remove", true, pythonRemove},
		{"site.python_app", true, sitePythonApp},
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
		{"env.vars", false, envVars},
		{"env.vars_set", true, envVarsSet},
		{"site.composer_auth", false, composerAuthList},
		{"site.composer_auth_set", true, composerAuthSet},
		{"site.quick_commands", false, siteQuickCommands},
		{"site.exec", true, siteExec},
		{"site.exec_output", false, siteExecOutput},
		{"audit.tail", false, auditTail},
		{"site.opcache", true, siteOpcache},
		{"site.limits", true, siteLimits},
		{"site.set_group", true, siteSetGroup},
		{"client.create", true, clientCreate},
		{"client.delete", true, clientDelete},
		{"client.quota", true, clientQuota},
		{"site.directives", true, siteDirectives},
		{"mail.install", true, mailInstall},
		{"mail.uninstall", true, mailUninstall},
		{"mail.hosting", false, mailHostingStatus},
		{"mail.domain.set", true, mailDomainSet},
		{"mail.mailbox.set", true, mailMailboxSet},
		{"mail.mailbox.delete", true, mailMailboxDelete},
		{"mail.alias.set", true, mailAliasSet},
		{"mail.usage", false, mailUsage},
		{"mail.autoreply", true, mailAutoreply},
		{"mail.forward.set", true, mailForwardSet},
		{"mail.limits.set", true, mailLimitsSet},
		{"mail.filters.set", true, mailFiltersSet},
		{"site.mounts", false, siteMountsList},
		{"site.mount", true, siteMountChange},
		{"mail.mailbox.password", true, mailMailboxPassword},
		{"mail.certs", true, mailCerts},
		{"mail.webmail.login", false, mailWebmailLogin},
		{"mail.queue", true, mailQueue},
		{"site.xhprof", true, siteXhprof},
		{"xhprof.install", true, xhprofInstall},
		{"xhprof.uninstall", true, xhprofUninstall},
		{"xhprof.status", false, xhprofStatus},
		{"site.traffic", true, siteTraffic},
		{"site.app_install", true, siteAppInstall},
		{"site.pack", true, sitePack},
		{"site.copy_shared", true, siteCopyShared},
		{"db.copy", true, dbCopy},
		{"site.import_scan", false, siteImportScan},
		{"site.import", true, siteImport},
		{"sys.updates", false, sysUpdates},
		{"sys.upgrade", true, sysUpgrade},
		{"sys.auto_updates", true, sysAutoUpdates},
		{"sys.auto_reboot", true, sysAutoReboot},
		{"sys.reboot", true, sysReboot},
		{"sys.settings", false, sysSettings},
		{"sys.hostname", true, sysHostname},
		{"sys.timezone", true, sysTimezone},
		{"sys.ntp", true, sysNTP},
		{"sys.swap", true, sysSwap},
		{"sys.disk_usage", false, sysDiskUsage},
		{"sys.disks", false, sysDisks},
		{"sys.network", false, sysNetwork},
		{"sys.processes", false, sysProcesses},
		{"sys.kill", true, sysKill},
		{"pkg.list", false, pkgList},
		{"pkg.search", false, pkgSearch},
		{"pkg.preview", false, pkgPreview},
		{"pkg.install", true, pkgInstall},
		{"pkg.remove", true, pkgRemove},
		{"pkg.hold", true, pkgHold},
		{"panel.upgrade", true, panelUpgrade},
		{"panel.upgrade_status", false, panelUpgradeStatus},
		{"games.catalog", false, gameCatalog},
		{"games.packages", false, gamePackages},
		{"games.package_install", true, gamePackageInstall},
		{"games.package_upload", true, gamePackageUpload},
		{"games.package_remove", true, gamePackageRemove},
		{"game.list", false, gameList},
		{"game.status", false, gameStatus},
		{"game.logs", false, gameLogs},
		{"game.backups", false, gameBackups},
		{"game.create", false, gameCreate}, // async; locks Mutate only for the privileged steps
		{"game.options", false, gameOptions},
		{"game.set_options", true, gameSetOptions},
		{"game.action", true, gameAction},
		{"game.command", true, gameCommand},
		{"game.settings", true, gameSettings},
		{"game.update", false, gameUpdate}, // async; locks Mutate only for the privileged steps
		{"game.backup", true, gameBackup},
		{"game.restore", true, gameRestore},
		{"game.delete", true, gameDelete},
		{"game.files", true, gameFiles},
		{"game.sftp", true, gameSFTP},
		{"game.properties", false, gameProperties},
		{"game.set_properties", true, gameSetProperties},
		{"game.player_lists", false, gamePlayerLists},
		{"game.player_action", true, gamePlayerAction},
		{"game.plugin_search", false, gamePluginSearch},
		{"game.plugins", false, gamePlugins},
		{"game.plugin_install", true, gamePluginInstall},
		{"game.plugin_remove", true, gamePluginRemove},
		{"game.factorio_settings", false, gameFactorioSettings},
		{"game.factorio_set_settings", true, gameFactorioSetSettings},
		{"game.mod_search", false, gameModSearch},
		{"game.mods", false, gameMods},
		{"game.mod_install", true, gameModInstall},
		{"game.mod_remove", true, gameModRemove},
		{"fail2ban.status", false, fail2banStatus},
		{"fail2ban.unban", true, fail2banUnban},
		{"mail.spam.set", true, mailSpamSet},
		{"site.credentials", false, siteCredentials},
		{"valkey.set", true, valkeySet},
		{"valkey.status", false, valkeyStatus},
		{"valkey.flush", true, valkeyFlush},
		{"valkey.restart", true, valkeyRestart},
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
		{"fw.geoblock", true, fwGeoblock},
		{"sys.log", false, sysLog},
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
