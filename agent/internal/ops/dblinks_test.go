package ops

import (
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestASharedDatabaseGivesTheGameAndTheSiteTheirOwnLogins(t *testing.T) {
	env, dr := dbEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(strings.Join(c.Args, " "), "/usr/bin/cat") {
			return executor.Result{Stdout: "APP_NAME=Shop\nDB_DATABASE=shop\nDB_PASSWORD=own\n"}, nil
		}
		return frankenVersion(c)
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	env.Cfg.GamesRoot = t.TempDir()
	if err := saveGame(env, gameState{ID: 2, Name: "Create", Game: "minecraft", Flavor: "neoforge", MemoryMB: 4096}); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []map[string]any{
		{"engine": "postgresql", "name": "stats", "game": 2},
		{"engine": "mariadb", "name": "stats"},
		{"engine": "mariadb", "name": "stats", "game": 2, "domain": "shop.example.com"},
		{"engine": "mariadb", "name": "stats", "domain": "shop.example.com", "prefix": "DB"},
		{"engine": "mariadb", "name": "stats", "game": 9},
	} {
		if _, err := call(t, env, dbLinkCreate, bad); err == nil {
			t.Errorf("accepted %v", bad)
		}
	}
	out, err := call(t, env, dbLinkCreate, map[string]any{"engine": "mariadb", "name": "stats", "game": 2})
	if err != nil {
		t.Fatal(err)
	}
	game := out.(map[string]any)
	if game["jdbc"] != "jdbc:mariadb://127.0.0.1:3306/stats" || !strings.HasPrefix(game["user"].(string), "wsl_") || len(game["password"].(string)) < 20 {
		t.Errorf("game login %v", game)
	}
	if _, err := call(t, env, dbLinkCreate, map[string]any{"engine": "mariadb", "name": "stats", "domain": "shop.example.com", "prefix": "MC_DB"}); err != nil {
		t.Fatal(err)
	}
	var sql, written string
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" {
			sql += c.Stdin
		}
		if c.Name == "setpriv" && strings.Contains(strings.Join(c.Args, " "), "/usr/bin/tee ") {
			written = c.Stdin
		}
	}
	// From this server only (socket and 127.0.0.1), whatever the published server allows.
	if n := strings.Count(sql, "CREATE USER IF NOT EXISTS '"+game["user"].(string)+"'@"); n != 2 || !strings.Contains(sql, "'"+game["user"].(string)+"'@'127.0.0.1'") {
		t.Errorf("hosts of the game's login (%d):\n%s", n, sql)
	}
	if !strings.Contains(sql, "GRANT ALL PRIVILEGES ON `stats`.* TO '"+game["user"].(string)+"'@'localhost'") {
		t.Errorf("grant on that database only:\n%s", sql)
	}
	for _, want := range []string{"DB_PASSWORD=own", `MC_DB_HOST="127.0.0.1"`, `MC_DB_DATABASE="stats"`, `MC_DB_USERNAME="wsl_`} {
		if !strings.Contains(written, want) {
			t.Errorf("site env lacks %q:\n%s", want, written)
		}
	}
	if links := loadDBLinks(env); len(links) != 2 || links[0].User == links[1].User {
		t.Fatalf("two logins: %+v", links)
	}
	// The database going away takes both logins with it.
	if _, err := call(t, env, dbDrop, map[string]string{"engine": "mariadb", "name": "stats", "confirm": "stats"}); err != nil {
		t.Fatal(err)
	}
	if links := loadDBLinks(env); len(links) != 0 {
		t.Errorf("left after the drop: %+v", links)
	}
}
