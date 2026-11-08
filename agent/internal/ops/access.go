package ops

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/protocol"
)

// ---------------------------------------------------------------- panel access

// panelAccessFile is imported into the panel's site block on the edge.
func panelAccessFile(env *Env) string {
	return filepath.Join(filepath.Dir(env.Cfg.ProxyConfig), "panel-access.caddy")
}

// panel.access limits who can open the panel (and phpMyAdmin, Adminer,
// Mailpit on its domain) to the given addresses / networks. Deploy webhooks
// and the CI API stay reachable from anywhere (optional), so pushes and
// pipelines keep working when the panel itself is private. Loopback is
// always allowed: an SSH tunnel is the way back in.
func panelAccess(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Allow       []string `json:"allow"`        // empty = public
		PublicHooks bool     `json:"public_hooks"` // webhooks + CI API reachable from anywhere
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if len(p.Allow) > 50 {
		return nil, protocol.Invalid("allow: at most 50 entries")
	}
	nets := []string{}
	for _, a := range p.Allow {
		a = strings.TrimSpace(a)
		if ip := net.ParseIP(a); ip != nil {
			nets = append(nets, ip.String())
			continue
		}
		_, n, err := net.ParseCIDR(a)
		if err != nil {
			return nil, protocol.Invalid("allow: " + strconv.Quote(a) + " is not an IP address or network (CIDR)")
		}
		ones, _ := n.Mask.Size()
		if ones == 0 {
			return nil, protocol.Invalid("allow: " + a + " allows everyone; leave the list empty for a public panel")
		}
		nets = append(nets, n.String())
	}
	slices.Sort(nets)
	nets = slices.Compact(nets)

	var b bytes.Buffer
	// Imported inside the panel's route block: evaluated before any handler.
	b.WriteString("# Managed by WSPanel agent (panel.access). Who may open the panel.\n")
	if len(nets) > 0 {
		b.WriteString("@panel_outsider {\n\tnot remote_ip 127.0.0.1/8 ::1 " + strings.Join(nets, " ") + "\n")
		if p.PublicHooks {
			b.WriteString("\tnot path /hooks/deploy/* /api/deploy/*\n")
		}
		b.WriteString("}\nrespond @panel_outsider 404 {\n\tclose\n}\n")
	}
	path := panelAccessFile(env)
	old, _ := os.ReadFile(path)
	owner, err := lookupGroupOwner(env, env.Cfg.ProxyGroup)
	if err != nil {
		return nil, err
	}
	if err := env.FS.WriteFile(path, b.Bytes(), 0o640, owner); err != nil {
		return nil, err
	}
	restore := func() { _ = env.FS.WriteFile(path, old, 0o640, owner) }
	if err := validateEdge(ctx, env); err != nil {
		restore()
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "reload", env.Cfg.ProxyUnit); err != nil {
		restore()
		return nil, err
	}
	return map[string]any{"allow": nets, "public_hooks": p.PublicHooks, "public": len(nets) == 0}, nil
}

// ---------------------------------------------------------------- remote refs

var lsRemoteRe = regexp.MustCompile(`^([0-9a-f]{40,64})\trefs/(heads|tags)/(\S+)$`)

// deployRemoteRef asks the repository (as the site user, with its deploy
// key) what it would deploy now: the configured branch's head, or the newest
// version tag. Lets the panel deploy by polling when nothing can reach it.
func deployRemoteRef(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		Kind   string `json:"kind"` // branch | tags
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Kind != "branch" && p.Kind != "tags" {
		return nil, protocol.Invalid("kind: branch or tags")
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.Deploy == nil || st.Deploy.Repo == "" {
		return nil, protocol.Invalid("no repository is set for " + p.Domain)
	}
	args := []string{"/usr/bin/git", "ls-remote", "--quiet"}
	if p.Kind == "branch" {
		args = append(args, "--heads", "--", st.Deploy.Repo, "refs/heads/"+st.Deploy.Branch)
	} else {
		args = append(args, "--tags", "--refs", "--", st.Deploy.Repo)
	}
	var out bytes.Buffer
	if _, err := env.Exec.RunLog(ctx, &out, "systemd-run", append(sandboxRunArgs(env, l, l.Home, nil), args...)...); err != nil {
		return nil, fmt.Errorf("git ls-remote failed: %s", strings.TrimSpace(lastLine(out.String())))
	}
	var best, bestSHA string
	for _, line := range strings.Split(out.String(), "\n") {
		m := lsRemoteRe.FindStringSubmatch(strings.TrimSpace(line))
		if m == nil {
			continue
		}
		if p.Kind == "branch" {
			if m[2] == "heads" && m[3] == st.Deploy.Branch {
				return map[string]any{"ref": m[3], "commit": m[1]}, nil
			}
			continue
		}
		if m[2] != "tags" {
			continue
		}
		if best == "" || versionLess(best, m[3]) {
			best, bestSHA = m[3], m[1]
		}
	}
	if best == "" {
		return map[string]any{"ref": "", "commit": ""}, nil
	}
	return map[string]any{"ref": best, "commit": bestSHA}, nil
}

func lastLine(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	return lines[len(lines)-1]
}

var versionRe = regexp.MustCompile(`^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(-[0-9A-Za-z.-]+)?$`)

// versionLess orders tags like semantic versions (v1.10.0 > v1.9.3);
// tags that are not versions sort below all versions, by name.
func versionLess(a, b string) bool {
	ma, mb := versionRe.FindStringSubmatch(a), versionRe.FindStringSubmatch(b)
	switch {
	case ma == nil && mb == nil:
		return a < b
	case ma == nil:
		return true
	case mb == nil:
		return false
	}
	for i := 1; i <= 3; i++ {
		x, _ := strconv.Atoi(ma[i])
		y, _ := strconv.Atoi(mb[i])
		if x != y {
			return x < y
		}
	}
	// 1.0.0-rc1 < 1.0.0
	if (ma[4] == "") != (mb[4] == "") {
		return ma[4] != ""
	}
	return ma[4] < mb[4]
}
