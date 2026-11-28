package ops

import (
	"strconv"
	"strings"

	"wspanel/agent/internal/protocol"
)

// A node that does not serve (the standby, a fenced old primary) holds the
// primary's copy: what it got from the primary is replaced at a promotion
// or a rejoin. Something created on it (a site, a database, a mailbox)
// would vanish then, or clash with the primary's copy. Its panel manages
// the node itself (updates, modules, services, the mirror); the sites,
// databases and mail are managed on the serving node.

// The service's data, by prefix: every change of these is refused on a
// node that does not serve (a drop on a replica, for one, is a local
// transaction that breaks its replication; a site enabled there would run
// its scheduler and queues beside the primary's).
var standbyServicePrefixes = []string{"site.", "db.", "mail.", "dns.", "client.", "deploy.", "worker.", "cron.", "env.", "valkey.", "ssh."}

// standbyWriteOps: changes registered as not mutating (they run as jobs
// and take no lock), and the backups (the serving node's job: a standby's
// copy uploaded off-site would be taken for the newest).
var standbyWriteOps = map[string]bool{
	"db.restore": true, "db.copy": true, "db.backup": true, "site.restore": true, "site.backup": true,
	"site.import": true, "deploy.run": true, "server.backup": true,
}

// standbyNodeOps: in those prefixes, what belongs to the node itself (its
// software, its own mail queue and certificates), allowed everywhere.
var standbyNodeOps = map[string]bool{
	"mail.install": true, "mail.uninstall": true, "mail.certs": true, "mail.queue": true,
	"dns.install": true, "dns.uninstall": true, "db.backup_delete": true,
}

// StandbyRefuses: the error for op on a node that does not serve, nil otherwise.
// mutating: the op's registry flag.
func StandbyRefuses(env *Env, op string, mutating bool) error {
	if standbyNodeOps[op] {
		return nil
	}
	if !standbyWriteOps[op] {
		if !mutating {
			return nil
		}
		service := false
		for _, p := range standbyServicePrefixes {
			service = service || strings.HasPrefix(op, p)
		}
		if !service {
			return nil
		}
	}
	st := currentMirror(env)
	if st.Role != roleStandby && st.Role != roleFenced {
		return nil
	}
	where := "the serving server"
	m := mirrorRuntime
	m.mu.Lock()
	if m.Peer != nil && strings.HasPrefix(m.Peer.PanelURL, "https://") {
		where += " (" + m.Peer.PanelURL + ")"
	}
	m.mu.Unlock()
	return protocol.Conflict("this server is the mirror's " + st.Role + ": sites, databases and mail are managed on " + where + "; a change here would break the copy or be lost when it is applied")
}

// panelURL: this node's own panel.
func panelURL(env *Env) string {
	d := env.Cfg.PanelDomain
	if d == "" {
		return ""
	}
	if p := env.Cfg.PanelPort; p > 0 && p != 443 {
		return "https://" + d + ":" + strconv.Itoa(p)
	}
	return "https://" + d
}
