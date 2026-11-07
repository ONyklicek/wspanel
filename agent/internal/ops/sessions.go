package ops

import (
	"errors"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
)

// killSessionProcesses kills every process of the site user that does not
// belong to a unit the agent manages for it (its PHP-FPM pool, its app and
// worker units). That covers SSH/SFTP sessions and anything left running from
// them, whether or not logind put them into a session scope.
func killSessionProcesses(env *Env, username string) error {
	if env.Cfg.DryRun {
		return nil
	}
	u, err := user.Lookup(username)
	if err != nil {
		return err
	}
	uid, _ := strconv.Atoi(u.Uid)
	managed := func(cgroup string) bool {
		unit := filepath.Base(strings.TrimSpace(cgroup))
		return strings.HasPrefix(unit, "wspanel-"+username+"-") && strings.HasSuffix(unit, ".service") ||
			strings.HasPrefix(unit, "php") && strings.HasSuffix(unit, "-fpm.service")
	}
	procs, _ := filepath.Glob("/proc/[0-9]*")
	var errs []error
	for _, p := range procs {
		fi, err := os.Stat(p)
		if err != nil {
			continue
		}
		st, ok := fi.Sys().(*syscall.Stat_t)
		if !ok || int(st.Uid) != uid {
			continue
		}
		cg, err := os.ReadFile(filepath.Join(p, "cgroup")) // cgroup v2: "0::/system.slice/x.service"
		if err != nil || managed(string(cg)) {
			continue
		}
		pid, _ := strconv.Atoi(filepath.Base(p))
		if err := syscall.Kill(pid, syscall.SIGKILL); err != nil && !errors.Is(err, syscall.ESRCH) {
			errs = append(errs, fmt.Errorf("kill %d: %w", pid, err))
		}
	}
	return errors.Join(errs...)
}
