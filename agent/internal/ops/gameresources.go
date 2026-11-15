package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strings"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// How the game servers share the server with the sites, as the
// administrator set it: the RAM the sites keep (the rest is the games'
// budget), whose CPU and disk come first, and a cap on the CPU all games
// together may take even on an idle server.
type gamesPolicy struct {
	ReserveMB int    `json:"reserve_mb"` // 0: automatic, max(¼ RAM, 1.5 GB)
	Priority  string `json:"priority"`   // sites | balanced | games
	CPUCores  int    `json:"cpu_cores"`  // 0: no cap
}

var gamesPriorities = []string{"sites", "balanced", "games"}

// The weight of wspanel.slice (where the games' slice lives) next to
// system.slice (the sites, the databases, the panel: systemd's 100). With
// the sites first a game gets CPU and disk the sites leave over, without
// being starved when they are idle.
var gamesWeights = map[string]int{"sites": 20, "balanced": 100, "games": 400}

// wspanelSlice is the parent of the games' slice (systemd nests by the dash).
const wspanelSlice = "wspanel.slice"

// hostCPUs is the number of CPUs (tests set it).
var hostCPUs = runtime.NumCPU

func gamesPolicyPath(env *Env) string { return filepath.Join(gameStateDir(env), "resources.json") }

func loadGamesPolicy(env *Env) gamesPolicy {
	p := gamesPolicy{Priority: "sites"}
	if b, err := os.ReadFile(gamesPolicyPath(env)); err == nil {
		_ = json.Unmarshal(b, &p)
	}
	if !contains(gamesPriorities, p.Priority) {
		p.Priority = "sites"
	}
	return p
}

// minSitesReserveMB: what the edge, PHP, a database and the panel need at
// the least; a reserve set lower is refused.
const minSitesReserveMB = 1024

func (p gamesPolicy) validate(totalMB int) error {
	if !contains(gamesPriorities, p.Priority) {
		return protocol.Invalid("priority: sites, balanced or games")
	}
	if p.ReserveMB != 0 {
		if p.ReserveMB < minSitesReserveMB {
			return protocol.Invalid(fmt.Sprintf("reserve_mb: at least %d MB stay for the sites, databases and the panel", minSitesReserveMB))
		}
		if totalMB > 0 && p.ReserveMB > totalMB-512 {
			return protocol.Invalid(fmt.Sprintf("reserve_mb: this server has %d MB of RAM; keeping %d MB leaves the game servers nothing", totalMB, p.ReserveMB))
		}
	}
	if p.CPUCores < 0 || p.CPUCores > hostCPUs() {
		return protocol.Invalid(fmt.Sprintf("cpu_cores: 0 (no cap) to %d, the server's CPUs", hostCPUs()))
	}
	return nil
}

// gamesReserveOf is the RAM the sites keep under the policy.
func gamesReserveOf(env *Env, totalMB int) int {
	if r := loadGamesPolicy(env).ReserveMB; r > 0 {
		return r
	}
	return gamesReserveMB(totalMB)
}

// runningGame: a game server whose memory is taken now.
type runningGame struct {
	ID     int    `json:"id"`
	Name   string `json:"name"`
	UnitMB int    `json:"unit_mb"`
}

// runningGames: the game servers whose unit runs or starts now (their
// memory is taken), except the one being started.
func runningGames(ctx context.Context, env *Env, except int) ([]runningGame, error) {
	games := allGames(env)
	units := []string{}
	byUnit := map[string]gameState{}
	for _, g := range games {
		if g.ID != except {
			units = append(units, g.unit())
			byUnit[g.unit()] = g
		}
	}
	out := []runningGame{}
	if len(units) == 0 {
		return out, nil
	}
	st, err := unitStates(ctx, env, units)
	if err != nil {
		return nil, err
	}
	for _, s := range st {
		switch s.Active {
		case "active", "activating", "reloading", "deactivating":
			g := byUnit[s.Unit]
			out = append(out, runningGame{ID: g.ID, Name: g.Name, UnitMB: gameUnitMemoryMB(env, g)})
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out, nil
}

// checkGameStart refuses to start a game server when the ones that run now
// and it together would need more than the games' budget: any one may have
// all of it, but not all of them at once.
func checkGameStart(ctx context.Context, env *Env, g gameState) error {
	budget := gamesBudgetMB(env)
	if budget == 0 {
		return nil
	}
	running, err := runningGames(ctx, env, g.ID)
	if err != nil {
		return err
	}
	need := gameUnitMemoryMB(env, g)
	used := 0
	names := []string{}
	for _, r := range running {
		used += r.UnitMB
		names = append(names, fmt.Sprintf("%s %d MB", r.Name, r.UnitMB))
	}
	if used+need <= budget {
		return nil
	}
	if len(running) == 0 {
		return protocol.Conflict(fmt.Sprintf("memory: this game server needs %d MB and game servers may use %d MB: lower its memory", need, budget))
	}
	return protocol.Conflict(fmt.Sprintf("memory: this game server needs %d MB; the running ones (%s) take %d MB of the %d MB game servers may use (%d MB free): stop one of them first",
		need, strings.Join(names, ", "), used, budget, max(budget-used, 0)))
}

// gameResources: the budget, the policy and what the running servers take.
func gameResources(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	return gamesResourcesView(ctx, env), nil
}

func gamesResourcesView(ctx context.Context, env *Env) map[string]any {
	p := loadGamesPolicy(env)
	total := hostMemoryMB()
	running, _ := runningGames(ctx, env, 0)
	used := 0
	for _, r := range running {
		used += r.UnitMB
	}
	servers := []map[string]any{}
	for _, g := range allGames(env) {
		servers = append(servers, map[string]any{"id": g.ID, "name": g.Name, "unit_mb": gameUnitMemoryMB(env, g)})
	}
	out := map[string]any{
		"ram_mb": total, "reserve_mb": p.ReserveMB, "auto_reserve_mb": 0, "budget_mb": gamesBudgetMB(env),
		"running_mb": used, "running": running, "servers": servers,
		"priority": p.Priority, "cpu_cores": p.CPUCores, "cpus": hostCPUs(),
	}
	if total > 0 {
		out["auto_reserve_mb"] = gamesReserveMB(total)
	}
	return out
}

// gameSetResources: a new policy; the slices take it at once (running
// servers too), and a budget now below what runs is reported, not enforced
// by stopping anything: the slice's limit makes the games share it.
func gameSetResources(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p gamesPolicy
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Priority == "" {
		p.Priority = "sites"
	}
	if err := p.validate(hostMemoryMB()); err != nil {
		return nil, err
	}
	if err := env.FS.MkdirAll(gameStateDir(env), 0o700, fsx.Root); err != nil {
		return nil, err
	}
	b, _ := json.MarshalIndent(p, "", "  ")
	if err := env.FS.WriteFile(gamesPolicyPath(env), b, 0o600, fsx.Root); err != nil {
		return nil, err
	}
	changed, err := writeGamesSlice(env)
	if err != nil {
		return nil, err
	}
	if changed && !env.Cfg.DryRun {
		if _, err := env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
			return nil, err
		}
	}
	return gamesResourcesView(ctx, env), nil
}

// wspanelSliceUnit: the games' parent slice with their CPU and disk weight.
func wspanelSliceUnit(p gamesPolicy) string {
	w := gamesWeights[p.Priority]
	return "# Managed by WSPanel agent. Do not edit: changes are overwritten by the panel.\n" +
		"[Unit]\nDescription=WSPanel game servers and their weight next to the sites\nBefore=slices.target\n\n[Slice]\n" +
		fmt.Sprintf("CPUWeight=%d\nIOWeight=%d\n", w, w)
}
