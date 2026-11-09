package ops

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"io"
	"math/big"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// dbEngine is one database server. Identifiers reaching SQL are validated
// names (validate.DBName) and agent-generated passwords; nothing else.
type dbEngine interface {
	create(ctx context.Context, env *Env, name, password string) error
	drop(ctx context.Context, env *Env, name string) error
	setPassword(ctx context.Context, env *Env, name, password string) error
	list(ctx context.Context, env *Env) ([]dbInfo, error)
	version(ctx context.Context, env *Env) (string, error)
	dump(ctx context.Context, env *Env, name string, w io.Writer) error
	restore(ctx context.Context, env *Env, name string, r io.Reader) error
	port() int
	ext() string
	conn() dbConn
}

// dbConn: how a site reaches the server. The shared servers listen on
// 127.0.0.1; a dedicated instance only on a socket in its own directory.
type dbConn struct {
	Host   string // address, or (PostgreSQL) the socket directory
	Port   int
	Socket string // MariaDB's socket file (instances)
}

type dbInfo struct {
	Name     string `json:"name"`
	Size     int64  `json:"size"`
	Instance int    `json:"instance,omitempty"` // a site's dedicated server
}

func engineFor(env *Env, name string) (dbEngine, error) {
	if err := validate.OneOf("engine", name, []string{"mariadb", "postgresql", "mongodb"}); err != nil {
		return nil, err
	}
	if !slices.Contains(env.Cfg.DBEngines, name) {
		return nil, protocol.NotFound(name + " is not installed on this server")
	}
	switch name {
	case "mariadb":
		return mariadb{}, nil
	case "mongodb":
		return mongo{}, nil
	}
	return postgres{}, nil
}

// newPassword: 32 random alphanumerics (~190 bits), safe in SQL, URLs and .env.
func newPassword() string {
	const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789"
	b := make([]byte, 32)
	for i := range b {
		n, _ := rand.Int(rand.Reader, big.NewInt(int64(len(chars))))
		b[i] = chars[n.Int64()]
	}
	return string(b)
}

// ---------------------------------------------------------------- MariaDB

// mariadb: the shared server, or (socket set) a site's dedicated instance.
type mariadb struct {
	socket string
}

func (mariadb) port() int   { return 3306 }
func (mariadb) ext() string { return ".sql.gz" }
func (m mariadb) conn() dbConn {
	if m.socket != "" {
		return dbConn{Host: "localhost", Port: 3306, Socket: m.socket}
	}
	return dbConn{Host: "127.0.0.1", Port: 3306}
}

// args: the client's connection options (an instance's socket).
func (m mariadb) args(a ...string) []string {
	if m.socket != "" {
		return append([]string{"--socket=" + m.socket}, a...)
	}
	return a
}

// netHosts: the hosts the owner may connect from besides the socket. An
// instance has no network.
func (m mariadb) netHosts(env *Env) []string {
	if m.socket != "" {
		return nil
	}
	return append([]string{"127.0.0.1"}, mariadbNetHosts(loadDBServer(env)["mariadb"])...)
}

// sql runs statements as root over the local socket (unix_socket auth: no
// admin password exists). SQL goes in on stdin, never in argv, where any
// local user could read passwords from /proc/<pid>/cmdline.
func (m mariadb) sql(ctx context.Context, env *Env, q string) (string, error) {
	var out strings.Builder
	_, err := env.Exec.RunIO(ctx, strings.NewReader(q), &out, "mariadb", m.args("--batch", "--skip-column-names")...)
	return out.String(), err
}

// grantPattern escapes MariaDB's GRANT wildcards: in `db`.* an unescaped _
// matches any character, so `shop_db` would also cover `shopXdb` (another
// site's database).
func grantPattern(db string) string {
	return strings.NewReplacer("_", `\_`, "%", `\%`).Replace(db)
}

// The owner may connect over TCP (127.0.0.1) and the socket (localhost).
func (m mariadb) create(ctx context.Context, env *Env, name, pw string) error {
	q := fmt.Sprintf("CREATE DATABASE `%[1]s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "+
		"CREATE USER '%[1]s'@'localhost' IDENTIFIED BY '%[2]s'; GRANT ALL PRIVILEGES ON `%[3]s`.* TO '%[1]s'@'localhost';", name, pw, grantPattern(name))
	for i, h := range m.netHosts(env) {
		if i == 0 { // 127.0.0.1: the shared server's own address
			q += fmt.Sprintf(" CREATE USER '%[1]s'@'%[4]s' IDENTIFIED BY '%[2]s'; GRANT ALL PRIVILEGES ON `%[3]s`.* TO '%[1]s'@'%[4]s';", name, pw, grantPattern(name), h)
			continue
		}
		// Published server: the owner may also connect over the network, TLS only.
		q += fmt.Sprintf(" CREATE OR REPLACE USER '%[1]s'@'%[4]s' IDENTIFIED BY '%[2]s' REQUIRE SSL; GRANT ALL PRIVILEGES ON `%[3]s`.* TO '%[1]s'@'%[4]s';",
			name, pw, grantPattern(name), h)
	}
	_, err := m.sql(ctx, env, q)
	return err
}

func (m mariadb) drop(ctx context.Context, env *Env, name string) error {
	hosts := append([]string{"localhost", "%"}, m.netHosts(env)...)
	// <db>-def: the account an imported dump's objects belong to (see restoreUntrusted).
	_, err := m.sql(ctx, env, fmt.Sprintf("DROP DATABASE IF EXISTS `%s`; DROP USER IF EXISTS %s; DROP USER IF EXISTS '%s'@'localhost';",
		name, mariadbAccounts(name, slices.Compact(hosts)), importDefiner(name)))
	return err
}

func (m mariadb) setPassword(ctx context.Context, env *Env, name, pw string) error {
	q := fmt.Sprintf("ALTER USER '%[1]s'@'localhost' IDENTIFIED BY '%[2]s';", name, pw)
	for i, h := range m.netHosts(env) {
		if i == 0 {
			q += fmt.Sprintf(" ALTER USER '%s'@'%s' IDENTIFIED BY '%s';", name, h, pw)
			continue
		}
		q += fmt.Sprintf(" ALTER USER IF EXISTS '%s'@'%s' IDENTIFIED BY '%s';", name, h, pw)
	}
	_, err := m.sql(ctx, env, q)
	return err
}

func (m mariadb) list(ctx context.Context, env *Env) ([]dbInfo, error) {
	out, err := m.sql(ctx, env, "SELECT s.schema_name, COALESCE(SUM(t.data_length + t.index_length), 0) FROM information_schema.schemata s "+
		"LEFT JOIN information_schema.tables t ON t.table_schema = s.schema_name "+
		"WHERE s.schema_name NOT IN ('mysql','information_schema','performance_schema','sys') GROUP BY s.schema_name ORDER BY 1")
	if err != nil {
		return nil, err
	}
	return parseNameSize(out), nil
}

func (m mariadb) version(ctx context.Context, env *Env) (string, error) {
	out, err := m.sql(ctx, env, "SELECT VERSION()")
	v := strings.TrimSpace(out)
	if i := strings.Index(v, "-"); i > 0 {
		v = v[:i]
	}
	return v, err
}

func (m mariadb) dump(ctx context.Context, env *Env, name string, w io.Writer) error {
	_, err := env.Exec.RunIO(ctx, nil, w, "mariadb-dump", m.args("--single-transaction", "--routines", "--triggers", "--events",
		"--default-character-set=utf8mb4", name)...)
	return err
}

// The dump's content comes from the site's database. --sandbox makes the
// client refuse its own commands that touch the system (\!, source, ...),
// --local-infile=0 its reading of local files (LOAD DATA LOCAL).
func (m mariadb) restore(ctx context.Context, env *Env, name string, r io.Reader) error {
	_, err := env.Exec.RunIO(ctx, r, io.Discard, "mariadb", m.args("--sandbox", "--local-infile=0", "--default-character-set=utf8mb4", name)...)
	return err
}

// ---------------------------------------------------------------- PostgreSQL

// postgres: the shared cluster, or (osUser set) a site's dedicated cluster,
// reached on its socket directory as the cluster's owner.
type postgres struct {
	osUser  string
	sockDir string
	sock    int
}

func (p postgres) port() int {
	if p.sock > 0 {
		return p.sock
	}
	return 5432
}
func (postgres) ext() string { return ".pgdump" }
func (p postgres) conn() dbConn {
	if p.sockDir != "" {
		return dbConn{Host: p.sockDir, Port: p.port()}
	}
	return dbConn{Host: "127.0.0.1", Port: 5432}
}

// as: run a PostgreSQL client as the cluster's owner (peer auth: no admin
// password exists), pointed at the cluster.
func (p postgres) as(bin string, a ...string) []string {
	u := p.osUser
	if u == "" {
		u = "postgres"
	}
	args := []string{"--reuid=" + u, "--regid=" + u, "--init-groups", "--reset-env", "--", bin}
	if p.sockDir != "" {
		args = append(args, "--host="+p.sockDir, "--port="+strconv.Itoa(p.port()))
	}
	return append(args, a...)
}

// psql: SQL on stdin, never in argv (see mariadb.sql).
func (p postgres) psql(ctx context.Context, env *Env, db, q string) (string, error) {
	var out strings.Builder
	_, err := env.Exec.RunIO(ctx, strings.NewReader(q), &out, "setpriv", p.as("/usr/bin/psql", "-X", "-q", "-A", "-t", "-v", "ON_ERROR_STOP=1", "-d", db, "-f", "-")...)
	return out.String(), err
}

// Each database is owned by its own role; PUBLIC loses CONNECT, so other
// sites' roles cannot even connect to it.
func (p postgres) create(ctx context.Context, env *Env, name, pw string) error {
	if _, err := p.psql(ctx, env, "postgres", fmt.Sprintf(`CREATE ROLE "%s" LOGIN PASSWORD '%s'`, name, pw)); err != nil {
		return err
	}
	if _, err := p.psql(ctx, env, "postgres", fmt.Sprintf(`CREATE DATABASE "%[1]s" OWNER "%[1]s" ENCODING 'UTF8' TEMPLATE template0`, name)); err != nil {
		return err
	}
	_, err := p.psql(ctx, env, "postgres", fmt.Sprintf(`REVOKE ALL ON DATABASE "%s" FROM PUBLIC`, name))
	return err
}

func (p postgres) drop(ctx context.Context, env *Env, name string) error {
	if _, err := p.psql(ctx, env, "postgres", fmt.Sprintf(`DROP DATABASE IF EXISTS "%s" WITH (FORCE)`, name)); err != nil {
		return err
	}
	_, err := p.psql(ctx, env, "postgres", fmt.Sprintf(`DROP ROLE IF EXISTS "%s"`, name))
	return err
}

func (p postgres) setPassword(ctx context.Context, env *Env, name, pw string) error {
	_, err := p.psql(ctx, env, "postgres", fmt.Sprintf(`ALTER ROLE "%s" PASSWORD '%s'`, name, pw))
	return err
}

func (p postgres) list(ctx context.Context, env *Env) ([]dbInfo, error) {
	out, err := p.psql(ctx, env, "postgres", `SELECT datname || E'\t' || pg_database_size(datname) FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres' ORDER BY 1`)
	if err != nil {
		return nil, err
	}
	return parseNameSize(out), nil
}

func (p postgres) version(ctx context.Context, env *Env) (string, error) {
	out, err := p.psql(ctx, env, "postgres", "SHOW server_version")
	v := strings.Fields(strings.TrimSpace(out))
	if len(v) == 0 {
		return "", err
	}
	return v[0], err
}

func (p postgres) dump(ctx context.Context, env *Env, name string, w io.Writer) error {
	_, err := env.Exec.RunIO(ctx, nil, w, "setpriv", p.as("/usr/bin/pg_dump", "--format=custom", "--dbname="+name)...)
	return err
}

// The dump's content comes from the site's database (functions, index
// expressions, ...): restore with the owner's rights (SET ROLE), never as
// superuser, and without replaying ownership or grants.
func (p postgres) restore(ctx context.Context, env *Env, name string, r io.Reader) error {
	_, err := env.Exec.RunIO(ctx, r, io.Discard, "setpriv", p.as("/usr/bin/pg_restore", "--clean", "--if-exists", "--exit-on-error", "--no-owner", "--no-privileges",
		"--role="+name, "--dbname="+name)...)
	return err
}

func parseNameSize(out string) []dbInfo {
	var dbs []dbInfo
	for _, line := range strings.Split(strings.TrimSpace(out), "\n") {
		name, size, ok := strings.Cut(line, "\t")
		if !ok {
			continue
		}
		n, _ := strconv.ParseInt(strings.TrimSpace(size), 10, 64)
		dbs = append(dbs, dbInfo{Name: strings.TrimSpace(name), Size: n})
	}
	if dbs == nil {
		dbs = []dbInfo{}
	}
	return dbs
}

// ---------------------------------------------------------------- operations

type dbParams struct {
	Engine  string `json:"engine"`
	Name    string `json:"name"`
	Domain  string `json:"domain,omitempty"`  // db.create / db.password: write credentials into this site's env file
	Confirm string `json:"confirm,omitempty"` // db.drop / db.restore: must repeat the name
	// db.create: in the site's dedicated server (its id) instead of the shared one.
	Instance int `json:"instance,omitempty"`
}

// resolve: the server holding the database (a dedicated instance, or shared).
func (p dbParams) resolve(env *Env) (dbEngine, error) {
	if err := validate.DBName("name", p.Name); err != nil {
		return nil, err
	}
	return dbFor(env, p.Engine, p.Name)
}

func dbList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Engine string `json:"engine"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	e, err := engineFor(env, p.Engine)
	if err != nil {
		return nil, err
	}
	list, err := e.list(ctx, env)
	if err != nil {
		return nil, err
	}
	// The sites' dedicated servers: their databases too, marked (what the
	// agent created there; sizes from the server while it runs).
	for _, i := range allDBInstances(env) {
		if i.Engine != p.Engine {
			continue
		}
		sizes := map[string]int64{}
		if in, err := i.engine(env).list(ctx, env); err == nil {
			for _, d := range in {
				sizes[d.Name] = d.Size
			}
		}
		for _, n := range i.Databases {
			list = append(list, dbInfo{Name: n, Size: sizes[n], Instance: i.ID})
		}
	}
	return list, nil
}

// dbCreate creates a database and its owner (same name). With a domain, the
// credentials go straight into the site's env file and never leave the agent;
// without one, the password is returned once.
func dbCreate(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dbParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	shared, err := engineFor(env, p.Engine)
	if err != nil {
		return nil, err
	}
	if err := validate.DBName("name", p.Name); err != nil {
		return nil, err
	}
	var site *siteLayout
	if p.Domain != "" {
		_, l, err := loadSite(env, p.Domain)
		if err != nil {
			return nil, err
		}
		site = &l
	}
	if taken, err := dbNameTaken(ctx, env, p.Engine, p.Name, shared); err != nil {
		return nil, err
	} else if taken {
		return nil, protocol.Conflict("database " + p.Name + " already exists")
	}
	e := shared
	var inst *dbInstance
	if p.Instance != 0 {
		i, err := loadDBInstance(env, p.Instance)
		if err != nil {
			return nil, err
		}
		if i.Engine != p.Engine || p.Domain == "" || i.Domain != p.Domain {
			return nil, protocol.Invalid("instance: the dedicated " + p.Engine + " server of the site given in domain")
		}
		inst, e = &i, i.engine(env)
	}
	pw := newPassword()
	if err := e.create(ctx, env, p.Name, pw); err != nil {
		return nil, err
	}
	if inst != nil {
		inst.Databases = append(inst.Databases, p.Name)
		if err := saveDBInstance(env, *inst); err != nil {
			_ = e.drop(context.WithoutCancel(ctx), env, p.Name)
			return nil, err
		}
	}
	c := e.conn()
	out := map[string]any{"engine": p.Engine, "name": p.Name, "user": p.Name, "host": c.Host, "port": c.Port}
	if c.Socket != "" {
		out["socket"] = c.Socket
	}
	if inst != nil {
		out["instance"] = inst.ID
	}
	if site == nil {
		out["password"] = pw
		return out, nil
	}
	if err := writeDBEnv(ctx, env, *site, p.Engine, e, p.Name, pw); err != nil {
		_ = e.drop(context.WithoutCancel(ctx), env, p.Name)
		if inst != nil {
			inst.Databases = slices.DeleteFunc(inst.Databases, func(n string) bool { return n == p.Name })
			_ = saveDBInstance(env, *inst)
		}
		return nil, fmt.Errorf("writing credentials to %s failed, database removed again: %w", p.Domain, err)
	}
	out["env_file"] = envFilePath(*site)
	return out, nil
}

// dbPassword sets a new random password (into the site's env file, or returned once).
func dbPassword(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dbParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	e, err := p.resolve(env)
	if err != nil {
		return nil, err
	}
	var site *siteLayout
	if p.Domain != "" {
		_, l, err := loadSite(env, p.Domain)
		if err != nil {
			return nil, err
		}
		site = &l
	}
	pw := newPassword()
	if err := e.setPassword(ctx, env, p.Name, pw); err != nil {
		return nil, err
	}
	if site == nil {
		return map[string]any{"name": p.Name, "password": pw}, nil
	}
	if err := writeDBEnv(ctx, env, *site, p.Engine, e, p.Name, pw); err != nil {
		return nil, fmt.Errorf("password changed, but writing it to %s failed (set it again): %w", p.Domain, err)
	}
	return map[string]any{"name": p.Name, "env_file": envFilePath(*site)}, nil
}

func dbDrop(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dbParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	e, err := p.resolve(env)
	if err != nil {
		return nil, err
	}
	if p.Confirm != p.Name {
		return nil, protocol.Invalid("confirm: must equal name")
	}
	if err := removeBackupSchedule(ctx, env, p.Engine, p.Name); err != nil {
		return nil, err
	}
	if err := e.drop(ctx, env, p.Name); err != nil {
		return nil, err
	}
	if i, ok := instanceOf(env, p.Engine, p.Name); ok {
		i.Databases = slices.DeleteFunc(i.Databases, func(n string) bool { return n == p.Name })
		if err := saveDBInstance(env, i); err != nil {
			return nil, err
		}
	}
	return map[string]any{"engine": p.Engine, "name": p.Name, "dropped": true, "backups_kept": true}, nil
}
