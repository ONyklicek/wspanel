package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

func instanceSite(t *testing.T) (*Env, *executor.DryRun, *string) {
	t.Helper()
	env, dr := dbEnv(t)
	written := new(string)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		args := strings.Join(c.Args, " ")
		if c.Name == "setpriv" && strings.Contains(args, "/usr/bin/cat") {
			return executor.Result{Stdout: "APP_NAME=Shop\n"}, nil
		}
		if c.Name == "setpriv" && strings.Contains(args, "/usr/bin/tee ") {
			*written = c.Stdin
		}
		return frankenVersion(c)
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	return env, dr, written
}

// callsOf: the args of the calls of name (from the mark-th call on).
func callsOf(dr *executor.DryRun, name string, mark ...int) []string {
	var out []string
	calls := dr.Calls()
	if len(mark) > 0 {
		calls = calls[mark[0]:]
	}
	for _, c := range calls {
		if c.Name == name {
			out = append(out, strings.Join(c.Args, " "))
		}
	}
	return out
}

func TestDedicatedMariaDBIsTheSitesAlone(t *testing.T) {
	env, dr, written := instanceSite(t)
	if _, err := call(t, env, dbInstanceCreate, map[string]any{"domain": "shop.example.com", "engine": "mariadb", "memory_mb": 64}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("too little memory: %v", err)
	}
	start := len(dr.Calls())
	out, err := call(t, env, dbInstanceCreate, map[string]any{"domain": "shop.example.com", "engine": "mariadb", "memory_mb": 1024, "cpu_percent": 150})
	if err != nil {
		t.Fatal(err)
	}
	if out.(map[string]any)["id"] != 1 {
		t.Fatalf("instance: %v", out)
	}
	// Its own user, which only the site's user joins.
	if u := callsOf(dr, "useradd", start); len(u) != 1 || !strings.HasSuffix(u[0], " wsd_1") {
		t.Fatalf("useradd: %v", u)
	}
	if g := callsOf(dr, "usermod", start); len(g) != 1 || g[0] != "--append --groups wsd_1 web_shop_example_com" {
		t.Fatalf("usermod: %v", g)
	}
	tmp := read2(t, filepath.Join(env.Cfg.TmpfilesDir, "wspanel-db-1.conf"))
	if !strings.Contains(tmp, filepath.Join(env.Cfg.DBInstancesRun, "1")+" 0750 wsd_1 wsd_1") {
		t.Fatalf("socket directory: %s", tmp)
	}
	conf := read2(t, filepath.Join(env.Cfg.DBInstancesConf, "1.cnf"))
	for _, want := range []string{"skip-networking", "socket = " + filepath.Join(env.Cfg.DBInstancesRun, "1", "mysqld.sock"), "innodb_buffer_pool_size = 512M", "local-infile = 0"} {
		if !strings.Contains(conf, want) {
			t.Errorf("my.cnf lacks %q:\n%s", want, conf)
		}
	}
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-db-1.service"))
	for _, want := range []string{"User=wsd_1", "MemoryMax=1024M", "CPUQuota=150%", "SocketBindDeny=any", "ProtectSystem=strict", "--defaults-file="} {
		if !strings.Contains(unit, want) {
			t.Errorf("unit lacks %q", want)
		}
	}
	if i := callsOf(dr, "mariadb-install-db"); len(i) != 1 || !strings.Contains(i[0], "--auth-root-authentication-method=socket") || !strings.Contains(i[0], "--skip-auth-anonymous-user") {
		t.Fatalf("install-db: %v", i)
	}
	if _, err := call(t, env, dbInstanceCreate, map[string]any{"domain": "shop.example.com", "engine": "mariadb", "memory_mb": 512}); code(err) != protocol.CodeConflict {
		t.Fatalf("a second instance of the engine: %v", err)
	}

	// A database in it: over its socket, the socket in the site's .env.
	if _, err := call(t, env, dbCreate, map[string]any{"engine": "mariadb", "name": "shop", "domain": "shop.example.com", "instance": 1}); err != nil {
		t.Fatal(err)
	}
	sock := filepath.Join(env.Cfg.DBInstancesRun, "1", "mysqld.sock")
	var created bool
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" && strings.Contains(c.Stdin, "CREATE DATABASE `shop`") {
			created = c.Args[0] == "--socket="+sock && !strings.Contains(c.Stdin, "127.0.0.1")
		}
	}
	if !created {
		t.Fatal("database not created over the instance's socket (or with a TCP account)")
	}
	for _, want := range []string{`DB_HOST="localhost"`, `DB_SOCKET="` + sock + `"`, `DB_DATABASE="shop"`} {
		if !strings.Contains(*written, want) {
			t.Errorf("env lacks %q:\n%s", want, *written)
		}
	}
	// Names stay unique per engine; other sites cannot use the instance.
	if _, err := call(t, env, dbCreate, map[string]any{"engine": "mariadb", "name": "shop"}); code(err) != protocol.CodeConflict {
		t.Fatalf("same name on the shared server: %v", err)
	}
	if _, err := call(t, env, dbCreate, map[string]any{"engine": "mariadb", "name": "other", "instance": 1}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("instance without its site: %v", err)
	}
	list, _ := call(t, env, dbList, map[string]string{"engine": "mariadb"})
	if dbs := list.([]dbInfo); len(dbs) != 1 || dbs[0].Instance != 1 {
		t.Fatalf("list: %+v", dbs)
	}
	// Backups go through the instance; the database tools cannot reach it.
	mark := len(dr.Calls())
	if _, err := call(t, env, dbBackup, map[string]string{"engine": "mariadb", "name": "shop"}); err != nil {
		t.Fatal(err)
	}
	if d := callsOf(dr, "mariadb-dump", mark); len(d) != 1 || !strings.HasPrefix(d[0], "--socket="+sock) {
		t.Fatalf("dump: %v", d)
	}
	if _, err := call(t, env, dbAccess, map[string]string{"engine": "mariadb", "name": "shop", "tool": "adminer"}); code(err) != protocol.CodeConflict {
		t.Fatalf("tools: %v", err)
	}

	// The site and the instance go only in order.
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "shop.example.com", "confirm": "shop.example.com"}); code(err) != protocol.CodeConflict {
		t.Fatalf("site with an instance deleted: %v", err)
	}
	if _, err := call(t, env, dbInstanceDelete, map[string]any{"id": 1, "confirm": "shop.example.com"}); code(err) != protocol.CodeConflict {
		t.Fatalf("instance with a database deleted: %v", err)
	}
	if _, err := call(t, env, dbDrop, map[string]string{"engine": "mariadb", "name": "shop", "confirm": "shop"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, dbInstanceDelete, map[string]any{"id": 1, "confirm": "nope"}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("unconfirmed: %v", err)
	}
	mark = len(dr.Calls())
	if _, err := call(t, env, dbInstanceDelete, map[string]any{"id": 1, "confirm": "shop.example.com"}); err != nil {
		t.Fatal(err)
	}
	// The account stays (locked): its group's GID is never reused.
	if u := callsOf(dr, "userdel", mark); len(u) != 0 {
		t.Fatalf("userdel: %v", u)
	}
	if u := callsOf(dr, "usermod", mark); len(u) != 1 || u[0] != "--lock --expiredate 1 wsd_1" {
		t.Fatalf("usermod: %v", u)
	}
	// Nor is the id: the next instance is wsd_2.
	out, err = call(t, env, dbInstanceCreate, map[string]any{"domain": "shop.example.com", "engine": "mariadb", "memory_mb": 512})
	if err != nil || out.(map[string]any)["id"] != 2 {
		t.Fatalf("next instance: %v %v", out, err)
	}
	if g := callsOf(dr, "gpasswd", mark); len(g) != 1 || g[0] != "--delete web_shop_example_com wsd_1" {
		t.Fatalf("gpasswd: %v", g)
	}
	for _, f := range []string{filepath.Join(env.Cfg.SystemdDir, "wspanel-db-1.service"), filepath.Join(env.Cfg.DBInstancesConf, "1.cnf"), dbInstancePath(env, 1)} {
		if _, err := os.Stat(f); err == nil {
			t.Errorf("%s left behind", f)
		}
	}
}

func TestDedicatedPostgreSQLIsItsOwnCluster(t *testing.T) {
	env, dr, written := instanceSite(t)
	if _, err := call(t, env, dbInstanceCreate, map[string]any{"domain": "shop.example.com", "engine": "postgresql", "memory_mb": 2048}); err != nil {
		t.Fatal(err)
	}
	c := callsOf(dr, "pg_createcluster")
	if len(c) != 1 {
		t.Fatalf("pg_createcluster: %v", c)
	}
	for _, want := range []string{"--user=wsd_1", "--socketdir=" + filepath.Join(env.Cfg.DBInstancesRun, "1"), "--port=6001", "17 wsd1", "--auth-host=reject"} {
		if !strings.Contains(c[0], want) {
			t.Errorf("pg_createcluster lacks %q: %s", want, c[0])
		}
	}
	confDir := filepath.Join(env.Cfg.PostgresConfDir, "17", "wsd1")
	if hba := read2(t, filepath.Join(confDir, "pg_hba.conf")); !strings.Contains(hba, "local all wsd_1 peer\nlocal all all scram-sha-256") || strings.Contains(hba, "host ") {
		t.Fatalf("pg_hba: %s", hba)
	}
	if conf := read2(t, filepath.Join(confDir, "conf.d", "wspanel.conf")); !strings.Contains(conf, "listen_addresses = ''") {
		t.Fatalf("conf: %s", conf)
	}
	if d := read2(t, filepath.Join(env.Cfg.SystemdDir, "postgresql@17-wsd1.service.d", "wspanel.conf")); !strings.Contains(d, "MemoryMax=2048M") {
		t.Fatalf("drop-in: %s", d)
	}
	if _, err := call(t, env, dbCreate, map[string]any{"engine": "postgresql", "name": "shop", "domain": "shop.example.com", "instance": 1}); err != nil {
		t.Fatal(err)
	}
	var asOwner bool
	for _, call := range dr.Calls() {
		if call.Name == "setpriv" && strings.Contains(call.Stdin, `CREATE ROLE "shop"`) {
			args := strings.Join(call.Args, " ")
			asOwner = strings.HasPrefix(args, "--reuid=wsd_1") && strings.Contains(args, "--port=6001")
		}
	}
	if !asOwner {
		t.Fatal("role not created in the instance as its owner")
	}
	for _, want := range []string{`DB_CONNECTION="pgsql"`, `DB_HOST="` + filepath.Join(env.Cfg.DBInstancesRun, "1") + `"`, `DB_PORT="6001"`, `DB_SOCKET=""`} {
		if !strings.Contains(*written, want) {
			t.Errorf("env lacks %q:\n%s", want, *written)
		}
	}
	// New limits rewrite the drop-in and restart it.
	mark := len(dr.Calls())
	if _, err := call(t, env, dbInstanceUpdate, map[string]any{"id": 1, "memory_mb": 4096, "cpu_percent": 200}); err != nil {
		t.Fatal(err)
	}
	if d := read2(t, filepath.Join(env.Cfg.SystemdDir, "postgresql@17-wsd1.service.d", "wspanel.conf")); !strings.Contains(d, "MemoryMax=4096M") || !strings.Contains(d, "CPUQuota=200%") {
		t.Fatalf("drop-in: %s", d)
	}
	if r := callsOf(dr, "systemctl", mark); !strings.Contains(strings.Join(r, "\n"), "restart postgresql@17-wsd1.service") {
		t.Fatalf("not restarted: %v", r)
	}
}

func TestInstanceConnections(t *testing.T) {
	m := mariadb{socket: "/run/wspanel-db/3/mysqld.sock"}
	if c := m.conn(); c.Host != "localhost" || c.Socket == "" {
		t.Fatalf("mariadb conn: %+v", c)
	}
	p := postgres{osUser: "wsd_3", sockDir: "/run/wspanel-db/3", sock: 6003}
	if c := p.conn(); c.Host != "/run/wspanel-db/3" || c.Port != 6003 {
		t.Fatalf("postgres conn: %+v", c)
	}
	if c := (mariadb{}).conn(); c.Host != "127.0.0.1" || c.Socket != "" {
		t.Fatalf("shared: %+v", c)
	}
}
