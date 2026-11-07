// panel-agent is the only privileged component of WSPanel. It listens on a
// Unix socket and executes a fixed whitelist of operations for the panel.
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
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
	flag.Parse()
	if *showVersion {
		fmt.Println(ops.Version)
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
		"systemctl":  "/usr/bin/systemctl",
		"useradd":    "/usr/sbin/useradd",
		"userdel":    "/usr/sbin/userdel",
		"setfacl":    "/usr/bin/setfacl",
		"frankenphp": cfg.FrankenPHPBin,
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
	case "frankenphp":
		if len(c.Args) > 0 && c.Args[0] == "version" {
			return executor.Result{Stdout: "FrankenPHP dry-run"}, nil
		}
	}
	return executor.Result{}, nil
}
