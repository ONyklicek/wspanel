package ops

import (
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

// Zero-downtime switches of a site's own process after a deploy.

// gracefulWorkerRestart asks a worker-mode site's FrankenPHP to restart its
// PHP workers: the server keeps accepting connections and the new workers
// load the code the switched `current` points at. Returns false when it could
// not be done that way (the caller restarts the unit instead).
func gracefulWorkerRestart(ctx context.Context, env *Env, l siteLayout) (bool, error) {
	if env.Cfg.DryRun || l.Mode != "worker" {
		return false, nil
	}
	if cfg, err := os.ReadFile(l.InstanceConfig); err != nil || !strings.Contains(string(cfg), "admin unix/") {
		return false, nil // an instance from before the admin socket: one plain restart brings it
	}
	sock := filepath.Join(l.RunDir, "admin.sock")
	// The directory is the site user's: only dial a real socket of that user,
	// not a link it may have put there.
	fi, err := os.Lstat(sock)
	if err != nil || fi.Mode()&os.ModeSocket == 0 {
		return false, nil
	}
	owner, err := lookupOwner(env, l.User)
	if err != nil {
		return false, err
	}
	if st, ok := fi.Sys().(*syscall.Stat_t); !ok || int(st.Uid) != owner.UID {
		return false, fmt.Errorf("admin socket of %s is not the site user's", l.Domain)
	}
	client := &http.Client{
		Timeout: 2 * time.Minute, // workers finish their requests before they restart
		Transport: &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: 3 * time.Second}).DialContext(ctx, "unix", sock)
		}},
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "http://localhost/frankenphp/workers/restart", nil)
	res, err := client.Do(req)
	if err != nil {
		return false, err
	}
	defer res.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(res.Body, 2000))
	if res.StatusCode/100 != 2 {
		return false, fmt.Errorf("worker restart: HTTP %d %s", res.StatusCode, strings.TrimSpace(string(body)))
	}
	return true, nil
}
