package ops

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha1"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Database servers as a whole: the superuser's password (root / postgres) and
// access from the internet.
//
// The agent never depends on that password: it keeps talking to MariaDB over
// the socket (unix_socket) and to PostgreSQL as the postgres OS user (peer).
// A network login always needs TLS (REQUIRE SSL / hostssl); local connections
// of the sites (127.0.0.1) are left exactly as they were.

type dbServerState struct {
	Remote   bool     `json:"remote"`
	Anywhere bool     `json:"anywhere"`
	Allow    []string `json:"allow,omitempty"`
	// RootHash is MariaDB's mysql_native_password hash of the root password
	// (never the password): with it the agent creates root@'%' when remote
	// access is switched on later. Root-only state file, like mysql.user.
	RootHash     string    `json:"root_hash,omitempty"`
	RootPassword bool      `json:"root_password"`
	RootSetAt    time.Time `json:"root_set_at,omitzero"`
}

func dbServerStatePath(env *Env) string { return filepath.Join(env.Cfg.StateDir, "dbserver.json") }

func loadDBServer(env *Env) map[string]dbServerState {
	st := map[string]dbServerState{}
	if b, err := os.ReadFile(dbServerStatePath(env)); err == nil {
		_ = json.Unmarshal(b, &st)
	}
	return st
}

func saveDBServer(env *Env, st map[string]dbServerState) error {
	b, _ := json.MarshalIndent(st, "", "  ")
	return env.FS.WriteFile(dbServerStatePath(env), b, 0o600, fsx.Root)
}

func rootUser(engine string) string {
	if engine == "mariadb" {
		return "root"
	}
	return "postgres"
}

// Printable ASCII without quotes, backslash, backtick and space: safe inside a
// single-quoted SQL literal and in client config files.
var rootPasswordRe = regexp.MustCompile(`^[A-Za-z0-9!#$%&()*+,\-./:;<=>?@\[\]^_{|}~]{16,128}$`)

// nativeHash is MariaDB's mysql_native_password hash: "*" + SHA1(SHA1(pw)).
func nativeHash(pw string) string {
	a := sha1.Sum([]byte(pw))
	b := sha1.Sum(a[:])
	return "*" + strings.ToUpper(hex.EncodeToString(b[:]))
}

var nativeHashRe = regexp.MustCompile(`^\*[0-9A-F]{40}$`)

// ---------------------------------------------------------------- db.server_status

func dbServerStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	st := loadDBServer(env)
	engines := map[string]any{}
	for _, name := range env.Cfg.DBEngines {
		e, err := engineFor(env, name)
		if err != nil {
			continue
		}
		s := st[name]
		allow := s.Allow
		if allow == nil {
			allow = []string{}
		}
		info := map[string]any{
			"port": e.port(), "remote": s.Remote, "anywhere": s.Anywhere, "allow": allow,
			"root_user": rootUser(name), "root_password": s.RootPassword,
		}
		if !s.RootSetAt.IsZero() {
			info["root_set_at"] = s.RootSetAt
		}
		if v, err := e.version(ctx, env); err == nil && v != "" {
			info["version"] = v
		}
		if fp := dbCertFingerprint(env, name); fp != "" {
			info["tls_sha256"] = fp
		}
		engines[name] = info
	}
	return map[string]any{"engines": engines}, nil
}

// ---------------------------------------------------------------- db.root_password

// dbRootPassword sets (or removes) the password of root / postgres. A
// generated password is returned once; the agent keeps no copy of it.
func dbRootPassword(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Engine   string `json:"engine"`
		Password string `json:"password,omitempty"`
		Remove   bool   `json:"remove,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if _, err := engineFor(env, p.Engine); err != nil {
		return nil, err
	}
	generated := false
	if !p.Remove {
		if p.Password == "" {
			p.Password, generated = newPassword(), true
		} else if !rootPasswordRe.MatchString(p.Password) {
			return nil, protocol.Invalid("password: 16-128 characters, letters, digits and punctuation without quotes, backslash, backtick or space")
		}
	} else if p.Password != "" {
		return nil, protocol.Invalid("password: not accepted with remove")
	}

	st := loadDBServer(env)
	s := st[p.Engine]
	switch p.Engine {
	case "mariadb":
		m := mariadb{}
		if p.Remove {
			drop := ""
			if hosts := mariadbNetHosts(s); len(hosts) > 0 {
				drop = " DROP USER IF EXISTS " + mariadbAccounts("root", hosts) + ";"
			}
			if _, err := m.sql(ctx, env, "ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket; DROP USER IF EXISTS 'root'@'%';"+drop); err != nil {
				return nil, err
			}
			s.RootHash = ""
		} else {
			h := nativeHash(p.Password)
			q := fmt.Sprintf("ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket OR mysql_native_password USING '%s';", h)
			q += rootRemoteSQL(h, mariadbNetHosts(s))
			if _, err := m.sql(ctx, env, q); err != nil {
				return nil, err
			}
			s.RootHash = h
		}
	default:
		q := "ALTER ROLE postgres PASSWORD NULL"
		if !p.Remove {
			q = fmt.Sprintf("ALTER ROLE postgres PASSWORD '%s'", p.Password)
		}
		if _, err := (postgres{}).psql(ctx, env, "postgres", q); err != nil {
			return nil, err
		}
	}
	s.RootPassword = !p.Remove
	s.RootSetAt = time.Time{}
	if !p.Remove {
		s.RootSetAt = time.Now().UTC()
	}
	st[p.Engine] = s
	if err := saveDBServer(env, st); err != nil {
		return nil, err
	}
	out := map[string]any{"engine": p.Engine, "user": rootUser(p.Engine), "root_password": s.RootPassword}
	if generated {
		out["password"] = p.Password
	}
	return out, nil
}

func rootRemoteSQL(hash string, hosts []string) string {
	var q strings.Builder
	for _, h := range hosts {
		fmt.Fprintf(&q, "CREATE OR REPLACE USER 'root'@'%[2]s' IDENTIFIED BY PASSWORD '%[1]s' REQUIRE SSL; "+
			"GRANT ALL PRIVILEGES ON *.* TO 'root'@'%[2]s' WITH GRANT OPTION; ", hash, h)
	}
	return q.String()
}

// ---------------------------------------------------------------- db.remote

const dbRemoteMaxAllow = 50

func dbRemote(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Engine   string   `json:"engine"`
		Enabled  bool     `json:"enabled"`
		Anywhere bool     `json:"anywhere"`
		Allow    []string `json:"allow"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	e, err := engineFor(env, p.Engine)
	if err != nil {
		return nil, err
	}
	allow, err := normalizeAllow(p.Allow)
	if err != nil {
		return nil, err
	}
	if p.Enabled && !p.Anywhere && len(allow) == 0 {
		return nil, protocol.Invalid("allow: list the addresses allowed to connect, or set anywhere")
	}
	if p.Engine == "mariadb" {
		for _, a := range allow {
			if pfx := netip.MustParsePrefix(a); pfx.Addr().Is6() && pfx.Bits() != 128 {
				return nil, protocol.Invalid("allow: MariaDB accepts single IPv6 addresses only, not " + a)
			}
		}
	}
	if p.Enabled && !p.Anywhere {
		// The allowlist must not rest on the firewall alone, but it must not run
		// without it either: an inactive ufw would leave the port open to all.
		res, err := env.Exec.Run(ctx, "ufw", "status")
		if err != nil {
			return nil, err
		}
		if active, _ := parseUFWStatus(res.Stdout); !active {
			return nil, protocol.Conflict("the firewall (ufw) is not active; an allowlist needs it")
		}
	}

	st := loadDBServer(env)
	s := st[p.Engine]
	prev := s
	s.Remote = p.Enabled
	s.Anywhere = p.Enabled && p.Anywhere
	s.Allow = nil
	if p.Enabled && !p.Anywhere {
		s.Allow = allow
	}

	switch p.Engine {
	case "mariadb":
		err = applyMariaDBRemote(ctx, env, prev, s)
	default:
		err = applyPostgresRemote(ctx, env, s)
	}
	if err != nil {
		return nil, err
	}
	if err := syncDBFirewall(ctx, env, p.Engine, e.port(), s); err != nil {
		return nil, err
	}
	st[p.Engine] = s
	if err := saveDBServer(env, st); err != nil {
		return nil, err
	}
	out := map[string]any{"engine": p.Engine, "remote": s.Remote, "anywhere": s.Anywhere, "allow": allow, "port": e.port()}
	if err := writeDBFail2ban(ctx, env, st); err != nil {
		out["warning"] = "fail2ban: " + err.Error()
	}
	return out, nil
}

// normalizeAllow accepts IPv4/IPv6 addresses and prefixes; /0 must be asked
// for as "anywhere" so that it is never an accident.
func normalizeAllow(in []string) ([]string, error) {
	if len(in) > dbRemoteMaxAllow {
		return nil, protocol.Invalid(fmt.Sprintf("allow: at most %d entries", dbRemoteMaxAllow))
	}
	var out []string
	for _, a := range in {
		a = strings.TrimSpace(a)
		if a == "" {
			continue
		}
		var pfx netip.Prefix
		if p, err := netip.ParsePrefix(a); err == nil {
			pfx = p.Masked()
		} else if addr, err := netip.ParseAddr(a); err == nil {
			pfx = netip.PrefixFrom(addr, addr.BitLen())
		} else {
			return nil, protocol.Invalid("allow: " + a + " is not an IP address or network")
		}
		if pfx.Bits() == 0 {
			return nil, protocol.Invalid("allow: " + a + " means everyone; use anywhere")
		}
		if !slices.Contains(out, pfx.String()) {
			out = append(out, pfx.String())
		}
	}
	if out == nil {
		out = []string{}
	}
	return out, nil
}

// ---------------------------------------------------------------- MariaDB

// mariadbNetHosts: the host part of the network accounts. With an allowlist
// MariaDB itself checks the address too (one account per address or IPv4
// network), so nothing rests on the firewall alone; '%' only for anywhere.
func mariadbNetHosts(s dbServerState) []string {
	if !s.Remote {
		return nil
	}
	if s.Anywhere {
		return []string{"%"}
	}
	var hosts []string
	for _, a := range s.Allow {
		pfx, err := netip.ParsePrefix(a)
		if err != nil {
			continue
		}
		addr := pfx.Addr()
		if pfx.Bits() == addr.BitLen() {
			hosts = append(hosts, addr.String())
			continue
		}
		if addr.Is4() {
			mask := ^uint32(0) << (32 - pfx.Bits())
			hosts = append(hosts, fmt.Sprintf("%s/%d.%d.%d.%d", addr, mask>>24, mask>>16&0xff, mask>>8&0xff, mask&0xff))
		}
	}
	return hosts
}

func mariadbAccounts(name string, hosts []string) string {
	out := make([]string, 0, len(hosts))
	for _, h := range hosts {
		out = append(out, fmt.Sprintf("'%s'@'%s'", name, h))
	}
	return strings.Join(out, ", ")
}

func mariadbRemoteConf(env *Env) string {
	return filepath.Join(env.Cfg.MariaDBConfDir, "90-wspanel-remote.cnf")
}

func applyMariaDBRemote(ctx context.Context, env *Env, prev, s dbServerState) error {
	m := mariadb{}
	owners, err := mariadbOwnerHashes(ctx, env)
	if err != nil {
		return err
	}
	names := make([]string, 0, len(owners))
	for name := range owners {
		names = append(names, name)
	}
	slices.Sort(names)

	// The accounts of the previous setting go first ('%' always: also what an
	// older agent created), then the new ones are made.
	old := mariadbNetHosts(prev)
	if !slices.Contains(old, "%") {
		old = append([]string{"%"}, old...)
	}
	var drop []string
	for _, user := range append([]string{"root"}, names...) {
		drop = append(drop, mariadbAccounts(user, old))
	}
	if _, err := m.sql(ctx, env, "DROP USER IF EXISTS "+strings.Join(drop, ", ")+";"); err != nil {
		return err
	}

	if !s.Remote {
		if err := os.Remove(mariadbRemoteConf(env)); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
		_, err := env.Exec.Run(ctx, "systemctl", "restart", "mariadb.service")
		return err
	}

	crt, key, err := ensureDBCert(ctx, env, "mariadb")
	if err != nil {
		return err
	}
	conf := "# Managed by WSPanel agent (db.remote). Do not edit.\n[mariadbd]\nbind-address = *\n" +
		"ssl_cert = " + crt + "\nssl_key = " + key + "\n" +
		"# failed logins in the journal with the client's IP (not a resolved name), for fail2ban;\n" +
		"# accounts then match by IP only, as the network accounts are written\nskip-name-resolve = ON\nlog_warnings = 2\n"
	if err := env.FS.WriteFile(mariadbRemoteConf(env), []byte(conf), 0o644, fsx.Root); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "restart", "mariadb.service"); err != nil {
		return err
	}
	// Network accounts mirror the local ones (same password hash), TLS only.
	hosts := mariadbNetHosts(s)
	var q strings.Builder
	for _, name := range names {
		for _, h := range hosts {
			fmt.Fprintf(&q, "CREATE OR REPLACE USER '%[1]s'@'%[4]s' IDENTIFIED BY PASSWORD '%[2]s' REQUIRE SSL; GRANT ALL PRIVILEGES ON `%[3]s`.* TO '%[1]s'@'%[4]s'; ",
				name, owners[name], grantPattern(name), h)
		}
	}
	if s.RootHash != "" {
		q.WriteString(rootRemoteSQL(s.RootHash, hosts))
	}
	if q.Len() == 0 {
		return nil
	}
	_, err = m.sql(ctx, env, q.String())
	return err
}

// mariadbOwnerHashes: the local accounts the panel created (one per database,
// same name) and their password hashes.
func mariadbOwnerHashes(ctx context.Context, env *Env) (map[string]string, error) {
	m := mariadb{}
	dbs, err := m.list(ctx, env)
	if err != nil {
		return nil, err
	}
	out := map[string]string{}
	if len(dbs) == 0 {
		return out, nil
	}
	names := make([]string, 0, len(dbs))
	for _, d := range dbs {
		names = append(names, "'"+d.Name+"'") // names come from the server's own list of schemas
	}
	res, err := m.sql(ctx, env, "SELECT User, authentication_string FROM mysql.user WHERE Host = 'localhost' AND User IN ("+strings.Join(names, ",")+")")
	if err != nil {
		return nil, err
	}
	for _, line := range strings.Split(strings.TrimSpace(res), "\n") {
		user, hash, ok := strings.Cut(strings.TrimSpace(line), "\t")
		if ok && nativeHashRe.MatchString(hash) && slices.Contains(names, "'"+user+"'") {
			out[user] = hash
		}
	}
	return out, nil
}

// ensureDBCert makes the self-signed certificate the engine serves for TLS
// (clients connect with sslmode=require / --ssl; the fingerprint is shown in
// the panel for those who verify it). Generated here, no openssl binary.
func ensureDBCert(_ context.Context, env *Env, engine string) (string, string, error) {
	dir := filepath.Join(env.Cfg.DBTLSDir, engine)
	crt, key := filepath.Join(dir, "server.crt"), filepath.Join(dir, "server.key")
	owner, err := lookupOwner(env, "mysql")
	if err != nil {
		return "", "", err
	}
	if err := env.FS.MkdirAll(dir, 0o750, owner); err != nil {
		return "", "", err
	}
	if fsx.Exists(crt) && fsx.Exists(key) {
		return crt, key, nil
	}
	cn := env.Cfg.PanelDomain
	if cn == "" {
		cn, _ = os.Hostname()
	}
	certPEM, keyPEM, err := selfSignedCert(cn, 10*365*24*time.Hour)
	if err != nil {
		return "", "", fmt.Errorf("creating the TLS certificate failed: %w", err)
	}
	if err := env.FS.WriteFile(key, keyPEM, 0o600, owner); err != nil {
		return "", "", err
	}
	if err := env.FS.WriteFile(crt, certPEM, 0o644, owner); err != nil {
		return "", "", err
	}
	return crt, key, nil
}

func selfSignedCert(cn string, validity time.Duration) ([]byte, []byte, error) {
	priv, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 127))
	if err != nil {
		return nil, nil, err
	}
	tmpl := &x509.Certificate{
		SerialNumber:          serial,
		Subject:               pkix.Name{CommonName: cn},
		DNSNames:              []string{cn},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().Add(validity),
		KeyUsage:              x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &priv.PublicKey, priv)
	if err != nil {
		return nil, nil, err
	}
	keyDER, err := x509.MarshalPKCS8PrivateKey(priv)
	if err != nil {
		return nil, nil, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}), nil
}

func dbCertFingerprint(env *Env, engine string) string {
	if engine != "mariadb" {
		return ""
	}
	b, err := os.ReadFile(filepath.Join(env.Cfg.DBTLSDir, engine, "server.crt"))
	if err != nil {
		return ""
	}
	block, _ := pem.Decode(b)
	if block == nil {
		return ""
	}
	if _, err := x509.ParseCertificate(block.Bytes); err != nil {
		return ""
	}
	sum := sha256.Sum256(block.Bytes)
	return strings.ToUpper(hex.EncodeToString(sum[:]))
}

// ---------------------------------------------------------------- PostgreSQL

const (
	pgHBABegin = "# BEGIN WSPanel remote access (db.remote). Do not edit."
	pgHBAEnd   = "# END WSPanel remote access"
)

func postgresClusters(env *Env) ([]string, error) {
	dirs, _ := filepath.Glob(filepath.Join(env.Cfg.PostgresEtcDir, "*", "main"))
	if len(dirs) == 0 {
		return nil, protocol.NotFound("PostgreSQL configuration not found in " + env.Cfg.PostgresEtcDir)
	}
	return dirs, nil
}

func applyPostgresRemote(ctx context.Context, env *Env, s dbServerState) error {
	clusters, err := postgresClusters(env)
	if err != nil {
		return err
	}
	owner, err := lookupOwner(env, "postgres")
	if err != nil {
		return err
	}
	var hba []string
	if s.Remote {
		nets := s.Allow
		if s.Anywhere {
			nets = []string{"0.0.0.0/0", "::/0"}
		}
		for _, n := range nets {
			hba = append(hba, "hostssl all all "+n+" scram-sha-256")
		}
	}
	for _, dir := range clusters {
		conf := filepath.Join(dir, "conf.d", "90-wspanel-remote.conf")
		if s.Remote {
			if err := env.FS.MkdirAll(filepath.Join(dir, "conf.d"), 0o755, owner); err != nil {
				return err
			}
			body := "# Managed by WSPanel agent (db.remote). Do not edit.\nlisten_addresses = '*'\nssl = on\n" +
				"password_encryption = scram-sha-256\n# client address in the log, for fail2ban\nlog_line_prefix = '%m [%p] %h %q%u@%d '\n"
			if err := env.FS.WriteFile(conf, []byte(body), 0o644, owner); err != nil {
				return err
			}
		} else if err := os.Remove(conf); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
		hbaPath := filepath.Join(dir, "pg_hba.conf")
		cur, err := readConfigNoFollow(hbaPath)
		if err != nil {
			return err
		}
		if err := env.FS.WriteFile(hbaPath, []byte(replaceManagedBlock(string(cur), pgHBABegin, pgHBAEnd, hba)), 0o640, owner); err != nil {
			return err
		}
	}
	_, err = env.Exec.Run(ctx, "systemctl", "restart", "postgresql.service")
	return err
}

// readConfigNoFollow reads a config file that lives in a directory another
// user owns (pg_hba.conf is postgres's): never through a symlink or a hard
// link, so root cannot be tricked into reading, say, /etc/shadow and writing
// it back with that user as the owner.
func readConfigNoFollow(path string) ([]byte, error) {
	f, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil || !fi.Mode().IsRegular() {
		return nil, fmt.Errorf("%s is not a regular file", path)
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); ok && st.Nlink != 1 {
		return nil, fmt.Errorf("%s has more than one link", path)
	}
	if fi.Size() > 1<<20 {
		return nil, fmt.Errorf("%s is larger than 1 MB", path)
	}
	return io.ReadAll(f)
}

// replaceManagedBlock swaps the lines between begin and end (appending the
// block when absent, dropping it when lines is empty).
func replaceManagedBlock(content, begin, end string, lines []string) string {
	var kept []string
	inside := false
	for _, l := range strings.Split(strings.TrimRight(content, "\n"), "\n") {
		switch {
		case l == begin:
			inside = true
		case l == end:
			inside = false
		case !inside:
			kept = append(kept, l)
		}
	}
	for len(kept) > 0 && kept[len(kept)-1] == "" {
		kept = kept[:len(kept)-1]
	}
	if len(lines) > 0 {
		kept = append(kept, "", begin)
		kept = append(kept, lines...)
		kept = append(kept, end)
	}
	return strings.Join(kept, "\n") + "\n"
}

// ---------------------------------------------------------------- firewall + fail2ban

func dbFirewallComment(engine string) string { return "wspanel-db-" + engine }

// syncDBFirewall replaces the engine's rules (tagged by comment) with the ones
// the state asks for.
func syncDBFirewall(ctx context.Context, env *Env, engine string, port int, s dbServerState) error {
	res, err := env.Exec.Run(ctx, "ufw", "status")
	if err != nil {
		return err
	}
	_, rules := parseUFWStatus(res.Stdout)
	comment := dbFirewallComment(engine)
	p := strconv.Itoa(port)
	for _, r := range rules {
		if r.Comment != comment {
			continue
		}
		args := []string{"delete", "allow", p + "/tcp"}
		if from := strings.TrimSuffix(r.From, " (v6)"); from != "Anywhere" {
			args = []string{"delete", "allow", "proto", "tcp", "from", from, "to", "any", "port", p}
		}
		if _, err := env.Exec.Run(ctx, "ufw", args...); err != nil {
			return err
		}
	}
	if !s.Remote {
		return nil
	}
	if s.Anywhere {
		_, err := env.Exec.Run(ctx, "ufw", "allow", p+"/tcp", "comment", comment)
		return err
	}
	for _, n := range s.Allow {
		if _, err := env.Exec.Run(ctx, "ufw", "allow", "proto", "tcp", "from", n, "to", "any", "port", p, "comment", comment); err != nil {
			return err
		}
	}
	return nil
}

// The stock mysqld-auth filter looks for a "mysqld" process; Debian 13 runs "mariadbd".
const mariadbFail2banFilter = `# Managed by WSPanel agent (db.remote).
[Definition]
failregex = ^.*\[Warning\] Access denied for user '[^']+'@'<HOST>'
ignoreregex =
`

const pgFail2banFilter = `# Managed by WSPanel agent (db.remote).
[Definition]
failregex = ^\S+ \S+ \S+ \[\d+\] <HOST> .*FATAL:  password authentication failed
            ^\S+ \S+ \S+ \[\d+\] <HOST> .*FATAL:  no pg_hba\.conf entry
ignoreregex =
`

// writeDBFail2ban: failed network logins to a published engine get banned
// (5 attempts in 10 minutes, 1 hour), for allowlisted and open servers alike.
func writeDBFail2ban(ctx context.Context, env *Env, st map[string]dbServerState) error {
	jail := filepath.Join(env.Cfg.Fail2banDir, "jail.d", "wspanel-db.conf")
	filter := filepath.Join(env.Cfg.Fail2banDir, "filter.d", "wspanel-postgres.conf")
	mfilter := filepath.Join(env.Cfg.Fail2banDir, "filter.d", "wspanel-mariadb.conf")
	var b strings.Builder
	b.WriteString("# Managed by WSPanel agent (db.remote).\n")
	if st["mariadb"].Remote {
		if err := env.FS.MkdirAll(filepath.Dir(mfilter), 0o755, fsx.Root); err != nil {
			return err
		}
		if err := env.FS.WriteFile(mfilter, []byte(mariadbFail2banFilter), 0o644, fsx.Root); err != nil {
			return err
		}
		b.WriteString("\n[wspanel-mariadb]\nenabled = true\nfilter = wspanel-mariadb\nbackend = systemd\njournalmatch = _SYSTEMD_UNIT=mariadb.service\nport = 3306\nmaxretry = 5\nfindtime = 10m\nbantime = 1h\n")
	}
	if st["postgresql"].Remote {
		if err := env.FS.MkdirAll(filepath.Dir(filter), 0o755, fsx.Root); err != nil {
			return err
		}
		if err := env.FS.WriteFile(filter, []byte(pgFail2banFilter), 0o644, fsx.Root); err != nil {
			return err
		}
		b.WriteString("\n[wspanel-postgres]\nenabled = true\nfilter = wspanel-postgres\nbackend = auto\nlogpath = /var/log/postgresql/postgresql-*-main.log\nport = 5432\nmaxretry = 5\nfindtime = 10m\nbantime = 1h\n")
	}
	if !st["mariadb"].Remote && !st["postgresql"].Remote {
		if err := os.Remove(jail); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	} else {
		if err := env.FS.MkdirAll(filepath.Dir(jail), 0o755, fsx.Root); err != nil {
			return err
		}
		if err := env.FS.WriteFile(jail, []byte(b.String()), 0o644, fsx.Root); err != nil {
			return err
		}
	}
	_, err := env.Exec.Run(ctx, "systemctl", "reload-or-restart", "fail2ban.service")
	return err
}
