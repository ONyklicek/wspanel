package ops

import (
	"context"
	"fmt"
	"path/filepath"
	"slices"
	"sort"

	"wspanel/agent/internal/fsx"
)

// The shared database servers come with their packages' defaults, sized for
// any machine: MariaDB caches 128 MB of data and indexes, PostgreSQL 128 MB.
// A shop's few hundred MB of orders then come from the disk on every page:
// on rc9 one page of a Livewire table (four indexed queries over 320 MB) took
// 1.1-1.4 s with 128 MB and 0.12 s with 1 GB, and it looked like slow PHP.
// The caches follow the server's RAM instead. They are protected rather than
// reserved: a cache fills only as the data is read, so a server with small
// databases leaves the memory to the games, and when a database does need
// it, the kernel takes it from the games (which have no protection) instead
// of from the database.

// sharedDBMemoryMB is what the shared database servers' caches may hold.
func sharedDBMemoryMB(totalMB int, engines []string) (mariadbMB, postgresMB int) {
	if slices.Contains(engines, "mariadb") {
		mariadbMB = min(max(totalMB*15/100, 128), 16384)
	}
	if slices.Contains(engines, "postgresql") {
		postgresMB = min(max(totalMB*10/100, 128), 8192)
	}
	return mariadbMB, postgresMB
}

func mariadbTuning(poolMB int) string {
	return fmt.Sprintf(`# Managed by WSPanel agent: the shared server's memory follows the server's
# RAM (15 %%). Settings of your own: a file sorting after this one.
[mysqld]
innodb_buffer_pool_size = %dM
# Room for every site's PHP workers and queue workers at once.
max_connections = 300
`, poolMB)
}

func postgresTuning(totalMB, sharedMB int) string {
	return fmt.Sprintf(`# Managed by WSPanel agent: the shared cluster's memory follows the server's
# RAM (10 %%). Settings of your own: a file sorting after this one.
shared_buffers = %dMB
# What the planner may count on in the page cache (no memory is taken).
effective_cache_size = %dMB
# SSD storage: an index lookup costs about as much as reading in order.
random_page_cost = 1.1
effective_io_concurrency = 200
work_mem = 8MB
maintenance_work_mem = %dMB
`, sharedMB, totalMB/2, min(max(totalMB/32, 64), 1024))
}

// TuneSharedDBServers writes the shared servers' memory settings for this
// server's RAM and their protection (MemoryLow, within system.slice's); it
// returns the units whose settings changed (a restart applies them; the
// protection applies with daemon-reload).
func TuneSharedDBServers(env *Env) ([]string, error) {
	total := hostMemoryMB()
	if total <= 0 {
		return nil, nil
	}
	mariaMB, pgMB := sharedDBMemoryMB(total, env.Cfg.DBEngines)
	var changed []string
	protect := func(unit string, mb int) error {
		_, err := writeIfChanged(env, filepath.Join(env.Cfg.SystemdDir, unit+".d", "wspanel-memory.conf"),
			fmt.Sprintf("# Managed by WSPanel agent: the database's cache stays in RAM.\n[Service]\nMemoryLow=%dM\n", mb+64))
		return err
	}
	if mariaMB > 0 && fsx.Exists(env.Cfg.MariaDBConfDir) {
		ok, err := writeIfChanged(env, filepath.Join(env.Cfg.MariaDBConfDir, "60-wspanel.cnf"), mariadbTuning(mariaMB))
		if err != nil {
			return changed, err
		}
		if ok {
			changed = append(changed, "mariadb.service")
		}
		if err := protect("mariadb.service", mariaMB); err != nil {
			return changed, err
		}
	}
	if pgMB > 0 {
		// The packaged cluster of each installed version (main).
		dirs, _ := filepath.Glob(filepath.Join(env.Cfg.PostgresConfDir, "*", "main", "conf.d"))
		sort.Strings(dirs)
		for _, d := range dirs {
			ok, err := writeIfChanged(env, filepath.Join(d, "60-wspanel.conf"), postgresTuning(total, pgMB))
			if err != nil {
				return changed, err
			}
			if ok && !slices.Contains(changed, "postgresql.service") {
				changed = append(changed, "postgresql.service")
			}
			version := filepath.Base(filepath.Dir(filepath.Dir(d)))
			if err := protect("postgresql@"+version+"-main.service", pgMB); err != nil {
				return changed, err
			}
		}
	}
	// What every site depends on, protected as a whole against the games'
	// slice: the edge, the web tier, PHP and the panel (768 MB) and the caches.
	_, err := writeIfChanged(env, filepath.Join(env.Cfg.SystemdDir, "system.slice.d", "wspanel.conf"),
		fmt.Sprintf("# Managed by WSPanel agent. The services every site depends on (see the units'\n"+
			"# MemoryLow=): protected as a whole against the game servers' slice.\n[Slice]\nMemoryLow=%dM\n", 768+mariaMB+pgMB))
	return changed, err
}

// tuneAndRestartDBServers is TuneSharedDBServers for an engine just
// installed (engine.install): nothing uses it yet, so its restart costs
// nothing. Another engine whose settings changed is left running; they
// apply at its next restart (an upgrade restarts it).
func tuneAndRestartDBServers(ctx context.Context, env *Env, engine string) error {
	units, err := TuneSharedDBServers(env)
	if err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return err
	}
	if slices.Contains(units, engine+".service") {
		if _, err := env.Exec.Run(ctx, "systemctl", "restart", engine+".service"); err != nil {
			return err
		}
	}
	return nil
}
