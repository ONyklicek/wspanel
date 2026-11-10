package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"net/netip"
	"os"
	"regexp"
	"strings"
	"time"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// System updates (apt), automatic security updates, and fail2ban's bans.

type upgradable struct {
	Name     string `json:"name"`
	From     string `json:"from"`
	To       string `json:"to"`
	Security bool   `json:"security"`
}

// "Inst libssl3 [3.0.15-1] (3.0.16-1 Debian-Security:13/stable-security [amd64])"
// upgradeNameRe: a package as apt lists it for an upgrade (name, optionally :arch).
var upgradeNameRe = regexp.MustCompile(`^[a-z0-9][a-z0-9.+-]{0,99}(:[a-z0-9-]{1,20})?$`)

var aptInstRe = regexp.MustCompile(`^Inst (\S+) (?:\[(\S+)\] )?\((\S+) ([^\[]*)`)

func sysUpdates(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Refresh bool `json:"refresh,omitempty"` // apt-get update first
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Refresh {
		uctx, cancel := context.WithTimeout(ctx, 10*time.Minute) // slow mirrors: past the executor's 2 minutes
		defer cancel()
		if _, err := env.Exec.RunIO(uctx, nil, nil, "apt-get", "update", "-q"); err != nil {
			return nil, err
		}
	}
	// A dry run of the upgrade lists exactly what it would install.
	res, err := env.Exec.Run(ctx, "apt-get", "-s", "-o", "Debug::NoLocking=1", "dist-upgrade")
	if err != nil {
		return nil, err
	}
	pkgs := []upgradable{}
	for _, line := range strings.Split(res.Stdout, "\n") {
		if m := aptInstRe.FindStringSubmatch(line); m != nil {
			pkgs = append(pkgs, upgradable{Name: m[1], From: m[2], To: m[3], Security: strings.Contains(strings.ToLower(m[4]), "security")})
		}
	}
	out := map[string]any{"packages": pkgs, "reboot_required": fsx.Exists("/run/reboot-required")}
	if b, err := os.ReadFile("/run/reboot-required.pkgs"); err == nil {
		out["reboot_packages"] = strings.Fields(string(b))
	}
	out["auto_security"] = autoSecurityOn(env)
	out["auto_reboot"], out["auto_reboot_time"] = autoReboot(env)
	out["reboot_scheduled"] = scheduledReboot()
	out["booted_at"] = bootedAt()
	return out, nil
}

func autoSecurityOn(env *Env) bool {
	b, err := os.ReadFile(env.Cfg.AutoUpgrades)
	return err == nil && strings.Contains(string(b), `Unattended-Upgrade "1"`)
}

// sysUpgrade installs the updates (async): all of them, or only security ones.
func sysUpgrade(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		SecurityOnly bool     `json:"security_only,omitempty"`
		Packages     []string `json:"packages,omitempty"` // only these (already installed) packages
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if len(p.Packages) > 0 && p.SecurityOnly {
		return nil, protocol.Invalid("packages or security_only")
	}
	if len(p.Packages) > 2000 {
		return nil, protocol.Invalid("packages: at most 2000")
	}
	for _, n := range p.Packages {
		if !upgradeNameRe.MatchString(n) {
			return nil, protocol.Invalid(fmt.Sprintf("packages: %q is not a package name", n))
		}
	}
	var (
		res executor.Result
		err error
	)
	keepConf := []string{"-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold", "-o", "DPkg::Lock::Timeout=300"}
	if len(p.Packages) > 0 {
		// --only-upgrade: a name that is not installed is skipped, never installed.
		args := append([]string{"install", "-y", "-q", "--only-upgrade", "--no-install-recommends"}, keepConf...)
		res, err = upgradeRun(ctx, env, "/usr/bin/apt-get", append(append(args, "--"), p.Packages...)...)
	} else if p.SecurityOnly {
		if !env.Cfg.DryRun {
			if _, err := aptRun(ctx, env, "--no-install-recommends", "install", "unattended-upgrades"); err != nil {
				return nil, err
			}
		}
		res, err = upgradeRun(ctx, env, "/usr/bin/unattended-upgrade", "-v")
	} else {
		// Keep changed config files (a package's new default never overwrites ours).
		res, err = upgradeRun(ctx, env, "/usr/bin/apt-get", append(append([]string{"-y", "-q"}, keepConf...), "dist-upgrade")...)
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{"upgraded": true, "reboot_required": fsx.Exists("/run/reboot-required"), "log": tail(res.Stdout, 4000)}, nil
}

// upgradeRun runs an upgrade in a transient unit of its own: an upgrade of
// the server takes minutes (no executor default timeout, only the job's), and
// it may upgrade wspanel-agent, whose restart stops everything in the agent's
// cgroup. dpkg killed half-way leaves the system half-configured; in its own
// unit it finishes even when the agent restarts under it.
func upgradeRun(ctx context.Context, env *Env, bin string, args ...string) (executor.Result, error) {
	unit := fmt.Sprintf("wspanel-upgrade-%d", time.Now().UnixNano())
	full := append([]string{"--wait", "--pipe", "--collect", "--quiet", "--service-type=exec",
		"--unit=" + unit, "--description=WSPanel: server update",
		"--setenv=DEBIAN_FRONTEND=noninteractive", "--setenv=PATH=/usr/sbin:/usr/bin:/sbin:/bin",
		"--", bin}, args...)
	return env.Exec.RunIO(ctx, nil, nil, "systemd-run", full...)
}

// sysAutoUpdates switches the daily automatic security updates.
func sysAutoUpdates(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Enabled bool `json:"enabled"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Enabled && !env.Cfg.DryRun {
		if _, err := aptRun(ctx, env, "--no-install-recommends", "install", "unattended-upgrades"); err != nil {
			return nil, err
		}
	}
	v := "0"
	if p.Enabled {
		v = "1"
	}
	conf := "// Managed by WSPanel (Server → Aktualizace).\nAPT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"" + v + "\";\n"
	if err := env.FS.WriteFile(env.Cfg.AutoUpgrades, []byte(conf), 0o644, fsx.Root); err != nil {
		return nil, err
	}
	return map[string]any{"auto_security": p.Enabled}, nil
}

// ---------------------------------------------------------------- fail2ban

func fail2banStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	res, err := env.Exec.Run(ctx, "fail2ban-client", "status")
	if err != nil {
		return nil, err
	}
	jails := map[string][]string{}
	for _, line := range strings.Split(res.Stdout, "\n") {
		if _, list, ok := strings.Cut(line, "Jail list:"); ok {
			for _, j := range strings.Split(list, ",") {
				if j = strings.TrimSpace(j); j != "" && validJail.MatchString(j) {
					jails[j] = []string{}
				}
			}
		}
	}
	for j := range jails {
		st, err := env.Exec.Run(ctx, "fail2ban-client", "status", j)
		if err != nil {
			continue
		}
		for _, line := range strings.Split(st.Stdout, "\n") {
			if _, list, ok := strings.Cut(line, "Banned IP list:"); ok {
				jails[j] = append(jails[j], strings.Fields(list)...)
			}
		}
	}
	return map[string]any{"jails": jails}, nil
}

var validJail = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,64}$`)

func fail2banUnban(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		IP string `json:"ip"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	addr, err := netip.ParseAddr(p.IP)
	if err != nil {
		return nil, protocol.Invalid("ip: not an IP address")
	}
	if _, err := env.Exec.Run(ctx, "fail2ban-client", "unban", addr.String()); err != nil {
		return nil, err
	}
	return map[string]any{"ip": addr.String(), "unbanned": true}, nil
}
