package ops

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strconv"

	"wspanel/agent/internal/fsx"
)

// restoreGames builds the game servers of a restored server again: their
// state came back with the server's backup, their users with its accounts.
// Each gets its runtime, the game itself (downloaded again: a game's backup
// holds its data, not the server's files), the newest backup of its data,
// its unit and firewall rule, and starts when it started with the old
// server. One that cannot be built is reported; the others go on.
func restoreGames(ctx context.Context, env *Env, man serverManifest, src backupSource, rep *RestoreReport, logf func(string, ...any)) {
	for _, g := range allGames(env) {
		file, err := src.newest(ctx, gameTarget(env, g.ID))
		if err != nil {
			rep.problem("game server %d (%s): %v", g.ID, g.Name, err)
			continue
		}
		logf("game server %d (%s)", g.ID, g.Name)
		start := slices.Contains(man.GameUnits, g.unit())
		if err := rebuildGame(ctx, env, g, file, start); err != nil {
			rep.problem("game server %d (%s): %v", g.ID, g.Name, err)
			continue
		}
		if file == "" {
			rep.problem("game server %d (%s): no backup of its data, it starts with a new world", g.ID, g.Name)
			continue
		}
		rep.ok("game server %d (%s, %s)", g.ID, g.Name, file)
	}
}

// rebuildGame: a game server from its state, with the data of the given
// backup (none: what the game makes on its first start).
func rebuildGame(ctx context.Context, env *Env, g gameState, backup string, start bool) error {
	def, ok := gameDefOf(env, g.Game)
	if !ok {
		return fmt.Errorf("the game %q is not on this server (install its package in Server → Herní balíčky, then restore the backup)", g.Game)
	}
	src, err := gameSourceOf(env, g)
	if err != nil {
		return err
	}
	dl, err := resolveGameDownload(ctx, src, g.Version)
	if err != nil {
		return fmt.Errorf("version %s: %w", g.Version, err)
	}
	switch {
	case def.Engine == "minecraft":
		java := g.Java
		if java == 0 {
			java = dl.Java
		}
		if _, err := ensureJava(ctx, env, java); err != nil {
			return err
		}
	case src.Type == "steamcmd":
		if err := ensureSteamDeps(ctx, env); err != nil {
			return err
		}
	}
	owner, err := lookupOwner(env, g.user())
	if err != nil {
		return fmt.Errorf("its user %s: %w", g.user(), err)
	}
	home := gameHome(env, g.ID)
	if err := env.FS.MkdirAll(env.Cfg.GamesRoot, 0o751, fsx.Root); err != nil {
		return err
	}
	for _, d := range []string{home, filepath.Join(home, "server"), filepath.Join(home, "data"), filepath.Join(home, "tmp")} {
		if err := env.FS.MkdirAll(d, 0o750, owner); err != nil {
			return err
		}
	}
	if err := placeDownload(ctx, env, g, dl); err != nil {
		return err
	}
	if src.Type == "steamcmd" {
		g.Build = steamBuildID(env, g, src)
	}
	if backup != "" {
		f, err := os.Open(filepath.Join(gameBackupDir(env, g.ID), backup))
		if err != nil {
			return err
		}
		args := append(userCreds(g.user()), "/usr/bin/tar", "-xzf", "-", "-C", home, "--no-same-owner", "--no-same-permissions", "data")
		_, err = env.Exec.RunIO(ctx, f, nil, "setpriv", args...)
		f.Close()
		if err != nil {
			return fmt.Errorf("its data from %s: %w", backup, err)
		}
	}
	// The licence was agreed to when this server was made (Minecraft's EULA).
	if err := configureGame(ctx, env, g, true); err != nil {
		return err
	}
	if err := withMutate(env, func() error {
		if err := saveGame(env, g); err != nil {
			return err
		}
		if err := applyRconGuard(ctx, env); err != nil {
			return err
		}
		if err := writeGameUnit(ctx, env, g); err != nil {
			return err
		}
		if g.OwnsFirewall && !ufwAllows(ctx, env, g.Port, gameProto(env, g)) {
			if _, err := env.Exec.Run(ctx, "ufw", "allow", fmt.Sprintf("%d/%s", g.Port, gameProto(env, g)), "comment", "wspanel game "+strconv.Itoa(g.ID)); err != nil {
				return err
			}
		}
		if start {
			_, err := env.Exec.Run(ctx, "systemctl", "enable", "--now", g.unit())
			return err
		}
		return nil
	}); err != nil {
		return err
	}
	return nil
}
