package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

const shopHash = "*2470C0C06DEE42FD1618BB99005ADCA2EC9D1E19"

// dbServerEnv: MariaDB has one database "shop" whose owner has shopHash; ufw
// already holds one rule the agent tagged earlier.
func dbServerEnv(t *testing.T) (*Env, *executor.DryRun) {
	env, dr := dbEnv(t)
	root := t.TempDir()
	env.Cfg.MariaDBConfDir = filepath.Join(root, "etc/mysql/mariadb.conf.d")
	env.Cfg.PostgresEtcDir = filepath.Join(root, "etc/postgresql")
	env.Cfg.DBTLSDir = filepath.Join(root, "etc/wspanel-db-tls")
	env.Cfg.Fail2banDir = filepath.Join(root, "etc/fail2ban")
	for _, d := range []string{filepath.Join(env.Cfg.PostgresEtcDir, "17/main"), env.Cfg.StateDir, env.Cfg.MariaDBConfDir} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.WriteFile(filepath.Join(env.Cfg.PostgresEtcDir, "17/main/pg_hba.conf"), []byte("local all postgres peer\nhost all all 127.0.0.1/32 scram-sha-256\n"), 0o640); err != nil {
		t.Fatal(err)
	}
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch {
		case c.Name == "mariadb" && strings.Contains(c.Stdin, "information_schema.schemata"):
			return executor.Result{Stdout: "shop\t4096\n"}, nil
		case c.Name == "mariadb" && strings.Contains(c.Stdin, "authentication_string"):
			return executor.Result{Stdout: "shop\t" + shopHash + "\n"}, nil
		case c.Name == "ufw" && len(c.Args) == 1 && c.Args[0] == "status":
			return executor.Result{Stdout: "Status: active\n\nTo                         Action      From\n--                         ------      ----\n" +
				"22/tcp                     ALLOW       Anywhere\n" +
				"3306/tcp                   ALLOW       198.51.100.0/24            # wspanel-db-mariadb\n" +
				"3306/tcp (v6)              ALLOW       2001:db8::/32              # wspanel-db-mariadb\n"}, nil
		}
		return executor.Result{}, nil
	}
	return env, dr
}

func stdinOf(dr *executor.DryRun, name, contains string) string {
	var all []string
	for _, c := range dr.Calls() {
		if c.Name == name && strings.Contains(c.Stdin, contains) {
			all = append(all, c.Stdin)
		}
	}
	return strings.Join(all, "\n")
}

func ran(dr *executor.DryRun, cmd string) bool {
	for _, c := range dr.Calls() {
		if c.String() == cmd {
			return true
		}
	}
	return false
}

func TestRootPasswordIsGeneratedReturnedOnceAndNeverInSQLForMariaDB(t *testing.T) {
	env, dr := dbServerEnv(t)
	out, err := call(t, env, dbRootPassword, map[string]any{"engine": "mariadb"})
	if err != nil {
		t.Fatal(err)
	}
	pw := out.(map[string]any)["password"].(string)
	if len(pw) != 32 {
		t.Fatalf("password %q", pw)
	}
	sql := stdinOf(dr, "mariadb", "ALTER USER 'root'@'localhost'")
	if !strings.Contains(sql, "IDENTIFIED VIA unix_socket OR mysql_native_password USING '"+nativeHash(pw)+"'") {
		t.Fatalf("root keeps socket auth and gets the hash: %s", sql)
	}
	for _, c := range dr.Calls() {
		if strings.Contains(c.Stdin, pw) || strings.Contains(strings.Join(c.Args, " "), pw) {
			t.Fatalf("the plain password reached a command: %s", c)
		}
	}
	state, _ := os.ReadFile(dbServerStatePath(env))
	if strings.Contains(string(state), pw) || !strings.Contains(string(state), nativeHash(pw)) {
		t.Fatalf("state keeps the hash, never the password: %s", state)
	}
	if strings.Contains(sql, "'root'@'%'") {
		t.Fatal("no network root while the server is not published")
	}
}

func TestNativeHashMatchesMariaDB(t *testing.T) {
	// SELECT PASSWORD('secret') on MariaDB
	if got := nativeHash("secret"); got != "*14E65567ABDB5135D0CFD9A70B3032C179A49EE7" {
		t.Fatal(got)
	}
}

func TestRootPasswordForPostgresAndValidation(t *testing.T) {
	env, dr := dbServerEnv(t)
	for _, bad := range []string{"short", "with'quote-123456789", `back\slash-123456789`, "space in it 1234567"} {
		if _, err := call(t, env, dbRootPassword, map[string]any{"engine": "postgresql", "password": bad}); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%q accepted: %v", bad, err)
		}
	}
	out, err := call(t, env, dbRootPassword, map[string]any{"engine": "postgresql", "password": "Own-Password_2026!"})
	if err != nil {
		t.Fatal(err)
	}
	if _, shown := out.(map[string]any)["password"]; shown {
		t.Fatal("a password the admin chose is not echoed back")
	}
	if !strings.Contains(stdinOf(dr, "setpriv", "ALTER ROLE postgres"), "ALTER ROLE postgres PASSWORD 'Own-Password_2026!'") {
		t.Fatal("postgres password not set")
	}
	if _, err := call(t, env, dbRootPassword, map[string]any{"engine": "postgresql", "remove": true}); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(stdinOf(dr, "setpriv", "PASSWORD NULL"), "ALTER ROLE postgres PASSWORD NULL") || loadDBServer(env)["postgresql"].RootPassword {
		t.Fatal("password not removed")
	}
}

func TestRemoteAccessValidation(t *testing.T) {
	env, dr := dbServerEnv(t)
	for _, p := range []map[string]any{
		{"engine": "mariadb", "enabled": true},                                    // nobody allowed
		{"engine": "mariadb", "enabled": true, "allow": []string{"0.0.0.0/0"}},    // everyone in disguise
		{"engine": "mariadb", "enabled": true, "allow": []string{"10.0.0.1; rm"}}, // not an address
		{"engine": "mariadb", "enabled": true, "allow": []string{"::/0"}},         // everyone (IPv6)
		{"engine": "oracle", "enabled": true, "anywhere": true},                   // unknown engine
		{"engine": "mariadb", "enabled": true, "allow": make([]string, 51)},       // too many
	} {
		if _, err := call(t, env, dbRemote, p); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v accepted: %v", p, err)
		}
	}
	if len(dr.Calls()) != 0 {
		t.Fatalf("nothing may run on invalid input: %v", dr.Calls())
	}
}

func TestMariaDBPublishedToAnAllowlist(t *testing.T) {
	env, dr := dbServerEnv(t)
	if _, err := call(t, env, dbRootPassword, map[string]any{"engine": "mariadb", "password": "Root-Password-2026!"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, dbRemote, map[string]any{"engine": "mariadb", "enabled": true, "allow": []string{"203.0.113.7", "10.8.0.9/24", "203.0.113.7/32"}})
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(out.(map[string]any)["allow"].([]string), ","); got != "203.0.113.7/32,10.8.0.0/24" {
		t.Fatalf("allow normalized: %s", got)
	}
	conf, err := os.ReadFile(mariadbRemoteConf(env))
	if err != nil || !strings.Contains(string(conf), "bind-address = *") || !strings.Contains(string(conf), "ssl_cert = ") || !strings.Contains(string(conf), "skip-name-resolve = ON") {
		t.Fatalf("conf: %s %v", conf, err)
	}
	if !ran(dr, "systemctl restart mariadb.service") {
		t.Fatal("mariadb not restarted")
	}
	sql := stdinOf(dr, "mariadb", "CREATE OR REPLACE USER 'shop'")
	for _, want := range []string{
		// MariaDB checks the address itself: one account per allowed host / IPv4 network, never '%'.
		"CREATE OR REPLACE USER 'shop'@'203.0.113.7' IDENTIFIED BY PASSWORD '" + shopHash + "' REQUIRE SSL",
		"CREATE OR REPLACE USER 'shop'@'10.8.0.0/255.255.255.0' IDENTIFIED BY PASSWORD '" + shopHash + "' REQUIRE SSL",
		"GRANT ALL PRIVILEGES ON `shop`.* TO 'shop'@'10.8.0.0/255.255.255.0'",
		"CREATE OR REPLACE USER 'root'@'203.0.113.7' IDENTIFIED BY PASSWORD '" + nativeHash("Root-Password-2026!") + "' REQUIRE SSL",
	} {
		if !strings.Contains(sql, want) {
			t.Errorf("missing %q in\n%s", want, sql)
		}
	}
	// Old tagged rules go, the allowlist comes in; other rules are untouched.
	for _, want := range []string{
		"ufw delete allow proto tcp from 198.51.100.0/24 to any port 3306",
		"ufw delete allow proto tcp from 2001:db8::/32 to any port 3306",
		"ufw allow proto tcp from 203.0.113.7/32 to any port 3306 comment wspanel-db-mariadb",
		"ufw allow proto tcp from 10.8.0.0/24 to any port 3306 comment wspanel-db-mariadb",
	} {
		if !ran(dr, want) {
			t.Errorf("missing: %s", want)
		}
	}
	if ran(dr, "ufw allow 3306/tcp comment wspanel-db-mariadb") {
		t.Fatal("the port was opened to everyone")
	}
	if strings.Contains(sql, "@'%'") {
		t.Fatalf("an allowlist must not create '%%' accounts:\n%s", sql)
	}
	jail, _ := os.ReadFile(filepath.Join(env.Cfg.Fail2banDir, "jail.d/wspanel-db.conf"))
	if !strings.Contains(string(jail), "[wspanel-mariadb]") || strings.Contains(string(jail), "wspanel-postgres") {
		t.Fatalf("jail: %s", jail)
	}

	// A database created while published also gets its network account.
	created, err := call(t, env, dbCreate, map[string]string{"engine": "mariadb", "name": "blog"})
	if err != nil {
		t.Fatal(err)
	}
	pw := created.(map[string]any)["password"].(string)
	if !strings.Contains(stdinOf(dr, "mariadb", "CREATE DATABASE `blog`"), "CREATE OR REPLACE USER 'blog'@'10.8.0.0/255.255.255.0' IDENTIFIED BY '"+pw+"' REQUIRE SSL") {
		t.Fatal("new database has no network account")
	}
}

func TestMariaDBUnpublished(t *testing.T) {
	env, dr := dbServerEnv(t)
	if _, err := call(t, env, dbRemote, map[string]any{"engine": "mariadb", "enabled": true, "anywhere": true}); err != nil {
		t.Fatal(err)
	}
	if !ran(dr, "ufw allow 3306/tcp comment wspanel-db-mariadb") {
		t.Fatal("anywhere opens the port")
	}
	if _, err := call(t, env, dbRemote, map[string]any{"engine": "mariadb", "enabled": false}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(mariadbRemoteConf(env)); !os.IsNotExist(err) {
		t.Fatal("bind config left behind")
	}
	if !strings.Contains(stdinOf(dr, "mariadb", "DROP USER IF EXISTS"), "DROP USER IF EXISTS 'root'@'%', 'shop'@'%'") {
		t.Fatal("network accounts not dropped")
	}
	if _, err := os.Stat(filepath.Join(env.Cfg.Fail2banDir, "jail.d/wspanel-db.conf")); !os.IsNotExist(err) {
		t.Fatal("jail left behind")
	}
	if loadDBServer(env)["mariadb"].Remote {
		t.Fatal("state still says published")
	}
}

func TestPostgresPublished(t *testing.T) {
	env, dr := dbServerEnv(t)
	if _, err := call(t, env, dbRemote, map[string]any{"engine": "postgresql", "enabled": true, "allow": []string{"2001:db8::1"}}); err != nil {
		t.Fatal(err)
	}
	main := filepath.Join(env.Cfg.PostgresEtcDir, "17/main")
	conf, _ := os.ReadFile(filepath.Join(main, "conf.d/90-wspanel-remote.conf"))
	if !strings.Contains(string(conf), "listen_addresses = '*'") || !strings.Contains(string(conf), "ssl = on") {
		t.Fatalf("conf: %s", conf)
	}
	hba, _ := os.ReadFile(filepath.Join(main, "pg_hba.conf"))
	if !strings.Contains(string(hba), "host all all 127.0.0.1/32 scram-sha-256") || !strings.Contains(string(hba), "hostssl all all 2001:db8::1/128 scram-sha-256") {
		t.Fatalf("hba: %s", hba)
	}
	if !ran(dr, "systemctl restart postgresql.service") || !ran(dr, "ufw allow proto tcp from 2001:db8::1/128 to any port 5432 comment wspanel-db-postgresql") {
		t.Fatal("not restarted / not opened")
	}
	filter, _ := os.ReadFile(filepath.Join(env.Cfg.Fail2banDir, "filter.d/wspanel-postgres.conf"))
	if !strings.Contains(string(filter), "password authentication failed") {
		t.Fatal("no fail2ban filter")
	}

	// Switching the allowlist rewrites the block instead of adding to it; off removes it.
	if _, err := call(t, env, dbRemote, map[string]any{"engine": "postgresql", "enabled": true, "anywhere": true}); err != nil {
		t.Fatal(err)
	}
	hba, _ = os.ReadFile(filepath.Join(main, "pg_hba.conf"))
	if strings.Count(string(hba), pgHBABegin) != 1 || strings.Contains(string(hba), "2001:db8::1") || !strings.Contains(string(hba), "hostssl all all 0.0.0.0/0 scram-sha-256") {
		t.Fatalf("hba after change: %s", hba)
	}
	if _, err := call(t, env, dbRemote, map[string]any{"engine": "postgresql", "enabled": false}); err != nil {
		t.Fatal(err)
	}
	hba, _ = os.ReadFile(filepath.Join(main, "pg_hba.conf"))
	if string(hba) != "local all postgres peer\nhost all all 127.0.0.1/32 scram-sha-256\n" {
		t.Fatalf("hba not restored: %q", hba)
	}
}

func TestToolAccessToEveryDatabase(t *testing.T) {
	env, dr := dbServerEnv(t)
	if _, err := call(t, env, dbAccess, map[string]any{"engine": "mariadb", "tool": "adminer", "scope": "all", "name": "shop"}); code(err) != protocol.CodeInvalidParams {
		t.Fatal("a name with scope all is ambiguous")
	}
	if _, err := call(t, env, dbAccess, map[string]any{"engine": "mariadb", "tool": "adminer", "scope": "everything"}); code(err) != protocol.CodeInvalidParams {
		t.Fatal("unknown scope accepted")
	}
	env.Cfg.DBTokenDir = t.TempDir()
	if _, err := call(t, env, dbAccess, map[string]any{"engine": "mariadb", "tool": "adminer", "scope": "all"}); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(stdinOf(dr, "mariadb", "wsptmp_"), "GRANT ALL PRIVILEGES ON *.* TO") {
		t.Fatal("not a server-wide user")
	}
	if _, err := call(t, env, dbAccess, map[string]any{"engine": "postgresql", "tool": "adminer", "scope": "all"}); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(stdinOf(dr, "setpriv", "wsptmp_"), "IN ROLE postgres; ALTER ROLE") {
		t.Fatal("postgres temp user is not a member of postgres")
	}
	for _, a := range loadAccess(env) {
		if a.DB != "" {
			t.Fatalf("scope all is recorded with an empty database: %+v", a)
		}
	}
}

func TestReplaceManagedBlockIsIdempotent(t *testing.T) {
	once := replaceManagedBlock("a\nb\n", "# B", "# E", []string{"x"})
	twice := replaceManagedBlock(once, "# B", "# E", []string{"x"})
	if once != twice || once != "a\nb\n\n# B\nx\n# E\n" {
		t.Fatalf("%q / %q", once, twice)
	}
	if replaceManagedBlock(once, "# B", "# E", nil) != "a\nb\n" {
		t.Fatal("block not removed")
	}
}

func TestDBCertIsGeneratedInProcessAndFingerprinted(t *testing.T) {
	env, dr := dbServerEnv(t)
	crt, key, err := ensureDBCert(t.Context(), env, "mariadb")
	if err != nil {
		t.Fatal(err)
	}
	if fi, err := os.Stat(key); err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("key: %v %v", fi, err)
	}
	if b, _ := os.ReadFile(crt); !strings.Contains(string(b), "BEGIN CERTIFICATE") {
		t.Fatal("no certificate")
	}
	if len(dbCertFingerprint(env, "mariadb")) != 64 {
		t.Fatal("no fingerprint")
	}
	for _, c := range dr.Calls() {
		if c.Name == "openssl" || c.Name == "chown" || c.Name == "chmod" {
			t.Fatalf("no external binary for the certificate: %s", c)
		}
	}
	// A second call keeps the certificate (clients may have pinned it).
	before, _ := os.ReadFile(crt)
	if _, _, err := ensureDBCert(t.Context(), env, "mariadb"); err != nil {
		t.Fatal(err)
	}
	if after, _ := os.ReadFile(crt); string(after) != string(before) {
		t.Fatal("certificate replaced")
	}
}

func TestAllowlistNeedsAnActiveFirewall(t *testing.T) {
	env, dr := dbServerEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "ufw" {
			return executor.Result{Stdout: "Status: inactive\n"}, nil
		}
		return executor.Result{}, nil
	}
	if _, err := call(t, env, dbRemote, map[string]any{"engine": "postgresql", "enabled": true, "allow": []string{"10.8.0.0/24"}}); code(err) != protocol.CodeConflict {
		t.Fatalf("allowlist without a firewall: %v", err)
	}
	if loadDBServer(env)["postgresql"].Remote {
		t.Fatal("published anyway")
	}
}

func TestMariaDBRefusesIPv6Networks(t *testing.T) {
	env, _ := dbServerEnv(t)
	if _, err := call(t, env, dbRemote, map[string]any{"engine": "mariadb", "enabled": true, "allow": []string{"2001:db8::/32"}}); code(err) != protocol.CodeInvalidParams {
		t.Fatalf("IPv6 network accepted for MariaDB: %v", err)
	}
	if hosts := mariadbNetHosts(dbServerState{Remote: true, Allow: []string{"2001:db8::1/128", "192.0.2.0/25"}}); strings.Join(hosts, " ") != "2001:db8::1 192.0.2.0/255.255.255.128" {
		t.Fatal(hosts)
	}
}

func TestPgHBAIsNotReadThroughASymlink(t *testing.T) {
	env, _ := dbServerEnv(t)
	hba := filepath.Join(env.Cfg.PostgresEtcDir, "17/main/pg_hba.conf")
	secret := filepath.Join(t.TempDir(), "shadow")
	if err := os.WriteFile(secret, []byte("root:$6$secret\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	_ = os.Remove(hba)
	if err := os.Symlink(secret, hba); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, dbRemote, map[string]any{"engine": "postgresql", "enabled": true, "anywhere": true}); err == nil {
		t.Fatal("followed a symlinked pg_hba.conf")
	}
	if b, _ := os.ReadFile(secret); string(b) != "root:$6$secret\n" {
		t.Fatal("the link target was touched")
	}
}
