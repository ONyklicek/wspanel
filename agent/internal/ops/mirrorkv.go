package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"

	"wspanel/agent/internal/fsx"
)

// The standby's copy of each site's Valkey/Redis: a replica under the site
// user, with its data in the site's own data dir (left out of the file
// sync), following the primary's server through a tunnel only that user may
// use. It answers on a unix socket only: nothing on the standby uses it
// until it is promoted, when it stops following, saves, and the site's own
// server starts on that data.

func kvReplicaUnit(user string) string { return "wspanel-mirror-kv-" + user + ".service" }

func kvReplicaPaths(env *Env, user string) (unitFile, conf, runDir, socket string) {
	runDir = "/run/wspanel-mirror-kv/" + user
	return filepath.Join(env.Cfg.SystemdDir, kvReplicaUnit(user)), filepath.Join("/etc/wspanel/mirror/kv", user+".conf"), runDir, runDir + "/kv.sock"
}

// kvPorts: the tunnel port of each site (kept, so a replica's config does not change).
func kvPorts(env *Env, domains []string) (map[string]int, error) {
	ports := map[string]int{}
	if b, err := os.ReadFile(mirrorFile(env, "kv-ports.json")); err == nil {
		_ = json.Unmarshal(b, &ports)
	}
	used := map[int]bool{}
	for d, p := range ports {
		if !slices.Contains(domains, d) {
			delete(ports, d)
			continue
		}
		used[p] = true
	}
	next := tunnelPortKVBase
	for _, d := range domains {
		if _, ok := ports[d]; ok {
			continue
		}
		for used[next] && next <= tunnelPortKVMax {
			next++
		}
		if next > tunnelPortKVMax {
			return nil, fmt.Errorf("no tunnel port left for %s", d)
		}
		ports[d], used[next] = next, true
	}
	b, _ := json.Marshal(ports)
	return ports, writeMirrorFile(env, "kv-ports.json", b)
}

var requirepassRe = regexp.MustCompile(`(?m)^requirepass (\S+)$`)

// syncKVReplicas: a replica for each site of the primary that has one.
func syncKVReplicas(ctx context.Context, env *Env, sites []siteState) {
	var domains []string
	byDomain := map[string]siteState{}
	for _, st := range sites {
		if st.Valkey != nil {
			domains = append(domains, st.Domain)
			byDomain[st.Domain] = st
		}
	}
	ports, err := kvPorts(env, domains)
	if err != nil {
		mirrorRuntime.layer("kv", err, "", nil)
		return
	}
	authDir := filepath.Join(env.Cfg.SiteConfigDir, "valkey-auth") + "/"
	auth, err := snapshotFiles(env, func(p string) bool { return strings.HasPrefix(p, authDir) })
	if err != nil {
		mirrorRuntime.layer("kv", err, "", nil)
		return
	}
	keep := map[string]bool{}
	var failed []string
	up := 0
	for _, d := range domains {
		st := byDomain[d]
		l, err := layout(env.Cfg, st)
		if err != nil {
			continue
		}
		keep[l.User] = true
		m := requirepassRe.FindSubmatch(auth[authDir+l.User+".conf"])
		if m == nil {
			failed = append(failed, d+": no password in the primary's state")
			continue
		}
		linkUp, err := ensureKVReplica(ctx, env, st, l, ports[d], string(m[1]))
		if err != nil {
			failed = append(failed, d+": "+err.Error())
			continue
		}
		if linkUp {
			up++
		}
	}
	removeStaleKVReplicas(ctx, env, keep)
	err = nil
	if len(failed) > 0 {
		err = fmt.Errorf("%s", strings.Join(failed, "; "))
	} else if up < len(domains) {
		err = fmt.Errorf("%d of %d replicas are still connecting", len(domains)-up, len(domains))
	}
	mirrorRuntime.layer("kv", err, fmt.Sprintf("%d sites with Valkey/Redis", len(domains)), nil)
}

func ensureKVReplica(ctx context.Context, env *Env, st siteState, l siteLayout, port int, password string) (bool, error) {
	uid, ok := uidOf(l.User)
	if !ok {
		return false, fmt.Errorf("user %s does not exist yet", l.User)
	}
	if !fsx.Exists(l.Home) {
		return false, fmt.Errorf("the site's files are not here yet")
	}
	if err := ensureTunnel(ctx, env, port, "kv:"+st.Domain, []int{0, uid}); err != nil {
		return false, err
	}
	v := valkeyPaths(env, l)
	if err := asUser(ctx, env, l.User, "/usr/bin/install", "-d", "-m", "0700", v.DataDir); err != nil {
		return false, err
	}
	unitFile, conf, runDir, socket := kvReplicaPaths(env, l.User)
	server := st.Valkey.server()
	var b strings.Builder
	b.WriteString("# Managed by WSPanel agent (mirror): the standby's replica of the site's server.\n")
	fmt.Fprintf(&b, "port 0\nunixsocket %s\nunixsocketperm 700\nprotected-mode yes\ndaemonize no\nlogfile \"\"\n", socket)
	fmt.Fprintf(&b, "dir %s\ndbfilename dump.rdb\nmaxmemory %dmb\nmaxmemory-policy %s\ndatabases 16\nappendonly no\n", v.DataDir, st.Valkey.MaxMemoryMB, st.Valkey.Policy)
	// The replica keeps a snapshot either way: it is what the site's own
	// server starts from after a promotion.
	b.WriteString("save 300 1 60 1000\n")
	fmt.Fprintf(&b, "replicaof 127.0.0.1 %d\nreplica-read-only yes\nreplica-serve-stale-data yes\n", port)
	fmt.Fprintf(&b, "masterauth %s\nrequirepass %s\n", password, password)
	if server == kvRedis {
		for _, mod := range redisModules(env) {
			b.WriteString("loadmodule " + mod + "\n")
		}
	}
	group, err := lookupGroupOwner(env, l.User)
	if err != nil {
		return false, err
	}
	if err := env.FS.MkdirAll(filepath.Dir(conf), 0o755, fsx.Root); err != nil {
		return false, err
	}
	changed, err := writeFileIfChanged(env, conf, []byte(b.String()), 0o640, group)
	if err != nil {
		return false, err
	}
	data := unitData{
		Description: "WSPanel mirror: replica of " + kvLabel(server) + " for " + l.Domain,
		User:        l.User, Home: l.Home, WorkDir: l.Home, StopTimeout: 30,
		ExecStart: kvServerBin(env, server) + " " + conf,
		Extra: []string{
			"RuntimeDirectory=" + strings.TrimPrefix(runDir, "/run/"), "RuntimeDirectoryMode=0700",
			fmt.Sprintf("MemoryMax=%dM", st.Valkey.MaxMemoryMB*3/2+64), "LimitNOFILE=4096",
		},
	}
	if changed || !fsx.Exists(unitFile) {
		var rb undo
		if err := installService(ctx, env, unitFile, data, &rb); err != nil {
			return false, err
		}
	}
	if env.Cfg.DryRun {
		return true, nil
	}
	res, err := valkeyCLIAt(ctx, env, l.User, socket, password, "INFO", "replication")
	if err != nil {
		return false, nil
	}
	return strings.Contains(res, "master_link_status:up"), nil
}

// valkeyCLIAt: the CLI as the site user against a socket, password on stdin.
func valkeyCLIAt(ctx context.Context, env *Env, user, socket, password string, args ...string) (string, error) {
	res, err := env.Exec.RunIO(ctx, strings.NewReader(password+"\n"), nil, "setpriv",
		append(append(userCreds(user), kvCLIBin(env), "--askpass", "--no-auth-warning", "-s", socket), args...)...)
	return res.Stdout, err
}

func kvReplicaUsers(env *Env) []string {
	m, _ := filepath.Glob(filepath.Join(env.Cfg.SystemdDir, "wspanel-mirror-kv-*.service"))
	var out []string
	for _, f := range m {
		out = append(out, strings.TrimSuffix(strings.TrimPrefix(filepath.Base(f), "wspanel-mirror-kv-"), ".service"))
	}
	return out
}

func removeStaleKVReplicas(ctx context.Context, env *Env, keep map[string]bool) {
	for _, u := range kvReplicaUsers(env) {
		if !keep[u] {
			removeKVReplica(ctx, env, u)
		}
	}
}

func removeKVReplica(ctx context.Context, env *Env, user string) {
	unitFile, conf, _, _ := kvReplicaPaths(env, user)
	_ = removeUnit(ctx, env, unitFile)
	_ = os.Remove(conf)
}

func stopKVReplicas(ctx context.Context, env *Env) {
	for _, u := range kvReplicaUsers(env) {
		removeKVReplica(ctx, env, u)
	}
}

// promoteKVReplicas: each replica stops following and writes its data
// where the site's own server will read it; then it goes.
func promoteKVReplicas(ctx context.Context, env *Env, rep *RestoreReport) {
	n := 0
	for _, u := range kvReplicaUsers(env) {
		_, conf, _, socket := kvReplicaPaths(env, u)
		b, _ := os.ReadFile(conf)
		pw := ""
		if m := requirepassRe.FindSubmatch(b); m != nil {
			pw = string(m[1])
		}
		if _, err := valkeyCLIAt(ctx, env, u, socket, pw, "REPLICAOF", "NO", "ONE"); err != nil {
			rep.problem("Valkey/Redis of %s: %v", u, err)
		} else if _, err := valkeyCLIAt(ctx, env, u, socket, pw, "SAVE"); err != nil {
			rep.problem("Valkey/Redis of %s: saving: %v", u, err)
		} else {
			n++
		}
		removeKVReplica(ctx, env, u)
	}
	if n > 0 {
		rep.ok("%d Valkey/Redis replicas promoted", n)
	}
}

// dropStaleKVData: a site whose server does not persist must not start
// from the replica's snapshot again after its next restart.
func dropStaleKVData(ctx context.Context, env *Env) {
	for _, st := range allSiteStates(env.Cfg) {
		if st.Valkey == nil || st.Valkey.Persist {
			continue
		}
		if l, err := layout(env.Cfg, st); err == nil {
			_ = removeKVData(ctx, env, l.User, valkeyPaths(env, l))
		}
	}
}
