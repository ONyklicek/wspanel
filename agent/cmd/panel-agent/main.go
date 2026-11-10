// panel-agent is the only privileged component of WSPanel. It listens on a
// Unix socket and executes a fixed whitelist of operations for the panel.
package main

import (
	"context"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"os"
	"os/signal"
	"runtime"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/audit"
	"wspanel/agent/internal/config"
	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/ops"
	"wspanel/agent/internal/peercred"
	"wspanel/agent/internal/server"
)

func main() {
	cfgPath := flag.String("config", "/etc/panel-agent/agent.json", "config file")
	showVersion := flag.Bool("version", false, "print version and exit")
	probeBind := flag.String("probe-bind", "", "internal: try to bind addr, print bound/denied (socket-bind enforcement probe)")
	backup := flag.String("backup", "", "run a scheduled backup of engine/name and exit (used by backup timers)")
	keep := flag.Int("keep", 7, "with -backup: number of backups to keep")
	siteBackup := flag.String("site-backup", "", "run a scheduled backup of the site <domain> and exit (used by site backup timers)")
	fileopHome := flag.String("fileop", "", "internal: serve one file-manager request inside this site home (run as the site user)")
	mountBind := flag.String("mount-bind", "", "internal: bind the staged network disk <domain>/<name> into the site (its unit's ExecStartPost)")
	mountUnbind := flag.String("mount-unbind", "", "internal: remove the bind of the network disk <domain>/<name> (its unit's ExecStop)")
	backupManifest := flag.String("server-backup-manifest", "", "print what a server backup holds (recovery key in WSPANEL_RECOVERY_KEY) and exit")
	restoreState := flag.String("server-restore", "", "restore a server backup's state onto this server (wspanel-restore; the agent stopped)")
	restoreData := flag.String("server-restore-data", "", "after -server-restore: restore the sites, databases and mail of that server backup")
	pythonInstall := flag.String("python-install", "", "install these CPython versions (comma-separated, e.g. 3.13) with uv and exit (install.sh --python)")
	backupsDir := flag.String("backups", "", "with -server-restore-data: a copy of the old server's /var/backups/wspanel instead of the off-site storage")
	flag.Parse()
	if *mountBind != "" || *mountUnbind != "" {
		cfg, err := config.Load(*cfgPath)
		if err == nil {
			ref := *mountBind + *mountUnbind
			domain, name, _ := strings.Cut(ref, "/")
			if *mountBind != "" {
				err = ops.BindSiteMount(cfg, domain, name)
			} else {
				err = ops.UnbindSiteMount(cfg, domain, name)
			}
		}
		if err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		return
	}
	if *backupManifest != "" || *restoreState != "" || *restoreData != "" {
		if err := runServerRestore(*cfgPath, *backupManifest, *restoreState, *restoreData, *backupsDir); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		return
	}
	if *pythonInstall != "" {
		if err := runPythonInstall(*cfgPath, *pythonInstall); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		return
	}
	if *showVersion {
		fmt.Println(ops.Version)
		return
	}
	if *fileopHome != "" {
		// Started by the agent through setpriv as the site user; never as root.
		if os.Geteuid() == 0 {
			fmt.Fprintln(os.Stderr, "fileop must not run as root")
			os.Exit(2)
		}
		if err := ops.RunFileOp(*fileopHome); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		return
	}
	if *probeBind != "" {
		// Only EACCES/EPERM count as "denied"; anything else (port in use, ...)
		// is reported as an error so the caller fails closed.
		ln, err := net.Listen("tcp", *probeBind)
		switch {
		case err == nil:
			ln.Close()
			fmt.Println("bound")
		case errors.Is(err, syscall.EACCES) || errors.Is(err, syscall.EPERM):
			fmt.Println("denied")
		default:
			fmt.Println("error: " + err.Error())
		}
		return
	}

	log := slog.New(slog.NewTextHandler(os.Stderr, nil))
	if *siteBackup != "" {
		if err := runSiteBackupMode(*cfgPath, *siteBackup, log); err != nil {
			log.Error("site backup failed", "err", err)
			os.Exit(1)
		}
		return
	}
	if *backup != "" {
		if err := runBackupMode(*cfgPath, *backup, *keep, log); err != nil {
			log.Error("backup failed", "err", err)
			os.Exit(1)
		}
		return
	}
	if err := run(*cfgPath, log); err != nil {
		log.Error("fatal", "err", err)
		os.Exit(1)
	}
}

func run(cfgPath string, log *slog.Logger) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return err
	}
	if !cfg.DryRun {
		if runtime.GOOS != "linux" || !peercred.Supported {
			return fmt.Errorf("real mode requires Linux; set dry_run for development")
		}
		if os.Geteuid() != 0 {
			return fmt.Errorf("must run as root")
		}
	}

	var exec executor.Executor
	if cfg.DryRun {
		exec = &executor.DryRun{Log: func(s string) { log.Info(s) }, Responder: dryResponder}
	} else {
		exec = executor.NewReal(binaries(cfg), []string{
			"PATH=/usr/sbin:/usr/bin:/sbin:/bin",
			"LANG=C.UTF-8",
			"DEBIAN_FRONTEND=noninteractive",
			// Caddy validation must not write into /root.
			"HOME=" + cfg.StateDir,
			"XDG_DATA_HOME=" + cfg.StateDir + "/xdg/data",
			"XDG_CONFIG_HOME=" + cfg.StateDir + "/xdg/config",
		})
	}

	a, err := audit.Open(cfg.AuditLog)
	if err != nil {
		return fmt.Errorf("audit log: %w", err)
	}
	defer a.Close()

	env := &ops.Env{Cfg: cfg, Exec: exec, FS: &fsx.FS{DryRun: cfg.DryRun}, ConfigPath: cfgPath}
	srv := server.New(env, a, log)
	ln, err := srv.Listen()
	if err != nil {
		return err
	}
	log.Info("panel-agent listening", "socket", cfg.Socket, "version", ops.Version, "dry_run", cfg.DryRun)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	go ops.MigrateLegacyWorkers(ctx, env, func(format string, args ...any) { log.Info(fmt.Sprintf(format, args...)) })
	go ops.RenderMailOnStart(ctx, env, func(format string, args ...any) { log.Info(fmt.Sprintf(format, args...)) })
	go ops.RenderEdgesOnStart(ctx, env, func(format string, args ...any) { log.Info(fmt.Sprintf(format, args...)) })
	go ops.RenderGameUnitsOnStart(ctx, env, func(format string, args ...any) { log.Info(fmt.Sprintf(format, args...)) })
	return srv.Serve(ctx, ln)
}

// runBackupMode runs one scheduled backup (from a systemd timer) with the same
// executor allowlist as the daemon, and records it in the audit log.
func runBackupMode(cfgPath, spec string, keep int, log *slog.Logger) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return err
	}
	a, err := audit.Open(cfg.AuditLog)
	if err != nil {
		return err
	}
	defer a.Close()
	env := &ops.Env{Cfg: cfg, Exec: executor.NewReal(binaries(cfg), []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8"}), FS: &fsx.FS{DryRun: cfg.DryRun}}
	start := time.Now()
	out, err := ops.RunScheduledBackup(context.Background(), env, spec, keep)
	params, _ := json.Marshal(map[string]any{"backup": spec, "keep": keep})
	entry := audit.Entry{Time: start.UTC(), Op: "db.backup.scheduled", Params: params, PeerUID: 0, OK: err == nil, DurationMS: time.Since(start).Milliseconds()}
	if err != nil {
		entry.Error = err.Error()
	}
	_ = a.Write(entry)
	log.Info("scheduled backup", "spec", spec, "result", out)
	return err
}

// runSiteBackupMode runs one scheduled whole-site backup (from its timer).
func runSiteBackupMode(cfgPath, domain string, log *slog.Logger) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return err
	}
	a, err := audit.Open(cfg.AuditLog)
	if err != nil {
		return err
	}
	defer a.Close()
	env := &ops.Env{Cfg: cfg, Exec: executor.NewReal(binaries(cfg), []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8"}), FS: &fsx.FS{DryRun: cfg.DryRun}}
	start := time.Now()
	out, err := ops.RunScheduledSiteBackup(context.Background(), env, domain)
	params, _ := json.Marshal(map[string]any{"domain": domain})
	entry := audit.Entry{Time: start.UTC(), Op: "site.backup.scheduled", Params: params, PeerUID: 0, OK: err == nil, DurationMS: time.Since(start).Milliseconds()}
	if err != nil {
		entry.Error = err.Error()
	}
	_ = a.Write(entry)
	log.Info("scheduled site backup", "domain", domain, "result", out)
	return err
}

// binaries is the executor allowlist: logical name -> absolute path.
func binaries(cfg config.Config) map[string]string {
	b := map[string]string{
		"systemctl":          "/usr/bin/systemctl",
		"uv":                 cfg.UVBin, // python.install (as root: uv itself, the interpreters into /opt/uv/python)
		"useradd":            "/usr/sbin/useradd",
		"userdel":            "/usr/sbin/userdel",
		"usermod":            "/usr/sbin/usermod",
		"gpasswd":            "/usr/bin/gpasswd",
		"mariadb-install-db": "/usr/bin/mariadb-install-db",
		"mongosh":            "/usr/bin/mongosh",
		"mongodump":          "/usr/bin/mongodump",
		"mongorestore":       "/usr/bin/mongorestore",
		"mongodb-setup":      "/usr/lib/wspanel/mongodb.sh",
		"pg_createcluster":   "/usr/bin/pg_createcluster",
		"pg_dropcluster":     "/usr/bin/pg_dropcluster",
		"groupadd":           "/usr/sbin/groupadd",
		"groupdel":           "/usr/sbin/groupdel",
		"chown":              "/usr/bin/chown",   // a site moving between clients (-h, never follows links)
		"setfacl":            "/usr/bin/setfacl", // root: only the owning-group entry of a site home (no -R)
		"findmnt":            "/usr/bin/findmnt",
		"quotaon":            "/usr/sbin/quotaon", // -p only: is the group quota on
		"setquota":           "/usr/sbin/setquota",
		"chpasswd":           "/usr/sbin/chpasswd", // password on stdin only
		"postconf":           "/usr/sbin/postconf",
		"postmap":            "/usr/sbin/postmap",
		"postqueue":          "/usr/sbin/postqueue",
		"postsuper":          "/usr/sbin/postsuper",
		"postfix":            "/usr/sbin/postfix", // reload only
		"doveadm":            "/usr/bin/doveadm",  // quota get
		"mail-hosting":       "/usr/lib/wspanel/mail-hosting.sh",
		"xhprof":             "/usr/lib/wspanel/xhprof.sh",
		"dns-setup":          "/usr/lib/wspanel/dns.sh",    // install|remove
		"clamav":             "/usr/lib/wspanel/clamav.sh", // enable|disable
		"nft":                "/usr/sbin/nft",              // -f <the agent's own ruleset> only
		"saslpasswd2":        "/usr/sbin/saslpasswd2",      // password on stdin (-p)
		"setpriv":            "/usr/bin/setpriv",           // runs fixed binaries as a site user
		"systemd-analyze":    "/usr/bin/systemd-analyze",
		"systemd-tmpfiles":   "/usr/bin/systemd-tmpfiles",
		"systemd-run":        "/usr/bin/systemd-run",
		"mariadb":            "/usr/bin/mariadb",
		"mariadb-dump":       "/usr/bin/mariadb-dump",
		"sqlite3":            "/usr/bin/sqlite3", // consistent copies of the panel's and PowerDNS's databases
		"apt-get":            "/usr/bin/apt-get",
		"unattended-upgrade": "/usr/bin/unattended-upgrade",
		"fail2ban-client":    "/usr/bin/fail2ban-client",
		"smbclient":          "/usr/bin/smbclient",
		"gpg":                "/usr/bin/gpg",         // dearmor WSPanel's key only (the game catalog)
		"gpgv":               "/usr/bin/gpgv",        // the game catalog's signature
		"rclone":             "/usr/bin/rclone",      // obscure only (a mount's password, on stdin)
		"ssh-keyscan":        "/usr/bin/ssh-keyscan", // an SFTP disk's host key, when it is added
		"journalctl":         "/usr/bin/journalctl",
		"dpkg-query":         "/usr/bin/dpkg-query",
		"apt-cache":          "/usr/bin/apt-cache",
		"apt-mark":           "/usr/bin/apt-mark",    // hold/unhold, showmanual/showhold
		"hostnamectl":        "/usr/bin/hostnamectl", // set-hostname
		"timedatectl":        "/usr/bin/timedatectl", // show, list-timezones, set-timezone, set-ntp
		"fallocate":          "/usr/bin/fallocate",   // the swap file only
		"dd":                 "/usr/bin/dd",          // the swap file where fallocate cannot
		"mkswap":             "/usr/sbin/mkswap",
		"swapon":             "/usr/sbin/swapon",
		"swapoff":            "/usr/sbin/swapoff",
		"du":                 "/usr/bin/du",        // disk usage by directory (-x, never follows links)
		"smartctl":           "/usr/sbin/smartctl", // disk health, read only (-i -H -A)
		"ip":                 "/usr/sbin/ip",       // -j addr/route show, read only
		"ufw":                cfg.UFWBinary,
		"frankenphp":         cfg.FrankenPHPBin,
	}
	// Every version the panel can install, so a version added at runtime
	// (php.install) is usable without restarting the agent.
	for _, v := range append(append([]string{}, cfg.PHPVersions...), ops.InstallablePHP...) {
		b["php-fpm"+v] = config.ForVersion(cfg.FPMBinary, v)
	}
	return b
}

// dryResponder fakes plausible output so the panel UI works on a dev machine.
func dryResponder(c executor.Call) (executor.Result, error) {
	switch c.Name {
	case "systemctl":
		if len(c.Args) > 0 && c.Args[0] == "show" {
			out := ""
			for _, a := range c.Args {
				if a == "--" || a[0] == '-' || a == "show" {
					continue
				}
				out += "Id=" + a + "\nLoadState=loaded\nActiveState=active\nSubState=running\nUnitFileState=enabled\n\n"
			}
			return executor.Result{Stdout: out}, nil
		}
	case "ufw":
		if len(c.Args) > 0 && c.Args[0] == "status" {
			return executor.Result{Stdout: "Status: active\n\nTo                         Action      From\n--                         ------      ----\n22/tcp                     ALLOW       Anywhere\n80/tcp                     ALLOW       Anywhere\n443                        ALLOW       Anywhere\n22/tcp (v6)                ALLOW       Anywhere (v6)\n"}, nil
		}
	case "frankenphp":
		if len(c.Args) > 0 && c.Args[0] == "version" {
			return executor.Result{Stdout: "FrankenPHP v1.13.5 PHP 8.5.12 Caddy v2.11.9 (dry-run)"}, nil
		}
	}
	return executor.Result{}, nil
}

// runServerRestore: the steps of wspanel-restore. The recovery key comes in
// WSPANEL_RECOVERY_KEY (64 hex digits), never on the command line.
// runPythonInstall: install.sh's --python, through the same op the panel uses.
func runPythonInstall(cfgPath, versions string) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return err
	}
	env := &ops.Env{Cfg: cfg, Exec: executor.NewReal(binaries(cfg), []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8"}), FS: &fsx.FS{DryRun: cfg.DryRun}}
	for _, v := range strings.Split(versions, ",") {
		raw, _ := json.Marshal(map[string]string{"version": strings.TrimSpace(v)})
		out, err := ops.Registry()["python.install"].Handle(context.Background(), env, raw)
		if err != nil {
			return fmt.Errorf("Python %s: %w", v, err)
		}
		if m, ok := out.(map[string]any); ok && m["changed"] == true {
			fmt.Println("installed Python " + v)
		} else {
			fmt.Println("Python " + v + " is installed already")
		}
	}
	return nil
}

func runServerRestore(cfgPath, manifestOf, statePath, dataPath, backupsDir string) error {
	key, err := hex.DecodeString(strings.TrimSpace(os.Getenv("WSPANEL_RECOVERY_KEY")))
	if err != nil || len(key) != 32 {
		return errors.New("WSPANEL_RECOVERY_KEY must be the recovery key (64 hex digits, Zálohy mimo server → Klíč pro obnovu)")
	}
	if manifestOf != "" {
		man, err := ops.ReadServerBackupManifest(manifestOf, key)
		if err != nil {
			return err
		}
		if os.Getenv("WSPANEL_MANIFEST_FORMAT") == "shell" {
			// For the installer: one value per line, no JSON parser needed.
			fmt.Printf("VERSION=%s\nCREATED=%s\nHOST=%s\nSITES=%d\nMAIL=%d\nDATABASES=%d\n", man.Version, man.Created.Format(time.RFC3339), man.Host,
				len(man.Sites), len(man.MailDomains), len(man.Databases))
			for _, f := range man.Flags {
				fmt.Println("FLAG=" + f)
			}
			return nil
		}
		return json.NewEncoder(os.Stdout).Encode(man)
	}
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return err
	}
	env := &ops.Env{Cfg: cfg, Exec: executor.NewReal(binaries(cfg), []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8"}), FS: &fsx.FS{DryRun: cfg.DryRun}}
	ctx := context.Background()
	report := func(rep ops.RestoreReport) error {
		for _, r := range rep.Restored {
			fmt.Println("restored: " + r)
		}
		for _, p := range rep.Problems {
			fmt.Println("PROBLEM:  " + p)
		}
		return nil
	}
	if statePath != "" {
		_, rep, err := ops.RestoreServerState(ctx, env, statePath, key)
		_ = report(rep)
		return err
	}
	man, err := ops.ReadServerBackupManifest(dataPath, key)
	if err != nil {
		return err
	}
	src, err := ops.OpenBackupSource(ctx, env, backupsDir)
	if err != nil {
		return err
	}
	defer src.Close()
	rep := ops.RestoreServerData(ctx, env, man, src, func(f string, a ...any) { fmt.Printf("==> "+f+"\n", a...) })
	return report(rep)
}
