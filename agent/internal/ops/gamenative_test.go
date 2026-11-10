package ops

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/audit"
	"wspanel/agent/internal/protocol"
)

// cs2Env: an amd64 server with the catalog's Counter-Strike 2 package.
func cs2Env(t *testing.T) *Env {
	t.Helper()
	old := hostArch
	hostArch = "amd64"
	t.Cleanup(func() { hostArch = old })
	env, _ := gameTestEnv(t)
	b, err := os.ReadFile("../../../games/catalog/cs2.json")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, gamePackageUpload, map[string]string{"manifest": string(b)}); err != nil {
		t.Fatalf("the catalog's CS2 package: %v", err)
	}
	return env
}

func TestCounterStrike2IsAPackage(t *testing.T) {
	env := cs2Env(t)
	out, err := call(t, env, gameCatalog, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	if v := out.(map[string]any)["cs2"].(map[string][]string)["public"]; strings.Join(v, ",") != "latest" {
		t.Errorf("versions: %v", v)
	}
	g := out.(map[string]any)["games"].(map[string]any)["cs2"].(map[string]any)
	if g["engine"] != "native" || g["proto"] != "udp" || g["available"] != true {
		t.Errorf("game: %v", g)
	}

	created, err := call(t, env, gameCreate, map[string]any{"id": 9, "name": "Pražský 100% server", "game": "cs2", "flavor": "public", "version": "latest", "memory_mb": 4096})
	if err != nil {
		t.Fatal(err)
	}
	if created.(map[string]any)["console"] != true {
		t.Errorf("view: %v", created)
	}
	st, _ := loadGame(env, 9)
	if st.Port != 28015 || st.RconPort != 28015 {
		t.Errorf("Source RCON is TCP on the game's port: %+v", st)
	}
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-game-9.service"))
	for _, want := range []string{
		`ExecStart="` + gameHome(env, 9) + `/server/game/bin/linuxsteamrt64/cs2" "-dedicated" "-usercon" "-port" "28015" "-maxplayers" "10"`,
		`"+game_alias" "competitive" "+map" "de_dust2" "+hostname" "Pražský 100%% server" "+rcon_password" "` + st.RconPassword + `"`,
		"SocketBindAllow=udp:28015", "SocketBindAllow=tcp:28015", "KillSignal=SIGINT", "ProtectProc=invisible", "MemoryMax=4096M",
	} {
		if !strings.Contains(unit, want) {
			t.Errorf("unit lacks %q:\n%s", want, unit)
		}
	}
	if strings.Contains(unit, "sv_setsteamaccount") || strings.Contains(unit, "sv_password") {
		t.Error("an argument for a setting nobody set")
	}

	// Settings: checked against the package's rules; secrets apart, never shown.
	if _, err := call(t, env, gameSetOptions, map[string]any{"id": 9, "values": map[string]string{"map": "de_dust2; quit"}}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("a map the pattern refuses: %v", err)
	}
	if _, err := call(t, env, gameSetOptions, map[string]any{"id": 9, "values": map[string]string{"gslt": "ABC"}}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("a secret among the values: %v", err)
	}
	if _, err := call(t, env, gameSetOptions, map[string]any{"id": 9, "values": map[string]string{"max_players": "65"}}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("over the maximum: %v", err)
	}
	token := strings.Repeat("AB12", 8)
	if _, err := call(t, env, gameSetOptions, map[string]any{"id": 9, "values": map[string]string{"map": "de_mirage", "mode": "wingman"}, "secrets": map[string]string{"gslt": token}}); err != nil {
		t.Fatal(err)
	}
	unit = read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-game-9.service"))
	if !strings.Contains(unit, `"+sv_setsteamaccount" "`+token+`"`) || !strings.Contains(unit, `"+map" "de_mirage"`) || !strings.Contains(unit, `"+game_alias" "wingman"`) {
		t.Errorf("unit after settings:\n%s", unit)
	}
	opts, _ := call(t, env, gameOptions, map[string]any{"id": 9})
	b, _ := json.Marshal(opts)
	if strings.Contains(string(b), token) || !strings.Contains(string(b), `"secrets":{"gslt":true,"password":false}`) {
		t.Errorf("options show a secret or hide that it is set: %s", b)
	}
	// An empty secret keeps it; clear removes it.
	_, _ = call(t, env, gameSetOptions, map[string]any{"id": 9, "values": map[string]string{}, "secrets": map[string]string{"gslt": ""}})
	if !strings.Contains(read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-game-9.service")), token) {
		t.Error("an empty secret dropped the token")
	}
	_, _ = call(t, env, gameSetOptions, map[string]any{"id": 9, "values": map[string]string{}, "clear": []string{"gslt"}})
	if strings.Contains(read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-game-9.service")), "sv_setsteamaccount") {
		t.Error("clear left the token")
	}
	red := string(audit.Redact("game.set_options", json.RawMessage(`{"id":9,"values":{"map":"de_nuke"},"secrets":{"gslt":"`+token+`"}}`)))
	if strings.Contains(red, token) || !strings.Contains(red, "de_nuke") {
		t.Errorf("audit: %s", red)
	}

	// Steam has one version, its newest build.
	if _, err := call(t, env, gameUpdate, map[string]any{"id": 9, "version": "1.0"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("a version Steam does not have: %v", err)
	}
	if _, err := call(t, env, gameUpdate, map[string]any{"id": 9}); err != nil {
		t.Errorf("update: %v", err)
	}
}

func TestANativeGameIsBusyWhileItInstalls(t *testing.T) {
	env := cs2Env(t)
	if _, err := call(t, env, gameCreate, map[string]any{"id": 4, "name": "A", "game": "cs2", "flavor": "public", "version": "latest", "memory_mb": 4096}); err != nil {
		t.Fatal(err)
	}
	release, err := claimGame(4)
	if err != nil {
		t.Fatal(err)
	}
	defer release()
	for name, c := range map[string]struct {
		op     Handler
		params map[string]any
	}{
		"delete":  {gameDelete, map[string]any{"id": 4}},
		"restore": {gameRestore, map[string]any{"id": 4, "file": "game-4-20261110T040000Z.tar.gz"}},
		"options": {gameSetOptions, map[string]any{"id": 4, "values": map[string]string{}}},
		"update":  {gameUpdate, map[string]any{"id": 4}},
	} {
		if _, err := call(t, env, c.op, c.params); code(err) != protocol.CodeConflict {
			t.Errorf("%s while busy: %v", name, err)
		}
	}
}

func TestANativeGameWithoutAConsoleTakesNoCommands(t *testing.T) {
	old := hostArch
	hostArch = "amd64"
	t.Cleanup(func() { hostArch = old })
	env, _ := gameTestEnv(t)
	pkg := `{"id": "quiet", "name": "Quiet", "version": "1", "game": {"id": "quiet", "label": "Quiet", "engine": "native", "proto": "udp",
	  "default_port": 30000, "min_memory_mb": 512, "arch": ["amd64"], "run": {"command": "bin/server", "args": ["--port={port}"]}},
	  "flavors": [{"id": "public", "label": "Steam", "source": {"type": "steamcmd", "app_id": 4020}}]}`
	if _, err := call(t, env, gamePackageUpload, map[string]string{"manifest": pkg}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, gameCreate, map[string]any{"id": 2, "name": "Q", "game": "quiet", "flavor": "public", "version": "latest", "memory_mb": 1024}); err != nil {
		t.Fatal(err)
	}
	if st, _ := loadGame(env, 2); st.RconPort != 0 {
		t.Errorf("no console, no RCON port: %+v", st)
	}
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-game-2.service"))
	if !strings.Contains(unit, `"--port=30000"`) || strings.Contains(unit, "SocketBindAllow=tcp") {
		t.Errorf("unit:\n%s", unit)
	}
	if _, err := call(t, env, gameCommand, map[string]any{"id": 2, "command": "status"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("a command without a console: %v", err)
	}
	if v, _ := call(t, env, gameStatus, map[string]any{"id": 2}); v.(map[string]any)["console"] != false {
		t.Errorf("status: %v", v)
	}
}

func TestARunSpecIsCheckedBeforeItCounts(t *testing.T) {
	base := func(run string) string {
		return `{"id": "x-game", "name": "X", "version": "1", "game": {"id": "xgame", "label": "X", "engine": "native", "proto": "udp",
		  "default_port": 30000, "min_memory_mb": 512, "arch": ["amd64"], "run": ` + run + `},
		  "flavors": [{"id": "public", "label": "Steam", "source": {"type": "steamcmd", "app_id": 1}}]}`
	}
	for name, run := range map[string]string{
		"a path out of the server":    `{"command": "../../bin/sh", "args": []}`,
		"an absolute path":            `{"command": "/bin/sh", "args": []}`,
		"an unknown placeholder":      `{"command": "srv", "args": ["{rcon_secret}"]}`,
		"a setting nobody declares":   `{"command": "srv", "args": ["{setting:map}"]}`,
		"a stray brace":               `{"command": "srv", "args": ["{port"]}`,
		"own RCON without its port":   `{"command": "srv", "args": [], "console": {"protocol": "source-rcon", "port": "own"}}`,
		"another console protocol":    `{"command": "srv", "args": [], "console": {"protocol": "telnet", "port": "game"}}`,
		"a link out of data":          `{"command": "srv", "args": [], "links": {"cfg": "../etc"}}`,
		"a file linked to a dir":      `{"command": "srv", "args": [], "links": {"cfg/": "cfg"}}`,
		"a secret with a default":     `{"command": "srv", "args": [], "settings": [{"key": "pw", "label": "P", "type": "secret", "default": "x"}]}`,
		"a default its pattern fails": `{"command": "srv", "args": [], "settings": [{"key": "map", "label": "M", "type": "text", "default": "A B", "pattern": "^[a-z]+$"}]}`,
		"a when for no setting":       `{"command": "srv", "args": [{"when": "gslt", "args": ["x"]}]}`,
		"an unknown field":            `{"command": "srv", "args": [], "exec": "rm -rf /"}`,
		"a signal to kill it":         `{"command": "srv", "args": [], "stop_signal": "SIGKILL"}`,
	} {
		if _, err := parseGamePkg([]byte(base(run))); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	if _, err := parseGamePkg([]byte(base(`{"command": "srv", "args": []}`))); err != nil {
		t.Errorf("a plain one: %v", err)
	}
	// run belongs to the native engine only, and steamcmd to it.
	if _, err := parseGamePkg([]byte(strings.Replace(base(`{"command": "srv", "args": []}`), `"native"`, `"factorio"`, 1))); err == nil {
		t.Error("run on another engine")
	}
}

func TestSystemdQuotingKeepsAnArgumentOne(t *testing.T) {
	for in, want := range map[string]string{
		`plain`:       `"plain"`,
		`two words`:   `"two words"`,
		`50% $HOME`:   `"50%% $$HOME"`,
		`say "hi" \n`: `"say \"hi\" \\n"`,
	} {
		if got := systemdQuote(in); got != want {
			t.Errorf("%s: %s", in, got)
		}
	}
}

// A2S as Steam servers answer it: a challenge first, then the info and players.
func TestA2SAsksWhoPlays(t *testing.T) {
	pc, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer pc.Close()
	challenge := []byte{1, 2, 3, 4}
	go func() {
		buf := make([]byte, 1400)
		for {
			n, addr, err := pc.ReadFrom(buf)
			if err != nil {
				return
			}
			req := buf[:n]
			head := []byte{0xFF, 0xFF, 0xFF, 0xFF}
			switch {
			case req[4] == 0x54 && n == 25, req[4] == 0x55 && string(req[5:9]) != string(challenge):
				_, _ = pc.WriteTo(append(append(head, 0x41), challenge...), addr)
			case req[4] == 0x54:
				info := append(head, 0x49, 17)
				for _, s := range []string{"Pražský server", "de_dust2", "csgo", "Counter-Strike 2"} {
					info = append(append(info, s...), 0)
				}
				info = binary.LittleEndian.AppendUint16(info, 730)
				info = append(info, 2, 10, 0)
				_, _ = pc.WriteTo(info, addr)
			case req[4] == 0x55:
				pl := append(head, 0x44, 2)
				for _, name := range []string{"Alice", "Bob"} {
					pl = append(append(append(pl, 0), name...), 0)
					pl = append(pl, 0, 0, 0, 0, 0, 0, 0, 0)
				}
				_, _ = pc.WriteTo(pl, addr)
			}
		}
	}()
	p, err := a2sQuery(context.Background(), pc.LocalAddr().(*net.UDPAddr).Port)
	if err != nil {
		t.Fatal(err)
	}
	if p["online"] != 2 || p["max"] != 10 || strings.Join(p["names"].([]string), ",") != "Alice,Bob" {
		t.Errorf("players: %v", p)
	}
}
