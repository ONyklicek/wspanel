package ops

import (
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"wspanel/agent/internal/executor"
)

func TestRebootIsScheduledAndCancelled(t *testing.T) {
	env, dr := testEnv(t)
	for _, bad := range []map[string]any{{"in_minutes": 0, "at": "25:00"}, {"in_minutes": 2000}, {"in_minutes": -1}, {"at": "4:00"}, {"at": "04:00", "in_minutes": 5}} {
		if _, err := call(t, env, sysReboot, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	if len(dr.Calls()) != 0 {
		t.Fatalf("nothing may run for a refused restart: %v", dr.Calls())
	}
	out, err := call(t, env, sysReboot, map[string]any{"in_minutes": 5})
	if err != nil {
		t.Fatal(err)
	}
	c := dr.Calls()[0]
	if c.Name != "systemctl" || c.Args[0] != "reboot" || !strings.HasPrefix(c.Args[1], "--when=") {
		t.Errorf("reboot call: %v", c)
	}
	when, _ := time.Parse(time.RFC3339, out.(map[string]any)["scheduled"].(string))
	if d := time.Until(when); d < 4*time.Minute || d > 6*time.Minute {
		t.Errorf("scheduled in %v", d)
	}
	if _, err := call(t, env, sysReboot, map[string]any{"cancel": true}); err != nil || dr.Calls()[1].String() != "systemctl reboot --when=cancel" {
		t.Errorf("cancel: %v %v", err, dr.Calls())
	}
}

func TestNextClockIsTheNextOccurrence(t *testing.T) {
	loc := time.FixedZone("x", 3600)
	now := time.Date(2026, 11, 9, 15, 0, 0, 0, loc)
	if got := nextClock(now, "16:30"); !got.Equal(time.Date(2026, 11, 9, 16, 30, 0, 0, loc)) {
		t.Errorf("later today: %v", got)
	}
	if got := nextClock(now, "04:00"); !got.Equal(time.Date(2026, 11, 10, 4, 0, 0, 0, loc)) {
		t.Errorf("tomorrow: %v", got)
	}
}

func TestAutoRebootWritesUnattendedUpgradesConfig(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, sysAutoReboot, map[string]any{"enabled": true, "time": "4am"}); code(err) != "invalid_params" {
		t.Errorf("a bad time must be refused: %v", err)
	}
	if _, err := call(t, env, sysAutoReboot, map[string]any{"enabled": true, "time": "03:30"}); err != nil {
		t.Fatal(err)
	}
	if on, at := autoReboot(env); !on || at != "03:30" {
		t.Errorf("auto reboot: %v %q", on, at)
	}
	if !strings.Contains(read2(t, autoRebootFile(env)), `Unattended-Upgrade::Automatic-Reboot-Time "03:30";`) {
		t.Error("the time is in the config")
	}
	if _, err := call(t, env, sysAutoReboot, map[string]any{"enabled": false}); err != nil {
		t.Fatal(err)
	}
	if on, _ := autoReboot(env); on || fileExists(autoRebootFile(env)) {
		t.Error("switched off: the file is gone")
	}
}

func fileExists(p string) bool { _, err := os.Stat(p); return err == nil }

func TestHostnameRenamesTheServerAndItsHostsLine(t *testing.T) {
	env, dr := testEnv(t)
	for _, bad := range []string{"", "-x.example.com", "a..b", "a b", "host_name", "x;reboot", strings.Repeat("a", 64) + ".com"} {
		if _, err := call(t, env, sysHostname, map[string]string{"hostname": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	os.MkdirAll(filepath.Dir(env.Cfg.HostsFile), 0o755)
	os.WriteFile(env.Cfg.HostsFile, []byte("127.0.0.1\tlocalhost\n127.0.1.1\tdebian.localdomain debian\n::1\tlocalhost ip6-localhost\n127.0.1.1\told\n"), 0o644)
	os.MkdirAll(env.Cfg.CloudCfgDir, 0o755)
	if _, err := call(t, env, sysHostname, map[string]string{"hostname": "Web1.Example.com."}); err != nil {
		t.Fatal(err)
	}
	if c := dr.Calls(); len(c) != 1 || c[0].String() != "hostnamectl set-hostname web1.example.com" {
		t.Errorf("calls: %v", c)
	}
	want := "127.0.0.1\tlocalhost\n127.0.1.1\tweb1.example.com web1\n::1\tlocalhost ip6-localhost\n"
	if got := read2(t, env.Cfg.HostsFile); got != want {
		t.Errorf("hosts:\n%s", got)
	}
	if !strings.Contains(read2(t, filepath.Join(env.Cfg.CloudCfgDir, "99-wspanel-hostname.cfg")), "preserve_hostname: true") {
		t.Error("cloud-init keeps the name")
	}
}

func TestTimezoneMustExist(t *testing.T) {
	env, dr := testEnv(t)
	zoneinfoDir = t.TempDir()
	t.Cleanup(func() { zoneinfoDir = "/usr/share/zoneinfo" })
	os.MkdirAll(filepath.Join(zoneinfoDir, "Europe"), 0o755)
	os.WriteFile(filepath.Join(zoneinfoDir, "Europe/Prague"), []byte("TZif"), 0o644)
	for _, bad := range []string{"Europe/Nowhere", "../etc/passwd", "Europe", "a b", "Europe/../Europe/Prague"} {
		if _, err := call(t, env, sysTimezone, map[string]string{"timezone": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, sysTimezone, map[string]string{"timezone": "Europe/Prague"}); err != nil {
		t.Fatal(err)
	}
	if c := dr.Calls(); len(c) != 1 || c[0].String() != "timedatectl set-timezone Europe/Prague" {
		t.Errorf("calls: %v", c)
	}
}

func TestNTPInstallsTimesyncdWhereMissing(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.String() == "timedatectl show" {
			return executor.Result{Stdout: "Timezone=UTC\nNTP=no\nCanNTP=no\nNTPSynchronized=no\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, sysSettings, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	if m := out.(map[string]any); m["timezone"] != "UTC" || m["ntp"] != false || m["ntp_available"] != false {
		t.Errorf("settings: %v", m)
	}
	if _, err := call(t, env, sysNTP, map[string]bool{"enabled": true}); err != nil {
		t.Fatal(err)
	}
	got := []string{}
	for _, c := range dr.Calls()[1:] {
		got = append(got, c.String())
	}
	if strings.Join(got, "|") != "timedatectl show|apt-get install -y -q --no-install-recommends systemd-timesyncd|timedatectl set-ntp true" {
		t.Errorf("calls: %v", got)
	}
}

func TestSwapFileIsCreatedAndRemovedWithItsFstabLine(t *testing.T) {
	env, dr := testEnv(t)
	for _, bad := range []map[string]any{{}, {"size_mb": 100}, {"size_mb": 70000}, {"swappiness": 300}} {
		if _, err := call(t, env, sysSwap, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	os.WriteFile(env.Cfg.Fstab, []byte("UUID=abc / ext4 defaults 0 1\n"), 0o644)
	if _, err := call(t, env, sysSwap, map[string]any{"size_mb": 1024, "swappiness": 10}); err != nil {
		t.Fatal(err)
	}
	got := []string{}
	for _, c := range dr.Calls() {
		got = append(got, c.Name+" "+c.Args[0])
	}
	if strings.Join(got, "|") != "fallocate -l|mkswap --|swapon --" {
		t.Errorf("calls: %v", got)
	}
	if f := read2(t, env.Cfg.Fstab); f != "UUID=abc / ext4 defaults 0 1\n"+env.Cfg.SwapFile+"\tnone\tswap\tsw\t0\t0\n" {
		t.Errorf("fstab:\n%s", f)
	}
	if !strings.Contains(read2(t, filepath.Join(env.Cfg.SysctlDir, "60-wspanel-swap.conf")), "vm.swappiness = 10") {
		t.Error("swappiness kept for boot")
	}
	if _, err := call(t, env, sysSwap, map[string]any{"size_mb": 0}); err != nil {
		t.Fatal(err)
	}
	if f := read2(t, env.Cfg.Fstab); f != "UUID=abc / ext4 defaults 0 1\n" {
		t.Errorf("fstab after removal:\n%s", f)
	}
}

func TestDiskUsageListsDirectoriesLargestFirst(t *testing.T) {
	env, dr := testEnv(t)
	dir := t.TempDir()
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		return executor.Result{Stdout: "100\t" + dir + "/a\n5000\t" + dir + "/b\n5100\t" + dir + "\n", ExitCode: 1}, os.ErrPermission
	}
	for _, bad := range []string{"relative", "/x/../etc", dir + "/missing"} {
		if _, err := call(t, env, sysDiskUsage, map[string]string{"path": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	out, err := call(t, env, sysDiskUsage, map[string]string{"path": dir})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	e := m["entries"].([]duEntry)
	if m["total"] != int64(5100) || len(e) != 2 || e[0].Path != dir+"/b" || m["partial"] != true {
		t.Errorf("usage: %v", m)
	}
	if c := dr.Calls()[0]; c.String() != "du -x -B1 -d 1 -- "+dir {
		t.Errorf("du: %v", c)
	}
}

func TestDisksReadSMART(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch {
		case strings.HasPrefix(c.String(), "smartctl --scan-open"):
			return executor.Result{Stdout: `{"devices":[{"name":"/dev/sda","type":"sat"},{"name":"/dev/nvme0","type":"nvme"},{"name":"/dev/sdb; reboot","type":"sat"}]}`}, nil
		case strings.HasSuffix(c.String(), "/dev/sda"):
			// Exit bit 3 (failing) still has the data.
			return executor.Result{ExitCode: 8, Stdout: `{"model_name":"WDC X","serial_number":"S1","user_capacity":{"bytes":1000},"smart_status":{"passed":false},"temperature":{"current":41},"power_on_time":{"hours":12000},"ata_smart_attributes":{"table":[{"id":5,"raw":{"value":12}},{"id":197,"raw":{"value":3}}]}}`}, os.ErrInvalid
		case strings.HasSuffix(c.String(), "/dev/nvme0"):
			return executor.Result{Stdout: `{"model_name":"Samsung","nvme_total_capacity":2000,"smart_status":{"passed":true},"nvme_smart_health_information_log":{"percentage_used":7}}`}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, sysDisks, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	d := out.(map[string]any)["disks"].([]smartDisk)
	if len(d) != 2 {
		t.Fatalf("disks (the odd name skipped): %+v", d)
	}
	if d[0].Passed == nil || *d[0].Passed || *d[0].Reallocated != 12 || *d[0].Pending != 3 || *d[0].Temperature != 41 || d[0].Error != "" {
		t.Errorf("sda: %+v", d[0])
	}
	if !*d[1].Passed || *d[1].WearPercent != 7 || d[1].Capacity != 2000 {
		t.Errorf("nvme: %+v", d[1])
	}
}

func TestNetworkSkipsLoopback(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch c.String() {
		case "ip -j addr show":
			return executor.Result{Stdout: `[{"ifname":"lo","link_type":"loopback","addr_info":[{"local":"127.0.0.1","prefixlen":8}]},{"ifname":"eth0","operstate":"UP","address":"aa:bb","mtu":1500,"link_type":"ether","addr_info":[{"local":"203.0.113.5","prefixlen":24},{"local":"2001:db8::5","prefixlen":64}]}]`}, nil
		case "ip -j -4 route show default":
			return executor.Result{Stdout: `[{"dst":"default","gateway":"203.0.113.1","dev":"eth0"}]`}, nil
		}
		return executor.Result{Stdout: "[]"}, nil
	}
	out, err := call(t, env, sysNetwork, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	ifs := m["interfaces"].([]netIface)
	if len(ifs) != 1 || ifs[0].State != "up" || strings.Join(ifs[0].Addresses, ",") != "203.0.113.5/24,2001:db8::5/64" {
		t.Errorf("interfaces: %+v", ifs)
	}
	if gw := m["gateways"].([]map[string]string); len(gw) != 1 || gw[0]["gateway"] != "203.0.113.1" {
		t.Errorf("gateways: %v", gw)
	}
}

func TestKillChecksTheProcessFirst(t *testing.T) {
	env, _ := testEnv(t)
	env.Cfg.DryRun = false
	procs := map[int]procIdent{
		100: {Start: 5, PPID: 1, Cmd: "/usr/bin/python3 app.py", Cgroup: "0::/system.slice/wspanel-x.service"},
		200: {Start: 6, PPID: 2, Cmd: ""},
		300: {Start: 7, PPID: 1, Cmd: "/usr/bin/php artisan", Cgroup: "0::/system.slice/panel-agent.service"},
	}
	procIdentity = func(pid int) (procIdent, error) {
		if p, ok := procs[pid]; ok {
			return p, nil
		}
		return procIdent{}, os.ErrNotExist
	}
	var sent []string
	signalProcess = func(pid int, sig syscall.Signal) error { sent = append(sent, sig.String()); return nil }
	t.Cleanup(func() {
		procIdentity = readProcIdentity
		signalProcess = func(pid int, sig syscall.Signal) error { return syscall.Kill(pid, sig) }
	})
	for _, bad := range []map[string]any{
		{"pid": 1, "start": 1}, {"pid": os.Getpid(), "start": 1}, {"pid": 100, "start": 4}, // reused pid
		{"pid": 200, "start": 6}, {"pid": 300, "start": 7}, {"pid": 999, "start": 1}, {"pid": 100, "start": 5, "signal": "HUP"},
	} {
		if _, err := call(t, env, sysKill, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	if len(sent) != 0 {
		t.Fatalf("no signal for a refused process: %v", sent)
	}
	if _, err := call(t, env, sysKill, map[string]any{"pid": 100, "start": 5, "signal": "KILL"}); err != nil {
		t.Fatal(err)
	}
	if len(sent) != 1 || sent[0] != syscall.SIGKILL.String() {
		t.Errorf("sent: %v", sent)
	}
}
