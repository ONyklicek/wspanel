package ops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// TestMain keeps the host's own RAM out of the tests: the memory budget of
// game servers is checked only where a test sets hostMemoryMB.
func TestMain(m *testing.M) {
	hostMemoryMB = func() int { return 0 }
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

func TestGameMemoryIsRefusedBeyondTheGamesBudget(t *testing.T) {
	fakePublishers(t)
	env, _ := gameTestEnv(t)
	withHostMemory(t, 8000) // keeps 2000 MB, games get 6000 MB
	for _, id := range []int{1, 2} {
		if err := saveGame(env, gameState{ID: id, Game: "minecraft", Flavor: "paper", MemoryMB: 2048}); err != nil {
			t.Fatal(err)
		}
	}
	// Two Minecraft servers already reserve 2 × (2048×5/4+256) = 5632 MB.
	third := gameState{ID: 3, Game: "minecraft", Flavor: "paper", MemoryMB: 1024}
	err := checkGameMemory(env, third, 0)
	if err == nil || !strings.Contains(err.Error(), "7168 MB together") || !strings.Contains(err.Error(), "368 MB left") {
		t.Fatalf("over the budget: %v", err)
	}
	third.MemoryMB = 64 // 336 MB: 5968 MB in all, fits
	if err := checkGameMemory(env, third, 0); err != nil {
		t.Errorf("within the budget: %v", err)
	}
	// Lowering a limit is allowed even on an over-committed server.
	withHostMemory(t, 4000)
	lower := gameState{ID: 2, Game: "minecraft", Flavor: "paper", MemoryMB: 1024}
	if err := checkGameMemory(env, lower, gameUnitMemoryMB(env, gameState{Game: "minecraft", MemoryMB: 2048})); err != nil {
		t.Errorf("lowering: %v", err)
	}
	// Unknown RAM (not Linux): nothing to check against.
	withHostMemory(t, 0)
	if err := checkGameMemory(env, gameState{ID: 9, Game: "minecraft", MemoryMB: 200000}, 0); err != nil {
		t.Errorf("unknown RAM: %v", err)
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
