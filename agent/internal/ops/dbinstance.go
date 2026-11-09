package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// A dedicated database server of one site: its own MariaDB or PostgreSQL,
// run as its own system user (wsd_<id>) from its own data directory, with no
// network at all. Its socket sits in a directory only that user's group may
// enter, and the site's user is the group's only other member: other sites
// cannot even reach the server, let alone guess a password. Memory and CPU
// are capped by its unit.

type dbInstance struct {
	ID         int      `json:"id"`
	Engine     string   `json:"engine"`
	Domain     string   `json:"domain"`
	SiteUser   string   `json:"site_user"`
	MemoryMB   int      `json:"memory_mb"`
	CPUPercent int      `json:"cpu_percent,omitempty"` // of one core; 0 = no limit
	PGVersion  string   `json:"pg_version,omitempty"`
	Databases  []string `json:"databases"`
	Created    string   `json:"created"`
}

func (i dbInstance) user() string { return "wsd_" + strconv.Itoa(i.ID) }
func (i dbInstance) home(env *Env) string {
	return filepath.Join(env.Cfg.DBInstancesRoot, strconv.Itoa(i.ID))
}
func (i dbInstance) dataDir(env *Env) string { return filepath.Join(i.home(env), "data") }
func (i dbInstance) runDir(env *Env) string {
	return filepath.Join(env.Cfg.DBInstancesRun, strconv.Itoa(i.ID))
}
func (i dbInstance) mariadbConf(env *Env) string {
	return filepath.Join(env.Cfg.DBInstancesConf, strconv.Itoa(i.ID)+".cnf")
}
func (i dbInstance) tmpfiles(env *Env) string {
	return filepath.Join(env.Cfg.TmpfilesDir, "wspanel-db-"+strconv.Itoa(i.ID)+".conf")
}
func (i dbInstance) cluster() string { return "wsd" + strconv.Itoa(i.ID) }

// pgPort only names the socket file (no TCP); unique so pg_createcluster
// keeps the clusters apart.
func (i dbInstance) pgPort() int { return 6000 + i.ID }
func (i dbInstance) pgConfDir(env *Env) string {
	return filepath.Join(env.Cfg.PostgresConfDir, i.PGVersion, i.cluster())
}

func (i dbInstance) unit() string {
	if i.Engine == "postgresql" {
		return "postgresql@" + i.PGVersion + "-" + i.cluster() + ".service"
	}
	return "wspanel-db-" + strconv.Itoa(i.ID) + ".service"
}

// engine: the instance as a database server the db ops work with.
func (i dbInstance) engine(env *Env) dbEngine {
	if i.Engine == "postgresql" {
		return postgres{osUser: i.user(), sockDir: i.runDir(env), sock: i.pgPort()}
	}
	return mariadb{socket: filepath.Join(i.runDir(env), "mysqld.sock")}
}

func dbInstanceDir(env *Env) string { return filepath.Join(env.Cfg.StateDir, "db-instances") }
func dbInstancePath(env *Env, id int) string {
	return filepath.Join(dbInstanceDir(env), strconv.Itoa(id)+".json")
}

func loadDBInstance(env *Env, id int) (dbInstance, error) {
	var i dbInstance
	if id <= 0 {
		return i, protocol.Invalid("id: a dedicated database server")
	}
	b, err := os.ReadFile(dbInstancePath(env, id))
	if os.IsNotExist(err) {
		return i, protocol.NotFound(fmt.Sprintf("dedicated database server %d does not exist", id))
	}
	if err != nil {
		return i, err
	}
	return i, json.Unmarshal(b, &i)
}

func saveDBInstance(env *Env, i dbInstance) error {
	if err := env.FS.MkdirAll(dbInstanceDir(env), 0o700, fsx.Root); err != nil {
		return err
	}
	sort.Strings(i.Databases)
	b, _ := json.MarshalIndent(i, "", "  ")
	return env.FS.WriteFile(dbInstancePath(env, i.ID), b, 0o600, fsx.Root)
}

func allDBInstances(env *Env) []dbInstance {
	files, _ := filepath.Glob(filepath.Join(dbInstanceDir(env), "*.json"))
	out := []dbInstance{}
	for _, f := range files {
		var i dbInstance
		if b, err := os.ReadFile(f); err == nil && json.Unmarshal(b, &i) == nil && i.ID > 0 {
			out = append(out, i)
		}
	}
	sort.Slice(out, func(a, b int) bool { return out[a].ID < out[b].ID })
	return out
}

// instanceOf: the dedicated server a database lives in (false: the shared one).
func instanceOf(env *Env, engine, name string) (dbInstance, bool) {
	for _, i := range allDBInstances(env) {
		if i.Engine == engine && slices.Contains(i.Databases, name) {
			return i, true
		}
	}
	return dbInstance{}, false
}

// dbFor: the server holding the database: its site's dedicated instance, or
// the shared server of the engine.
func dbFor(env *Env, engine, name string) (dbEngine, error) {
	e, err := engineFor(env, engine)
	if err != nil {
		return nil, err
	}
	if i, ok := instanceOf(env, engine, name); ok {
		return i.engine(env), nil
	}
	return e, nil
}

// dbNameTaken: a database of that name exists on the shared server or in any
// instance of the engine (names stay unique per engine: backups are kept by
// engine and name).
func dbNameTaken(ctx context.Context, env *Env, engine, name string, shared dbEngine) (bool, error) {
	if _, ok := instanceOf(env, engine, name); ok {
		return true, nil
	}
	list, err := shared.list(ctx, env)
	if err != nil {
		return false, err
	}
	return slices.ContainsFunc(list, func(d dbInfo) bool { return d.Name == name }), nil
}

func installedPGVersion(env *Env) string {
	if env.Cfg.DryRun {
		return "17"
	}
	entries, _ := os.ReadDir("/usr/lib/postgresql")
	best := 0
	for _, e := range entries {
		if n, err := strconv.Atoi(e.Name()); err == nil && n > best {
			if _, err := os.Stat(filepath.Join("/usr/lib/postgresql", e.Name(), "bin", "postgres")); err == nil {
				best = n
			}
		}
	}
	if best == 0 {
		return ""
	}
	return strconv.Itoa(best)
}

type dbInstanceParams struct {
	ID         int    `json:"id,omitempty"`
	Domain     string `json:"domain,omitempty"`
	Engine     string `json:"engine,omitempty"`
	MemoryMB   int    `json:"memory_mb,omitempty"`
	CPUPercent int    `json:"cpu_percent,omitempty"`
	Confirm    string `json:"confirm,omitempty"`
	Action     string `json:"action,omitempty"`
}

func (p dbInstanceParams) limits() error {
	if p.MemoryMB < 256 || p.MemoryMB > 262144 {
		return protocol.Invalid("memory_mb: 256 to 262144")
	}
	if p.CPUPercent < 0 || p.CPUPercent > 6400 {
		return protocol.Invalid("cpu_percent: 0 (no limit) to 6400")
	}
	return nil
}

// dbInstanceCreate sets up a site's dedicated server (async: initialising
// the data directory takes a while).
func dbInstanceCreate(ctx context.Context, env *Env, raw json.RawMessage) (result any, err error) {
	var p dbInstanceParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("engine", p.Engine, []string{"mariadb", "postgresql"}); err != nil {
		return nil, err
	}
	if _, err := engineFor(env, p.Engine); err != nil {
		return nil, err
	}
	if err := p.limits(); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	for _, i := range allDBInstances(env) {
		if i.Domain == p.Domain && i.Engine == p.Engine {
			return nil, protocol.Conflict(fmt.Sprintf("%s already has a dedicated %s server (%d)", p.Domain, p.Engine, i.ID))
		}
	}
	next, err := nextDBInstanceID(env)
	if err != nil {
		return nil, err
	}
	inst := dbInstance{ID: next, Engine: p.Engine, Domain: p.Domain, SiteUser: l.User, MemoryMB: p.MemoryMB,
		CPUPercent: p.CPUPercent, Databases: []string{}, Created: time.Now().UTC().Format(time.RFC3339)}
	if p.Engine == "postgresql" {
		if inst.PGVersion = installedPGVersion(env); inst.PGVersion == "" {
			return nil, fmt.Errorf("no PostgreSQL server binaries found in /usr/lib/postgresql")
		}
	}

	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()
	// Once the site's user was given the group, a process of the site may
	// carry its GID: the account (and so the GID) is then kept, locked.
	granted := false
	rollback.add(func() { teardownDBInstance(context.WithoutCancel(ctx), env, inst, granted) })
	if _, err = env.Exec.Run(ctx, "useradd", "--system", "--user-group", "--no-create-home", "--home-dir", inst.home(env),
		"--shell", "/usr/sbin/nologin", "--comment", "WSPanel database server of "+p.Domain, inst.user()); err != nil {
		return nil, err
	}
	owner, err := lookupOwner(env, inst.user())
	if err != nil {
		return nil, err
	}
	if err = env.FS.MkdirAll(env.Cfg.DBInstancesRoot, 0o751, fsx.Root); err != nil {
		return nil, err
	}
	if err = env.FS.Mkdir(inst.home(env), 0o700, owner); err != nil {
		return nil, err
	}
	if err = env.FS.Mkdir(filepath.Join(inst.home(env), "tmp"), 0o700, owner); err != nil {
		return nil, err
	}
	// The socket directory: the instance's user and group; the site's user
	// joins the group, no one else.
	if err = env.FS.WriteFile(inst.tmpfiles(env), []byte(fmt.Sprintf("# Managed by WSPanel agent.\nd %s 0750 %s %s -\n",
		inst.runDir(env), inst.user(), inst.user())), 0o644, fsx.Root); err != nil {
		return nil, err
	}
	if _, err = env.Exec.Run(ctx, "systemd-tmpfiles", "--create", inst.tmpfiles(env)); err != nil {
		return nil, err
	}
	granted = true // also when usermod fails halfway
	if _, err = env.Exec.Run(ctx, "usermod", "--append", "--groups", inst.user(), l.User); err != nil {
		return nil, err
	}
	if inst.Engine == "postgresql" {
		err = createPGInstance(ctx, env, inst)
	} else {
		err = createMariaDBInstance(ctx, env, inst)
	}
	if err != nil {
		return nil, err
	}
	if err = waitDBInstance(ctx, env, inst); err != nil {
		return nil, err
	}
	if inst.Engine == "mariadb" { // no anonymous account, whatever the installer's defaults
		if _, err = (mariadb{socket: filepath.Join(inst.runDir(env), "mysqld.sock")}).sql(ctx, env, "DROP USER IF EXISTS ''@'localhost';"); err != nil {
			return nil, err
		}
	}
	if err = saveDBInstance(env, inst); err != nil {
		return nil, err
	}
	// The site's processes pick up the new group when they start again.
	reloadSiteProcesses(ctx, env, l)
	return dbInstanceView(ctx, env, inst), nil
}

func mariadbInstanceConf(env *Env, i dbInstance) string {
	pool := max(64, i.MemoryMB/2)
	conns := min(200, max(20, i.MemoryMB/16))
	return fmt.Sprintf(`# Managed by WSPanel agent: the dedicated MariaDB of %[1]s.
[mariadbd]
datadir = %[3]s
tmpdir = %[4]s
socket = %[5]s/mysqld.sock
pid-file = %[5]s/mysqld.pid
skip-networking
skip-name-resolve
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
innodb_buffer_pool_size = %[6]dM
max_connections = %[7]d
local-infile = 0

[client]
socket = %[5]s/mysqld.sock
`, i.Domain, "", i.dataDir(env), filepath.Join(i.home(env), "tmp"), i.runDir(env), pool, conns)
}

func (i dbInstance) limitLines() []string {
	lines := []string{fmt.Sprintf("MemoryMax=%dM", i.MemoryMB), "MemorySwapMax=0"}
	if i.CPUPercent > 0 {
		lines = append(lines, fmt.Sprintf("CPUQuota=%d%%", i.CPUPercent))
	}
	return lines
}

func writeMariaDBInstanceUnit(ctx context.Context, env *Env, i dbInstance) error {
	extra := append([]string{
		"ExecStartPre=+/usr/bin/systemd-tmpfiles --create " + i.tmpfiles(env),
		"SocketBindDeny=any", // no network, whatever the config says
		"LimitNOFILE=16384",
		"TimeoutStartSec=900", // crash recovery of a big database
	}, i.limitLines()...)
	unit, err := render("unit.service.tmpl", unitData{
		Description: "WSPanel dedicated MariaDB of " + i.Domain,
		User:        i.user(), Home: i.home(env), WorkDir: i.home(env), RunDir: i.runDir(env),
		ExecStart: "/usr/sbin/mariadbd --defaults-file=" + i.mariadbConf(env), StopTimeout: 900, Extra: extra,
	})
	if err != nil {
		return err
	}
	path := filepath.Join(env.Cfg.SystemdDir, i.unit())
	if err := env.FS.WriteFile(path, unit, 0o644, fsx.Root); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "systemd-analyze", "verify", path); err != nil {
		return fmt.Errorf("database unit invalid: %w", err)
	}
	_, err = env.Exec.Run(ctx, "systemctl", "daemon-reload")
	return err
}

func createMariaDBInstance(ctx context.Context, env *Env, i dbInstance) error {
	if err := env.FS.MkdirAll(env.Cfg.DBInstancesConf, 0o755, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(i.mariadbConf(env), []byte(mariadbInstanceConf(env, i)), 0o644, fsx.Root); err != nil {
		return err
	}
	// root reaches it over the socket (unix_socket auth): no admin password exists.
	if _, err := env.Exec.RunIO(ctx, nil, nil, "mariadb-install-db", "--defaults-file="+i.mariadbConf(env), "--user="+i.user(),
		"--auth-root-authentication-method=socket", "--skip-auth-anonymous-user", "--skip-test-db"); err != nil {
		return err
	}
	if err := writeMariaDBInstanceUnit(ctx, env, i); err != nil {
		return err
	}
	_, err := env.Exec.Run(ctx, "systemctl", "enable", "--now", i.unit())
	return err
}

// pgPIDFile: in the instance's own run directory (/run/postgresql is the
// postgres user's).
func (i dbInstance) pgPIDFile(env *Env) string { return filepath.Join(i.runDir(env), "postgres.pid") }

func pgInstanceConf(env *Env, i dbInstance) string {
	return fmt.Sprintf(`# Managed by WSPanel agent: the dedicated PostgreSQL of %s.
listen_addresses = ''
external_pid_file = '%s'
shared_buffers = %dMB
effective_cache_size = %dMB
max_connections = %d
`, i.Domain, i.pgPIDFile(env), max(32, i.MemoryMB/4), max(64, i.MemoryMB/2), min(200, max(20, i.MemoryMB/16)))
}

// pgInstanceHBA: the owner as itself (the agent), everyone else with a
// password; there is no network to come from.
func pgInstanceHBA(i dbInstance) string {
	return fmt.Sprintf("# Managed by WSPanel agent.\nlocal all %s peer\nlocal all all scram-sha-256\n", i.user())
}

func writePGInstanceLimits(ctx context.Context, env *Env, i dbInstance) error {
	dir := filepath.Join(env.Cfg.SystemdDir, i.unit()+".d")
	if err := env.FS.MkdirAll(dir, 0o755, fsx.Root); err != nil {
		return err
	}
	body := "# Managed by WSPanel agent.\n[Service]\nExecStartPre=+/usr/bin/systemd-tmpfiles --create " + i.tmpfiles(env) + "\n" +
		"PIDFile=" + i.pgPIDFile(env) + "\n" + strings.Join(i.limitLines(), "\n") + "\n"
	if err := env.FS.WriteFile(filepath.Join(dir, "wspanel.conf"), []byte(body), 0o644, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.MkdirAll(filepath.Join(i.pgConfDir(env), "conf.d"), 0o755, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(filepath.Join(i.pgConfDir(env), "conf.d", "wspanel.conf"), []byte(pgInstanceConf(env, i)), 0o644, fsx.Root); err != nil {
		return err
	}
	_, err := env.Exec.Run(ctx, "systemctl", "daemon-reload")
	return err
}

func createPGInstance(ctx context.Context, env *Env, i dbInstance) error {
	if _, err := env.Exec.RunIO(ctx, nil, nil, "pg_createcluster", "--user="+i.user(), "--group="+i.user(),
		"--datadir="+i.dataDir(env), "--socketdir="+i.runDir(env), "--port="+strconv.Itoa(i.pgPort()), "--start-conf=auto",
		i.PGVersion, i.cluster(), "--", "--auth-local=peer", "--auth-host=reject", "--encoding=UTF8"); err != nil {
		return err
	}
	owner, err := lookupOwner(env, i.user())
	if err != nil {
		return err
	}
	if err := env.FS.MkdirAll(i.pgConfDir(env), 0o755, fsx.Root); err != nil { // pg_createcluster's
		return err
	}
	if err := env.FS.WriteFile(filepath.Join(i.pgConfDir(env), "pg_hba.conf"), []byte(pgInstanceHBA(i)), 0o640, owner); err != nil {
		return err
	}
	if err := writePGInstanceLimits(ctx, env, i); err != nil {
		return err
	}
	_, err = env.Exec.Run(ctx, "systemctl", "enable", "--now", i.unit())
	return err
}

// waitDBInstance: the server answers (a fresh MariaDB may take a few seconds).
func waitDBInstance(ctx context.Context, env *Env, i dbInstance) error {
	if env.Cfg.DryRun {
		return nil
	}
	var last error
	for range 60 {
		if _, last = i.engine(env).version(ctx, env); last == nil {
			return nil
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(time.Second):
		}
	}
	return fmt.Errorf("the dedicated server does not answer: %w", last)
}

// reloadSiteProcesses: PHP-FPM and the site's units start their processes
// with the user's groups as they are now.
func reloadSiteProcesses(ctx context.Context, env *Env, l siteLayout) {
	if l.PHPVersion != "" && l.Mode == "classic" {
		_, _ = env.Exec.Run(ctx, "systemctl", "reload", strings.ReplaceAll(env.Cfg.FPMUnit, "{version}", l.PHPVersion))
	}
	units, _ := filepath.Glob(filepath.Join(env.Cfg.SystemdDir, "wspanel-"+l.User+"*.service"))
	for _, u := range units {
		_, _ = env.Exec.Run(ctx, "systemctl", "try-restart", filepath.Base(u))
	}
}

// nextDBInstanceID: ids (and so wsd_<id> and its group) are never handed out
// twice, even after a delete.
func nextDBInstanceID(env *Env) (int, error) {
	next := 1
	counter := filepath.Join(dbInstanceDir(env), "next-id")
	if b, err := os.ReadFile(counter); err == nil {
		if n, err := strconv.Atoi(strings.TrimSpace(string(b))); err == nil {
			next = max(next, n)
		}
	}
	for _, i := range allDBInstances(env) {
		next = max(next, i.ID+1)
	}
	if err := env.FS.MkdirAll(dbInstanceDir(env), 0o700, fsx.Root); err != nil {
		return 0, err
	}
	return next, env.FS.WriteFile(counter, []byte(strconv.Itoa(next+1)), 0o600, fsx.Root)
}

// teardownDBInstance removes everything of an instance (best effort: also
// the rollback of a half-created one). keepAccount leaves wsd_<id> (locked,
// no home) in place: its group's GID then never goes to a new group while a
// process of the site may still carry it (an SSH session started before).
func teardownDBInstance(ctx context.Context, env *Env, i dbInstance, keepAccount bool) {
	_, _ = env.Exec.Run(ctx, "systemctl", "disable", "--now", i.unit())
	if i.Engine == "postgresql" && i.PGVersion != "" {
		_, _ = env.Exec.RunIO(ctx, nil, nil, "pg_dropcluster", "--stop", i.PGVersion, i.cluster())
		_ = os.RemoveAll(filepath.Join(env.Cfg.SystemdDir, i.unit()+".d"))
		_ = os.RemoveAll(i.pgConfDir(env))
	} else {
		_ = os.Remove(filepath.Join(env.Cfg.SystemdDir, i.unit()))
		_ = os.Remove(i.mariadbConf(env))
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
	_ = os.Remove(i.tmpfiles(env))
	_ = os.RemoveAll(i.runDir(env))
	if i.home(env) != env.Cfg.DBInstancesRoot && strings.HasPrefix(i.home(env), env.Cfg.DBInstancesRoot+"/") {
		_ = os.RemoveAll(i.home(env))
	}
	if i.SiteUser != "" {
		_, _ = env.Exec.Run(ctx, "gpasswd", "--delete", i.SiteUser, i.user())
	}
	if keepAccount {
		_, _ = env.Exec.Run(ctx, "usermod", "--lock", "--expiredate", "1", i.user())
	} else {
		_, _ = env.Exec.Run(ctx, "userdel", i.user())
	}
	_ = os.Remove(dbInstancePath(env, i.ID))
}

func dirSize(path string) int64 {
	var n int64
	_ = filepath.WalkDir(path, func(_ string, d fs.DirEntry, err error) error {
		if err == nil && d.Type().IsRegular() {
			if fi, err := d.Info(); err == nil {
				n += fi.Size()
			}
		}
		return nil
	})
	return n
}

func dbInstanceView(ctx context.Context, env *Env, i dbInstance) map[string]any {
	out := map[string]any{"id": i.ID, "engine": i.Engine, "domain": i.Domain, "memory_mb": i.MemoryMB, "cpu_percent": i.CPUPercent,
		"databases": i.Databases, "unit": i.unit(), "created": i.Created, "size": dirSize(i.dataDir(env))}
	if st, err := unitStates(ctx, env, []string{i.unit()}); err == nil {
		out["active"], out["sub"] = st[0].Active, st[0].Sub
	}
	if v, err := i.engine(env).version(ctx, env); err == nil && v != "" {
		out["version"] = v
	}
	c := i.engine(env).conn()
	out["host"], out["port"], out["socket"] = c.Host, c.Port, c.Socket
	return out
}

func dbInstances(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	out := []map[string]any{}
	for _, i := range allDBInstances(env) {
		out = append(out, dbInstanceView(ctx, env, i))
	}
	return map[string]any{"instances": out}, nil
}

// dbInstanceUpdate: new memory and CPU limits (the server restarts).
func dbInstanceUpdate(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dbInstanceParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := p.limits(); err != nil {
		return nil, err
	}
	i, err := loadDBInstance(env, p.ID)
	if err != nil {
		return nil, err
	}
	i.MemoryMB, i.CPUPercent = p.MemoryMB, p.CPUPercent
	if i.Engine == "postgresql" {
		err = writePGInstanceLimits(ctx, env, i)
	} else {
		if err = env.FS.WriteFile(i.mariadbConf(env), []byte(mariadbInstanceConf(env, i)), 0o644, fsx.Root); err == nil {
			err = writeMariaDBInstanceUnit(ctx, env, i)
		}
	}
	if err != nil {
		return nil, err
	}
	if err := saveDBInstance(env, i); err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "restart", i.unit()); err != nil {
		return nil, err
	}
	if err := waitDBInstance(ctx, env, i); err != nil {
		return nil, err
	}
	return dbInstanceView(ctx, env, i), nil
}

func dbInstanceAction(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dbInstanceParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("action", p.Action, []string{"start", "stop", "restart"}); err != nil {
		return nil, err
	}
	i, err := loadDBInstance(env, p.ID)
	if err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", p.Action, i.unit()); err != nil {
		return nil, err
	}
	return dbInstanceView(ctx, env, i), nil
}

// dbInstanceDelete removes an instance that holds no databases any more
// (dropping them first keeps their backups, like any database).
func dbInstanceDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dbInstanceParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	i, err := loadDBInstance(env, p.ID)
	if err != nil {
		return nil, err
	}
	if p.Confirm != i.Domain {
		return nil, protocol.Invalid("confirm: must equal the site's domain")
	}
	if len(i.Databases) > 0 {
		return nil, protocol.Conflict(fmt.Sprintf("the dedicated server still holds databases (%s): drop them first", strings.Join(i.Databases, ", ")))
	}
	teardownDBInstance(ctx, env, i, true)
	if _, l, err := loadSite(env, i.Domain); err == nil {
		reloadSiteProcesses(ctx, env, l)
	}
	return map[string]any{"id": i.ID, "deleted": true}, nil
}

// siteDBInstances: the dedicated servers of a site (a site with one is not
// deleted before them).
func siteDBInstances(env *Env, domain string) []dbInstance {
	var out []dbInstance
	for _, i := range allDBInstances(env) {
		if i.Domain == domain {
			out = append(out, i)
		}
	}
	return out
}
