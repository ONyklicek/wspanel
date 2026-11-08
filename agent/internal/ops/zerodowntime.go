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

	"wspanel/agent/internal/peercred"
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
		Transport: &http.Transport{
			DisableKeepAlives: true,
			DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
				c, err := (&net.Dialer{Timeout: 3 * time.Second}).DialContext(ctx, "unix", sock)
				if err != nil {
					return nil, err
				}
				// Decided on the connection itself, not on the path: whatever
				// the path pointed at by now, the server on the other end must
				// be the site user's own process.
				uc, ok := c.(*net.UnixConn)
				if !ok {
					c.Close()
					return nil, fmt.Errorf("admin socket: not a unix connection")
				}
				uid, err := peercred.UID(uc)
				if err != nil || int(uid) != owner.UID {
					c.Close()
					return nil, fmt.Errorf("admin socket of %s is served by another user", l.Domain)
				}
				return c, nil
			},
		},
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

// removeNodeSlots removes both slots' units of a Node site (mode change, delete).
func removeNodeSlots(ctx context.Context, env *Env, l siteLayout) {
	for _, name := range []string{appUnitName(l.User), appUnitNameB(l.User)} {
		if path := filepath.Join(env.Cfg.SystemdDir, name); fileIn(env.Cfg.SystemdDir, name) {
			_ = removeUnit(ctx, env, path)
		}
	}
}

// blueGreenNode switches a Node site to the new release without a gap: the
// idle slot starts with the new code on its own port, and only when it
// answers does the edge send visitors there; the old slot then finishes its
// requests and stops. When the new one does not answer, visitors never
// leave the old one. Reports whether the switch happened (failed = the
// caller rolls the release back).
func (d *deployment) blueGreenNode(ctx context.Context) (switched bool, err error) {
	st, cur, err := loadSite(d.env, d.l.Domain)
	if err != nil {
		return false, err
	}
	if st.PortB == 0 {
		if st.PortB, err = allocatePort(d.env); err != nil {
			return false, err
		}
		if err := saveSiteState(d.env, st); err != nil {
			return false, err
		}
	}
	next := st
	next.Slot = "b"
	if st.Slot == "b" {
		next.Slot = ""
	}
	nl, err := layout(d.env.Cfg, next)
	if err != nil {
		return false, err
	}
	data, entry, err := appUnitData(d.env, nl)
	if err != nil {
		return false, err
	}
	var rb undo
	started, err := installUnit(ctx, d.env, nl.AppUnitFile, data, entry, &rb)
	if err != nil {
		rb.run()
		return false, err
	}
	if !started {
		return false, fmt.Errorf("the new release has no %s", entry)
	}
	d.logf("started %s on 127.0.0.1:%d with the new release\n", nl.AppUnit, nl.Port)
	ready := false
	for i := 0; i < 60 && !ready; i++ {
		ready = probeSite(ctx, d.env, nl)["ok"] == true
		if !ready {
			time.Sleep(time.Second)
		}
	}
	if !ready {
		d.logf("%s does not answer; visitors stay on %s\n", nl.AppUnit, cur.AppUnit)
		_, _ = d.env.Exec.Run(ctx, "systemctl", "disable", "--now", nl.AppUnit)
		return false, nil
	}
	// Under the agent's lock, but the site must still be what we started from.
	if now, _, err := loadSite(d.env, d.l.Domain); err != nil || now.Disabled || now.Mode != "node" || now.Slot != st.Slot {
		_, _ = d.env.Exec.Run(ctx, "systemctl", "disable", "--now", nl.AppUnit)
		return false, fmt.Errorf("the site changed meanwhile; not switching")
	}
	if err := writeEdge(ctx, d.env, nl, &rb); err != nil {
		rb.run()
		_, _ = d.env.Exec.Run(ctx, "systemctl", "disable", "--now", nl.AppUnit)
		return false, err
	}
	if err := saveSiteState(d.env, next); err != nil {
		return false, err
	}
	d.logf("edge now sends visitors to %s\n", nl.AppUnit)
	// The old slot finishes the requests it has (TimeoutStopSec), then stops.
	_, _ = d.env.Exec.Run(ctx, "systemctl", "disable", cur.AppUnit)
	_, _ = d.env.Exec.Run(ctx, "systemctl", "stop", "--no-block", cur.AppUnit)
	d.l = nl
	return true, nil
}
