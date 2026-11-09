package ops

import (
	"context"
	"encoding/json"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/protocol"
)

// Debian packages (Server → Balíčky): search and install from the configured
// repositories, list what is installed, hold a version, remove what the admin
// added. What the server and the panel run on cannot be removed here.

var pkgNameRe = regexp.MustCompile(`^[a-z0-9][a-z0-9.+-]{1,99}$`)

func validPkg(name string) error {
	if !pkgNameRe.MatchString(name) {
		return protocol.Invalid("name: a Debian package name (lowercase letters, digits, . + -)")
	}
	return nil
}

// protectedPkg: packages the panel or the system needs. Removing them here
// (or anything that would take them along) is refused.
var protectedPkgPrefixes = []string{
	"wspanel", "panel-", "frankenphp", "php", "caddy", "mariadb", "mysql", "postgresql", "libpq",
	"postfix", "dovecot", "rspamd", "clamav", "roundcube", "valkey", "redis", "pdns", "opendkim",
	"openssh", "systemd", "udev", "dbus", "ufw", "nftables", "iptables", "fail2ban", "sudo",
	"apt", "dpkg", "debian-archive-keyring", "linux-image", "linux-base", "grub", "shim", "initramfs",
	"unattended-upgrades", "ca-certificates", "libc6", "libssl", "openssl", "acl", "quota", "cron",
	"git", "curl", "gnupg", "gpg", "rclone", "smbclient", "sqlite3", "util-linux", "mount", "login",
	"passwd", "base-files", "base-passwd", "bash", "coreutils", "e2fsprogs", "ifupdown", "netplan",
	"network-manager", "cloud-init", "isc-dhcp", "dhcpcd", "tzdata", "locales",
}

func protectedPkg(name string) bool {
	return slices.ContainsFunc(protectedPkgPrefixes, func(p string) bool { return strings.HasPrefix(name, p) })
}

type pkgInfo struct {
	Name      string `json:"name"`
	Version   string `json:"version"`
	Summary   string `json:"summary"`
	Size      int64  `json:"size"` // installed size, bytes
	Manual    bool   `json:"manual"`
	Held      bool   `json:"held"`
	Protected bool   `json:"protected"`
}

const dpkgFormat = "${Package}\t${Version}\t${db:Status-Abbrev}\t${Installed-Size}\t${Priority}\t${Essential}\t${binary:Summary}\n"

type dpkgRow struct {
	pkgInfo
	essential bool
}

// installedPkgs: dpkg's view of installed packages (all, or the named ones).
func installedPkgs(ctx context.Context, env *Env, names ...string) (map[string]dpkgRow, error) {
	args := append([]string{"-W", "-f=" + dpkgFormat, "--"}, names...)
	if len(names) == 0 {
		args = args[:2]
	}
	res, err := env.Exec.Run(ctx, "dpkg-query", args...)
	if err != nil && res.Stdout == "" && len(names) == 0 { // named ones: unknown names make it exit 1
		return nil, err
	}
	out := map[string]dpkgRow{}
	for _, line := range strings.Split(res.Stdout, "\n") {
		f := strings.SplitN(line, "\t", 7)
		// Status-Abbrev: wanted action, then state ("hi" is installed and held).
		if len(f) < 7 || len(f[2]) < 2 || f[2][1] != 'i' {
			continue
		}
		kb, _ := strconv.ParseInt(f[3], 10, 64)
		name, _, _ := strings.Cut(f[0], ":")
		out[name] = dpkgRow{
			pkgInfo:   pkgInfo{Name: name, Version: f[1], Summary: f[6], Size: kb * 1024},
			essential: f[5] == "yes" || f[4] == "required" || f[4] == "important",
		}
	}
	return out, nil
}

func aptMarkList(ctx context.Context, env *Env, what string) map[string]bool {
	out := map[string]bool{}
	if res, err := env.Exec.Run(ctx, "apt-mark", what); err == nil {
		for _, n := range strings.Fields(res.Stdout) {
			name, _, _ := strings.Cut(n, ":")
			out[name] = true
		}
	}
	return out
}

// pkgList: installed packages, with what the admin installed by hand marked
// (apt-mark showmanual) and held ones.
func pkgList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	rows, err := installedPkgs(ctx, env)
	if err != nil {
		return nil, err
	}
	manual, held := aptMarkList(ctx, env, "showmanual"), aptMarkList(ctx, env, "showhold")
	out := make([]pkgInfo, 0, len(rows))
	for _, r := range rows {
		p := r.pkgInfo
		p.Manual, p.Held, p.Protected = manual[p.Name], held[p.Name], r.essential || protectedPkg(p.Name)
		out = append(out, p)
	}
	slices.SortFunc(out, func(a, b pkgInfo) int { return strings.Compare(a.Name, b.Name) })
	return map[string]any{"packages": out}, nil
}

// pkgSearch: packages whose name matches, from the configured repositories.
func pkgSearch(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Query string `json:"query"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	p.Query = strings.ToLower(strings.TrimSpace(p.Query))
	if !regexp.MustCompile(`^[a-z0-9][a-z0-9.+-]{1,63}$`).MatchString(p.Query) {
		return nil, protocol.Invalid("query: at least two characters of a package name")
	}
	// The query as a literal (apt-cache search takes a regex).
	res, err := env.Exec.Run(ctx, "apt-cache", "search", "--names-only", regexp.QuoteMeta(p.Query))
	if err != nil {
		return nil, err
	}
	found := []pkgInfo{}
	for _, line := range strings.Split(res.Stdout, "\n") {
		name, summary, ok := strings.Cut(line, " - ")
		if ok && pkgNameRe.MatchString(name) {
			found = append(found, pkgInfo{Name: name, Summary: summary})
		}
	}
	// Exact match first, then prefix matches, then the rest; at most 100.
	rank := func(n string) int {
		switch {
		case n == p.Query:
			return 0
		case strings.HasPrefix(n, p.Query):
			return 1
		}
		return 2
	}
	slices.SortFunc(found, func(a, b pkgInfo) int {
		if ra, rb := rank(a.Name), rank(b.Name); ra != rb {
			return ra - rb
		}
		return strings.Compare(a.Name, b.Name)
	})
	total := len(found)
	if len(found) > 100 {
		found = found[:100]
	}
	names := make([]string, 0, len(found))
	for _, f := range found {
		names = append(names, f.Name)
	}
	if len(names) > 0 {
		inst, _ := installedPkgs(ctx, env, names...)
		for i := range found {
			if r, ok := inst[found[i].Name]; ok {
				found[i].Version = r.Version
			}
			found[i].Protected = protectedPkg(found[i].Name)
		}
	}
	return map[string]any{"packages": found, "total": total}, nil
}

// aptSimulate: what installing or removing name would install, upgrade and remove.
func aptSimulate(ctx context.Context, env *Env, action, name string) (inst, remove []string, err error) {
	res, err := env.Exec.Run(ctx, "apt-get", "-s", "-o", "Debug::NoLocking=1", "--no-install-recommends", action, name)
	if err != nil {
		return nil, nil, err
	}
	for _, line := range strings.Split(res.Stdout, "\n") {
		f := strings.Fields(line)
		if len(f) < 2 {
			continue
		}
		switch f[0] {
		case "Inst":
			inst = append(inst, f[1])
		case "Remv":
			remove = append(remove, f[1])
		}
	}
	return inst, remove, nil
}

// checkRemoval refuses a removal that would take a protected or essential
// package along.
func checkRemoval(ctx context.Context, env *Env, remove []string) error {
	if len(remove) == 0 {
		return nil
	}
	names := make([]string, 0, len(remove))
	for _, r := range remove {
		n, _, _ := strings.Cut(r, ":")
		names = append(names, n)
	}
	rows, _ := installedPkgs(ctx, env, names...)
	for _, n := range names {
		if protectedPkg(n) || rows[n].essential {
			return protocol.Invalid("name: the server or the panel needs " + n + " (it would be removed too)")
		}
	}
	return nil
}

// pkgPreview: what an install or a removal would do, for the confirmation.
func pkgPreview(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Name   string `json:"name"`
		Action string `json:"action"` // install | remove
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validPkg(p.Name); err != nil {
		return nil, err
	}
	if p.Action != "install" && p.Action != "remove" {
		return nil, protocol.Invalid("action: install or remove")
	}
	inst, remove, err := aptSimulate(ctx, env, p.Action, p.Name)
	if err != nil {
		return nil, protocol.Invalid("name: " + p.Name + " cannot be " + p.Action + "ed (" + strings.TrimSpace(err.Error()) + ")")
	}
	out := map[string]any{"install": nonNil(inst), "remove": nonNil(remove), "allowed": true}
	if p.Action == "remove" {
		if err := checkRemoval(ctx, env, remove); err != nil {
			out["allowed"], out["reason"] = false, err.Error()
		}
	}
	return out, nil
}

// aptRun: apt-get with a patient lock (unattended-upgrades may be running),
// keeping changed config files; long, so no executor default timeout.
func aptRun(ctx context.Context, env *Env, args ...string) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, 30*time.Minute)
	defer cancel()
	full := append([]string{"-y", "-q", "-o", "DPkg::Lock::Timeout=300",
		"-o", "Dpkg::Options::=--force-confdef", "-o", "Dpkg::Options::=--force-confold"}, args...)
	res, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", full...)
	return tail(res.Stdout, 4000), err
}

// pkgInstall (async): one package and what it needs, without recommends.
func pkgInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Name string `json:"name"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validPkg(p.Name); err != nil {
		return nil, err
	}
	if _, _, err := aptSimulate(ctx, env, "install", p.Name); err != nil {
		return nil, protocol.Invalid("name: " + p.Name + " cannot be installed (" + strings.TrimSpace(err.Error()) + ")")
	}
	log, err := aptRun(ctx, env, "--no-install-recommends", "install", p.Name)
	if err != nil {
		return nil, err
	}
	return map[string]any{"name": p.Name, "installed": true, "log": log}, nil
}

// pkgRemove (async): removes a package (its configuration stays), never one
// the server or the panel needs, nor one that would take such along.
func pkgRemove(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Name string `json:"name"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validPkg(p.Name); err != nil {
		return nil, err
	}
	if protectedPkg(p.Name) {
		return nil, protocol.Invalid("name: the server or the panel needs " + p.Name)
	}
	_, remove, err := aptSimulate(ctx, env, "remove", p.Name)
	if err != nil {
		return nil, err
	}
	if err := checkRemoval(ctx, env, remove); err != nil {
		return nil, err
	}
	log, err := aptRun(ctx, env, "remove", p.Name)
	if err != nil {
		return nil, err
	}
	return map[string]any{"name": p.Name, "removed": remove, "log": log}, nil
}

// pkgHold keeps a package at its version (updates skip it) or releases it.
func pkgHold(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Name string `json:"name"`
		Hold bool   `json:"hold"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validPkg(p.Name); err != nil {
		return nil, err
	}
	if rows, _ := installedPkgs(ctx, env, p.Name); !env.Cfg.DryRun && rows[p.Name].Name == "" {
		return nil, protocol.Invalid("name: " + p.Name + " is not installed")
	}
	verb := "unhold"
	if p.Hold {
		verb = "hold"
	}
	if _, err := env.Exec.Run(ctx, "apt-mark", verb, p.Name); err != nil {
		return nil, err
	}
	return map[string]any{"name": p.Name, "held": p.Hold}, nil
}
