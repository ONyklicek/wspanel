package ops

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Network disks of a site: an SMB share (kernel CIFS), an FTP/FTPS or SFTP
// server (rclone over FUSE), mounted at <home>/mounts/<name>. Every file on
// it belongs to the site's user and group, so the site's PHP, workers and
// SFTP read and write it like their own files; other sites cannot enter the
// home. mounts/ is outside what a site backup packs (releases and shared),
// so a backup never pulls a NAS in.
//
// A mount is a unit of its own (wspanel-mount-<user>-<name>.service, enabled,
// so it comes back after a reboot). The password lives only in a root-only
// file the unit reads: never in the unit, the state or a command line.

type siteMount struct {
	Name     string `json:"name"`
	Type     string `json:"type"` // smb | ftp | sftp
	Host     string `json:"host"`
	Port     int    `json:"port,omitempty"`
	Share    string `json:"share,omitempty"`   // smb
	Path     string `json:"path,omitempty"`    // folder on the share / server
	User     string `json:"user"`              // login
	Domain   string `json:"domain,omitempty"`  // smb: workgroup / AD domain
	Version  string `json:"version,omitempty"` // smb protocol: 3.1.1 | 3.0 | 2.1 | 2.0 | 1.0
	TLS      string `json:"tls,omitempty"`     // ftp: "" | explicit | implicit
	Encrypt  bool   `json:"encrypt,omitempty"` // smb: encrypted transport (SMB 3 "seal")
	ReadOnly bool   `json:"read_only,omitempty"`
}

var (
	mountTypes    = []string{"smb", "ftp", "sftp"}
	smbVersions   = []string{"3.1.1", "3.0", "2.1", "2.0", "1.0"}
	mountNameRe   = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,31}$`)
	mountHostRe   = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9.\-]{0,252}$|^\[?[0-9A-Fa-f:.]{2,45}\]?$`)
	mountShareRe  = regexp.MustCompile(`^[A-Za-z0-9 ._$@+()-]{1,80}$`)
	mountPathSeg  = regexp.MustCompile(`^[A-Za-z0-9 ._@+()&,'!~-]{1,255}$`)
	mountLoginRe  = regexp.MustCompile(`^[^\x00-\x1f\x7f"\\,=]{1,128}$`)
	mountSecretRe = regexp.MustCompile(`^[^\x00-\x1f\x7f]{0,256}$`)
)

// Names a disk's unit and files by the site user and the disk with "." in
// between: neither part can hold a dot (users have theirs replaced, disk
// names allow none), so web_x_cz + shop_com-nas is never web_x_cz-shop_com
// + nas, as it was with "-".
func mountUnit(user, name string) string { return "wspanel-mount-" + user + "." + name + ".service" }

// legacyMountUnit: the name before (user-name), only ever matched together
// with the site named inside the unit.
func legacyMountUnit(user, name string) string {
	return "wspanel-mount-" + user + "-" + name + ".service"
}
func mountDir(l siteLayout) string { return filepath.Join(l.Home, "mounts") }
func mountPoint(l siteLayout, name string) string {
	return filepath.Join(mountDir(l), name)
}
func mountSecretFile(env *Env, user, name string) string {
	return filepath.Join(env.Cfg.StateDir, "mounts", user, name+".secret")
}

// mountStage: where the disk is really mounted, root's own; the site sees
// it bound into its home (BindSiteMount), never mounted through its paths.
func mountStage(cfg config.Config, domain, name string) string {
	return filepath.Join(cfg.StateDir, "mnt", domain, name)
}

func mountKnownHosts(env *Env, user, name string) string {
	return filepath.Join(env.Cfg.StateDir, "mounts", user, name+".known_hosts")
}

// agentCommand: this binary with its config, for a unit's ExecStartPost/ExecStop.
func agentCommand(env *Env, flag, arg string) string {
	bin, err := os.Executable()
	if err != nil || env.Cfg.DryRun {
		bin = "/usr/local/sbin/panel-agent"
	}
	cfg := env.ConfigPath
	if cfg == "" {
		cfg = "/etc/panel-agent/agent.json"
	}
	return bin + " -config " + unitWord(cfg) + " " + flag + " " + unitWord(arg)
}

func mountCacheDir(user, name string) string {
	return "/var/cache/wspanel-mounts/" + user + "/" + name
}

// validate checks a mount (password apart) and normalizes it.
func (m *siteMount) validate() error {
	if !mountNameRe.MatchString(m.Name) {
		return protocol.Invalid("name: lowercase letters, digits, - and _ (up to 32)")
	}
	if !slices.Contains(mountTypes, m.Type) {
		return protocol.Invalid("type: smb, ftp or sftp")
	}
	m.Host = strings.TrimSpace(m.Host)
	if !mountHostRe.MatchString(m.Host) {
		return protocol.Invalid("host: a host name or IP address")
	}
	if m.Port < 0 || m.Port > 65535 {
		return protocol.Invalid("port: 1-65535")
	}
	if !mountLoginRe.MatchString(m.User) {
		return protocol.Invalid("user: required, without control characters, quotes, commas or =")
	}
	var segs []string
	for _, s := range strings.Split(strings.Trim(strings.ReplaceAll(m.Path, "\\", "/"), "/"), "/") {
		if s == "" {
			continue
		}
		if s == "." || s == ".." || !mountPathSeg.MatchString(s) {
			return protocol.Invalid("path: folder names only (letters, digits, spaces, . _ - and the like)")
		}
		segs = append(segs, s)
	}
	m.Path = strings.Join(segs, "/")
	switch m.Type {
	case "smb":
		if !mountShareRe.MatchString(m.Share) {
			return protocol.Invalid("share: the share's name")
		}
		if m.Domain != "" && !mountLoginRe.MatchString(m.Domain) {
			return protocol.Invalid("domain: a workgroup or domain name")
		}
		if m.Version == "" {
			m.Version = "3.0"
		}
		if !slices.Contains(smbVersions, m.Version) {
			return protocol.Invalid("version: 3.1.1, 3.0, 2.1, 2.0 or 1.0")
		}
		if m.Encrypt && !strings.HasPrefix(m.Version, "3") {
			return protocol.Invalid("encrypt: needs SMB 3")
		}
		m.TLS = ""
	case "ftp":
		m.Encrypt = false
		if m.TLS != "" && m.TLS != "explicit" && m.TLS != "implicit" {
			return protocol.Invalid("tls: explicit, implicit or empty")
		}
		m.Share, m.Domain, m.Version = "", "", ""
	case "sftp":
		m.Share, m.Domain, m.Version, m.TLS, m.Encrypt = "", "", "", "", false
	}
	return nil
}

// unitWord quotes one argument of an ExecStart line: systemd expands % and $
// there, so both are doubled; quotes and backslashes never get this far.
func unitWord(s string) string {
	s = strings.ReplaceAll(s, "%", "%%")
	s = strings.ReplaceAll(s, "$", "$$")
	return `"` + s + `"`
}

// mountUnitContent is the unit that mounts m for the site's user and group:
// at the root-owned stage, then bound into the home.
func mountUnitContent(env *Env, l siteLayout, m siteMount, uid, gid int) string {
	stage := mountStage(env.Cfg, l.Domain, m.Name)
	secret := mountSecretFile(env, l.User, m.Name)
	ref := l.Domain + "/" + m.Name
	var b strings.Builder
	fmt.Fprintf(&b, "# Managed by WSPanel agent (site %s).\n[Unit]\nDescription=WSPanel: %s disk %s of %s\n", l.Domain, strings.ToUpper(m.Type), m.Name, l.Domain)
	// Retried for as long as it takes: a NAS still booting (or briefly away)
	// when the server starts must not leave the disk off until someone acts.
	b.WriteString("Wants=network-online.target\nAfter=network-online.target\nStartLimitIntervalSec=0\n\n[Service]\n")
	switch m.Type {
	case "smb":
		src := "//" + m.Host + "/" + m.Share
		if m.Path != "" {
			src += "/" + m.Path
		}
		opts := []string{"credentials=" + secret, "uid=" + strconv.Itoa(uid), "gid=" + strconv.Itoa(gid), "forceuid", "forcegid",
			"file_mode=0660", "dir_mode=0770", "vers=" + m.Version, "nosuid", "nodev", "noexec", "iocharset=utf8"}
		if m.Port > 0 {
			opts = append(opts, "port="+strconv.Itoa(m.Port))
		}
		if m.Encrypt {
			opts = append(opts, "seal") // the server must encrypt, or the mount fails
		}
		if m.ReadOnly {
			opts = append(opts, "ro")
		}
		b.WriteString("Type=oneshot\nRemainAfterExit=yes\nTimeoutStartSec=60\nRestart=on-failure\nRestartSec=20\n")
		fmt.Fprintf(&b, "ExecStart=/usr/bin/mount -t cifs %s %s -o %s\n", unitWord(src), unitWord(stage), strings.Join(opts, ","))
		fmt.Fprintf(&b, "ExecStartPost=%s\n", agentCommand(env, "-mount-bind", ref))
		fmt.Fprintf(&b, "ExecStop=-%s\n", agentCommand(env, "-mount-unbind", ref))
		fmt.Fprintf(&b, "ExecStop=/usr/bin/umount -l %s\n", unitWord(stage))
		fmt.Fprintf(&b, "ExecStopPost=-/usr/bin/umount -l %s\n", unitWord(stage))
	default:
		backend := m.Type
		args := []string{"/usr/bin/rclone", "mount", unitWord(":" + backend + ":" + m.Path), unitWord(stage), "--config=/dev/null",
			"--" + backend + "-host=" + m.Host, "--" + backend + "-user=" + unitWord(m.User)}
		if m.Port > 0 {
			args = append(args, "--"+backend+"-port="+strconv.Itoa(m.Port))
		}
		if backend == "ftp" {
			args = append(args, "--no-modtime") // FTP cannot set it; rclone would log every write
		}
		if backend == "sftp" {
			// Only the host key seen when the disk was added (no silent MITM).
			args = append(args, "--sftp-known-hosts-file="+unitWord(mountKnownHosts(env, l.User, m.Name)))
		}
		switch m.TLS {
		case "explicit":
			args = append(args, "--ftp-explicit-tls")
		case "implicit":
			args = append(args, "--ftp-tls")
		}
		args = append(args, "--uid="+strconv.Itoa(uid), "--gid="+strconv.Itoa(gid), "--umask=007", "--allow-other", "--default-permissions",
			"--vfs-cache-mode=writes", "--cache-dir="+mountCacheDir(l.User, m.Name), "--dir-cache-time=30s", "--log-level=NOTICE")
		if m.ReadOnly {
			args = append(args, "--read-only")
		}
		b.WriteString("Type=notify\nRestart=on-failure\nRestartSec=15\nTimeoutStartSec=60\n")
		fmt.Fprintf(&b, "EnvironmentFile=%s\n", secret)
		fmt.Fprintf(&b, "ExecStart=%s\n", strings.Join(args, " "))
		fmt.Fprintf(&b, "ExecStartPost=%s\n", agentCommand(env, "-mount-bind", ref))
		fmt.Fprintf(&b, "ExecStop=-%s\n", agentCommand(env, "-mount-unbind", ref))
		fmt.Fprintf(&b, "ExecStop=/usr/bin/fusermount3 -uz %s\n", unitWord(stage))
		fmt.Fprintf(&b, "ExecStopPost=-/usr/bin/fusermount3 -uz %s\n", unitWord(stage))
	}
	b.WriteString("\n[Install]\nWantedBy=multi-user.target\n")
	return b.String()
}

// mountSecret is the root-only file a mount unit reads its login from.
func mountSecret(ctx context.Context, env *Env, m siteMount, password string) (string, error) {
	if m.Type == "smb" {
		s := "username=" + m.User + "\npassword=" + password + "\n"
		if m.Domain != "" {
			s += "domain=" + m.Domain + "\n"
		}
		return s, nil
	}
	// rclone takes the password obscured (its own reversible encoding).
	obscured := "dry-run"
	if !env.Cfg.DryRun {
		res, err := env.Exec.RunIO(ctx, strings.NewReader(password), nil, "rclone", "obscure", "-")
		if err != nil {
			return "", fmt.Errorf("rclone obscure failed: %w", err)
		}
		obscured = strings.TrimSpace(res.Stdout)
	}
	return "RCLONE_" + strings.ToUpper(m.Type) + "_PASS=" + obscured + "\n", nil
}

// ensureMountTools installs what a kind of mount needs.
func ensureMountTools(ctx context.Context, env *Env, typ string) error {
	bin, pkgs := "/usr/sbin/mount.cifs", []string{"cifs-utils"}
	if typ != "smb" {
		bin, pkgs = "/usr/bin/rclone", []string{"rclone", "fuse3"}
	}
	if env.Cfg.DryRun || fsx.Exists(bin) {
		return nil
	}
	args := append([]string{"install", "-y", "-q", "--no-install-recommends"}, pkgs...)
	if _, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", args...); err != nil {
		return fmt.Errorf("installing %s failed: %w", strings.Join(pkgs, ", "), err)
	}
	return nil
}

func siteMountsList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p domainParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	out := []map[string]any{}
	for _, m := range st.Mounts {
		active := "unknown"
		if res, err := env.Exec.Run(ctx, "systemctl", "is-active", mountUnit(l.User, m.Name)); err == nil || res.Stdout != "" {
			active = strings.TrimSpace(res.Stdout)
		}
		out = append(out, map[string]any{"mount": m, "path": mountPoint(l, m.Name), "unit": mountUnit(l.User, m.Name), "active": active})
	}
	return map[string]any{"domain": p.Domain, "mounts": out}, nil
}

type siteMountParams struct {
	Domain   string    `json:"domain"`
	Action   string    `json:"action"` // add | remove | remount
	Mount    siteMount `json:"mount"`
	Password string    `json:"password,omitempty"`
}

// siteMountChange adds (connects, and keeps nothing when that fails), removes
// or reconnects a site's network disk.
func siteMountChange(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p siteMountParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	release, err := claimSite(p.Domain)
	if err != nil {
		return nil, err
	}
	defer release()
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	idx := slices.IndexFunc(st.Mounts, func(m siteMount) bool { return m.Name == p.Mount.Name })
	switch p.Action {
	case "add":
		if idx >= 0 {
			return nil, protocol.Conflict("disk " + p.Mount.Name + " already exists")
		}
		if len(st.Mounts) >= 10 {
			return nil, protocol.Invalid("a site has at most 10 disks")
		}
		m := p.Mount
		if err := m.validate(); err != nil {
			return nil, err
		}
		if !mountSecretRe.MatchString(p.Password) {
			return nil, protocol.Invalid("password: without control characters, up to 256")
		}
		if fsx.Exists(filepath.Join(env.Cfg.SystemdDir, mountUnit(l.User, m.Name))) {
			return nil, protocol.Conflict("disk " + m.Name + " already exists")
		}
		if err := ensureMountTools(ctx, env, m.Type); err != nil {
			return nil, err
		}
		if err := startMount(ctx, env, st, l, m, p.Password); err != nil {
			return nil, err
		}
		st.Mounts = append(st.Mounts, m)
		if err := saveSiteState(env, st); err != nil {
			_ = stopMount(ctx, env, l, m.Name)
			return nil, err
		}
		return map[string]any{"name": m.Name, "path": mountPoint(l, m.Name), "not_visible_to": unitsWithoutMount(ctx, env, st, l, mountPoint(l, m.Name)),
			"host_keys": hostKeyFingerprints(mountKnownHosts(env, l.User, m.Name))}, nil
	case "remove":
		if idx < 0 {
			return nil, protocol.NotFound("disk " + p.Mount.Name + " does not exist")
		}
		if err := stopMount(ctx, env, l, p.Mount.Name); err != nil {
			return nil, err
		}
		st.Mounts = slices.Delete(st.Mounts, idx, idx+1)
		return map[string]any{"removed": p.Mount.Name}, saveSiteState(env, st)
	case "remount":
		if idx < 0 {
			return nil, protocol.NotFound("disk " + p.Mount.Name + " does not exist")
		}
		unit := mountUnit(l.User, p.Mount.Name)
		// The unit as this agent writes it (a fix in a newer agent reaches
		// disks added before).
		owner, err := mountOwner(env, st, l)
		if err != nil {
			return nil, err
		}
		if err := env.FS.WriteFile(filepath.Join(env.Cfg.SystemdDir, unit), []byte(mountUnitContent(env, l, st.Mounts[idx], owner.UID, owner.GID)), 0o644, fsx.Root); err != nil {
			return nil, err
		}
		_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
		_, _ = env.Exec.Run(ctx, "systemctl", "reset-failed", unit)
		if _, err := env.Exec.Run(ctx, "systemctl", "restart", unit); err != nil {
			return nil, fmt.Errorf("the disk did not connect: %s", mountFailure(ctx, env, unit))
		}
		return map[string]any{"name": p.Mount.Name}, nil
	}
	return nil, protocol.Invalid("action: add, remove or remount")
}

// startMount writes the secret and the unit, and mounts; on failure nothing
// of it is left behind and the error says what the mount said.
func startMount(ctx context.Context, env *Env, st siteState, l siteLayout, m siteMount, password string) (err error) {
	owner, err := mountOwner(env, st, l)
	if err != nil {
		return err
	}
	unit := mountUnit(l.User, m.Name)
	unitFile := filepath.Join(env.Cfg.SystemdDir, unit)
	secretFile := mountSecretFile(env, l.User, m.Name)
	where := mountPoint(l, m.Name)
	var rollback undo
	defer func() {
		if err != nil {
			rollback.run()
		}
	}()
	secret, err := mountSecret(ctx, env, m, password)
	if err != nil {
		return err
	}
	if err := env.FS.MkdirAll(filepath.Dir(secretFile), 0o700, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(secretFile, []byte(secret), 0o600, fsx.Root); err != nil {
		return err
	}
	rollback.add(func() { _ = os.Remove(secretFile) })
	// mounts/ is the site's; the mount point itself root's and read-only, so
	// a process that does not see the disk (down, or a namespace from before
	// it was mounted) gets "permission denied" instead of quietly writing to
	// the local folder underneath.
	if err := env.FS.MkdirAll(mountDir(l), 0o750, owner); err != nil {
		return err
	}
	if err := env.FS.MkdirAll(where, 0o555, fsx.Root); err != nil {
		return err
	}
	stage := mountStage(env.Cfg, l.Domain, m.Name)
	if err := env.FS.MkdirAll(stage, 0o700, fsx.Root); err != nil {
		return err
	}
	rollback.add(func() { _ = os.Remove(stage) })
	if m.Type == "sftp" {
		if err := trustHostKey(ctx, env, m, mountKnownHosts(env, l.User, m.Name)); err != nil {
			return err
		}
		rollback.add(func() { _ = os.Remove(mountKnownHosts(env, l.User, m.Name)) })
	}
	rollback.add(func() { _ = os.Remove(where) })
	if m.Type != "smb" && !env.Cfg.DryRun {
		if err := os.MkdirAll(mountCacheDir(l.User, m.Name), 0o700); err != nil {
			return err
		}
		rollback.add(func() { _ = os.RemoveAll(mountCacheDir(l.User, m.Name)) })
	}
	if err := env.FS.WriteFile(unitFile, []byte(mountUnitContent(env, l, m, owner.UID, owner.GID)), 0o644, fsx.Root); err != nil {
		return err
	}
	rollback.add(func() {
		_, _ = env.Exec.Run(ctx, "systemctl", "disable", "--now", unit)
		_ = os.Remove(unitFile)
		_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
		_, _ = env.Exec.Run(ctx, "systemctl", "reset-failed", unit)
	})
	if _, err := env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "enable", "--now", unit); err != nil {
		return fmt.Errorf("the disk did not connect: %s", mountFailure(ctx, env, unit))
	}
	return nil
}

// unitsWithoutMount: the site's running processes that do not see the disk
// (their mount namespace is private and older than the mount; systemd
// normally passes new mounts on, a container may not). They see it after a
// restart; until then their writes fail rather than land beside the disk.
func unitsWithoutMount(ctx context.Context, env *Env, st siteState, l siteLayout, where string) []string {
	if env.Cfg.DryRun {
		return nil
	}
	units := siteUnits(env, l)
	if l.Mode == "classic" && l.PHPVersion != "" {
		units = append(units, strings.ReplaceAll(env.Cfg.FPMUnit, "{version}", l.PHPVersion))
	}
	var blind []string
	for _, u := range units {
		res, err := env.Exec.Run(ctx, "systemctl", "show", "-p", "MainPID", "--value", u)
		pid, _ := strconv.Atoi(strings.TrimSpace(res.Stdout))
		if err != nil || pid <= 0 {
			continue
		}
		info, err := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/mountinfo")
		if err != nil {
			continue
		}
		seen := false
		for _, line := range strings.Split(string(info), "\n") {
			if f := strings.Fields(line); len(f) > 4 && f[4] == where {
				seen = true
				break
			}
		}
		if !seen {
			blind = append(blind, u)
		}
	}
	return blind
}

// stopMount unmounts and forgets a disk; the files on it are untouched.
func stopMount(ctx context.Context, env *Env, l siteLayout, name string) error {
	// A disk added before the unit names changed: only when the unit says
	// it is this site's (the old name alone could be another site's).
	legacy := filepath.Join(env.Cfg.SystemdDir, legacyMountUnit(l.User, name))
	if b, err := os.ReadFile(legacy); err == nil && strings.Contains(string(b), "(site "+l.Domain+")") {
		_, _ = env.Exec.Run(ctx, "systemctl", "disable", "--now", legacyMountUnit(l.User, name))
		_ = os.Remove(legacy)
		_ = os.Remove(filepath.Join(env.Cfg.StateDir, "mounts", l.User+"-"+name+".secret"))
	}
	unit := mountUnit(l.User, name)
	unitFile := filepath.Join(env.Cfg.SystemdDir, unit)
	if fsx.Exists(unitFile) {
		// The unit's ExecStop does this too; here its failure is not ignored.
		if !env.Cfg.DryRun {
			if err := UnbindSiteMount(env.Cfg, l.Domain, name); err != nil {
				return err
			}
		}
		if _, err := env.Exec.Run(ctx, "systemctl", "disable", "--now", unit); err != nil {
			return err
		}
		if err := os.Remove(unitFile); err != nil {
			return err
		}
		_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
		_, _ = env.Exec.Run(ctx, "systemctl", "reset-failed", unit)
	}
	_ = os.Remove(mountSecretFile(env, l.User, name))
	_ = os.Remove(mountKnownHosts(env, l.User, name))
	_ = os.RemoveAll(mountCacheDir(l.User, name))
	_ = os.Remove(filepath.Dir(mountCacheDir(l.User, name)))
	_ = os.Remove(filepath.Dir(mountSecretFile(env, l.User, name)))
	_ = os.Remove(mountStage(env.Cfg, l.Domain, name))
	_ = os.Remove(filepath.Dir(mountStage(env.Cfg, l.Domain, name))) // the site's, once its last disk is gone
	// Only an empty folder, never what was on the disk; a lazy unmount
	// (FUSE) may take a moment to let go of it.
	for i := 0; i < 15; i++ {
		if err := os.Remove(mountPoint(l, name)); err == nil || os.IsNotExist(err) || env.Cfg.DryRun {
			break
		}
		time.Sleep(200 * time.Millisecond)
	}
	return nil
}

// mountOwner: the site's user and its (client's) group.
func mountOwner(env *Env, st siteState, l siteLayout) (fsx.Owner, error) {
	owner, err := lookupOwner(env, l.User)
	if err != nil {
		return owner, err
	}
	if st.Group != "" {
		g, err := user.LookupGroup(st.Group)
		if err != nil {
			return owner, err
		}
		owner.GID, _ = strconv.Atoi(g.Gid)
	}
	return owner, nil
}

// mountFailure: the last lines the unit logged (what mount or rclone said).
func mountFailure(ctx context.Context, env *Env, unit string) string {
	// Only this attempt (an earlier one of the same name may have failed differently).
	args := []string{"-u", unit, "-n", "20", "-o", "cat", "--no-pager", "--since", time.Now().Add(-2 * time.Minute).Format("2006-01-02 15:04:05")}
	if res, err := env.Exec.Run(ctx, "systemctl", "show", "-p", "InvocationID", "--value", unit); err == nil && strings.TrimSpace(res.Stdout) != "" {
		args = []string{"_SYSTEMD_INVOCATION_ID=" + strings.TrimSpace(res.Stdout), "-n", "20", "-o", "cat", "--no-pager"}
	}
	res, _ := env.Exec.Run(ctx, "journalctl", args...)
	// What mount / rclone said, without systemd's own lines about the unit.
	var said []string
	for _, line := range strings.Split(strings.TrimSpace(res.Stdout), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, unit) || strings.HasPrefix(line, "Starting ") || strings.HasPrefix(line, "Failed to start ") ||
			strings.HasPrefix(line, "Stopped ") || strings.HasPrefix(line, "Refer to the mount.cifs") {
			continue
		}
		said = append(said, line)
	}
	if len(said) == 0 {
		return "no details (journal of " + unit + ")"
	}
	return lastLines(strings.Join(said, "\n"), 3)
}

func lastLines(s string, n int) string {
	lines := strings.Split(s, "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, " / ")
}

// removeSiteMounts unmounts every disk of a site (deleting it): the ones its
// state lists, never units found by a name prefix (another site's user name
// can start with this one's).
func removeSiteMounts(ctx context.Context, env *Env, l siteLayout) error {
	b, err := os.ReadFile(siteStatePath(env.Cfg, l.Domain))
	if err != nil {
		return nil
	}
	var st siteState
	if err := json.Unmarshal(b, &st); err != nil {
		return err
	}
	for _, m := range st.Mounts {
		if err := stopMount(ctx, env, l, m.Name); err != nil {
			return err
		}
	}
	_ = os.Remove(filepath.Join(env.Cfg.StateDir, "mnt", l.Domain))
	return nil
}

// trustHostKey records the SFTP server's host key when the disk is added
// (trust on first use); rclone then refuses any other key, so a server
// swapped in between never sees the password. The fingerprints are shown.
func trustHostKey(ctx context.Context, env *Env, m siteMount, file string) error {
	port := m.Port
	if port == 0 {
		port = 22
	}
	keys := "dry-run ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl\n"
	if !env.Cfg.DryRun {
		res, err := env.Exec.Run(ctx, "ssh-keyscan", "-T", "10", "-p", strconv.Itoa(port), "--", m.Host)
		if err != nil && strings.TrimSpace(res.Stdout) == "" {
			return fmt.Errorf("the disk did not connect: ssh-keyscan: no host key from %s:%d", m.Host, port)
		}
		keys = res.Stdout
	}
	var lines []string
	for _, line := range strings.Split(keys, "\n") {
		f := strings.Fields(line)
		if len(f) == 3 && !strings.HasPrefix(line, "#") && strings.HasPrefix(f[1], "ssh-") || len(f) == 3 && strings.HasPrefix(f[1], "ecdsa-") {
			lines = append(lines, strings.Join(f, " "))
		}
	}
	if len(lines) == 0 {
		return fmt.Errorf("the disk did not connect: no host key from %s:%d", m.Host, port)
	}
	if err := env.FS.MkdirAll(filepath.Dir(file), 0o700, fsx.Root); err != nil {
		return err
	}
	return env.FS.WriteFile(file, []byte(strings.Join(lines, "\n")+"\n"), 0o600, fsx.Root)
}

// hostKeyFingerprints: SHA256 fingerprints of the recorded host keys.
func hostKeyFingerprints(file string) []string {
	b, err := os.ReadFile(file)
	if err != nil {
		return nil
	}
	var out []string
	for _, line := range strings.Split(strings.TrimSpace(string(b)), "\n") {
		f := strings.Fields(line)
		if len(f) != 3 {
			continue
		}
		blob, err := base64.StdEncoding.DecodeString(f[2])
		if err != nil {
			continue
		}
		sum := sha256.Sum256(blob)
		out = append(out, f[1]+" SHA256:"+base64.RawStdEncoding.EncodeToString(sum[:]))
	}
	return out
}

// stopSiteMounts / startSiteMounts bracket a change of the site's group: the
// disks are unmounted while files are re-owned (chown -R must not walk a
// NAS) and mounted again under the new group.
func stopSiteMounts(ctx context.Context, env *Env, st siteState, l siteLayout) {
	for _, m := range st.Mounts {
		_, _ = env.Exec.Run(ctx, "systemctl", "stop", mountUnit(l.User, m.Name))
	}
}

func startSiteMounts(ctx context.Context, env *Env, st siteState, l siteLayout) error {
	if len(st.Mounts) == 0 {
		return nil
	}
	owner, err := mountOwner(env, st, l)
	if err != nil {
		return err
	}
	for _, m := range st.Mounts {
		f := filepath.Join(env.Cfg.SystemdDir, mountUnit(l.User, m.Name))
		if err := env.FS.WriteFile(f, []byte(mountUnitContent(env, l, m, owner.UID, owner.GID)), 0o644, fsx.Root); err != nil {
			return err
		}
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return err
	}
	for _, m := range st.Mounts {
		_, _ = env.Exec.Run(ctx, "systemctl", "start", mountUnit(l.User, m.Name))
	}
	return nil
}
