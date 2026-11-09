//go:build linux

package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"runtime"
	"slices"
	"strconv"
	"strings"
	"time"
)

func readProcIdentity(pid int) (procIdent, error) {
	ps := readProc(pid)
	if ps == nil {
		return procIdent{}, fmt.Errorf("no process %d", pid)
	}
	cg, _ := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/cgroup")
	return procIdent{Start: ps.start, PPID: ps.ppid, Cmd: procCmdline(pid), Cgroup: string(cg)}, nil
}

// readProc: one process's sample (nil when gone).
func readProc(pid int) *procSample {
	for _, p := range readProcs() {
		if p.pid == pid {
			return &p
		}
	}
	return nil
}

func procCmdline(pid int) string {
	b, err := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/cmdline")
	if err != nil {
		return ""
	}
	if len(b) > 1024 {
		b = b[:1024]
	}
	return strings.TrimSpace(strings.ReplaceAll(string(b), "\x00", " "))
}

type procRow struct {
	PID     int     `json:"pid"`
	PPID    int     `json:"ppid"`
	User    string  `json:"user"`
	Name    string  `json:"name"`
	Cmd     string  `json:"cmd"`
	State   string  `json:"state"`
	CPU     float64 `json:"cpu"` // % of one core
	RSS     int64   `json:"rss"`
	Start   uint64  `json:"start"` // for sys.kill
	Started string  `json:"started"`
	Site    string  `json:"site,omitempty"`
}

// sysProcesses: every process (kernel threads only on request) with its CPU
// over half a second, memory and the site it belongs to.
func sysProcesses(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Kernel bool `json:"kernel,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	const window = 500 * time.Millisecond
	c1, p1 := readCPU(), readProcs()
	select {
	case <-time.After(window):
	case <-ctx.Done():
		return nil, ctx.Err()
	}
	c2, p2 := readCPU(), readProcs()
	perCore := float64(c2.total-c1.total) / float64(max(1, runtime.NumCPU()))
	boot := bootTime()
	sitesByUser := map[string]string{}
	for _, st := range allSiteStates(env.Cfg) {
		if l, err := layout(env.Cfg, st); err == nil {
			sitesByUser[l.User] = st.Domain
		}
	}
	rows := make([]procRow, 0, len(p2))
	kernel := 0
	for _, s := range p2 {
		if s.pid == 2 || s.ppid == 2 {
			kernel++
			if !p.Kernel {
				continue
			}
		}
		cpu := 0.0
		if prev, ok := p1[s.pid]; ok && perCore > 0 && s.ticks >= prev.ticks && prev.start == s.start {
			cpu = round1(100 * float64(s.ticks-prev.ticks) / perCore)
		}
		user := uidName(s.uid)
		r := procRow{PID: s.pid, PPID: s.ppid, User: user, Name: s.name, Cmd: procCmdline(s.pid), State: s.state, CPU: cpu, RSS: s.rss, Start: s.start, Site: sitesByUser[user]}
		if boot > 0 {
			r.Started = time.Unix(boot+int64(s.start/clockTicks), 0).UTC().Format(time.RFC3339)
		}
		rows = append(rows, r)
	}
	slices.SortFunc(rows, func(a, b procRow) int {
		switch {
		case a.CPU != b.CPU:
			if a.CPU > b.CPU {
				return -1
			}
			return 1
		case a.RSS != b.RSS:
			if a.RSS > b.RSS {
				return -1
			}
			return 1
		}
		return a.PID - b.PID
	})
	if len(rows) > 2000 {
		rows = rows[:2000]
	}
	return map[string]any{"processes": rows, "total": len(p2), "kernel_threads": kernel}, nil
}

// clockTicks: USER_HZ, 100 on every Linux the panel runs on.
const clockTicks = 100

func bootTime() int64 {
	b, err := os.ReadFile("/proc/stat")
	if err != nil {
		return 0
	}
	for _, line := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(line, "btime "); ok {
			n, _ := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
			return n
		}
	}
	return 0
}
