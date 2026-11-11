package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// The shared database servers' caches follow the RAM, and the games leave
// them room: on an 8 GB server the packages' 128 MB made one page of orders
// take over a second.
func TestTheSharedDatabaseServersAreSizedForTheServer(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.DBEngines = []string{"mariadb", "postgresql"}
	env.Cfg.MariaDBConfDir = filepath.Join(t.TempDir(), "mariadb.conf.d")
	pgConf := filepath.Join(env.Cfg.PostgresConfDir, "17", "main", "conf.d")
	for _, d := range []string{env.Cfg.MariaDBConfDir, pgConf} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	hostMemoryMB = func() int { return 8000 }
	t.Cleanup(func() { hostMemoryMB = func() int { return 0 } })

	units, err := TuneSharedDBServers(env)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(units, " ") != "mariadb.service postgresql.service" {
		t.Errorf("to restart: %v", units)
	}
	if m := read(t, filepath.Join(env.Cfg.MariaDBConfDir, "60-wspanel.cnf")); !strings.Contains(m, "innodb_buffer_pool_size = 1200M") {
		t.Errorf("MariaDB's cache for 8 GB:\n%s", m)
	}
	if p := read(t, filepath.Join(pgConf, "60-wspanel.conf")); !strings.Contains(p, "shared_buffers = 800MB") || !strings.Contains(p, "random_page_cost = 1.1") {
		t.Errorf("PostgreSQL for 8 GB:\n%s", p)
	}
	if units, _ := TuneSharedDBServers(env); len(units) != 0 {
		t.Errorf("unchanged settings restart %v again", units)
	}
	// Protected, not reserved: the kernel takes a game's memory, not theirs.
	if d := read(t, filepath.Join(env.Cfg.SystemdDir, "mariadb.service.d", "wspanel-memory.conf")); !strings.Contains(d, "MemoryLow=1264M") {
		t.Errorf("MariaDB's protection:\n%s", d)
	}
	if d := read(t, filepath.Join(env.Cfg.SystemdDir, "postgresql@17-main.service.d", "wspanel-memory.conf")); !strings.Contains(d, "MemoryLow=864M") {
		t.Errorf("the cluster's protection:\n%s", d)
	}
	if d := read(t, filepath.Join(env.Cfg.SystemdDir, "system.slice.d", "wspanel.conf")); !strings.Contains(d, "MemoryLow=2768M") {
		t.Errorf("system.slice must hold the caches' protection too:\n%s", d)
	}
	if m, _ := sharedDBMemoryMB(512000, []string{"mariadb"}); m != 16384 {
		t.Errorf("a big server's MariaDB cache: %d MB", m)
	}
}
