package ops

import (
	"context"
	"net"
	"os"
	"path/filepath"
	"slices"
	"time"

	"wspanel/agent/internal/fsx"
)

// The start gate. A primary of a paired mirror that starts (a reboot, a
// crash, a power cut) does not know whether the standby took over while it
// was away: until it learns, it must not serve. Its agent therefore fences
// it at once (reason "starting": sites off the edge, databases read-only,
// the sites' units stopped) and the mirror's loop lets it serve again when
//
//   - the other node answers as the standby (or fenced) with no newer epoch
//     and no promotion running: nothing changed while it was away; or
//   - the other node stays silent for the whole failover time, the witness
//     answers (when there is one) and nobody holds the floating IP: both
//     were down (a power cut) and only this one came back.
//
// The other node being the primary, or of a newer epoch, makes this one an
// ordinary replaced node (rejoin by hand). Without the gate a crashed
// primary served its sites, with their cron jobs and workers, from the
// moment its edge started until the first hello.

const reasonStarting = "starting"

// bootMarker: in /run (a tmpfs, empty after every boot of the machine):
// present, the agent only restarted (an upgrade) while the node kept
// serving all along, and there is nothing to gate; absent, the machine
// started. A variable for the tests.
var bootMarker = "/run/wspanel-mirror-booted"

// MirrorStartGate runs once at the agent's start, before anything else
// renders the sites; only after a start of the machine (an agent restart,
// e.g. an upgrade, leaves a serving node serving: its first hello settles
// the epoch as before).
func MirrorStartGate(ctx context.Context, env *Env, logf func(string, ...any)) {
	if env.Cfg.DryRun {
		return
	}
	claimed := claimBoot()
	st := currentMirror(env)
	if !claimed {
		// The agent restarted (the machine did not), or the edge's start
		// already gated this boot (MirrorBootGate): then go on fenced.
		if st.Role == roleFenced && st.Fenced == reasonStarting {
			logf("mirror: gated at this boot: not serving until the standby confirms")
			mirrorRuntime.event("info", "po startu čeká na potvrzení zrcadla, do té doby neobsluhuje weby")
		}
		return
	}
	if st.Role != rolePrimary || st.Peer == nil {
		return
	}
	logf("mirror: started as the primary of a mirror: not serving until the standby confirms (or stays silent for %ds)", st.FailoverAfter)
	if err := fenceLocal(ctx, env, reasonStarting); err != nil {
		logf("mirror: the start gate failed: %v", err)
	}
}

type startVerdict int

const (
	startWait startVerdict = iota
	startServe
	startReplaced
)

// startDecision: what a node fenced by the start gate does now. them is the
// other node's hello (nil: it did not answer); vipFree: nobody answers ARP
// for the floating IP (true without one).
func startDecision(st *mirrorState, them *helloInfo, now time.Time, witnessOK, vipFree bool) startVerdict {
	if them != nil {
		switch {
		case them.Role == rolePrimary || them.Epoch > st.Epoch || them.Action == "promoting":
			return startReplaced
		case (them.Role == roleStandby || them.Role == roleFenced) && them.Action == "":
			return startServe
		}
		return startWait
	}
	if now.Sub(st.Since) < time.Duration(st.FailoverAfter)*time.Second {
		return startWait
	}
	if len(st.Witness) > 0 && !witnessOK {
		return startWait // cut off ourselves, perhaps
	}
	if !vipFree {
		return startWait // someone serves at the floating IP
	}
	return startServe
}

// startingTick: the loop's part for a node the start gate fenced.
func startingTick(ctx context.Context, env *Env, st *mirrorState, them *helloInfo, logf func(string, ...any)) {
	witnessOK, vipFree := true, true
	if them == nil {
		if time.Since(st.Since) < time.Duration(st.FailoverAfter)*time.Second {
			return // the cheap checks only once the wait is over
		}
		_, witnessOK = probeWitness(ctx, st.Witness)
		if st.VIP != nil {
			mac, err := arpProbe(st.VIP.Interface, net.ParseIP(st.VIP.Address))
			vipFree = err == nil && mac == ""
		}
	}
	switch startDecision(st, them, time.Now(), witnessOK, vipFree) {
	case startServe:
		why := "zrcadlo potvrdilo, že je zrcadlem"
		if them == nil {
			why = "zrcadlo neodpovídá, svědek ano a plovoucí IP nikdo nedrží"
		}
		logf("mirror: the start gate opens (%s)", why)
		mirrorRuntime.event("info", "po startu: %s, tento server znovu obsluhuje weby", why)
		if err := unfenceToPrimary(context.WithoutCancel(ctx), env); err != nil {
			logf("mirror: serving again failed: %v", err)
		}
	case startReplaced:
		mirrorRuntime.event("danger", "po startu: druhý server mezitím převzal provoz (epocha %d): tento server zůstává odstavený, připojte ho zpět jako zrcadlo", them.Epoch)
		_, _ = updateMirror(env, func(s *mirrorState) error {
			if s.Role == roleFenced && s.Fenced == reasonStarting {
				s.Fenced = "replaced"
			}
			return nil
		})
	}
}

// claimBoot: the first caller since the machine started (the marker is
// created exclusively: the edge's ExecStartPre and the agent may race).
func claimBoot() bool {
	f, err := os.OpenFile(bootMarker, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err != nil {
		return false
	}
	_, _ = f.WriteString(time.Now().UTC().Format(time.RFC3339) + "\n")
	_ = f.Close()
	return true
}

// MirrorBootGate is the edge's ExecStartPre (panel-agent -mirror-boot-gate):
// at a boot the edge must not serve the sites of a primary that has not
// heard from its standby yet, and it starts before the agent can say so.
// Files only (no systemctl: the edge is starting): the sites' blocks go
// aside, the node is fenced "starting", MariaDB starts read-only. The agent
// takes it from there (the start gate). Nothing happens after the first
// start since the boot, or on a node that is not a paired primary.
func MirrorBootGate(env *Env) error {
	if !claimBoot() {
		return nil
	}
	st := currentMirror(env)
	if st.Role != rolePrimary || st.Peer == nil {
		return nil
	}
	if err := putEdgesAside(env); err != nil {
		return err
	}
	if slices.Contains(env.Cfg.DBEngines, "mariadb") {
		_, _ = writeFileIfChanged(env, filepath.Join(env.Cfg.MariaDBConfDir, mariadbMirrorCf), mariadbMirrorConfig(st.NodeID, true), 0o644, fsx.Root)
	}
	if _, err := updateMirror(env, func(s *mirrorState) error {
		if s.Role == rolePrimary {
			s.Role, s.Fenced, s.Since = roleFenced, reasonStarting, time.Now().UTC()
		}
		return nil
	}); err != nil {
		return err
	}
	return writeMirrorFile(env, "fenced", []byte(reasonStarting+"\n"))
}
