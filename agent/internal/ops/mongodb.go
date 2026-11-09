package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"wspanel/agent/internal/fsx"
)

// MongoDB: localhost only, authentication required (mongodb.sh). The agent
// works as the panel's administrator (wspanel, role root), whose password
// only it knows. Each database has an owner of the same name (role dbOwner,
// defined in that database, so authSource is the database).
//
// Passwords never reach a command line: mongosh gets a script file, the
// database tools a --config file, both root-only and removed afterwards.

type mongo struct{}

func (mongo) port() int    { return 27017 }
func (mongo) ext() string  { return ".archive.gz" }
func (mongo) conn() dbConn { return dbConn{Host: "127.0.0.1", Port: 27017} }
func mongoAdminPath(env *Env) string {
	return filepath.Join(env.Cfg.StateDir, "mongodb-admin.pw")
}

func mongoAdminPassword(env *Env) (string, error) {
	b, err := os.ReadFile(mongoAdminPath(env))
	if err != nil {
		if env.Cfg.DryRun {
			return "dryrun", nil
		}
		return "", fmt.Errorf("the panel's MongoDB administrator is not set up (%s): %w", mongoAdminPath(env), err)
	}
	return strings.TrimSpace(string(b)), nil
}

func mongoAdminURI(pw string) string {
	return "mongodb://wspanel:" + pw + "@127.0.0.1:27017/?authSource=admin"
}

// secretFile writes content to a root-only temporary file; the caller removes it.
func secretFile(env *Env, pattern, content string) (string, error) {
	dir := filepath.Join(env.Cfg.StateDir, "tmp")
	if err := env.FS.MkdirAll(dir, 0o700, fsx.Root); err != nil {
		return "", err
	}
	f, err := os.CreateTemp(dir, pattern)
	if err != nil {
		return "", err
	}
	defer f.Close()
	if err := f.Chmod(0o600); err != nil {
		return "", err
	}
	_, err = f.WriteString(content)
	return f.Name(), err
}

// eval runs JavaScript in mongosh as the administrator; `admin` is the admin
// database. Identifiers reaching it are validated names (validate.DBName)
// and agent-generated passwords.
func (mongo) eval(ctx context.Context, env *Env, js string) (string, error) {
	pw, err := mongoAdminPassword(env)
	if err != nil {
		return "", err
	}
	uri, _ := json.Marshal(mongoAdminURI(pw))
	script, err := secretFile(env, "mongo-*.js", "const admin = connect("+string(uri)+").getSiblingDB(\"admin\");\n"+js+"\n")
	if err != nil {
		return "", err
	}
	defer os.Remove(script)
	var out strings.Builder
	_, err = env.Exec.RunIO(ctx, nil, &out, "mongosh", "--quiet", "--norc", "--nodb", script)
	return out.String(), err
}

// toolConfig: the database tools' --config file (the connection, password
// included).
func (mongo) toolConfig(env *Env) (string, error) {
	pw, err := mongoAdminPassword(env)
	if err != nil {
		return "", err
	}
	return secretFile(env, "mongo-*.yaml", "uri: "+mongoAdminURI(pw)+"\n")
}

func (m mongo) create(ctx context.Context, env *Env, name, pw string) error {
	_, err := m.eval(ctx, env, fmt.Sprintf(`admin.getSiblingDB(%[1]q).createUser({user: %[1]q, pwd: %[2]q, roles: [{role: "dbOwner", db: %[1]q}]});`, name, pw))
	return err
}

func (m mongo) drop(ctx context.Context, env *Env, name string) error {
	_, err := m.eval(ctx, env, fmt.Sprintf(`const d = admin.getSiblingDB(%[1]q); d.dropDatabase(); try { d.dropUser(%[1]q); } catch (e) { if (e.codeName !== "UserNotFound") throw e; }`, name))
	return err
}

func (m mongo) setPassword(ctx context.Context, env *Env, name, pw string) error {
	_, err := m.eval(ctx, env, fmt.Sprintf(`admin.getSiblingDB(%[1]q).updateUser(%[1]q, {pwd: %[2]q});`, name, pw))
	return err
}

// list: the databases that have their owner (user == database), with the
// size on disk once they hold data.
func (m mongo) list(ctx context.Context, env *Env) ([]dbInfo, error) {
	out, err := m.eval(ctx, env, `const sizes = {};
admin.adminCommand({listDatabases: 1}).databases.forEach(d => { sizes[d.name] = d.sizeOnDisk; });
admin.getCollection("system.users").find({}, {user: 1, db: 1}).toArray()
  .filter(u => u.user === u.db && u.db !== "admin")
  .forEach(u => print(u.db + "\t" + (sizes[u.db] || 0)));`)
	if err != nil {
		return nil, err
	}
	return parseNameSize(out), nil
}

func (m mongo) version(ctx context.Context, env *Env) (string, error) {
	out, err := m.eval(ctx, env, `print(admin.version());`)
	return strings.TrimSpace(out), err
}

func (m mongo) dump(ctx context.Context, env *Env, name string, w io.Writer) error {
	cfg, err := m.toolConfig(env)
	if err != nil {
		return err
	}
	defer os.Remove(cfg)
	// A plain archive: the backup compresses it (.archive.gz), a staging copy needs none.
	_, err = env.Exec.RunIO(ctx, nil, w, "mongodump", "--config="+cfg, "--quiet", "--db="+name, "--archive")
	return err
}

// restore: every collection of the archive goes into this database (whatever
// database the dump came from: a staging copy, a renamed one), replacing
// what is there. Nothing reaches another database.
func (m mongo) restore(ctx context.Context, env *Env, name string, r io.Reader) error {
	cfg, err := m.toolConfig(env)
	if err != nil {
		return err
	}
	defer os.Remove(cfg)
	_, err = env.Exec.RunIO(ctx, r, io.Discard, "mongorestore", "--config="+cfg, "--quiet", "--archive", "--drop",
		"--nsExclude=admin.*", "--nsExclude=config.*", "--nsExclude=local.*",
		"--nsFrom=$db$.$coll$", "--nsTo="+name+".$coll$")
	return err
}

// mongoUsersDump: the databases' owners (not the panel's administrator) for
// a server backup; their passwords are hashes.
func mongoUsersDump(ctx context.Context, env *Env, w io.Writer) error {
	m := mongo{}
	cfg, err := m.toolConfig(env)
	if err != nil {
		return err
	}
	defer os.Remove(cfg)
	_, err = env.Exec.RunIO(ctx, nil, w, "mongodump", "--config="+cfg, "--quiet", "--db=admin", "--collection=system.users",
		`--query={"user": {"$ne": "wspanel"}}`, "--archive")
	return err
}

// mongoUsersRestore puts them back on a new server (its own administrator stays).
func mongoUsersRestore(ctx context.Context, env *Env, r io.Reader) error {
	m := mongo{}
	cfg, err := m.toolConfig(env)
	if err != nil {
		return err
	}
	defer os.Remove(cfg)
	_, err = env.Exec.RunIO(ctx, r, io.Discard, "mongorestore", "--config="+cfg, "--quiet", "--archive", "--nsInclude=admin.system.users")
	return err
}

// mongoURI: what a site connects with.
func mongoURI(name, pw string) string {
	return "mongodb://" + name + ":" + pw + "@127.0.0.1:" + strconv.Itoa(27017) + "/" + name + "?authSource=" + name
}
