package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

func mongoEnv(t *testing.T) (*Env, *executor.DryRun, *[]string) {
	t.Helper()
	env, dr := dbEnv(t)
	env.Cfg.DBEngines = append(env.Cfg.DBEngines, "mongodb")
	env.Cfg.ArtifactDir = filepath.Join(t.TempDir(), "artifacts")
	if err := os.MkdirAll(env.Cfg.StateDir, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(mongoAdminPath(env), []byte("AdminSecret123\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	scripts := new([]string) // what mongosh was given (read before the agent removes it)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "mongosh" {
			b, _ := os.ReadFile(c.Args[len(c.Args)-1])
			*scripts = append(*scripts, string(b))
			if strings.Contains(string(b), "listDatabases") {
				return executor.Result{Stdout: "shop\t81920\nreports\t0\n"}, nil
			}
		}
		if c.Name == "setpriv" && strings.Contains(c.String(), "/usr/bin/cat") {
			return executor.Result{Stdout: "APP_NAME=Shop\n"}, nil
		}
		return frankenVersion(c)
	}
	return env, dr, scripts
}

func TestMongoDatabasesWithoutPasswordsInArgv(t *testing.T) {
	env, dr, scripts := mongoEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, dbCreate, map[string]string{"engine": "mongodb", "name": "shop", "domain": "shop.example.com"}); code(err) != protocol.CodeConflict {
		t.Fatalf("an existing name: %v", err) // the listing already has shop
	}
	if _, err := call(t, env, dbCreate, map[string]string{"engine": "mongodb", "name": "catalog", "domain": "shop.example.com"}); err != nil {
		t.Fatal(err)
	}
	created := false
	for _, s := range *scripts {
		if strings.Contains(s, `createUser({user: "catalog"`) && strings.Contains(s, `role: "dbOwner", db: "catalog"`) && strings.Contains(s, "wspanel:AdminSecret123@127.0.0.1") {
			created = true
		}
	}
	if !created {
		t.Fatalf("createUser: %v", *scripts)
	}
	env2 := lastEnvWrite(dr)
	if !strings.Contains(env2, `MONGODB_URI="mongodb://catalog:`) || !strings.Contains(env2, `@127.0.0.1:27017/catalog?authSource=catalog"`) || !strings.Contains(env2, `MONGODB_DATABASE="catalog"`) {
		t.Fatalf("env:\n%s", env2)
	}
	if strings.Contains(env2, "DB_CONNECTION") {
		t.Fatalf("the app's main database was touched:\n%s", env2)
	}
	list, err := call(t, env, dbList, map[string]string{"engine": "mongodb"})
	if err != nil || len(list.([]dbInfo)) != 2 || list.([]dbInfo)[0].Size != 81920 {
		t.Fatalf("list: %v %v", list, err)
	}
	if _, err := call(t, env, dbBackup, map[string]string{"engine": "mongodb", "name": "shop"}); err != nil {
		t.Fatal(err)
	}
	backups, _ := call(t, env, dbBackups, map[string]string{"engine": "mongodb", "name": "shop"})
	if b := backups.(map[string]any)["backups"].([]backupInfo); len(b) != 1 || !strings.HasSuffix(b[0].File, ".archive.gz") {
		t.Fatalf("backups: %+v", b)
	}
	if _, err := call(t, env, dbCopy, map[string]string{"engine": "mongodb", "from": "shop", "to": "reports"}); err != nil {
		t.Fatal(err)
	}
	var restore string
	for _, c := range dr.Calls() {
		args := c.String()
		if strings.Contains(args, "AdminSecret123") || (c.Name == "mongosh" && strings.Contains(args, "catalog")) {
			t.Fatalf("a password reached argv: %s", args)
		}
		if c.Name == "mongorestore" {
			restore = args
		}
	}
	if strings.Contains(restore, "--gzip") {
		t.Errorf("the archive is compressed by the backup, not by the tools: %s", restore)
	}
	for _, want := range []string{"--nsTo=reports.$coll$", "--nsExclude=admin.*", "--drop", "--config="} {
		if !strings.Contains(restore, want) {
			t.Errorf("mongorestore lacks %q: %s", want, restore)
		}
	}
	// The temporary secrets are gone.
	if left, _ := filepath.Glob(filepath.Join(env.Cfg.StateDir, "tmp", "mongo-*")); len(left) != 0 {
		t.Fatalf("secret files left: %v", left)
	}
	if _, err := call(t, env, dbAccess, map[string]string{"engine": "mongodb", "name": "shop", "tool": "adminer"}); code(err) != protocol.CodeConflict {
		t.Fatalf("tools: %v", err)
	}
	if _, err := call(t, env, dbInstanceCreate, map[string]any{"domain": "shop.example.com", "engine": "mongodb", "memory_mb": 512}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("dedicated MongoDB: %v", err)
	}
}

func TestMongoInstallsFromItsScript(t *testing.T) {
	env, dr := dbEnv(t)
	if _, err := call(t, env, engineInstall, map[string]string{"engine": "mongodb"}); err != nil {
		t.Fatal(err)
	}
	var setup string
	for _, c := range dr.Calls() {
		if c.Name == "mongodb-setup" {
			setup = c.String()
		}
	}
	if !strings.Contains(setup, mongoAdminPath(env)+" 8.3 8.4") {
		t.Fatalf("setup: %q", setup)
	}
	if env.Cfg.DBEngines[len(env.Cfg.DBEngines)-1] != "mongodb" {
		t.Fatalf("engines: %v", env.Cfg.DBEngines)
	}
}
