//go:build linux

package ops

import (
	"bufio"
	"context"
	"encoding/json"
	"os"
	"runtime"
	"strconv"
	"strings"
	"syscall"
)

// sysResources reports load, memory, disks and uptime (read-only, /proc + statfs).
func sysResources(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	out := map[string]any{"cpus": runtime.NumCPU()}
	if b, err := os.ReadFile("/proc/loadavg"); err == nil {
		f := strings.Fields(string(b))
		if len(f) >= 3 {
			l1, _ := strconv.ParseFloat(f[0], 64)
			l5, _ := strconv.ParseFloat(f[1], 64)
			l15, _ := strconv.ParseFloat(f[2], 64)
			out["load"] = []float64{l1, l5, l15}
		}
	}
	if b, err := os.ReadFile("/proc/uptime"); err == nil {
		if f := strings.Fields(string(b)); len(f) > 0 {
			up, _ := strconv.ParseFloat(f[0], 64)
			out["uptime_seconds"] = int64(up)
		}
	}
	if f, err := os.Open("/proc/meminfo"); err == nil {
		mem := map[string]int64{}
		sc := bufio.NewScanner(f)
		for sc.Scan() {
			k, v, ok := strings.Cut(sc.Text(), ":")
			if !ok {
				continue
			}
			n, _ := strconv.ParseInt(strings.Fields(v)[0], 10, 64)
			mem[k] = n * 1024
		}
		f.Close()
		out["memory"] = map[string]int64{"total": mem["MemTotal"], "available": mem["MemAvailable"], "swap_total": mem["SwapTotal"], "swap_free": mem["SwapFree"]}
	}
	disks := []map[string]any{}
	seen := map[uint64]bool{}
	for _, path := range []string{"/", "/srv", "/var", "/var/lib/mysql", "/var/lib/postgresql", env.Cfg.BackupDir} {
		var st syscall.Statfs_t
		if syscall.Statfs(path, &st) != nil {
			continue
		}
		id := uint64(st.Fsid.X__val[0])<<32 | uint64(uint32(st.Fsid.X__val[1]))
		if seen[id] {
			continue
		}
		seen[id] = true
		total := int64(st.Blocks) * int64(st.Bsize)
		free := int64(st.Bavail) * int64(st.Bsize)
		disks = append(disks, map[string]any{"path": path, "total": total, "free": free})
	}
	out["disks"] = disks
	return out, nil
}
