package ops

import (
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"hash/crc32"
	"io"
	"net"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
)

// Replication of the shared database servers. Either node may be the
// source (after a failover the old primary becomes the replica), so both
// carry the same accounts and the same settings, only their server ids and
// read-only differ:
//
//   - MariaDB: binary log with GTIDs on both nodes; the replica is seeded
//     from a consistent dump of the source (streamed through the link, with
//     its GTID position), then replicates through a tunnel and is read-only.
//   - PostgreSQL: physical streaming replication with a replication slot;
//     the replica is a base backup of the source (pg_basebackup -R), hot
//     standby.
//
// The replication accounts exist on loopback only (the tunnel's far end is
// the source's own agent).

const (
	replUser        = "wspanel_repl"
	pgSlot          = "wspanel_mirror"
	mariadbMirrorCf = "70-wspanel-mirror.cnf"
	pgMirrorConf    = "70-wspanel-mirror.conf"
	pgHBAMirrorBeg  = "# BEGIN WSPanel mirror (replication). Do not edit."
	pgHBAMirrorEnd  = "# END WSPanel mirror"
)

// Local ports of the standby's tunnels (loopback, guarded by uid).
const (
	tunnelPortMariaDB  = 17306
	tunnelPortPostgres = 17432
	tunnelPortKVBase   = 17600
	tunnelPortKVMax    = 17999
)

func mariadbServerID(nodeID string) uint32 {
	return 1 + crc32.ChecksumIEEE([]byte(nodeID))%4000000000
}

// mariadbMirrorConfig: the node's replication settings; a replica is
// read-only (its SUPER users and the replication thread still write).
func mariadbMirrorConfig(nodeID string, replica bool) []byte {
	var b strings.Builder
	b.WriteString("# Managed by WSPanel agent (mirror). Do not edit.\n[mariadbd]\n")
	fmt.Fprintf(&b, "server_id = %d\n", mariadbServerID(nodeID))
	b.WriteString("log_bin = wspanel-bin\nbinlog_format = ROW\nlog_slave_updates = ON\ngtid_strict_mode = ON\n" +
		"binlog_expire_logs_seconds = 259200\nslave_net_timeout = 30\n")
	if replica {
		b.WriteString("read_only = ON\nskip_slave_start = OFF\n")
	}
	return []byte(b.String())
}

// writeIfChanged: true when the file changed.
func writeFileIfChanged(env *Env, path string, data []byte, mode os.FileMode, owner fsx.Owner) (bool, error) {
	if cur, err := os.ReadFile(path); err == nil && string(cur) == string(data) {
		return false, nil
	}
	return true, env.FS.WriteFile(path, data, mode, owner)
}

func sqlQuote(s string) string {
	return strings.ReplaceAll(strings.ReplaceAll(s, `\`, `\\`), `'`, `''`)
}

// ---------------------------------------------------------------- the source

// prepareReplicationSource: this node's servers as a replication source
// (the peer asks before seeding). Idempotent.
func prepareReplicationSource(ctx context.Context, env *Env) (any, error) {
	st := currentMirror(env)
	out := map[string]any{}
	if slices.Contains(env.Cfg.DBEngines, "mariadb") {
		if err := mariadbAsSource(ctx, env, st, false); err != nil {
			return nil, fmt.Errorf("MariaDB: %w", err)
		}
		out["mariadb"] = true
	}
	if slices.Contains(env.Cfg.DBEngines, "postgresql") {
		if err := postgresAsSource(ctx, env, st); err != nil {
			return nil, fmt.Errorf("PostgreSQL: %w", err)
		}
		out["postgresql"] = true
	}
	return out, nil
}

// mariadbAsSource: binary log and the replication account (also on a
// replica: it may become the source).
func mariadbAsSource(ctx context.Context, env *Env, st *mirrorState, replica bool) error {
	changed, err := writeFileIfChanged(env, filepath.Join(env.Cfg.MariaDBConfDir, mariadbMirrorCf), mariadbMirrorConfig(st.NodeID, replica), 0o644, fsx.Root)
	if err != nil {
		return err
	}
	if changed {
		if _, err := env.Exec.Run(ctx, "systemctl", "restart", "mariadb.service"); err != nil {
			return err
		}
	}
	if replica {
		return nil // the account comes with the source's dump
	}
	pw := sqlQuote(st.Repl.MariaDB)
	_, err = (mariadb{}).sql(ctx, env, fmt.Sprintf("CREATE USER IF NOT EXISTS '%[1]s'@'127.0.0.1' IDENTIFIED BY '%[2]s'; "+
		"ALTER USER '%[1]s'@'127.0.0.1' IDENTIFIED BY '%[2]s'; GRANT REPLICATION SLAVE ON *.* TO '%[1]s'@'127.0.0.1';", replUser, pw))
	return err
}

func pgDataDir(clusterConf string) string {
	// /etc/postgresql/<ver>/main → /var/lib/postgresql/<ver>/main
	ver := filepath.Base(filepath.Dir(clusterConf))
	return filepath.Join("/var/lib/postgresql", ver, "main")
}

func pgVersionOf(clusterConf string) string { return filepath.Base(filepath.Dir(clusterConf)) }

func postgresMainCluster(env *Env) (string, error) {
	dirs, err := postgresClusters(env)
	if err != nil {
		return "", err
	}
	slices.SortFunc(dirs, func(a, b string) int { return compareVersions(pgVersionOf(a), pgVersionOf(b)) })
	return dirs[len(dirs)-1], nil
}

// postgresMirrorFiles: the settings and the replication line of pg_hba.conf
// (both nodes: either may be the source). True when a restart is needed.
func postgresMirrorFiles(ctx context.Context, env *Env, dir string) (bool, error) {
	owner, err := lookupOwner(env, "postgres")
	if err != nil {
		return false, err
	}
	if err := env.FS.MkdirAll(filepath.Join(dir, "conf.d"), 0o755, owner); err != nil {
		return false, err
	}
	conf := "# Managed by WSPanel agent (mirror). Do not edit.\nwal_level = replica\nmax_wal_senders = 10\n" +
		"max_replication_slots = 10\nwal_keep_size = 1024MB\nhot_standby = on\nhot_standby_feedback = on\n"
	restart, err := writeFileIfChanged(env, filepath.Join(dir, "conf.d", pgMirrorConf), []byte(conf), 0o644, owner)
	if err != nil {
		return false, err
	}
	hbaPath := filepath.Join(dir, "pg_hba.conf")
	cur, err := readConfigNoFollow(hbaPath)
	if err != nil {
		return false, err
	}
	next := replaceManagedBlock(string(cur), pgHBAMirrorBeg, pgHBAMirrorEnd, []string{"host replication " + replUser + " 127.0.0.1/32 scram-sha-256"})
	if next != string(cur) {
		if err := env.FS.WriteFile(hbaPath, []byte(next), 0o640, owner); err != nil {
			return false, err
		}
		if !restart {
			_, _ = (postgres{}).psql(ctx, env, "postgres", "SELECT pg_reload_conf()")
		}
	}
	return restart, nil
}

func postgresAsSource(ctx context.Context, env *Env, st *mirrorState) error {
	dir, err := postgresMainCluster(env)
	if err != nil {
		return err
	}
	restart, err := postgresMirrorFiles(ctx, env, dir)
	if err != nil {
		return err
	}
	if restart {
		if _, err := env.Exec.Run(ctx, "systemctl", "restart", "postgresql.service"); err != nil {
			return err
		}
	}
	p := postgres{}
	pw := sqlQuote(st.Repl.Postgres)
	q := fmt.Sprintf(`DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '%[1]s') THEN CREATE ROLE %[1]s WITH REPLICATION LOGIN PASSWORD '%[2]s'; `+
		`ELSE ALTER ROLE %[1]s WITH REPLICATION LOGIN PASSWORD '%[2]s'; END IF; END $$;`, replUser, pw)
	if _, err := p.psql(ctx, env, "postgres", q); err != nil {
		return err
	}
	_, err = p.psql(ctx, env, "postgres", fmt.Sprintf(`SELECT pg_create_physical_replication_slot('%s') WHERE NOT EXISTS (SELECT 1 FROM pg_replication_slots WHERE slot_name = '%[1]s')`, pgSlot))
	return err
}

// peerDump streams a dump of the source: the whole MariaDB server (seed of
// the replica), or one database (MongoDB, a dedicated server's).
func peerDump(ctx context.Context, env *Env, conn net.Conn, raw json.RawMessage) {
	var p struct {
		Kind   string `json:"kind"` // mariadb-seed | database
		Engine string `json:"engine"`
		Name   string `json:"name"`
	}
	if err := json.Unmarshal(raw, &p); err != nil {
		peerReply(conn, nil, err)
		return
	}
	if currentMirror(env).Role != rolePrimary {
		peerReply(conn, nil, errors.New("only the primary hands out dumps"))
		return
	}
	switch p.Kind {
	case "mariadb-seed":
		if !slices.Contains(env.Cfg.DBEngines, "mariadb") {
			peerReply(conn, nil, errors.New("MariaDB is not installed"))
			return
		}
		peerReply(conn, map[string]any{}, nil)
		gz := gzip.NewWriter(conn)
		_, err := env.Exec.RunIO(ctx, nil, gz, "mariadb-dump", "--all-databases", "--single-transaction", "--gtid", "--master-data=1",
			"--routines", "--events", "--triggers", "--flush-privileges", "--hex-blob",
			"--ignore-table=mysql.gtid_slave_pos", "--ignore-table=mysql.innodb_index_stats", "--ignore-table=mysql.innodb_table_stats")
		if err == nil {
			err = gz.Close()
		}
		if err != nil {
			// The receiver sees a truncated gzip stream and fails too.
			_ = conn.Close()
		}
	case "database":
		if err := (dbParams{Engine: p.Engine, Name: p.Name}).validateOnly(env); err != nil {
			peerReply(conn, nil, err)
			return
		}
		e, err := dbFor(env, p.Engine, p.Name)
		if err != nil {
			peerReply(conn, nil, err)
			return
		}
		peerReply(conn, map[string]any{"ext": e.ext()}, nil)
		if err := e.dump(ctx, env, p.Name, conn); err != nil {
			_ = conn.Close()
		}
	default:
		peerReply(conn, nil, fmt.Errorf("unknown dump %q", p.Kind))
	}
}

// ---------------------------------------------------------------- the replica

func seededMark(env *Env, engine string) string { return mirrorFile(env, "seeded-"+engine) }

// ensureReplication: the standby's replicas of the shared servers, seeded
// when they are not yet.
func ensureReplication(ctx context.Context, env *Env, st *mirrorState, peer *helloInfo) {
	if slices.Contains(env.Cfg.DBEngines, "mariadb") && slices.Contains(peer.DBEngines, "mariadb") {
		lag, detail, err := mariadbReplica(ctx, env, st)
		mirrorRuntime.layer("mariadb", err, detail, lag)
	}
	if slices.Contains(env.Cfg.DBEngines, "postgresql") && slices.Contains(peer.DBEngines, "postgresql") {
		lag, detail, err := postgresReplica(ctx, env, st)
		mirrorRuntime.layer("postgresql", err, detail, lag)
	}
}

type replicaStatus map[string]string

// mariadbSlaveStatus: SHOW SLAVE STATUS as name → value (empty: none).
func mariadbSlaveStatus(ctx context.Context, env *Env) (replicaStatus, error) {
	var out strings.Builder
	if _, err := env.Exec.RunIO(ctx, strings.NewReader("SHOW SLAVE STATUS"), &out, "mariadb", "--batch"); err != nil {
		return nil, err
	}
	lines := strings.Split(strings.TrimRight(out.String(), "\n"), "\n")
	if len(lines) < 2 {
		return replicaStatus{}, nil
	}
	cols, vals := strings.Split(lines[0], "\t"), strings.Split(lines[1], "\t")
	rs := replicaStatus{}
	for i, c := range cols {
		if i < len(vals) {
			rs[c] = vals[i]
		}
	}
	return rs, nil
}

func mariadbReplica(ctx context.Context, env *Env, st *mirrorState) (*float64, string, error) {
	if err := mariadbAsSource(ctx, env, st, true); err != nil {
		return nil, "", err
	}
	rs, err := mariadbSlaveStatus(ctx, env)
	if err != nil {
		return nil, "", err
	}
	if !fsx.Exists(seededMark(env, "mariadb")) || rs["Master_Host"] == "" {
		mirrorRuntime.event("info", "MariaDB: plnění repliky z primáru")
		if err := seedMariaDB(ctx, env, st); err != nil {
			return nil, "", fmt.Errorf("seeding the replica: %w", err)
		}
		if rs, err = mariadbSlaveStatus(ctx, env); err != nil {
			return nil, "", err
		}
	}
	if rs["Slave_IO_Running"] != "Yes" || rs["Slave_SQL_Running"] != "Yes" {
		msg := strings.TrimSpace(rs["Last_IO_Error"] + " " + rs["Last_SQL_Error"])
		if rs["Slave_IO_Running"] == "Connecting" {
			return nil, "connecting", errors.New("the replica is connecting to the primary " + msg)
		}
		// A stopped replica (after a restart) starts again by itself.
		_, _ = (mariadb{}).sql(ctx, env, "START SLAVE")
		return nil, "", fmt.Errorf("replication stopped (IO %s, SQL %s) %s", rs["Slave_IO_Running"], rs["Slave_SQL_Running"], msg)
	}
	var lag *float64
	if s, err := strconv.ParseFloat(rs["Seconds_Behind_Master"], 64); err == nil {
		lag = &s
	}
	return lag, "GTID " + rs["Gtid_IO_Pos"], nil
}

// seedMariaDB: the replica becomes a copy of the source at a GTID position
// and replicates from there. What this node had is dumped aside first.
func seedMariaDB(ctx context.Context, env *Env, st *mirrorState) error {
	if err := peerCall(ctx, env, "repl_prepare", map[string]any{}, nil); err != nil {
		return err
	}
	if err := safetyDump(ctx, env, "mariadb"); err != nil {
		return fmt.Errorf("the local copy before seeding: %w", err)
	}
	m := mariadb{}
	// Its own binary log goes too: GTIDs of its own would break strict mode.
	if _, err := m.sql(ctx, env, "STOP SLAVE; RESET SLAVE ALL; RESET MASTER;"); err != nil {
		return err
	}
	// Its databases go (the dump creates the source's; the system schema is
	// replaced table by table, never dropped).
	list, err := m.sql(ctx, env, "SHOW DATABASES")
	if err != nil {
		return err
	}
	var drop strings.Builder
	for _, d := range strings.Fields(list) {
		if !slices.Contains([]string{"mysql", "information_schema", "performance_schema", "sys"}, d) {
			fmt.Fprintf(&drop, "DROP DATABASE `%s`;", strings.ReplaceAll(d, "`", "``"))
		}
	}
	if drop.Len() > 0 {
		if _, err := m.sql(ctx, env, "SET SESSION sql_log_bin = 0;"+drop.String()); err != nil {
			return err
		}
	}
	r, closer, err := peerStream(ctx, env, "dump", map[string]any{"kind": "mariadb-seed"}, nil)
	if err != nil {
		return err
	}
	defer closer.Close()
	gz, err := gzip.NewReader(r)
	if err != nil {
		return err
	}
	// The dump sets gtid_slave_pos itself (--master-data with --gtid).
	if _, err := env.Exec.RunIO(ctx, io.MultiReader(strings.NewReader("SET SESSION sql_log_bin = 0;\n"), gz), nil, "mariadb", m.args("--binary-mode")...); err != nil {
		return err
	}
	q := fmt.Sprintf("CHANGE MASTER TO MASTER_HOST='127.0.0.1', MASTER_PORT=%d, MASTER_USER='%s', MASTER_PASSWORD='%s', "+
		"MASTER_USE_GTID=slave_pos, MASTER_CONNECT_RETRY=10; START SLAVE;", tunnelPortMariaDB, replUser, sqlQuote(st.Repl.MariaDB))
	if _, err := m.sql(ctx, env, q); err != nil {
		return err
	}
	return writeMirrorFile(env, "seeded-mariadb", []byte(time.Now().UTC().Format(time.RFC3339)+"\n"))
}

// safetyDump keeps what this node's server held before it is replaced by a
// copy of the other (a returning old primary may hold writes nobody else has).
func safetyDump(ctx context.Context, env *Env, engine string) error {
	dir := filepath.Join(env.Cfg.BackupDir, "mirror")
	if err := env.FS.MkdirAll(dir, 0o700, fsx.Root); err != nil {
		return err
	}
	path := filepath.Join(dir, engine+"-before-seed-"+time.Now().UTC().Format("20060102T150405Z")+".sql.gz")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	gz := gzip.NewWriter(f)
	_, err = env.Exec.RunIO(ctx, nil, gz, "mariadb-dump", "--all-databases", "--single-transaction", "--routines", "--events", "--triggers", "--hex-blob")
	if cerr := gz.Close(); err == nil {
		err = cerr
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		_ = os.Remove(path)
		return err
	}
	pruneMirrorSafety(dir, engine, 3)
	return nil
}

func pruneMirrorSafety(dir, engine string, keep int) {
	m, _ := filepath.Glob(filepath.Join(dir, engine+"-before-seed-*"))
	slices.Sort(m)
	for len(m) > keep {
		_ = os.RemoveAll(m[0])
		m = m[1:]
	}
}

func postgresReplica(ctx context.Context, env *Env, st *mirrorState) (*float64, string, error) {
	dir, err := postgresMainCluster(env)
	if err != nil {
		return nil, "", err
	}
	if restart, err := postgresMirrorFiles(ctx, env, dir); err != nil {
		return nil, "", err
	} else if restart && fsx.Exists(seededMark(env, "postgresql")) {
		if _, err := env.Exec.Run(ctx, "systemctl", "restart", "postgresql.service"); err != nil {
			return nil, "", err
		}
	}
	p := postgres{}
	rec, err := p.psql(ctx, env, "postgres", "SELECT pg_is_in_recovery()")
	if err != nil && fsx.Exists(seededMark(env, "postgresql")) {
		return nil, "", err
	}
	if !fsx.Exists(seededMark(env, "postgresql")) || strings.TrimSpace(rec) != "t" {
		mirrorRuntime.event("info", "PostgreSQL: plnění repliky (base backup primáru)")
		if err := seedPostgres(ctx, env, st, dir); err != nil {
			return nil, "", fmt.Errorf("seeding the replica: %w", err)
		}
	}
	out, err := p.psql(ctx, env, "postgres", `SELECT coalesce((SELECT status FROM pg_stat_wal_receiver), 'none'), `+
		`CASE WHEN pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn() THEN 0 `+
		`ELSE coalesce(extract(epoch FROM now() - pg_last_xact_replay_timestamp()), -1) END`)
	if err != nil {
		return nil, "", err
	}
	parts := strings.Split(strings.TrimSpace(out), "|")
	if len(parts) != 2 {
		return nil, "", fmt.Errorf("unexpected replica state %q", out)
	}
	if parts[0] != "streaming" {
		return nil, parts[0], errors.New("the replica is not streaming from the primary (" + parts[0] + ")")
	}
	var lag *float64
	if s, err := strconv.ParseFloat(parts[1], 64); err == nil && s >= 0 {
		lag = &s
	}
	return lag, "streaming", nil
}

// seedPostgres: the cluster becomes a base backup of the source; the old
// data directory is kept aside (the newest only).
func seedPostgres(ctx context.Context, env *Env, st *mirrorState, confDir string) error {
	if err := peerCall(ctx, env, "repl_prepare", map[string]any{}, nil); err != nil {
		return err
	}
	ver, data := pgVersionOf(confDir), pgDataDir(confDir)
	owner, err := lookupOwner(env, "postgres")
	if err != nil {
		return err
	}
	pass := "/var/lib/postgresql/.wspanel-mirror.pgpass"
	line := fmt.Sprintf("127.0.0.1:%d:*:%s:%s\n", tunnelPortPostgres, replUser, strings.NewReplacer(`\`, `\\`, ":", `\:`).Replace(st.Repl.Postgres))
	if err := env.FS.WriteFile(pass, []byte(line), 0o600, owner); err != nil {
		return err
	}
	unit := "postgresql@" + ver + "-main.service"
	if _, err := env.Exec.Run(ctx, "systemctl", "stop", unit); err != nil {
		return err
	}
	aside := data + ".before-mirror"
	_ = os.RemoveAll(aside)
	if fsx.Exists(data) {
		if err := os.Rename(data, aside); err != nil {
			return err
		}
	}
	conninfo := fmt.Sprintf("host=127.0.0.1 port=%d user=%s passfile=%s application_name=%s", tunnelPortPostgres, replUser, pass, st.NodeID)
	bctx, cancel := context.WithTimeout(ctx, 6*time.Hour)
	defer cancel()
	_, err = env.Exec.RunIO(bctx, nil, nil, "setpriv", "--reuid=postgres", "--regid=postgres", "--init-groups", "--reset-env", "--",
		"/usr/lib/postgresql/"+ver+"/bin/pg_basebackup", "-d", conninfo, "-D", data, "-X", "stream", "-S", pgSlot, "-R", "-c", "fast")
	if err != nil {
		// Back to what was there: the cluster must start again.
		_ = os.RemoveAll(data)
		if fsx.Exists(aside) {
			_ = os.Rename(aside, data)
		}
		_, _ = env.Exec.Run(ctx, "systemctl", "start", unit)
		return err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "start", unit); err != nil {
		return err
	}
	return writeMirrorFile(env, "seeded-postgresql", []byte(time.Now().UTC().Format(time.RFC3339)+"\n"))
}

// ---------------------------------------------------------------- roles

// promoteDatabases: the replicas stop following and take writes.
func promoteDatabases(ctx context.Context, env *Env, st *mirrorState, rep *RestoreReport) {
	if slices.Contains(env.Cfg.DBEngines, "mariadb") {
		m := mariadb{}
		if _, err := m.sql(ctx, env, "STOP SLAVE; RESET SLAVE ALL; SET GLOBAL read_only = OFF;"); err != nil {
			rep.problem("MariaDB: %v", err)
		} else {
			rep.ok("MariaDB takes writes")
		}
		_, _ = writeFileIfChanged(env, filepath.Join(env.Cfg.MariaDBConfDir, mariadbMirrorCf), mariadbMirrorConfig(st.NodeID, false), 0o644, fsx.Root)
		if err := mariadbAsSource(ctx, env, st, false); err != nil {
			rep.problem("MariaDB as the source: %v", err)
		}
	}
	if slices.Contains(env.Cfg.DBEngines, "postgresql") {
		p := postgres{}
		if out, err := p.psql(ctx, env, "postgres", "SELECT pg_is_in_recovery()"); err == nil && strings.TrimSpace(out) == "t" {
			if _, err := p.psql(ctx, env, "postgres", "SELECT pg_promote(true, 120)"); err != nil {
				rep.problem("PostgreSQL: %v", err)
			} else {
				rep.ok("PostgreSQL promoted")
			}
		}
		_, _ = p.psql(ctx, env, "postgres", "ALTER SYSTEM RESET default_transaction_read_only; SELECT pg_reload_conf();")
		if err := postgresAsSource(ctx, env, st); err != nil {
			rep.problem("PostgreSQL as the source: %v", err)
		}
	}
	for _, e := range []string{"mariadb", "postgresql"} {
		_ = os.Remove(seededMark(env, e))
	}
}

// databasesReadOnly: a fenced node takes no writes (until it serves again).
func databasesReadOnly(ctx context.Context, env *Env, on bool) {
	if slices.Contains(env.Cfg.DBEngines, "mariadb") {
		v := "OFF"
		if on {
			v = "ON"
		}
		_, _ = (mariadb{}).sql(ctx, env, "SET GLOBAL read_only = "+v)
	}
	if slices.Contains(env.Cfg.DBEngines, "postgresql") {
		q := "ALTER SYSTEM RESET default_transaction_read_only; SELECT pg_reload_conf();"
		if on {
			q = "ALTER SYSTEM SET default_transaction_read_only = on; SELECT pg_reload_conf();"
		}
		_, _ = (postgres{}).psql(ctx, env, "postgres", q)
	}
}

// stopReplication: the standby leaves the mirror; its copies stay.
func stopReplication(ctx context.Context, env *Env) {
	if slices.Contains(env.Cfg.DBEngines, "mariadb") {
		_, _ = (mariadb{}).sql(ctx, env, "STOP SLAVE; RESET SLAVE ALL; SET GLOBAL read_only = OFF;")
		_ = os.Remove(filepath.Join(env.Cfg.MariaDBConfDir, mariadbMirrorCf))
	}
	if slices.Contains(env.Cfg.DBEngines, "postgresql") {
		if out, err := (postgres{}).psql(ctx, env, "postgres", "SELECT pg_is_in_recovery()"); err == nil && strings.TrimSpace(out) == "t" {
			_, _ = (postgres{}).psql(ctx, env, "postgres", "SELECT pg_promote(true, 120)")
		}
	}
	stopKVReplicas(ctx, env)
	stopTunnels()
	for _, e := range []string{"mariadb", "postgresql"} {
		_ = os.Remove(seededMark(env, e))
	}
}
