package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// System management (Server → Systém, Procesy; Zabezpečení → Aktualizace):
// restarting the server, the automatic restart after updates, the server's
// name, time zone and time sync, swap, disk usage by directory, disk health
// (SMART) and the network as the server sees it.

// ---------------------------------------------------------------- reboot

// shutdownScheduled: systemd's record of a scheduled reboot (systemctl reboot --when).
var shutdownScheduled = "/run/systemd/shutdown/scheduled"

var clockRe = regexp.MustCompile(`^([01][0-9]|2[0-3]):[0-5][0-9]$`)

// sysReboot schedules a restart: in N minutes (at least one, so the answer
// reaches the panel first and logged-in users are warned) or at a time of day
// (the next one). cancel drops a scheduled restart.
func sysReboot(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		InMinutes int    `json:"in_minutes,omitempty"`
		At        string `json:"at,omitempty"` // HH:MM, server time
		Cancel    bool   `json:"cancel,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Cancel {
		if _, err := env.Exec.Run(ctx, "systemctl", "reboot", "--when=cancel"); err != nil {
			return nil, err
		}
		return map[string]any{"scheduled": nil}, nil
	}
	when := time.Now().Add(time.Minute)
	switch {
	case p.At != "":
		if !clockRe.MatchString(p.At) || p.InMinutes != 0 {
			return nil, protocol.Invalid("at: a time HH:MM (and no in_minutes)")
		}
		when = nextClock(time.Now(), p.At)
	case p.InMinutes != 0:
		if p.InMinutes < 1 || p.InMinutes > 24*60 {
			return nil, protocol.Invalid("in_minutes: 1 to 1440")
		}
		when = time.Now().Add(time.Duration(p.InMinutes) * time.Minute)
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "reboot", "--when="+when.Format("2006-01-02 15:04:05")); err != nil {
		return nil, err
	}
	return map[string]any{"scheduled": when.UTC().Format(time.RFC3339)}, nil
}

// nextClock: the next HH:MM after now, in the server's time zone.
func nextClock(now time.Time, hhmm string) time.Time {
	h, _ := strconv.Atoi(hhmm[:2])
	m, _ := strconv.Atoi(hhmm[3:])
	t := time.Date(now.Year(), now.Month(), now.Day(), h, m, 0, 0, now.Location())
	if !t.After(now.Add(30 * time.Second)) {
		t = t.AddDate(0, 0, 1)
	}
	return t
}

// scheduledReboot: when a restart is scheduled (RFC 3339), or "".
func scheduledReboot() string {
	b, err := os.ReadFile(shutdownScheduled)
	if err != nil {
		return ""
	}
	var usec int64
	mode := ""
	for _, line := range strings.Split(string(b), "\n") {
		k, v, _ := strings.Cut(line, "=")
		switch k {
		case "USEC":
			usec, _ = strconv.ParseInt(v, 10, 64)
		case "MODE":
			mode = v
		}
	}
	if usec <= 0 || mode != "reboot" {
		return ""
	}
	return time.UnixMicro(usec).UTC().Format(time.RFC3339)
}

func bootedAt() string {
	b, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return ""
	}
	f := strings.Fields(string(b))
	if len(f) == 0 {
		return ""
	}
	up, err := strconv.ParseFloat(f[0], 64)
	if err != nil {
		return ""
	}
	return time.Now().Add(-time.Duration(up * float64(time.Second))).UTC().Format(time.RFC3339)
}

// autoRebootFile: unattended-upgrades' automatic restart, beside the periodic config.
func autoRebootFile(env *Env) string {
	return filepath.Join(filepath.Dir(env.Cfg.AutoUpgrades), "52wspanel-auto-reboot")
}

// autoReboot: whether unattended-upgrades restarts the server when an update
// needs it, and at what time.
func autoReboot(env *Env) (bool, string) {
	b, err := os.ReadFile(autoRebootFile(env))
	if err != nil {
		return false, ""
	}
	on := strings.Contains(string(b), `Automatic-Reboot "true"`)
	if m := regexp.MustCompile(`Automatic-Reboot-Time "([0-9:]+)"`).FindStringSubmatch(string(b)); m != nil {
		return on, m[1]
	}
	return on, ""
}

// sysAutoReboot: restart automatically at a set time when the automatic
// security updates need it (a new kernel, libc…).
func sysAutoReboot(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Enabled bool   `json:"enabled"`
		Time    string `json:"time,omitempty"` // HH:MM
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !p.Enabled {
		if err := os.Remove(autoRebootFile(env)); err != nil && !os.IsNotExist(err) {
			return nil, err
		}
		return map[string]any{"auto_reboot": false}, nil
	}
	if p.Time == "" {
		p.Time = "04:00"
	}
	if !clockRe.MatchString(p.Time) {
		return nil, protocol.Invalid("time: HH:MM")
	}
	conf := "// Managed by WSPanel (Zabezpečení → Aktualizace).\n" +
		"Unattended-Upgrade::Automatic-Reboot \"true\";\n" +
		"Unattended-Upgrade::Automatic-Reboot-WithUsers \"true\";\n" +
		"Unattended-Upgrade::Automatic-Reboot-Time \"" + p.Time + "\";\n"
	if err := env.FS.WriteFile(autoRebootFile(env), []byte(conf), 0o644, fsx.Root); err != nil {
		return nil, err
	}
	return map[string]any{"auto_reboot": true, "time": p.Time}, nil
}

// ---------------------------------------------------------------- name, time

var hostLabelRe = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)

func validHostname(h string) error {
	if h == "" || len(h) > 253 {
		return protocol.Invalid("hostname: 1 to 253 characters")
	}
	for _, l := range strings.Split(h, ".") {
		if !hostLabelRe.MatchString(l) {
			return protocol.Invalid(fmt.Sprintf("hostname: invalid part %q (lowercase letters, digits, hyphens)", l))
		}
	}
	return nil
}

var tzRe = regexp.MustCompile(`^[A-Za-z0-9_+-]{1,32}(/[A-Za-z0-9_+-]{1,32}){0,2}$`)

// zoneinfoDir: where the time zones live (tests point it elsewhere).
var zoneinfoDir = "/usr/share/zoneinfo"

// timedate: timedatectl show, as a map (Timezone, NTP, CanNTP, NTPSynchronized…).
func timedate(ctx context.Context, env *Env) map[string]string {
	out := map[string]string{}
	res, err := env.Exec.Run(ctx, "timedatectl", "show")
	if err != nil {
		return out
	}
	for _, line := range strings.Split(res.Stdout, "\n") {
		if k, v, ok := strings.Cut(line, "="); ok {
			out[k] = v
		}
	}
	return out
}

type swapArea struct {
	Path    string `json:"path"`
	Type    string `json:"type"` // file | partition
	Size    int64  `json:"size"`
	Used    int64  `json:"used"`
	Managed bool   `json:"managed"` // the panel's swap file
}

func activeSwaps(env *Env) []swapArea {
	out := []swapArea{}
	b, err := os.ReadFile("/proc/swaps")
	if err != nil {
		return out
	}
	for i, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if i == 0 || len(f) < 4 {
			continue
		}
		size, _ := strconv.ParseInt(f[2], 10, 64)
		used, _ := strconv.ParseInt(f[3], 10, 64)
		path := strings.ReplaceAll(f[0], `\040`, " ")
		out = append(out, swapArea{Path: path, Type: f[1], Size: size * 1024, Used: used * 1024, Managed: path == env.Cfg.SwapFile})
	}
	return out
}

func swappiness() int {
	b, err := os.ReadFile("/proc/sys/vm/swappiness")
	if err != nil {
		return -1
	}
	n, err := strconv.Atoi(strings.TrimSpace(string(b)))
	if err != nil {
		return -1
	}
	return n
}

// sysSettings: the server's name, time zone, time sync and swap.
func sysSettings(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Timezones bool `json:"timezones,omitempty"` // also the list to pick from
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	host, _ := os.Hostname()
	td := timedate(ctx, env)
	out := map[string]any{
		"hostname":         host,
		"timezone":         td["Timezone"],
		"ntp":              td["NTP"] == "yes",
		"ntp_available":    td["CanNTP"] != "no",
		"ntp_synchronized": td["NTPSynchronized"] == "yes",
		"time":             time.Now().Format(time.RFC3339),
		"swaps":            activeSwaps(env),
		"swappiness":       swappiness(),
		"swap_file":        env.Cfg.SwapFile,
	}
	if fi, err := os.Stat(env.Cfg.SwapFile); err == nil && fi.Mode().IsRegular() {
		out["swap_file_size"] = fi.Size()
	}
	if p.Timezones {
		zones := []string{}
		if res, err := env.Exec.Run(ctx, "timedatectl", "list-timezones"); err == nil {
			for _, z := range strings.Fields(res.Stdout) {
				if tzRe.MatchString(z) {
					zones = append(zones, z)
				}
			}
		}
		out["timezones"] = zones
	}
	return out, nil
}

// sysHostname renames the server: hostnamectl, its line in /etc/hosts, and
// on cloud servers cloud-init told to keep it (it would set the provider's
// name again at boot).
func sysHostname(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Hostname string `json:"hostname"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	p.Hostname = strings.TrimSuffix(strings.ToLower(strings.TrimSpace(p.Hostname)), ".")
	if err := validHostname(p.Hostname); err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "hostnamectl", "set-hostname", p.Hostname); err != nil {
		return nil, err
	}
	if err := setHostsLine(env, p.Hostname); err != nil {
		return nil, err
	}
	if fi, err := os.Stat(env.Cfg.CloudCfgDir); err == nil && fi.IsDir() {
		conf := "# Managed by WSPanel (Server → Systém): the name set in the panel stays.\npreserve_hostname: true\n"
		if err := env.FS.WriteFile(filepath.Join(env.Cfg.CloudCfgDir, "99-wspanel-hostname.cfg"), []byte(conf), 0o644, fsx.Root); err != nil {
			return nil, err
		}
	}
	return map[string]any{"hostname": p.Hostname}, nil
}

// setHostsLine points 127.0.1.1 at the new name (Debian's convention), so the
// server resolves its own name without DNS.
func setHostsLine(env *Env, host string) error {
	b, err := os.ReadFile(env.Cfg.HostsFile)
	if err != nil && !os.IsNotExist(err) {
		return err
	}
	names := host
	if short, _, ok := strings.Cut(host, "."); ok {
		names += " " + short
	}
	entry := "127.0.1.1\t" + names
	lines := strings.Split(strings.TrimRight(string(b), "\n"), "\n")
	done := false
	for i, l := range lines {
		if f := strings.Fields(l); len(f) > 0 && f[0] == "127.0.1.1" {
			if done {
				lines[i] = ""
				continue
			}
			lines[i], done = entry, true
		}
	}
	if !done {
		lines = append(lines, entry)
	}
	kept := lines[:0]
	for _, l := range lines {
		if l != "" || len(kept) == 0 {
			kept = append(kept, l)
		}
	}
	return env.FS.WriteFile(env.Cfg.HostsFile, []byte(strings.Join(kept, "\n")+"\n"), 0o644, fsx.Root)
}

func sysTimezone(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Timezone string `json:"timezone"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !tzRe.MatchString(p.Timezone) || strings.Contains(p.Timezone, "..") {
		return nil, protocol.Invalid("timezone: e.g. Europe/Prague")
	}
	if fi, err := os.Stat(filepath.Join(zoneinfoDir, p.Timezone)); err != nil || !fi.Mode().IsRegular() {
		return nil, protocol.Invalid("timezone: unknown time zone " + p.Timezone)
	}
	if _, err := env.Exec.Run(ctx, "timedatectl", "set-timezone", p.Timezone); err != nil {
		return nil, err
	}
	return map[string]any{"timezone": p.Timezone}, nil
}

// sysNTP switches time sync (systemd-timesyncd, installed when missing).
func sysNTP(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Enabled bool `json:"enabled"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Enabled && timedate(ctx, env)["CanNTP"] == "no" {
		if _, err := aptRun(ctx, env, "--no-install-recommends", "install", "systemd-timesyncd"); err != nil {
			return nil, err
		}
	}
	if _, err := env.Exec.Run(ctx, "timedatectl", "set-ntp", strconv.FormatBool(p.Enabled)); err != nil {
		return nil, err
	}
	return map[string]any{"ntp": p.Enabled}, nil
}

// ---------------------------------------------------------------- swap

// sysSwap sets the panel's swap file to size_mb (0 removes it) and, when
// given, vm.swappiness. Swap the panel did not create (a partition, another
// file) is listed but never touched.
func sysSwap(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		SizeMB     *int `json:"size_mb,omitempty"`
		Swappiness *int `json:"swappiness,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.SizeMB == nil && p.Swappiness == nil {
		return nil, protocol.Invalid("size_mb or swappiness")
	}
	if p.Swappiness != nil {
		if *p.Swappiness < 0 || *p.Swappiness > 200 {
			return nil, protocol.Invalid("swappiness: 0 to 200")
		}
		conf := fmt.Sprintf("# Managed by WSPanel (Server → Systém).\nvm.swappiness = %d\n", *p.Swappiness)
		if err := env.FS.WriteFile(filepath.Join(env.Cfg.SysctlDir, "60-wspanel-swap.conf"), []byte(conf), 0o644, fsx.Root); err != nil {
			return nil, err
		}
		if !env.Cfg.DryRun {
			if err := os.WriteFile("/proc/sys/vm/swappiness", []byte(strconv.Itoa(*p.Swappiness)), 0o644); err != nil {
				return nil, err
			}
		}
	}
	if p.SizeMB != nil {
		size := *p.SizeMB
		if size != 0 && (size < 256 || size > 64*1024) {
			return nil, protocol.Invalid("size_mb: 0 (none) or 256 to 65536")
		}
		if err := resizeSwapFile(ctx, env, size); err != nil {
			return nil, err
		}
	}
	return map[string]any{"swaps": activeSwaps(env), "swappiness": swappiness()}, nil
}

func resizeSwapFile(ctx context.Context, env *Env, sizeMB int) error {
	path := env.Cfg.SwapFile
	if fi, err := os.Lstat(path); err == nil && !fi.Mode().IsRegular() {
		return protocol.Invalid(path + " exists and is not a file")
	}
	active := slices.ContainsFunc(activeSwaps(env), func(s swapArea) bool { return s.Path == path })
	if sizeMB > 0 {
		var free int64 = -1
		for _, fs := range filesystems() {
			if fsx.Within(fs.Mount, path) || fs.Mount == "/" && free < 0 {
				free = fs.Free
			}
		}
		if old, err := os.Stat(path); err == nil {
			free += old.Size()
		}
		if free >= 0 && free < int64(sizeMB)<<20+1<<30 {
			return protocol.Invalid(fmt.Sprintf("size_mb: not enough free disk (the swap file needs %d MB and 1 GB must stay free)", sizeMB))
		}
	}
	// The old one goes first (its pages come back into memory).
	if active {
		if _, err := env.Exec.Run(ctx, "swapoff", "--", path); err != nil {
			return fmt.Errorf("swap could not be switched off (not enough free memory?): %w", err)
		}
	}
	if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
		return err
	}
	if sizeMB == 0 {
		return setFstabSwap(env, false)
	}
	if _, err := env.Exec.Run(ctx, "fallocate", "-l", strconv.Itoa(sizeMB)+"M", "--", path); err != nil {
		// Filesystems without fallocate for swap (some): write it out.
		os.Remove(path)
		if _, err := env.Exec.Run(ctx, "dd", "if=/dev/zero", "of="+path, "bs=1M", "count="+strconv.Itoa(sizeMB), "status=none"); err != nil {
			os.Remove(path)
			return err
		}
	}
	if !env.Cfg.DryRun {
		if err := os.Chmod(path, 0o600); err != nil {
			return err
		}
	}
	if _, err := env.Exec.Run(ctx, "mkswap", "--", path); err != nil {
		os.Remove(path)
		return err
	}
	if _, err := env.Exec.Run(ctx, "swapon", "--", path); err != nil {
		os.Remove(path)
		return fmt.Errorf("swap could not be switched on (a container or a filesystem without swap files?): %w", err)
	}
	return setFstabSwap(env, true)
}

// setFstabSwap adds or removes the swap file's line in /etc/fstab.
func setFstabSwap(env *Env, on bool) error {
	b, err := os.ReadFile(env.Cfg.Fstab)
	if err != nil && !os.IsNotExist(err) {
		return err
	}
	lines := []string{}
	for _, l := range strings.Split(strings.TrimRight(string(b), "\n"), "\n") {
		if f := strings.Fields(l); len(f) >= 3 && f[0] == env.Cfg.SwapFile && f[2] == "swap" {
			continue
		}
		lines = append(lines, l)
	}
	if on {
		lines = append(lines, env.Cfg.SwapFile+"\tnone\tswap\tsw\t0\t0")
	}
	return env.FS.WriteFile(env.Cfg.Fstab, []byte(strings.Join(lines, "\n")+"\n"), 0o644, fsx.Root)
}

// ---------------------------------------------------------------- disk usage

type duEntry struct {
	Path string `json:"path"`
	Size int64  `json:"size"`
}

// sysDiskUsage: the size of each directory directly under path (one
// filesystem, du -x), largest first.
func sysDiskUsage(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Path string `json:"path"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Path == "" {
		p.Path = "/"
	}
	if !filepath.IsAbs(p.Path) || filepath.Clean(p.Path) != p.Path || strings.ContainsAny(p.Path, "\n\x00") {
		return nil, protocol.Invalid("path: an absolute, clean path")
	}
	fi, err := os.Lstat(p.Path)
	if err != nil || !fi.IsDir() {
		return nil, protocol.Invalid("path: not a directory")
	}
	ctx, cancel := context.WithTimeout(ctx, 90*time.Second)
	defer cancel()
	res, err := env.Exec.RunIO(ctx, nil, nil, "du", "-x", "-B1", "-d", "1", "--", p.Path)
	if err != nil && res.Stdout == "" { // unreadable corners only make du exit 1
		return nil, err
	}
	entries := []duEntry{}
	var total int64
	for _, line := range strings.Split(res.Stdout, "\n") {
		size, path, ok := strings.Cut(line, "\t")
		if !ok {
			continue
		}
		n, _ := strconv.ParseInt(size, 10, 64)
		if path == p.Path {
			total = n
			continue
		}
		entries = append(entries, duEntry{Path: path, Size: n})
	}
	slices.SortFunc(entries, func(a, b duEntry) int {
		switch {
		case a.Size > b.Size:
			return -1
		case a.Size < b.Size:
			return 1
		}
		return strings.Compare(a.Path, b.Path)
	})
	if len(entries) > 100 {
		entries = entries[:100]
	}
	return map[string]any{"path": p.Path, "total": total, "entries": entries, "partial": err != nil}, nil
}

// ---------------------------------------------------------------- disks (SMART)

var (
	smartctlBin = "/usr/sbin/smartctl"
	devNameRe   = regexp.MustCompile(`^/dev/[A-Za-z0-9/_-]{1,64}$`)
	devTypeRe   = regexp.MustCompile(`^[A-Za-z0-9,_+-]{1,32}$`)
)

type smartDisk struct {
	Name        string `json:"name"`
	Type        string `json:"type"`
	Model       string `json:"model"`
	Serial      string `json:"serial"`
	Capacity    int64  `json:"capacity"`
	Available   bool   `json:"available"` // the disk reports SMART
	Passed      *bool  `json:"passed"`
	Temperature *int   `json:"temperature"`
	PowerOnHrs  *int   `json:"power_on_hours"`
	WearPercent *int   `json:"wear_percent"` // NVMe: percentage used
	Reallocated *int64 `json:"reallocated"`  // ATA 5
	Pending     *int64 `json:"pending"`      // ATA 197
	Error       string `json:"error,omitempty"`
}

// sysDisks: the health of each disk smartctl finds. Without smartmontools
// (installed from Balíčky on demand) only installed=false; virtual servers'
// disks usually report no SMART.
func sysDisks(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	if !env.Cfg.DryRun && !fsx.Exists(smartctlBin) {
		return map[string]any{"installed": false, "disks": []smartDisk{}}, nil
	}
	res, err := env.Exec.Run(ctx, "smartctl", "--scan-open", "-j")
	if err != nil && res.Stdout == "" {
		return nil, err
	}
	var scan struct {
		Devices []struct {
			Name string `json:"name"`
			Type string `json:"type"`
		} `json:"devices"`
	}
	_ = json.Unmarshal([]byte(res.Stdout), &scan)
	disks := []smartDisk{}
	for _, d := range scan.Devices {
		if !devNameRe.MatchString(d.Name) || !devTypeRe.MatchString(d.Type) || len(disks) >= 32 {
			continue
		}
		disks = append(disks, readSmart(ctx, env, d.Name, d.Type))
	}
	return map[string]any{"installed": true, "disks": disks}, nil
}

func readSmart(ctx context.Context, env *Env, name, typ string) smartDisk {
	disk := smartDisk{Name: name, Type: typ}
	// smartctl's exit status is a bit mask; bits 0-1 mean it could not read
	// the disk at all, the others report what it found (in the JSON too).
	res, err := env.Exec.Run(ctx, "smartctl", "-j", "-i", "-H", "-A", "-d", typ, "--", name)
	if err != nil && res.ExitCode&0b11 != 0 || res.Stdout == "" {
		disk.Error = "the disk could not be read"
		return disk
	}
	var s struct {
		Model    string `json:"model_name"`
		Serial   string `json:"serial_number"`
		Capacity struct {
			Bytes int64 `json:"bytes"`
		} `json:"user_capacity"`
		NVMeCapacity int64 `json:"nvme_total_capacity"`
		Support      struct {
			Available *bool `json:"available"`
		} `json:"smart_support"`
		Status *struct {
			Passed bool `json:"passed"`
		} `json:"smart_status"`
		Temperature *struct {
			Current int `json:"current"`
		} `json:"temperature"`
		PowerOn *struct {
			Hours int `json:"hours"`
		} `json:"power_on_time"`
		NVMe *struct {
			PercentageUsed int `json:"percentage_used"`
		} `json:"nvme_smart_health_information_log"`
		ATA *struct {
			Table []struct {
				ID  int `json:"id"`
				Raw struct {
					Value int64 `json:"value"`
				} `json:"raw"`
			} `json:"table"`
		} `json:"ata_smart_attributes"`
	}
	if json.Unmarshal([]byte(res.Stdout), &s) != nil {
		disk.Error = "unreadable smartctl output"
		return disk
	}
	disk.Model, disk.Serial, disk.Capacity = s.Model, s.Serial, s.Capacity.Bytes
	if disk.Capacity == 0 {
		disk.Capacity = s.NVMeCapacity
	}
	disk.Available = s.Status != nil && (s.Support.Available == nil || *s.Support.Available)
	if s.Status != nil {
		disk.Passed = &s.Status.Passed
	}
	if s.Temperature != nil && s.Temperature.Current > 0 {
		disk.Temperature = &s.Temperature.Current
	}
	if s.PowerOn != nil {
		disk.PowerOnHrs = &s.PowerOn.Hours
	}
	if s.NVMe != nil {
		disk.WearPercent = &s.NVMe.PercentageUsed
	}
	if s.ATA != nil {
		for _, a := range s.ATA.Table {
			v := a.Raw.Value
			switch a.ID {
			case 5:
				disk.Reallocated = &v
			case 197:
				disk.Pending = &v
			}
		}
	}
	return disk
}

// ---------------------------------------------------------------- network

type netIface struct {
	Name      string   `json:"name"`
	State     string   `json:"state"`
	MAC       string   `json:"mac"`
	MTU       int      `json:"mtu"`
	Addresses []string `json:"addresses"` // CIDR
}

// sysNetwork: interfaces, addresses, default routes and resolvers (read
// only: a wrong network change from a web page cuts the way back).
func sysNetwork(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	res, err := env.Exec.Run(ctx, "ip", "-j", "addr", "show")
	if err != nil {
		return nil, err
	}
	var links []struct {
		Name    string `json:"ifname"`
		State   string `json:"operstate"`
		MAC     string `json:"address"`
		MTU     int    `json:"mtu"`
		Link    string `json:"link_type"`
		AddrInf []struct {
			Local     string `json:"local"`
			PrefixLen int    `json:"prefixlen"`
			Scope     string `json:"scope"`
		} `json:"addr_info"`
	}
	_ = json.Unmarshal([]byte(res.Stdout), &links)
	ifaces := []netIface{}
	for _, l := range links {
		if l.Link == "loopback" {
			continue
		}
		in := netIface{Name: l.Name, State: strings.ToLower(l.State), MAC: l.MAC, MTU: l.MTU, Addresses: []string{}}
		for _, a := range l.AddrInf {
			if a.Local != "" {
				in.Addresses = append(in.Addresses, a.Local+"/"+strconv.Itoa(a.PrefixLen))
			}
		}
		ifaces = append(ifaces, in)
	}
	gateways := []map[string]string{}
	for _, fam := range []string{"-4", "-6"} {
		r, err := env.Exec.Run(ctx, "ip", "-j", fam, "route", "show", "default")
		if err != nil {
			continue
		}
		var routes []struct {
			Gateway string `json:"gateway"`
			Dev     string `json:"dev"`
		}
		_ = json.Unmarshal([]byte(r.Stdout), &routes)
		for _, rt := range routes {
			if rt.Gateway != "" {
				gateways = append(gateways, map[string]string{"gateway": rt.Gateway, "dev": rt.Dev})
			}
		}
	}
	resolvers := []string{}
	if b, err := os.ReadFile("/etc/resolv.conf"); err == nil {
		for _, line := range strings.Split(string(b), "\n") {
			if f := strings.Fields(line); len(f) >= 2 && f[0] == "nameserver" {
				resolvers = append(resolvers, f[1])
			}
		}
	}
	return map[string]any{"interfaces": ifaces, "gateways": gateways, "resolvers": resolvers}, nil
}
