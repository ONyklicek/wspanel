package ops

import (
	"bufio"
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Game servers share one slice whose memory limit leaves the rest of the
// server to the web tier, the databases and the panel. Each game's own
// MemoryMax only bounds that game: six of them at 2.8 GB on an 8 GB server
// used to push the edge, the web tier and PHP into swap, and the kernel's
// out-of-memory killer then took turns with the games' restarts for hours.
// Inside the slice the games compete among themselves; the kernel reclaims
// and kills there, not in the sites.
const gamesSlice = "wspanel-games.slice"

// hostMemoryMB is the server's RAM (MemTotal); 0 when unknown (tests set it).
var hostMemoryMB = func() int {
	f, err := os.Open("/proc/meminfo")
	if err != nil {
		return 0
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		if rest, ok := strings.CutPrefix(sc.Text(), "MemTotal:"); ok {
			kb, err := strconv.Atoi(strings.TrimSuffix(strings.TrimSpace(rest), " kB"))
			if err != nil {
				return 0
			}
			return kb / 1024
		}
	}
	return 0
}

// gamesReserveMB is what games never get: a quarter of the RAM, at least
// 1.5 GB (the edge, PHP, a database and the panel of a small server).
func gamesReserveMB(totalMB int) int {
	return max(totalMB/4, 1536)
}

// gamesBudgetMB is the memory all game servers together may use (0: unknown).
func gamesBudgetMB() int {
	total := hostMemoryMB()
	if total <= 0 {
		return 0
	}
	return max(total-gamesReserveMB(total), 0)
}

// gameUnitMemoryMB is the unit's MemoryMax: a JVM needs its heap and its own.
func gameUnitMemoryMB(env *Env, g gameState) int {
	if gameEngine(env, g) == "minecraft" {
		return g.MemoryMB*5/4 + 256
	}
	return g.MemoryMB
}

// checkGameMemory refuses a game whose limit would make all game servers
// together reserve more than the server leaves them. Lowering a limit is
// always allowed, so an over-committed server can be brought back down.
func checkGameMemory(env *Env, g gameState, previousMB int) error {
	budget := gamesBudgetMB()
	if budget == 0 {
		return nil
	}
	next := gameUnitMemoryMB(env, g)
	if previousMB > 0 && next <= previousMB {
		return nil
	}
	total := next
	for _, o := range allGames(env) {
		if o.ID != g.ID {
			total += gameUnitMemoryMB(env, o)
		}
	}
	if total <= budget {
		return nil
	}
	ram := hostMemoryMB()
	return protocol.Invalid(fmt.Sprintf(
		"memory_mb: the game servers would reserve %d MB together; this server has %d MB of RAM and keeps %d MB for the sites, databases and the panel, so games may use %d MB (%d MB left)",
		total, ram, gamesReserveMB(ram), budget, max(budget-(total-next), 0)))
}

// gamesSliceUnit renders the slice: a soft limit the kernel reclaims at first,
// then the hard one, without swap (a swapping game server is a stalled one).
func gamesSliceUnit(budgetMB int) string {
	var b strings.Builder
	b.WriteString("# Managed by WSPanel agent. Do not edit: changes are overwritten by the panel.\n")
	b.WriteString("[Unit]\nDescription=WSPanel game servers\nBefore=slices.target\n\n[Slice]\n")
	if budgetMB > 0 {
		fmt.Fprintf(&b, "MemoryHigh=%dM\nMemoryMax=%dM\nMemorySwapMax=0\n", budgetMB*95/100, budgetMB)
	}
	return b.String()
}

// writeGamesSlice installs the slice when its rendering changed; it reports
// whether systemd needs a daemon-reload.
func writeGamesSlice(env *Env) (bool, error) {
	path := filepath.Join(env.Cfg.SystemdDir, gamesSlice)
	unit := []byte(gamesSliceUnit(gamesBudgetMB()))
	if old, err := os.ReadFile(path); err == nil && string(old) == string(unit) {
		return false, nil
	}
	return true, env.FS.WriteFile(path, unit, 0o644, fsx.Root)
}

// RenderGameUnitsOnStart brings the units of existing game servers to the
// current rendering (into the slice, with the restart pause) after an
// upgrade. Running servers keep running; systemd moves one into the slice
// at its next start.
func RenderGameUnitsOnStart(ctx context.Context, env *Env, logf func(string, ...any)) {
	if env.Cfg.DryRun {
		return
	}
	games := allGames(env)
	if len(games) == 0 {
		return
	}
	_ = withMutate(env, func() error {
		n := 0
		for _, g := range games {
			path := filepath.Join(env.Cfg.SystemdDir, g.unit())
			old, _ := os.ReadFile(path)
			if err := writeGameUnit(ctx, env, g); err != nil {
				logf("refreshing the unit of game server %d: %v", g.ID, err)
				continue
			}
			if now, _ := os.ReadFile(path); string(now) != string(old) {
				n++
			}
		}
		if n > 0 {
			logf("refreshed the unit of %d game server(s)", n)
		}
		return nil
	})
}
