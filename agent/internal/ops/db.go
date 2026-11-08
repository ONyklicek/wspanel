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
}

type dbInfo struct {
	Name string `json:"name"`
	Size int64  `json:"size"`
}

func engineFor(env *Env, name string) (dbEngine, error) {
	if err := validate.OneOf("engine", name, []string{"mariadb", "postgresql"}); err != nil {
		return nil, err
	}
	if !slices.Contains(env.Cfg.DBEngines, name) {
		return nil, protocol.NotFound(name + " is not installed on this server")
	}
	if name == "mariadb" {
		return mariadb{}, nil
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

type mariadb struct{}

func (mariadb) port() int   { return 3306 }
func (mariadb) ext() string { return ".sql.gz" }

// sql runs statements as root over the local socket (unix_socket auth: no
// admin password exists). SQL goes in on stdin, never in argv, where any
// local user could read passwords from /proc/<pid>/cmdline.
func (mariadb) sql(ctx context.Context, env *Env, q string) (string, error) {
	var out strings.Builder
	_, err := env.Exec.RunIO(ctx, strings.NewReader(q), &out, "mariadb", "--batch", "--skip-column-names")
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
		"CREATE USER '%[1]s'@'localhost' IDENTIFIED BY '%[2]s'; CREATE USER '%[1]s'@'127.0.0.1' IDENTIFIED BY '%[2]s'; "+
		"GRANT ALL PRIVILEGES ON `%[3]s`.* TO '%[1]s'@'localhost', '%[1]s'@'127.0.0.1';", name, pw, grantPattern(name))
	// Published server: the owner may also connect over the network, TLS only.
	for _, h := range mariadbNetHosts(loadDBServer(env)["mariadb"]) {
		q += fmt.Sprintf(" CREATE OR REPLACE USER '%[1]s'@'%[4]s' IDENTIFIED BY '%[2]s' REQUIRE SSL; GRANT ALL PRIVILEGES ON `%[3]s`.* TO '%[1]s'@'%[4]s';",
			name, pw, grantPattern(name), h)
	}
	_, err := m.sql(ctx, env, q)
	return err
}

func (m mariadb) drop(ctx context.Context, env *Env, name string) error {
	hosts := append([]string{"localhost", "127.0.0.1", "%"}, mariadbNetHosts(loadDBServer(env)["mariadb"])...)
	// <db>-def: the account an imported dump's objects belong to (see restoreUntrusted).
	_, err := m.sql(ctx, env, fmt.Sprintf("DROP DATABASE IF EXISTS `%s`; DROP USER IF EXISTS %s; DROP USER IF EXISTS '%s'@'localhost';",
		name, mariadbAccounts(name, slices.Compact(hosts)), importDefiner(name)))
	return err
}

func (m mariadb) setPassword(ctx context.Context, env *Env, name, pw string) error {
	q := fmt.Sprintf("ALTER USER '%[1]s'@'localhost' IDENTIFIED BY '%[2]s'; ALTER USER '%[1]s'@'127.0.0.1' IDENTIFIED BY '%[2]s';", name, pw)
	for _, h := range mariadbNetHosts(loadDBServer(env)["mariadb"]) {
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

func (mariadb) dump(ctx context.Context, env *Env, name string, w io.Writer) error {
	_, err := env.Exec.RunIO(ctx, nil, w, "mariadb-dump", "--single-transaction", "--routines", "--triggers", "--events",
		"--default-character-set=utf8mb4", name)
	return err
}

// The dump's content comes from the site's database. --sandbox makes the
// client refuse its own commands that touch the system (\!, source, ...),
// --local-infile=0 its reading of local files (LOAD DATA LOCAL).
func (mariadb) restore(ctx context.Context, env *Env, name string, r io.Reader) error {
	_, err := env.Exec.RunIO(ctx, r, io.Discard, "mariadb", "--sandbox", "--local-infile=0", "--default-character-set=utf8mb4", name)
	return err
}

// ---------------------------------------------------------------- PostgreSQL

type postgres struct{}

func (postgres) port() int   { return 5432 }
func (postgres) ext() string { return ".pgdump" }

// psql as the postgres OS user (peer auth: no admin password exists). SQL on
// stdin, never in argv (see mariadb.sql).
func (postgres) psql(ctx context.Context, env *Env, db, q string) (string, error) {
	var out strings.Builder
	_, err := env.Exec.RunIO(ctx, strings.NewReader(q), &out, "setpriv", "--reuid=postgres", "--regid=postgres", "--init-groups", "--reset-env", "--",
		"/usr/bin/psql", "-X", "-q", "-A", "-t", "-v", "ON_ERROR_STOP=1", "-d", db, "-f", "-")
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

func (postgres) dump(ctx context.Context, env *Env, name string, w io.Writer) error {
	_, err := env.Exec.RunIO(ctx, nil, w, "setpriv", "--reuid=postgres", "--regid=postgres", "--init-groups", "--reset-env", "--",
		"/usr/bin/pg_dump", "--format=custom", "--dbname="+name)
	return err
}

// The dump's content comes from the site's database (functions, index
// expressions, ...): restore with the owner's rights (SET ROLE), never as
// superuser, and without replaying ownership or grants.
func (postgres) restore(ctx context.Context, env *Env, name string, r io.Reader) error {
	_, err := env.Exec.RunIO(ctx, r, io.Discard, "setpriv", "--reuid=postgres", "--regid=postgres", "--init-groups", "--reset-env", "--",
		"/usr/bin/pg_restore", "--clean", "--if-exists", "--exit-on-error", "--no-owner", "--no-privileges",
		"--role="+name, "--dbname="+name)
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
}

func (p dbParams) resolve(env *Env) (dbEngine, error) {
	e, err := engineFor(env, p.Engine)
	if err != nil {
		return nil, err
	}
	return e, validate.DBName("name", p.Name)
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
	return e.list(ctx, env)
}

// dbCreate creates a database and its owner (same name). With a domain, the
// credentials go straight into the site's env file and never leave the agent;
// without one, the password is returned once.
func dbCreate(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
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
	existing, err := e.list(ctx, env)
	if err != nil {
		return nil, err
	}
	if slices.ContainsFunc(existing, func(d dbInfo) bool { return d.Name == p.Name }) {
		return nil, protocol.Conflict("database " + p.Name + " already exists")
	}
	pw := newPassword()
	if err := e.create(ctx, env, p.Name, pw); err != nil {
		return nil, err
	}
	out := map[string]any{"engine": p.Engine, "name": p.Name, "user": p.Name, "host": "127.0.0.1", "port": e.port()}
	if site == nil {
		out["password"] = pw
		return out, nil
	}
	if err := writeDBEnv(ctx, env, *site, p.Engine, e, p.Name, pw); err != nil {
		_ = e.drop(context.WithoutCancel(ctx), env, p.Name)
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
	return map[string]any{"engine": p.Engine, "name": p.Name, "dropped": true, "backups_kept": true}, nil
}
