package ops

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

// TestMain keeps the host's own RAM out of the tests: the memory budget of
// game servers is checked only where a test sets hostMemoryMB.
func TestMain(m *testing.M) {
	hostMemoryMB = func() int { return 0 }
	// The app's config refresh in place, so a test sees what it ran.
	runAppRefresh = func(f func()) { f() }
	os.Exit(m.Run())
}

func withHostMemory(t *testing.T, mb int) {
	t.Helper()
	prev := hostMemoryMB
	hostMemoryMB = func() int { return mb }
	t.Cleanup(func() { hostMemoryMB = prev })
}

func TestGamesReserveKeepsAQuarterAndAtLeastOneAndAHalfGB(t *testing.T) {
	for total, want := range map[int]int{4096: 1536, 7936: 1984, 32768: 8192} {
		if got := gamesReserveMB(total); got != want {
			t.Errorf("reserve of %d MB = %d, want %d", total, got, want)
		}
	}
}

func TestOneGameMayHaveTheWholeBudgetButNoMore(t *testing.T) {
	fakePublishers(t)
	env, _ := gameTestEnv(t)
	withHostMemory(t, 8000) // keeps 2000 MB, games get 6000 MB
	for _, id := range []int{1, 2} {
		if err := saveGame(env, gameState{ID: id, Game: "minecraft", Flavor: "paper", MemoryMB: 2048}); err != nil {
			t.Fatal(err)
		}
	}
	// Others configured do not count: 4096×5/4+256 = 5376 MB fits alone.
	if err := checkGameMemory(env, gameState{ID: 3, Game: "minecraft", Flavor: "paper", MemoryMB: 4096}, 0); err != nil {
		t.Errorf("within the budget: %v", err)
	}
	err := checkGameMemory(env, gameState{ID: 3, Game: "minecraft", Flavor: "paper", MemoryMB: 4800}, 0)
	if err == nil || !strings.Contains(err.Error(), "would reserve 6256 MB") || !strings.Contains(err.Error(), "at most 6000 MB") {
		t.Fatalf("over the budget: %v", err)
	}
	// Lowering a limit is allowed even when it is still over a smaller budget.
	withHostMemory(t, 4000)
	if err := checkGameMemory(env, gameState{ID: 2, Game: "minecraft", Flavor: "paper", MemoryMB: 3000}, gameUnitMemoryMB(env, gameState{Game: "minecraft", MemoryMB: 4000})); err != nil {
		t.Errorf("lowering: %v", err)
	}
	// Unknown RAM (not Linux): nothing to check against.
	withHostMemory(t, 0)
	if err := checkGameMemory(env, gameState{ID: 9, Game: "minecraft", MemoryMB: 200000}, 0); err != nil {
		t.Errorf("unknown RAM: %v", err)
	}
}

// runningUnits answers systemctl show with the given units active.
func runningUnits(dr *executor.DryRun, active ...string) {
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if !strings.Contains(c.String(), "--property=Id,LoadState") {
			return executor.Result{}, nil
		}
		var b strings.Builder
		for _, a := range c.Args {
			if !strings.HasSuffix(a, ".service") {
				continue
			}
			state := "inactive"
			if slices.Contains(active, a) {
				state = "active"
			}
			fmt.Fprintf(&b, "Id=%s\nLoadState=loaded\nActiveState=%s\nSubState=x\nUnitFileState=enabled\n\n", a, state)
		}
		return executor.Result{Stdout: b.String()}, nil
	}
}

func TestAGameStartsOnlyWhenItFitsNextToTheRunningOnes(t *testing.T) {
	fakePublishers(t)
	env, dr := gameTestEnv(t)
	withHostMemory(t, 6096)                                                                                                                                           // keeps 1524→1536 MB, games get 4560 MB
	big := gameState{ID: 1, Name: "Create", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, RconPort: 27000, RconPassword: "p", MemoryMB: 3000}  // 4006 MB
	small := gameState{ID: 2, Name: "Lobby", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25566, RconPort: 27001, RconPassword: "p", MemoryMB: 1024} // 1536 MB
	for _, g := range []gameState{big, small} {
		if err := saveGame(env, g); err != nil {
			t.Fatal(err)
		}
	}
	runningUnits(dr, small.unit())
	_, err := call(t, env, gameAction, map[string]any{"id": 1, "action": "start"})
	if err == nil || !strings.Contains(err.Error(), "running ones (Lobby 1536 MB) take 1536 MB of the 4560 MB") || !strings.Contains(err.Error(), "(3024 MB free)") {
		t.Fatalf("started over the budget: %v", err)
	}
	for _, c := range dr.Calls() {
		if strings.Contains(c.String(), "systemctl start "+big.unit()) {
			t.Fatal("systemctl start ran")
		}
	}
	// Alone it has the whole budget.
	runningUnits(dr)
	if _, err := call(t, env, gameAction, map[string]any{"id": 1, "action": "start"}); err != nil {
		t.Fatalf("alone: %v", err)
	}
	// A running one restarts even after the budget shrank under it.
	runningUnits(dr, big.unit(), small.unit())
	if _, err := call(t, env, gameAction, map[string]any{"id": 1, "action": "restart"}); err != nil {
		t.Fatalf("restart of a running one: %v", err)
	}
}

func TestTheSitesReserveAndPriorityShapeTheSlices(t *testing.T) {
	fakePublishers(t)
	env, _ := gameTestEnv(t)
	withHostMemory(t, 8000)
	prev := hostCPUs
	hostCPUs = func() int { return 4 }
	t.Cleanup(func() { hostCPUs = prev })
	for _, bad := range []map[string]any{{"reserve_mb": 512}, {"reserve_mb": 7800}, {"priority": "web"}, {"cpu_cores": 5}} {
		if _, err := call(t, env, gameSetResources, bad); err == nil {
			t.Errorf("accepted %v", bad)
		}
	}
	out, err := call(t, env, gameSetResources, map[string]any{"reserve_mb": 4000, "priority": "sites", "cpu_cores": 2})
	if err != nil {
		t.Fatal(err)
	}
	if v := out.(map[string]any); v["budget_mb"] != 4000 || v["auto_reserve_mb"] != 2000 {
		t.Errorf("view %v", v)
	}
	slice := read2(t, filepath.Join(env.Cfg.SystemdDir, gamesSlice))
	for _, want := range []string{"MemoryMax=4000M", "CPUQuota=200%"} {
		if !strings.Contains(slice, want) {
			t.Errorf("games slice lacks %q:\n%s", want, slice)
		}
	}
	parent := read2(t, filepath.Join(env.Cfg.SystemdDir, wspanelSlice))
	if !strings.Contains(parent, "CPUWeight=20\nIOWeight=20") {
		t.Errorf("sites first: %s", parent)
	}
	if _, err := call(t, env, gameSetResources, map[string]any{"priority": "games"}); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(read2(t, filepath.Join(env.Cfg.SystemdDir, wspanelSlice)), "CPUWeight=400") {
		t.Error("games first")
	}
	if s := read2(t, filepath.Join(env.Cfg.SystemdDir, gamesSlice)); !strings.Contains(s, "MemoryMax=6000M") || strings.Contains(s, "CPUQuota") {
		t.Errorf("automatic reserve, no cap:\n%s", s)
	}
}

func TestGameUnitRunsInTheGamesSliceAndIsNotReloadedUnchanged(t *testing.T) {
	fakePublishers(t)
	env, dr := gameTestEnv(t)
	withHostMemory(t, 8000)
	g := gameState{ID: 3, Name: "F", Game: "factorio", Flavor: "stable", Version: "2.1.24", Port: 34197, RconPort: 27001, RconPassword: "pw", MemoryMB: 2048}
	if err := writeGameUnit(context.Background(), env, g); err != nil {
		t.Fatal(err)
	}
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, g.unit()))
	for _, want := range []string{"Slice=wspanel-games.slice", "OOMScoreAdjust=500", "RestartSec=10s", "RestartSteps=5", "RestartMaxDelaySec=5min", "StartLimitIntervalSec=0"} {
		if !strings.Contains(unit, want) {
			t.Errorf("unit lacks %q", want)
		}
	}
	slice := read2(t, filepath.Join(env.Cfg.SystemdDir, gamesSlice))
	for _, want := range []string{"[Slice]", "MemoryHigh=5700M", "MemoryMax=6000M", "MemorySwapMax=0"} {
		if !strings.Contains(slice, want) {
			t.Errorf("slice lacks %q:\n%s", want, slice)
		}
	}
	n := len(dr.Calls())
	if err := writeGameUnit(context.Background(), env, g); err != nil {
		t.Fatal(err)
	}
	if extra := dr.Calls()[n:]; len(extra) != 0 {
		t.Errorf("an unchanged unit ran %v", extra)
	}
}

func TestCronTaskUnitIsAOneshotWithoutRestartOrInstall(t *testing.T) {
	d := unitData{Description: "t", User: "web_x", Home: "/srv/sites/x", ExecStart: "/bin/true", StopTimeout: 30, Oneshot: true}
	b, err := render("unit.service.tmpl", d)
	if err != nil {
		t.Fatal(err)
	}
	unit := string(b)
	for _, want := range []string{"Type=oneshot", "TimeoutStartSec=1h"} {
		if !strings.Contains(unit, want) {
			t.Errorf("task unit lacks %q", want)
		}
	}
	for _, not := range []string{"Restart=", "StartLimitIntervalSec", "[Install]"} {
		if strings.Contains(unit, not) {
			t.Errorf("task unit has %q:\n%s", not, unit)
		}
	}
	d.Oneshot = false
	b, _ = render("unit.service.tmpl", d)
	for _, want := range []string{"Type=simple", "Restart=always", "RestartSec=2s", "RestartMaxDelaySec=30s", "WantedBy=multi-user.target"} {
		if !strings.Contains(string(b), want) {
			t.Errorf("service unit lacks %q", want)
		}
	}
}

// Games run in wspanel.slice/wspanel-games.slice (systemd nests by the dash):
// ending a game user's SFTP sessions must not kill its server there.
func TestAGameServerInTheSliceIsNotASessionProcess(t *testing.T) {
	env, _ := gameTestEnv(t)
	m := managedCgroups(env.Cfg, "", "wsg_4")
	for _, p := range []string{"/wspanel.slice/wspanel-games.slice/wspanel-game-4.service", "/system.slice/wspanel-game-4.service"} {
		if !m[p] {
			t.Errorf("%s counted as a session process", p)
		}
	}
}

// The slice never caps below the largest game: one Minecraft that ran with
// most of a small server's RAM before the budget existed still starts.
func TestGamesSliceIsNeverBelowTheLargestGame(t *testing.T) {
	fakePublishers(t)
	env, _ := gameTestEnv(t)
	withHostMemory(t, 4096) // budget 2560 MB
	if err := saveGame(env, gameState{ID: 1, Game: "minecraft", Flavor: "paper", MemoryMB: 2048}); err != nil {
		t.Fatal(err)
	}
	if got := gamesSliceMB(env); got != 2816 {
		t.Fatalf("slice %d MB, want the game's own 2816", got)
	}
}
