package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Hosting clients on the server: each client is a Linux group (wsc_<id>),
// the primary group of every site user it owns. The group quota on the
// filesystem of the sites root is the client's disk limit.

const (
	defaultFPMChildren = 10
	maxFPMChildren     = 200
)

// Groups every SSH/SFTP site user is also in. A user could chgrp files to
// them to step out of the client's quota, so they get a quota of one block.
var quotaTrapGroups = []string{"wspanel-ssh", "wspanel-sftp"}

// checkClientGroup: empty (no client) or an existing wsc_<id> group.
func checkClientGroup(env *Env, g string) error {
	if g == "" {
		return nil
	}
	if err := validate.ClientGroup("group", g); err != nil {
		return err
	}
	if env.Cfg.DryRun {
		return nil
	}
	if _, err := user.LookupGroup(g); err != nil {
		return protocol.NotFound("group " + g + " does not exist (client.create first)")
	}
	return nil
}

func clientCreate(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Group string `json:"group"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.ClientGroup("group", p.Group); err != nil {
		return nil, err
	}
	if _, err := user.LookupGroup(p.Group); err == nil && !env.Cfg.DryRun {
		return map[string]any{"group": p.Group, "created": false}, nil
	}
	if _, err := env.Exec.Run(ctx, "groupadd", "--system", p.Group); err != nil {
		return nil, err
	}
	return map[string]any{"group": p.Group, "created": true}, nil
}

func clientDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Group string `json:"group"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.ClientGroup("group", p.Group); err != nil {
		return nil, err
	}
	for _, st := range allSiteStates(env.Cfg) {
		if st.Group == p.Group {
			return nil, protocol.Conflict("group " + p.Group + " still owns site " + st.Domain)
		}
	}
	if _, err := user.LookupGroup(p.Group); err != nil && !env.Cfg.DryRun {
		return map[string]any{"group": p.Group, "deleted": false}, nil
	}
	if _, err := env.Exec.Run(ctx, "groupdel", p.Group); err != nil {
		return nil, err
	}
	return map[string]any{"group": p.Group, "deleted": true}, nil
}

// quotaMount returns the filesystem holding the sites and whether group
// quotas are enforced on it. Off (Docker, a filesystem mounted without
// quota) means the panel only measures.
func quotaMount(ctx context.Context, env *Env) (string, bool) {
	res, err := env.Exec.Run(ctx, "findmnt", "--noheadings", "--output", "TARGET", "--target", env.Cfg.SitesRoot)
	mount := strings.TrimSpace(res.Stdout)
	if err != nil || mount == "" {
		return "", false
	}
	// quotaon -p exits non-zero when a quota is on (it reports state, not success).
	res, _ = env.Exec.Run(ctx, "quotaon", "--print-state", "--group", mount)
	return mount, strings.Contains(res.Stdout+res.Stderr, "group quota on") && strings.Contains(res.Stdout+res.Stderr, "is on")
}

// clientQuota sets the client's disk limit (MB, 0 = unlimited) as a hard
// group block quota. Answers enforced=false when the filesystem has no quota.
func clientQuota(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Group string `json:"group"`
		MB    int64  `json:"mb"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.ClientGroup("group", p.Group); err != nil {
		return nil, err
	}
	if p.MB < 0 || p.MB > 1<<30 {
		return nil, protocol.Invalid("mb: must be 0 (unlimited) or a positive size")
	}
	if err := checkClientGroup(env, p.Group); err != nil {
		return nil, err
	}
	mount, on := quotaMount(ctx, env)
	if !on {
		return map[string]any{"group": p.Group, "enforced": false}, nil
	}
	kb := strconv.FormatInt(p.MB*1024, 10)
	// soft = hard: no grace period, a full quota refuses the write at once.
	if _, err := env.Exec.Run(ctx, "setquota", "-g", p.Group, kb, kb, "0", "0", mount); err != nil {
		return nil, err
	}
	for _, g := range quotaTrapGroups {
		if _, err := user.LookupGroup(g); err == nil {
			_, _ = env.Exec.Run(ctx, "setquota", "-g", g, "1", "1", "0", "0", mount)
		}
	}
	return map[string]any{"group": p.Group, "enforced": true, "mb": p.MB, "mount": mount}, nil
}

// siteSetGroup moves a site to a client's group (or back to its own group):
// the user's primary group, the group of its files, and the processes that
// run with it.
func siteSetGroup(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		Group  string `json:"group"` // empty = the site's own group
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := checkClientGroup(env, p.Group); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	from := st.Group
	if from == "" {
		from = l.User
	}
	to := p.Group
	if to == "" {
		to = l.User
		if _, err := user.LookupGroup(to); err != nil && !env.Cfg.DryRun {
			if _, err := env.Exec.Run(ctx, "groupadd", "--system", to); err != nil {
				return nil, err
			}
		}
	}
	if from == to {
		return map[string]any{"domain": p.Domain, "group": to, "changed": false}, nil
	}
	if _, err := env.Exec.Run(ctx, "usermod", "--gid", to, l.User); err != nil {
		return nil, err
	}
	// Only what had the old group; -h: a link is changed, never followed.
	if _, err := env.Exec.Run(ctx, "chown", "-R", "-h", "--from=:"+from, ":"+to, l.Home); err != nil {
		return nil, err
	}
	if fsx.Exists(l.InstanceConfig) {
		if _, err := env.Exec.Run(ctx, "chown", "-h", ":"+to, l.InstanceConfig); err != nil {
			return nil, err
		}
	}
	st.Group = p.Group
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	// Running processes keep the old group until they restart.
	if err := restartSiteProcesses(ctx, env, l); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "group": to, "changed": true}, nil
}

func restartSiteProcesses(ctx context.Context, env *Env, l siteLayout) error {
	units := []string{}
	switch l.Mode {
	case "classic":
		if l.PHPVersion != "" {
			if _, err := env.Exec.Run(ctx, "systemctl", "reload", strings.ReplaceAll(env.Cfg.FPMUnit, "{version}", l.PHPVersion)); err != nil {
				return err
			}
		}
	default:
		units = append(units, l.AppUnit)
	}
	for _, u := range siteWorkerUnits(env.Cfg, l.User) {
		units = append(units, filepath.Base(u))
	}
	if len(units) == 0 {
		return nil
	}
	_, err := env.Exec.Run(ctx, "systemctl", append([]string{"try-restart"}, units...)...)
	return err
}

// siteLimits sets per-site process limits from the client's package.
func siteLimits(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain      string `json:"domain"`
		FPMChildren int    `json:"fpm_children"` // 0 = default
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.FPMChildren < 0 || p.FPMChildren > maxFPMChildren {
		return nil, protocol.Invalid(fmt.Sprintf("fpm_children: must be 0-%d", maxFPMChildren))
	}
	st, _, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	st.FPMChildren = p.FPMChildren
	l, err := layout(env.Cfg, st)
	if err != nil {
		return nil, err
	}
	if st.Mode == "classic" {
		if err := applyPHPRuntime(ctx, env, l); err != nil {
			return nil, err
		}
	}
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "fpm_children": l.FPMChildren}, nil
}
