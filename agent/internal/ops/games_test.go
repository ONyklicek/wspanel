package ops

import (
	"context"
	"encoding/binary"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

// fakePublishers: Paper's, Mojang's and Factorio's APIs, for one version each.
func fakePublishers(t *testing.T) {
	t.Helper()
	var srv *httptest.Server
	srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/paper":
			fmt.Fprint(w, `{"versions":{"1.21":["1.21.10","1.21.11-rc1"],"1.20":["1.20.4","1.20.6"],"26.3":["26.3"]}}`)
		case "/paper/versions/1.21.10/builds/latest":
			fmt.Fprint(w, `{"id":130,"downloads":{"server:default":{"checksums":{"sha256":"`+strings.Repeat("a", 64)+`"},"size":5,"url":"https://example.test/paper.jar"}}}`)
		case "/mojang":
			fmt.Fprintf(w, `{"versions":[{"id":"26.3","type":"release","url":"%s/26.3.json"},{"id":"26.4-snapshot-7","type":"snapshot"},{"id":"1.19.4","type":"release"}]}`, srv.URL)
		case "/26.3.json":
			fmt.Fprint(w, `{"javaVersion":{"majorVersion":25},"downloads":{"server":{"sha1":"`+strings.Repeat("b", 40)+`","url":"https://example.test/server.jar"}}}`)
		case "/api/latest-releases":
			fmt.Fprint(w, `{"stable":{"headless":"2.1.24"}}`)
		case "/download/sha256sums/":
			fmt.Fprint(w, strings.Repeat("c", 64)+"  factorio-headless_linux_2.1.24.tar.xz\n")
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(srv.Close)
	old := []string{paperAPI, mojangManifest, factorioAPI}
	paperAPI, mojangManifest, factorioAPI = srv.URL+"/paper", srv.URL+"/mojang", srv.URL
	catalogCache.data = nil
	t.Cleanup(func() { paperAPI, mojangManifest, factorioAPI = old[0], old[1], old[2]; catalogCache.data = nil })
}

func gameTestEnv(t *testing.T) (*Env, *executor.DryRun) {
	env, dr := testEnv(t)
	root := t.TempDir()
	env.Cfg.GamesRoot = filepath.Join(root, "srv/games")
	env.Cfg.GameBackupDir = filepath.Join(root, "backups")
	env.Cfg.JavaDir = filepath.Join(root, "jvm")
	return env, dr
}

func TestGameCatalogOffersWhatDebianCanRun(t *testing.T) {
	fakePublishers(t)
	env, _ := gameTestEnv(t)
	out, err := call(t, env, gameCatalog, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	mc := out.(map[string]any)["minecraft"].(map[string][]string)
	if strings.Join(mc["paper"], ",") != "26.3,1.21.10,1.20.6" {
		t.Errorf("paper (no rc, nothing before 1.20.5): %v", mc["paper"])
	}
	if strings.Join(mc["vanilla"], ",") != "26.3" {
		t.Errorf("vanilla (releases from 1.20.5): %v", mc["vanilla"])
	}
}

func TestMinecraftJavaByVersion(t *testing.T) {
	for v, want := range map[string]int{"1.20.4": 0, "1.20.5": 21, "1.21.10": 21, "26.3": 25, "1.19": 0} {
		if got := mcJava(v); got != want {
			t.Errorf("%s: Java %d, want %d", v, got, want)
		}
	}
}

func TestGameDownloadsCarryTheirChecksums(t *testing.T) {
	fakePublishers(t)
	d, err := resolveGameDownload(context.Background(), "minecraft", "paper", "1.21.10")
	if err != nil || d.Build != "130" || d.Sum != strings.Repeat("a", 64) || d.Java != 21 {
		t.Errorf("paper: %+v %v", d, err)
	}
	d, err = resolveGameDownload(context.Background(), "minecraft", "vanilla", "26.3")
	if err != nil || d.Sum != "sha1:"+strings.Repeat("b", 40) || d.Java != 25 {
		t.Errorf("vanilla: %+v %v", d, err)
	}
	d, err = resolveGameDownload(context.Background(), "factorio", "stable", "2.1.24")
	if err != nil || d.Sum != strings.Repeat("c", 64) || !strings.HasSuffix(d.URL, "/get-download/2.1.24/headless/linux64") {
		t.Errorf("factorio: %+v %v", d, err)
	}
	if _, err := resolveGameDownload(context.Background(), "factorio", "stable", "2.1.99"); err == nil {
		t.Error("a version without a published checksum must not be downloaded")
	}
}

func TestGameCreateRefusesBadParams(t *testing.T) {
	fakePublishers(t)
	env, dr := gameTestEnv(t)
	base := map[string]any{"id": 7, "name": "Survival", "game": "minecraft", "flavor": "paper", "version": "1.21.10", "memory_mb": 2048, "eula": true}
	with := func(k string, v any) map[string]any {
		m := map[string]any{}
		for a, b := range base {
			m[a] = b
		}
		m[k] = v
		return m
	}
	for _, bad := range []map[string]any{
		with("eula", false), with("version", "1.19.4"), with("version", "1.21; rm"), with("game", "doom"), with("flavor", "forge"),
		with("memory_mb", 256), with("port", 22), with("port", 700), with("port", 80), with("name", "a\"b"), with("cpu_percent", -1), with("group", "root"),
	} {
		if _, err := call(t, env, gameCreate, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	if len(dr.Calls()) != 0 {
		t.Errorf("nothing may run for a refused server: %v", dr.Calls())
	}
}

func TestGameCreateSetsUpASandboxedServer(t *testing.T) {
	fakePublishers(t)
	env, dr := gameTestEnv(t)
	out, err := call(t, env, gameCreate, map[string]any{"id": 7, "name": "Survival", "game": "minecraft", "flavor": "paper", "version": "1.21.10", "memory_mb": 2048, "cpu_percent": 200, "eula": true})
	if err != nil {
		t.Fatal(err)
	}
	if m := out.(map[string]any); m["port"] != 25565 || m["proto"] != "tcp" || m["build"] != "130" {
		t.Errorf("view: %v", m)
	}
	g, err := loadGame(env, 7)
	if err != nil || g.RconPort < 27000 || len(g.RconPassword) != 48 || g.Java != 21 {
		t.Fatalf("state: %+v %v", g, err)
	}
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-game-7.service"))
	for _, want := range []string{"User=wsg_7", "SocketBindAllow=tcp:25565", fmt.Sprintf("SocketBindAllow=tcp:%d", g.RconPort), "SocketBindDeny=any",
		"MemoryMax=2816M", "CPUQuota=200%", "-Xmx2048M", "ReadWritePaths=" + gameHome(env, 7), "ProtectSystem=strict", "WorkingDirectory=" + gameHome(env, 7) + "/data"} {
		if !strings.Contains(unit, want) {
			t.Errorf("unit lacks %q:\n%s", want, unit)
		}
	}
	if fi, _ := os.Stat(filepath.Join(env.Cfg.SystemdDir, "wspanel-game-7.service")); fi.Mode().Perm() != 0o600 {
		t.Errorf("the unit is root's only: %v", fi.Mode())
	}
	guard := read2(t, filepath.Join(gameStateDir(env), "rcon-guard.nft"))
	if !strings.Contains(guard, fmt.Sprintf("tcp dport { %d } meta skuid != 0 reject", g.RconPort)) {
		t.Errorf("rcon guard:\n%s", guard)
	}
	calls := []string{}
	for _, c := range dr.Calls() {
		calls = append(calls, c.String())
	}
	all := strings.Join(calls, "\n")
	for _, want := range []string{"useradd --system --user-group --no-create-home --home-dir " + gameHome(env, 7), "ufw allow 25565/tcp comment wspanel game 7", "systemctl enable --now wspanel-game-7.service"} {
		if !strings.Contains(all, want) {
			t.Errorf("missing %q in:\n%s", want, all)
		}
	}
	// A second server cannot take the port, and the id is taken.
	if _, err := call(t, env, gameCreate, map[string]any{"id": 8, "name": "B", "game": "minecraft", "flavor": "paper", "version": "1.21.10", "memory_mb": 2048, "eula": true}); code(err) != "conflict" {
		t.Errorf("the same port twice: %v", err)
	}
	if _, err := call(t, env, gameCreate, map[string]any{"id": 7, "name": "B", "game": "minecraft", "flavor": "paper", "version": "1.21.10", "port": 25566, "memory_mb": 2048, "eula": true}); code(err) != "conflict" {
		t.Errorf("the same id twice: %v", err)
	}
	// Deleting takes the rule, the user and the guard entry away.
	n := len(dr.Calls())
	if _, err := call(t, env, gameDelete, map[string]any{"id": 7}); err != nil {
		t.Fatal(err)
	}
	after := []string{}
	for _, c := range dr.Calls()[n:] {
		after = append(after, c.String())
	}
	if !strings.Contains(strings.Join(after, "\n"), "ufw delete allow 25565/tcp") || fileExists(gameHome(env, 7)) {
		t.Errorf("delete: %v", after)
	}
	if strings.Contains(read2(t, filepath.Join(gameStateDir(env), "rcon-guard.nft")), "dport") {
		t.Error("no RCON ports left to guard")
	}
}

func TestFactorioUnitBindsUDPAndSavesOnStop(t *testing.T) {
	fakePublishers(t)
	env, _ := gameTestEnv(t)
	g := gameState{ID: 3, Name: "F", Game: "factorio", Flavor: "stable", Version: "2.1.24", Port: 34197, RconPort: 27001, RconPassword: "pw", MemoryMB: 2048}
	if err := writeGameUnit(context.Background(), env, g); err != nil {
		t.Fatal(err)
	}
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, g.unit()))
	for _, want := range []string{"SocketBindAllow=udp:34197", "KillSignal=SIGINT", "--rcon-bind 127.0.0.1:27001", "MemoryMax=2048M"} {
		if !strings.Contains(unit, want) {
			t.Errorf("unit lacks %q", want)
		}
	}
}

func TestSetPropertiesKeepsTheGamesOwnSettings(t *testing.T) {
	in := "#Minecraft server properties\nmotd=Hi\nserver-port=1\nrcon.port=2\nrcon.port=3\npvp=false\n"
	got := setProperties(in, map[string]string{"server-port": "25565", "rcon.port": "27000", "enable-rcon": "true"})
	want := "#Minecraft server properties\nmotd=Hi\nserver-port=25565\nrcon.port=27000\npvp=false\nenable-rcon=true\n"
	if got != want {
		t.Errorf("got:\n%s", got)
	}
}

// A minimal Source RCON server.
func fakeRcon(t *testing.T, password string, answer func(string) string) int {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func(c net.Conn) {
				defer c.Close()
				for {
					var size, id, typ int32
					if binary.Read(c, binary.LittleEndian, &size) != nil {
						return
					}
					binary.Read(c, binary.LittleEndian, &id)
					binary.Read(c, binary.LittleEndian, &typ)
					body := make([]byte, size-8)
					io.ReadFull(c, body)
					text := strings.TrimRight(string(body), "\x00")
					reply := func(id, typ int32, s string) {
						binary.Write(c, binary.LittleEndian, int32(10+len(s)))
						binary.Write(c, binary.LittleEndian, id)
						binary.Write(c, binary.LittleEndian, typ)
						c.Write(append([]byte(s), 0, 0))
					}
					if typ == 3 {
						if text != password {
							reply(-1, 2, "")
							return
						}
						reply(id, 2, "")
						continue
					}
					reply(id, 0, answer(text))
				}
			}(c)
		}
	}()
	return ln.Addr().(*net.TCPAddr).Port
}

func TestRconAndPlayers(t *testing.T) {
	port := fakeRcon(t, "secret", func(cmd string) string {
		if cmd == "list" {
			return "There are 2 of a max of 20 players online: §aAlice, Bob"
		}
		return "ran " + cmd
	})
	if out, err := rconExec(context.Background(), port, "secret", "say hi"); err != nil || out != "ran say hi" {
		t.Errorf("exec: %q %v", out, err)
	}
	if _, err := rconExec(context.Background(), port, "wrong", "list"); err == nil || !strings.Contains(err.Error(), "wrong password") {
		t.Errorf("wrong password: %v", err)
	}
	p, err := gamePlayers(context.Background(), gameState{Game: "minecraft", RconPort: port, RconPassword: "secret"})
	if err != nil || p["online"] != 2 || p["max"] != 20 || strings.Join(p["names"].([]string), ",") != "Alice,Bob" {
		t.Errorf("players: %v %v", p, err)
	}
}

func TestGameCommandIsOneLine(t *testing.T) {
	fakePublishers(t)
	env, _ := gameTestEnv(t)
	saveGame(env, gameState{ID: 5, Name: "x", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, RconPort: 27000, RconPassword: "p", MemoryMB: 2048})
	for _, bad := range []string{"", "say a\nstop", strings.Repeat("x", 1001)} {
		if _, err := call(t, env, gameCommand, map[string]any{"id": 5, "command": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, gameCommand, map[string]any{"id": 6, "command": "list"}); code(err) != "not_found" {
		t.Errorf("unknown server: %v", err)
	}
}

func TestGameRestoreTakesOnlyItsOwnBackups(t *testing.T) {
	env, _ := gameTestEnv(t)
	saveGame(env, gameState{ID: 5, Name: "x", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, RconPort: 27000, RconPassword: "p", MemoryMB: 2048})
	for _, bad := range []string{"../5/x.tar.gz", "game-6-20261109T120000Z.tar.gz", "game-5-x.tar.gz"} {
		if _, err := call(t, env, gameRestore, map[string]any{"id": 5, "file": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, gameUpdate, map[string]any{"id": 5, "version": "1.20.6"}); code(err) != "invalid_params" {
		t.Errorf("a world cannot go back to an older Minecraft: %v", err)
	}
}

func TestServerBackupLeavesGameServersOut(t *testing.T) {
	env, _ := gameTestEnv(t)
	for path, skip := range map[string]bool{
		gameStateDir(env):                                                 true,
		filepath.Join(gameStateDir(env), "7.json"):                        true,
		filepath.Join(env.Cfg.SystemdDir, "wspanel-game-7.service"):       true,
		filepath.Join(env.Cfg.SystemdDir, "wspanel-games-rcon.service"):   true,
		filepath.Join(env.Cfg.SystemdDir, "wspanel-web_x-queue@.service"): false,
		filepath.Join(env.Cfg.StateDir, "sites"):                          false,
	} {
		if got := skipServerPath(env, path); got != skip {
			t.Errorf("%s: skipped %v, want %v", path, got, skip)
		}
	}
}

func TestGameBackupsGoOffsiteAndComeBack(t *testing.T) {
	env, fake, url := offsiteEnv(t)
	root := t.TempDir()
	env.Cfg.GamesRoot, env.Cfg.GameBackupDir = filepath.Join(root, "games"), filepath.Join(root, "gb")
	if _, err := setOffsite(t, env, url, nil); err != nil {
		t.Fatal(err)
	}
	if err := saveGame(env, gameState{ID: 5, Name: "x", Game: "factorio", Flavor: "stable", Version: "2.1.24", Port: 34197, RconPort: 27000, RconPassword: "p", MemoryMB: 2048}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, gameBackup, map[string]any{"id": 5})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	file := m["file"].(string)
	if m["offsite"] != "srv1/games/5/"+file || m["offsite_error"] != nil || !gameBackupRe.MatchString(file) {
		t.Fatalf("backup: %v", m)
	}
	if _, ok := fake.Objects["srv1/games/5/"+file+".sig"]; !ok {
		t.Fatal("the copy is signed")
	}
	list, err := call(t, env, offsiteList, map[string]any{"game": 5})
	if err != nil || len(list.(map[string]any)["objects"].([]map[string]any)) != 1 {
		t.Fatalf("list: %v %v", list, err)
	}
	os.Remove(filepath.Join(gameBackupDir(env, 5), file))
	if _, err := call(t, env, offsiteFetch, map[string]any{"game": 5, "file": file}); err != nil {
		t.Fatal(err)
	}
	if !fileExists(filepath.Join(gameBackupDir(env, 5), file)) {
		t.Fatal("fetched back")
	}
	if _, err := call(t, env, offsiteFetch, map[string]any{"game": 5, "file": "game-6-20261109T120000Z.tar.gz"}); code(err) != "invalid_params" {
		t.Errorf("another server's backup: %v", err)
	}
	if _, err := call(t, env, offsiteList, map[string]any{"game": 9}); code(err) != "not_found" {
		t.Errorf("unknown server: %v", err)
	}
}

func TestGamePortsStayInTheirRangeAndAnAdminsRuleStays(t *testing.T) {
	fakePublishers(t)
	env, dr := gameTestEnv(t)
	base := map[string]any{"id": 7, "name": "S", "game": "minecraft", "flavor": "paper", "version": "1.21.10", "memory_mb": 2048, "eula": true}
	for _, port := range []int{3306, 5432, 6379, 8025, 27017, 27500, 19999, 40001} {
		p := map[string]any{"port": port}
		for k, v := range base {
			p[k] = v
		}
		if _, err := call(t, env, gameCreate, p); code(err) != "invalid_params" {
			t.Errorf("port %d must be refused: %v", port, err)
		}
	}
	// The admin opened 25565 themselves: the panel leaves that rule alone.
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.String() == "ufw status" {
			return executor.Result{Stdout: "Status: active\n\nTo                         Action      From\n--                         ------      ----\n25565/tcp                  ALLOW       Anywhere\n"}, nil
		}
		return executor.Result{}, nil
	}
	if _, err := call(t, env, gameCreate, base); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, gameDelete, map[string]any{"id": 7}); err != nil {
		t.Fatal(err)
	}
	for _, c := range dr.Calls() {
		if c.Name == "ufw" && c.Args[0] != "status" {
			t.Errorf("the admin's rule was touched: %v", c)
		}
	}
}

func TestARuleOfAServerFromBeforeOwnershipIsRemovedByItsComment(t *testing.T) {
	env, dr := gameTestEnv(t)
	saveGame(env, gameState{ID: 3, Name: "old", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, RconPort: 27000, RconPassword: "p", MemoryMB: 2048})
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.String() == "ufw status" {
			return executor.Result{Stdout: "Status: active\n\nTo                         Action      From\n--                         ------      ----\n25565/tcp                  ALLOW       Anywhere                   # wspanel game 3\n"}, nil
		}
		return executor.Result{}, nil
	}
	if _, err := call(t, env, gameDelete, map[string]any{"id": 3}); err != nil {
		t.Fatal(err)
	}
	found := false
	for _, c := range dr.Calls() {
		found = found || c.String() == "ufw delete allow 25565/tcp"
	}
	if !found {
		t.Error("the panel's rule of an older server must go with it")
	}
}

func TestGameSFTPIsSFTPOnlyWithRootOwnedKeys(t *testing.T) {
	env, dr := gameTestEnv(t)
	saveGame(env, gameState{ID: 3, Name: "S", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, RconPort: 27000, RconPassword: "p", MemoryMB: 2048})
	key := "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl me@pc"
	for _, bad := range []map[string]any{
		{"enabled": true}, // nothing to log in with
		{"enabled": true, "keys": []string{`command="x" ` + key}},
		{"enabled": true, "password_mode": "set", "password": "short"},
	} {
		bad["id"] = 3
		if _, err := call(t, env, gameSFTP, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	out, err := call(t, env, gameSFTP, map[string]any{"id": 3, "enabled": true, "keys": []string{key}})
	if err != nil {
		t.Fatal(err)
	}
	if m := out.(map[string]any); m["sftp"] != true || m["user"] != "wsg_3" || m["password"] != false {
		t.Errorf("out: %v", m)
	}
	if got := read2(t, filepath.Join(env.Cfg.AuthorizedKeysDir, "wsg_3")); !strings.Contains(got, "restrict "+key) || strings.Contains(got, "pty") {
		t.Errorf("keys file:\n%s", got)
	}
	all := []string{}
	for _, c := range dr.Calls() {
		all = append(all, c.String())
	}
	if !strings.Contains(strings.Join(all, "\n"), "usermod --groups "+env.Cfg.SFTPGroup+" --shell /usr/sbin/nologin wsg_3") {
		t.Errorf("calls: %v", all)
	}
	if g, _ := loadGame(env, 3); !g.SFTP {
		t.Error("state")
	}
	if _, err := call(t, env, gameSFTP, map[string]any{"id": 3, "enabled": false}); err != nil {
		t.Fatal(err)
	}
	if fileExists(filepath.Join(env.Cfg.AuthorizedKeysDir, "wsg_3")) {
		t.Error("keys removed when off")
	}
}

func TestEndingSFTPSessionsSparesTheGameServer(t *testing.T) {
	env, _ := gameTestEnv(t)
	m := managedCgroups(env.Cfg, "", "wsg_3")
	if !m["/system.slice/wspanel-game-3.service"] {
		t.Error("the game's own unit is the user's managed one")
	}
}
