//go:build linux

package ops

import (
	"bufio"
	"context"
	"encoding/json"
	"io/fs"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"runtime"
	"slices"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

// sys.metrics: a full picture of the server's load, read from /proc and
// statfs only. Rates (CPU, network, disk I/O, per-process CPU) come from two
// samples taken half a second apart; cumulative counters are returned too so
// the panel can compute exact per-minute averages for its history.

type cpuTicks struct{ total, idle, iowait, steal uint64 }

func readCPU() cpuTicks {
	b, err := os.ReadFile("/proc/stat")
	if err != nil {
		return cpuTicks{}
	}
	line, _, _ := strings.Cut(string(b), "\n")
	f := strings.Fields(line)
	var t cpuTicks
	for i, v := range f[1:] {
		n, _ := strconv.ParseUint(v, 10, 64)
		if i >= 8 { // guest time is already counted in user/nice
			break
		}
		t.total += n
		switch i {
		case 3:
			t.idle += n
		case 4:
			t.idle += n
			t.iowait = n
		case 7:
			t.steal = n
		}
	}
	return t
}

func readNet() (rx, tx uint64) {
	b, err := os.ReadFile("/proc/net/dev")
	if err != nil {
		return 0, 0
	}
	for _, line := range strings.Split(string(b), "\n")[2:] {
		name, rest, ok := strings.Cut(line, ":")
		name = strings.TrimSpace(name)
		if !ok || name == "lo" {
			continue
		}
		f := strings.Fields(rest)
		if len(f) < 9 {
			continue
		}
		r, _ := strconv.ParseUint(f[0], 10, 64)
		t, _ := strconv.ParseUint(f[8], 10, 64)
		rx, tx = rx+r, tx+t
	}
	return rx, tx
}

var physDiskRe = regexp.MustCompile(`^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|hd[a-z]+|nvme\d+n\d+|mmcblk\d+)$`)

// readDiskIO sums bytes read/written by whole physical disks (no partitions).
func readDiskIO() (read, written uint64) {
	b, err := os.ReadFile("/proc/diskstats")
	if err != nil {
		return 0, 0
	}
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) < 10 || !physDiskRe.MatchString(f[2]) {
			continue
		}
		r, _ := strconv.ParseUint(f[5], 10, 64)
		w, _ := strconv.ParseUint(f[9], 10, 64)
		read, written = read+r*512, written+w*512
	}
	return read, written
}

type procSample struct {
	pid   int
	uid   int
	ticks uint64
	rss   int64
	name  string
}

var pageSize = int64(os.Getpagesize())

func readProcs() map[int]procSample {
	out := map[int]procSample{}
	entries, _ := os.ReadDir("/proc")
	for _, e := range entries {
		pid, err := strconv.Atoi(e.Name())
		if err != nil {
			continue
		}
		b, err := os.ReadFile("/proc/" + e.Name() + "/stat")
		if err != nil {
			continue
		}
		s := string(b)
		open, closeIdx := strings.IndexByte(s, '('), strings.LastIndexByte(s, ')')
		if open < 0 || closeIdx < open {
			continue
		}
		f := strings.Fields(s[closeIdx+1:]) // f[0] = state (field 3)
		if len(f) < 22 {
			continue
		}
		ut, _ := strconv.ParseUint(f[11], 10, 64)
		st, _ := strconv.ParseUint(f[12], 10, 64)
		rss, _ := strconv.ParseInt(f[21], 10, 64)
		p := procSample{pid: pid, uid: -1, ticks: ut + st, rss: rss * pageSize, name: s[open+1 : closeIdx]}
		if fi, err := os.Stat("/proc/" + e.Name()); err == nil {
			if sys, ok := fi.Sys().(*syscall.Stat_t); ok {
				p.uid = int(sys.Uid)
			}
		}
		out[pid] = p
	}
	return out
}

func readMem() map[string]int64 {
	mem := map[string]int64{}
	f, err := os.Open("/proc/meminfo")
	if err != nil {
		return mem
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		k, v, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		if fields := strings.Fields(v); len(fields) > 0 {
			n, _ := strconv.ParseInt(fields[0], 10, 64)
			mem[k] = n * 1024
		}
	}
	return mem
}

var realFS = map[string]bool{"ext2": true, "ext3": true, "ext4": true, "xfs": true, "btrfs": true, "zfs": true, "f2fs": true, "jfs": true, "reiserfs": true, "vfat": true}

type fsInfo struct {
	Mount       string `json:"mount"`
	Type        string `json:"type"`
	Total       int64  `json:"total"`
	Used        int64  `json:"used"`
	Free        int64  `json:"free"` // available to unprivileged users
	InodesTotal int64  `json:"inodes_total"`
	InodesFree  int64  `json:"inodes_free"`
}

func filesystems() []fsInfo {
	b, err := os.ReadFile("/proc/self/mounts")
	if err != nil {
		return nil
	}
	out := []fsInfo{}
	seen := map[[2]int32]bool{}
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) < 3 {
			continue
		}
		mount, typ := strings.ReplaceAll(f[1], `\040`, " "), f[2]
		if !(realFS[typ] || (typ == "overlay" && mount == "/")) {
			continue
		}
		if strings.HasPrefix(mount, "/etc/") || strings.HasPrefix(mount, "/proc") || strings.HasPrefix(mount, "/sys") || strings.HasPrefix(mount, "/dev") || strings.HasPrefix(mount, "/run") || strings.HasPrefix(mount, "/boot/efi") {
			continue
		}
		var st syscall.Statfs_t
		if syscall.Statfs(mount, &st) != nil || st.Blocks == 0 {
			continue
		}
		id := [2]int32{st.Fsid.X__val[0], st.Fsid.X__val[1]}
		if seen[id] {
			continue
		}
		seen[id] = true
		bs := int64(st.Bsize)
		out = append(out, fsInfo{
			Mount: mount, Type: typ,
			Total: int64(st.Blocks) * bs, Used: int64(st.Blocks-st.Bfree) * bs, Free: int64(st.Bavail) * bs,
			InodesTotal: int64(st.Files), InodesFree: int64(st.Ffree),
		})
	}
	slices.SortFunc(out, func(a, b fsInfo) int { return strings.Compare(a.Mount, b.Mount) })
	return out
}

type topProc struct {
	PID  int     `json:"pid"`
	User string  `json:"user"`
	Name string  `json:"name"`
	Cmd  string  `json:"cmd"`
	CPU  float64 `json:"cpu"` // % of one core
	RSS  int64   `json:"rss"`
}

type siteLoad struct {
	Domain    string  `json:"domain"`
	User      string  `json:"user"`
	CPU       float64 `json:"cpu"` // % of one core
	RSS       int64   `json:"rss"`
	Processes int     `json:"processes"`
}

var userNames sync.Map // uid -> name

func uidName(uid int) string {
	if v, ok := userNames.Load(uid); ok {
		return v.(string)
	}
	name := strconv.Itoa(uid)
	if u, err := user.LookupId(name); err == nil {
		name = u.Username
	}
	userNames.Store(uid, name)
	return name
}

func round1(f float64) float64 { return float64(int64(f*10+0.5)) / 10 }

func sysMetrics(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	const window = 500 * time.Millisecond
	c1, p1 := readCPU(), readProcs()
	rx1, tx1 := readNet()
	r1, w1 := readDiskIO()
	select {
	case <-time.After(window):
	case <-ctx.Done():
		return nil, ctx.Err()
	}
	c2, p2 := readCPU(), readProcs()
	rx2, tx2 := readNet()
	r2, w2 := readDiskIO()

	ncpu := runtime.NumCPU()
	dt := float64(c2.total - c1.total)
	pct := func(d uint64) float64 {
		if dt <= 0 {
			return 0
		}
		return round1(100 * float64(d) / dt)
	}
	sec := window.Seconds()
	rate := func(a, b uint64) int64 {
		if b < a {
			return 0
		}
		return int64(float64(b-a) / sec)
	}

	mem := readMem()
	out := map[string]any{
		"cpus": ncpu,
		"cpu": map[string]float64{
			"percent": round1(100 - pct(c2.idle-c1.idle)),
			"iowait":  pct(c2.iowait - c1.iowait),
			"steal":   pct(c2.steal - c1.steal),
		},
		"memory": map[string]int64{
			"total": mem["MemTotal"], "available": mem["MemAvailable"],
			"used":       mem["MemTotal"] - mem["MemAvailable"],
			"cache":      mem["Buffers"] + mem["Cached"] + mem["SReclaimable"] - mem["Shmem"],
			"swap_total": mem["SwapTotal"], "swap_used": mem["SwapTotal"] - mem["SwapFree"],
		},
		"net":         map[string]int64{"rx_bps": rate(rx1, rx2), "tx_bps": rate(tx1, tx2)},
		"io":          map[string]int64{"read_bps": rate(r1, r2), "write_bps": rate(w1, w2)},
		"filesystems": filesystems(),
		"processes":   len(p2),
		"counters": map[string]uint64{
			"cpu_total": c2.total, "cpu_idle": c2.idle, "cpu_iowait": c2.iowait,
			"net_rx": rx2, "net_tx": tx2, "io_read": r2, "io_write": w2,
		},
		"sampled_at": time.Now().UTC(),
	}
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

	// Per-process CPU over the window (% of one core), then per site user.
	perCore := dt / float64(ncpu)
	procCPU := func(p procSample) float64 {
		prev, ok := p1[p.pid]
		if !ok || perCore <= 0 || p.ticks < prev.ticks {
			return 0
		}
		return 100 * float64(p.ticks-prev.ticks) / perCore
	}
	sitesByUser := map[string]string{}
	for _, st := range allSiteStates(env.Cfg) {
		if l, err := layout(env.Cfg, st); err == nil {
			sitesByUser[l.User] = st.Domain
		}
	}
	loads := map[string]*siteLoad{}
	procs := make([]topProc, 0, len(p2))
	for _, p := range p2 {
		name := uidName(p.uid)
		cpu := procCPU(p)
		if domain, ok := sitesByUser[name]; ok {
			sl := loads[domain]
			if sl == nil {
				sl = &siteLoad{Domain: domain, User: name}
				loads[domain] = sl
			}
			sl.CPU += cpu
			sl.RSS += p.rss
			sl.Processes++
		}
		procs = append(procs, topProc{PID: p.pid, User: name, Name: p.name, CPU: round1(cpu), RSS: p.rss})
	}
	sites := make([]siteLoad, 0, len(sitesByUser))
	for user, domain := range sitesByUser {
		sl := loads[domain]
		if sl == nil {
			sl = &siteLoad{Domain: domain, User: user}
		}
		sl.CPU = round1(sl.CPU)
		sites = append(sites, *sl)
	}
	slices.SortFunc(sites, func(a, b siteLoad) int { return strings.Compare(a.Domain, b.Domain) })
	out["sites"] = sites

	// Top 15 by CPU, then by memory, without duplicates; command lines of those only.
	slices.SortFunc(procs, func(a, b topProc) int {
		if a.CPU != b.CPU {
			if a.CPU > b.CPU {
				return -1
			}
			return 1
		}
		return int(b.RSS - a.RSS)
	})
	top := []topProc{}
	picked := map[int]bool{}
	for _, p := range procs {
		if len(top) >= 10 || p.CPU <= 0 {
			break
		}
		top, picked[p.PID] = append(top, p), true
	}
	slices.SortFunc(procs, func(a, b topProc) int {
		switch {
		case a.RSS > b.RSS:
			return -1
		case a.RSS < b.RSS:
			return 1
		}
		return 0
	})
	for _, p := range procs {
		if len(top) >= 15 {
			break
		}
		if !picked[p.PID] {
			top, picked[p.PID] = append(top, p), true
		}
	}
	for i := range top {
		if b, err := os.ReadFile("/proc/" + strconv.Itoa(top[i].PID) + "/cmdline"); err == nil {
			cmd := strings.TrimSpace(strings.ReplaceAll(string(b), "\x00", " "))
			if len(cmd) > 160 {
				cmd = cmd[:160] + "…"
			}
			top[i].Cmd = cmd
		}
	}
	out["top"] = top
	return out, nil
}

// Disk usage per site is a walk of its tree: cached, refreshed at most every
// 15 minutes. Only lstat is used, so planted symlinks are never followed.
var siteDiskCache struct {
	sync.Mutex
	at    time.Time
	sizes map[string]int64
}

func sysSiteDisk(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Refresh bool `json:"refresh,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	siteDiskCache.Lock()
	defer siteDiskCache.Unlock()
	if !p.Refresh && siteDiskCache.sizes != nil && time.Since(siteDiskCache.at) < 15*time.Minute {
		return map[string]any{"sizes": siteDiskCache.sizes, "measured_at": siteDiskCache.at}, nil
	}
	sizes := map[string]int64{}
	for _, st := range allSiteStates(env.Cfg) {
		home := filepath.Join(env.Cfg.SitesRoot, st.Domain)
		var total int64
		_ = filepath.WalkDir(home, func(_ string, d fs.DirEntry, err error) error {
			if err != nil {
				return nil
			}
			if ctx.Err() != nil {
				return ctx.Err()
			}
			if info, err := d.Info(); err == nil {
				if sys, ok := info.Sys().(*syscall.Stat_t); ok {
					total += sys.Blocks * 512 // allocated, like du
				}
			}
			return nil
		})
		sizes[st.Domain] = total
	}
	siteDiskCache.at, siteDiskCache.sizes = time.Now().UTC(), sizes
	return map[string]any{"sizes": sizes, "measured_at": siteDiskCache.at}, nil
}
