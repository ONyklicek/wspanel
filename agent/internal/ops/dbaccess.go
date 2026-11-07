package ops

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Temporary database users for phpMyAdmin / Adminer. The panel never sees a
// password: the agent creates a user limited to one database for a short
// time and hands its credentials to the tool through a one-time token file
// only the tools' pool user can read. The browser carries just the token.

var dbTools = []string{"phpmyadmin", "adminer"}

type tempAccess struct {
	Engine  string    `json:"engine"`
	DB      string    `json:"db"`
	User    string    `json:"user"`
	Expires time.Time `json:"expires"`
}

func accessStatePath(env *Env) string { return filepath.Join(env.Cfg.StateDir, "dbaccess.json") }

func loadAccess(env *Env) []tempAccess {
	var list []tempAccess
	if b, err := os.ReadFile(accessStatePath(env)); err == nil {
		_ = json.Unmarshal(b, &list)
	}
	return list
}

func saveAccess(env *Env, list []tempAccess) error {
	b, _ := json.MarshalIndent(list, "", "  ")
	return env.FS.WriteFile(accessStatePath(env), b, 0o600, fsx.Root)
}

func toolInstalled(env *Env, tool string) bool {
	entry := map[string]string{"phpmyadmin": "pma/index.php", "adminer": "adminer/adminer.php"}[tool]
	return entry != "" && (env.Cfg.DryRun || fsx.Exists(filepath.Join(env.Cfg.DBToolsDir, entry)))
}

func installedDBTools(env *Env) []string {
	var out []string
	for _, t := range dbTools {
		if toolInstalled(env, t) {
			out = append(out, t)
		}
	}
	if out == nil {
		out = []string{}
	}
	return out
}

func randomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

type accessParams struct {
	Engine string `json:"engine"`
	Name   string `json:"name,omitempty"`
	Tool   string `json:"tool"`
	Scope  string `json:"scope,omitempty"` // "" = the named database, "all" = every database (superuser)
}

func dbAccess(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p accessParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("scope", p.Scope, []string{"", "all"}); err != nil {
		return nil, err
	}
	var e dbEngine
	var err error
	if p.Scope == "all" {
		if p.Name != "" {
			return nil, protocol.Invalid("name: not accepted with scope all")
		}
		e, err = engineFor(env, p.Engine)
	} else {
		e, err = (dbParams{Engine: p.Engine, Name: p.Name}).resolve(env)
	}
	if err != nil {
		return nil, err
	}
	if err := validate.OneOf("tool", p.Tool, dbTools); err != nil {
		return nil, err
	}
	if p.Tool == "phpmyadmin" && p.Engine != "mariadb" {
		return nil, protocol.Invalid("tool: phpMyAdmin supports MariaDB only; use Adminer")
	}
	if !toolInstalled(env, p.Tool) {
		return nil, protocol.NotFound(p.Tool + " is not installed")
	}
	if p.Scope == "" {
		existing, err := e.list(ctx, env)
		if err != nil {
			return nil, err
		}
		if !env.Cfg.DryRun && !slices.ContainsFunc(existing, func(d dbInfo) bool { return d.Name == p.Name }) {
			return nil, protocol.NotFound("database " + p.Name + " does not exist")
		}
	}

	user := "wsptmp_" + randomHex(5)
	pw := newPassword()
	expires := time.Now().Add(time.Duration(env.Cfg.DBAccessTTLm) * time.Minute).UTC()
	if err := createTempUser(ctx, env, p.Engine, p.Name, user, pw, expires); err != nil {
		return nil, err
	}
	list := append(loadAccess(env), tempAccess{Engine: p.Engine, DB: p.Name, User: user, Expires: expires})
	if err := saveAccess(env, list); err != nil {
		return nil, err
	}

	token := randomHex(32)
	payload, _ := json.Marshal(map[string]any{
		"tool": p.Tool, "engine": p.Engine, "db": p.Name, "user": user, "password": pw,
		"expires": time.Now().Add(60 * time.Second).Unix(), // the token itself: one minute, single use
	})
	owner, err := lookupOwner(env, env.Cfg.DBToolsUser)
	if err != nil {
		return nil, err
	}
	if err := env.FS.MkdirAll(env.Cfg.DBTokenDir, 0o700, owner); err != nil {
		return nil, err
	}
	// O_EXCL temp file + rename: a symlink planted in the dir is never followed.
	if err := env.FS.WriteFile(filepath.Join(env.Cfg.DBTokenDir, token+".json"), payload, 0o600, owner); err != nil {
		return nil, err
	}
	path := map[string]string{"phpmyadmin": "/dbtools/pma/wspanel-signon.php", "adminer": "/dbtools/adminer/wspanel-signon.php"}[p.Tool]
	return map[string]any{"tool": p.Tool, "token": token, "signon_path": path, "user": user, "expires": expires}, nil
}

// createTempUser: db "" = every database (the "all" scope), as a superuser.
func createTempUser(ctx context.Context, env *Env, engine, db, user, pw string, expires time.Time) error {
	if db == "" {
		return createTempSuperuser(ctx, env, engine, user, pw, expires)
	}
	switch engine {
	case "mariadb":
		_, err := mariadb{}.sql(ctx, env, fmt.Sprintf(
			"CREATE USER '%[1]s'@'localhost' IDENTIFIED BY '%[2]s'; CREATE USER '%[1]s'@'127.0.0.1' IDENTIFIED BY '%[2]s'; "+
				"GRANT ALL PRIVILEGES ON `%[3]s`.* TO '%[1]s'@'localhost', '%[1]s'@'127.0.0.1';", user, pw, grantPattern(db)))
		return err
	default:
		// Member of the owner role, acting as it (objects stay owned by the
		// owner), and VALID UNTIL: the login expires even if cleanup never runs.
		_, err := postgres{}.psql(ctx, env, "postgres", fmt.Sprintf(
			`CREATE ROLE "%[1]s" LOGIN PASSWORD '%[2]s' VALID UNTIL '%[3]s' IN ROLE "%[4]s"; ALTER ROLE "%[1]s" SET role = '%[4]s'`,
			user, pw, expires.Format(time.RFC3339), db))
		return err
	}
}

func createTempSuperuser(ctx context.Context, env *Env, engine, user, pw string, expires time.Time) error {
	if engine == "mariadb" {
		_, err := mariadb{}.sql(ctx, env, fmt.Sprintf(
			"CREATE USER '%[1]s'@'localhost' IDENTIFIED BY '%[2]s'; CREATE USER '%[1]s'@'127.0.0.1' IDENTIFIED BY '%[2]s'; "+
				"GRANT ALL PRIVILEGES ON *.* TO '%[1]s'@'localhost', '%[1]s'@'127.0.0.1' WITH GRANT OPTION;", user, pw))
		return err
	}
	// Acts as postgres (a member using its role): whatever it creates is owned
	// by postgres, so the login can be dropped cleanly when it expires.
	_, err := postgres{}.psql(ctx, env, "postgres", fmt.Sprintf(
		`CREATE ROLE "%[1]s" LOGIN PASSWORD '%[2]s' VALID UNTIL '%[3]s' IN ROLE postgres; ALTER ROLE "%[1]s" SET role = 'postgres'`,
		user, pw, expires.Format(time.RFC3339)))
	return err
}

func dropTempUser(ctx context.Context, env *Env, a tempAccess) error {
	switch a.Engine {
	case "mariadb":
		_, err := mariadb{}.sql(ctx, env, fmt.Sprintf("DROP USER IF EXISTS '%[1]s'@'localhost', '%[1]s'@'127.0.0.1';", a.User))
		return err
	default:
		pg := postgres{}
		if a.DB != "" {
			_, _ = pg.psql(ctx, env, a.DB, fmt.Sprintf(`REASSIGN OWNED BY "%[1]s" TO "%[2]s"; DROP OWNED BY "%[1]s"`, a.User, a.DB))
		}
		_, err := pg.psql(ctx, env, "postgres", fmt.Sprintf(`DROP ROLE IF EXISTS "%s"`, a.User))
		return err
	}
}

// CleanupDBAccess drops expired temporary users and stale token files. The
// server calls it every minute (and at start).
func CleanupDBAccess(ctx context.Context, env *Env) error {
	var keep []tempAccess
	var firstErr error
	for _, a := range loadAccess(env) {
		if time.Now().Before(a.Expires) {
			keep = append(keep, a)
			continue
		}
		if err := dropTempUser(ctx, env, a); err != nil {
			keep = append(keep, a) // retry next minute
			if firstErr == nil {
				firstErr = err
			}
		}
	}
	if err := saveAccess(env, keep); err != nil {
		return err
	}
	tokens, _ := filepath.Glob(filepath.Join(env.Cfg.DBTokenDir, "*.json"))
	for _, t := range tokens {
		if fi, err := os.Lstat(t); err == nil && time.Since(fi.ModTime()) > 2*time.Minute {
			_ = os.Remove(t)
		}
	}
	return firstErr
}
