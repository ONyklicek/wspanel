package ops

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/config"
)

// managedCgroups are the exact cgroup v2 paths of units the agent runs for a
// site user: its PHP-FPM pools and its app/worker units, all in system.slice.
// Exact paths only: a user's own systemd --user units live under user.slice
// and may carry any name (e.g. "php8.4-fpm.service").
//
// root is the cgroup path systemd's tree hangs from: "" on a host, a
// container prefix like "/docker/<id>" under cgroup namespaces sharing.
func managedCgroups(cfg config.Config, root, username string) map[string]bool {
	m := map[string]bool{}
	for _, v := range cfg.PHPVersions {
		m[root+"/system.slice/"+config.ForVersion(cfg.FPMUnit, v)] = true
	}
	m[root+"/system.slice/"+appUnitName(username)] = true
	m[root+"/system.slice/"+appUnitNameB(username)] = true
	for _, t := range WorkerTypes {
		m[root+"/system.slice/"+legacyWorkerUnit(username, t)] = true
	}
	// A game server's user: its server keeps running when its SFTP sessions end.
	if id, ok := strings.CutPrefix(username, "wsg_"); ok {
		m[root+"/system.slice/wspanel-game-"+id+".service"] = true
	}
	for _, id := range siteCronIDs(cfg, username) {
		m[root+"/system.slice/"+cronUnitBase(username, id)+".service"] = true
	}
	return m
}

// managedCgroupPrefixes: worker instances live in a slice per worker
// (system-wspanel\x2d<user>\x2d<type>.slice/<instance>), one per release.
func managedCgroupPrefixes(root, username string) []string {
	var out []string
	for _, t := range WorkerTypes {
		out = append(out, root+"/system.slice/system-"+strings.ReplaceAll("wspanel-"+username+"-"+t, "-", `\x2d`)+".slice/"+"wspanel-"+username+"-"+t+"@")
	}
	return out
}

// isManagedCgroup: the process runs in one of the site's own units.
func isManagedCgroup(managed map[string]bool, prefixes []string, path string) bool {
	if managed[path] {
		return true
	}
	for _, p := range prefixes {
		if strings.HasPrefix(path, p) && strings.HasSuffix(path, ".service") && !strings.Contains(path[len(p):], "/") {
			return true
		}
	}
	return false
}

// systemdRoot derives the cgroup root from the agent's own cgroup, which is
// known to be <root>/system.slice/<agent unit>. Fails closed if it is not.
func systemdRoot() (string, error) {
	own, ok := cgroupPath("self")
	if !ok {
		return "", fmt.Errorf("cannot read own cgroup")
	}
	i := strings.LastIndex(own, "/system.slice/")
	if i < 0 || strings.Contains(own[i+len("/system.slice/"):], "/") {
		return "", fmt.Errorf("agent is not running in a system.slice service (cgroup %q)", own)
	}
	return own[:i], nil
}

// cgroupPath returns the unified (v2) cgroup path of a process ("0::<path>").
func cgroupPath(pid string) (string, bool) {
	b, err := os.ReadFile(filepath.Join("/proc", pid, "cgroup"))
	if err != nil {
		return "", false
	}
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		if path, ok := strings.CutPrefix(sc.Text(), "0::"); ok {
			return path, true
		}
	}
	return "", false
}

// uidProcesses lists pids of uid; keep(pid) == true excludes a process.
func uidProcesses(uid int, keep func(pid string) bool) []int {
	var out []int
	procs, _ := filepath.Glob("/proc/[0-9]*")
	for _, p := range procs {
		fi, err := os.Stat(p)
		if err != nil {
			continue
		}
		st, ok := fi.Sys().(*syscall.Stat_t)
		if !ok || int(st.Uid) != uid {
			continue
		}
		pid := filepath.Base(p)
		if keep != nil && keep(pid) {
			continue
		}
		n, _ := strconv.Atoi(pid)
		out = append(out, n)
	}
	return out
}

// killUntilGone stops and kills matching processes in repeated passes (a
// process may fork between scan and kill), and fails if any survive.
func killUntilGone(uid int, keep func(pid string) bool) error {
	for pass := 0; pass < 20; pass++ {
		pids := uidProcesses(uid, keep)
		if len(pids) == 0 {
			return nil
		}
		for _, pid := range pids { // freeze first, so nothing forks while we kill
			_ = syscall.Kill(pid, syscall.SIGSTOP)
		}
		for _, pid := range pids {
			if err := syscall.Kill(pid, syscall.SIGKILL); err != nil && !errors.Is(err, syscall.ESRCH) {
				return fmt.Errorf("kill %d: %w", pid, err)
			}
		}
		time.Sleep(50 * time.Millisecond)
	}
	if left := uidProcesses(uid, keep); len(left) > 0 {
		return fmt.Errorf("%d processes of uid %d survived", len(left), uid)
	}
	return nil
}

func lookupUID(username string) (int, error) {
	u, err := user.Lookup(username)
	if err != nil {
		return 0, err
	}
	return strconv.Atoi(u.Uid)
}

// killSessionProcesses ends every process of the site user except those in
// its managed units: SSH/SFTP sessions, anything left running from them, and
// the user's own systemd --user manager.
func killSessionProcesses(ctx context.Context, env *Env, username string) error {
	if env.Cfg.DryRun {
		return nil
	}
	uid, err := lookupUID(username)
	if err != nil {
		return err
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "stop", "user@"+strconv.Itoa(uid)+".service")
	root, err := systemdRoot()
	if err != nil {
		return err
	}
	managed := managedCgroups(env.Cfg, root, username)
	prefixes := managedCgroupPrefixes(root, username)
	return killUntilGone(uid, func(pid string) bool {
		path, ok := cgroupPath(pid)
		return ok && isManagedCgroup(managed, prefixes, path)
	})
}

// killAllProcesses ends every process of the user, managed or not (site
// deletion, after its units are gone; old FPM workers may still be finishing).
func killAllProcesses(ctx context.Context, env *Env, username string) error {
	if env.Cfg.DryRun {
		return nil
	}
	uid, err := lookupUID(username)
	if err != nil {
		return err
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "stop", "user@"+strconv.Itoa(uid)+".service")
	return killUntilGone(uid, nil)
}

// noProcessesLeft verifies, after userdel, that the freed uid runs nothing
// (it may be reused for the next site).
func noProcessesLeft(env *Env, uid int) error {
	if env.Cfg.DryRun {
		return nil
	}
	if left := uidProcesses(uid, nil); len(left) > 0 {
		if err := killUntilGone(uid, nil); err != nil {
			return fmt.Errorf("deleted user's uid %d still runs processes: %w", uid, err)
		}
	}
	return nil
}
