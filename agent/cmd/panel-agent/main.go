// panel-agent is the only privileged component of WSPanel. It listens on a
// Unix socket and executes a fixed whitelist of operations for the panel.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"os"
	"os/signal"
	"runtime"
	"syscall"

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
	flag.Parse()
	if *showVersion {
		fmt.Println(ops.Version)
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

	env := &ops.Env{Cfg: cfg, Exec: exec, FS: &fsx.FS{DryRun: cfg.DryRun}}
	srv := server.New(env, a, log)
	ln, err := srv.Listen()
	if err != nil {
		return err
	}
	log.Info("panel-agent listening", "socket", cfg.Socket, "version", ops.Version, "dry_run", cfg.DryRun)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	return srv.Serve(ctx, ln)
}

// binaries is the executor allowlist: logical name -> absolute path.
func binaries(cfg config.Config) map[string]string {
	b := map[string]string{
		"systemctl":        "/usr/bin/systemctl",
		"useradd":          "/usr/sbin/useradd",
		"userdel":          "/usr/sbin/userdel",
		"usermod":          "/usr/sbin/usermod",
		"setpriv":          "/usr/bin/setpriv", // runs fixed binaries as a site user
		"systemd-analyze":  "/usr/bin/systemd-analyze",
		"systemd-tmpfiles": "/usr/bin/systemd-tmpfiles",
		"systemd-run":      "/usr/bin/systemd-run",
		"ufw":              cfg.UFWBinary,
		"frankenphp":       cfg.FrankenPHPBin,
	}
	for _, v := range cfg.PHPVersions {
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
