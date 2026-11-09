package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func mcWithData(t *testing.T) (*Env, gameState) {
	env, _ := gameTestEnv(t)
	g := gameState{ID: 4, Name: "S", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, RconPort: 27000, RconPassword: "secret", MemoryMB: 2048}
	if err := saveGame(env, g); err != nil {
		t.Fatal(err)
	}
	data := filepath.Join(gameHome(env, 4), "data")
	os.MkdirAll(data, 0o755)
	os.WriteFile(filepath.Join(data, "server.properties"), []byte("#Minecraft server properties\nmotd=Hi\nrcon.password=secret\nserver-port=25565\nwhite-list=true\ndifficulty=easy\n"), 0o640)
	os.WriteFile(filepath.Join(data, "whitelist.json"), []byte(`[{"uuid":"069a79f4-44e9-4726-a5be-fca90e38aaf5","name":"Notch"}]`), 0o640)
	os.WriteFile(filepath.Join(data, "ops.json"), []byte(`[{"uuid":"x","name":"Admin","level":4,"bypassesPlayerLimit":false}]`), 0o640)
	return env, g
}

func TestPropertiesAreReadWithoutSecretsAndThePanelsKeysMarked(t *testing.T) {
	env, _ := mcWithData(t)
	out, err := call(t, env, gameProperties, map[string]any{"id": 4})
	if err != nil {
		t.Fatal(err)
	}
	props := out.(map[string]any)["properties"].([]property)
	got := map[string]property{}
	for _, p := range props {
		got[p.Key] = p
	}
	if len(props) != 5 || got["motd"].Value != "Hi" || got["rcon.password"].Value != "" || !got["rcon.password"].Panel || !got["server-port"].Panel || got["difficulty"].Panel {
		t.Errorf("properties: %+v", props)
	}
	saveGame(env, gameState{ID: 9, Name: "F", Game: "factorio", Flavor: "stable", Version: "2.1.24", Port: 34197, RconPort: 27001, RconPassword: "p", MemoryMB: 2048})
	if _, err := call(t, env, gameProperties, map[string]any{"id": 9}); code(err) != "invalid_params" {
		t.Errorf("Factorio has no server.properties: %v", err)
	}
}

func TestSettingPropertiesRefusesThePanelsKeysAndBadValues(t *testing.T) {
	env, _ := mcWithData(t)
	for _, bad := range []map[string]string{
		{"server-port": "1"}, {"rcon.password": "x"}, {"enable-rcon": "false"}, {"management-server-enabled": "true"},
		{"motd": "a\nserver-port=1"}, {"Bad Key": "x"}, {},
	} {
		if _, err := call(t, env, gameSetProperties, map[string]any{"id": 4, "values": bad}); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, gameSetProperties, map[string]any{"id": 4, "values": map[string]string{"difficulty": "hard", "max-players": "50"}}); err != nil {
		t.Fatal(err)
	}
}

func TestPlayerListsAndActions(t *testing.T) {
	env, _ := mcWithData(t)
	out, err := call(t, env, gamePlayerLists, map[string]any{"id": 4})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	if m["whitelist_on"] != true || m["whitelist"].([]mcPlayer)[0].Name != "Notch" || m["ops"].([]mcPlayer)[0].Level != 4 || len(m["banned"].([]mcPlayer)) != 0 {
		t.Errorf("lists: %+v", m)
	}
	for action, want := range map[string]string{"whitelist_add": "whitelist add Notch", "op": "op Notch", "deop": "deop Notch", "pardon": "pardon Notch", "whitelist_on": "whitelist on"} {
		out, err := call(t, env, gamePlayerAction, map[string]any{"id": 4, "action": action, "name": "Notch"})
		if err != nil || out.(map[string]any)["command"] != want {
			t.Errorf("%s: %v %v", action, out, err)
		}
	}
	out, _ = call(t, env, gamePlayerAction, map[string]any{"id": 4, "action": "ban", "name": "Griefer", "reason": "spam"})
	if out.(map[string]any)["command"] != "ban Griefer spam" {
		t.Errorf("ban: %v", out)
	}
	out, _ = call(t, env, gamePlayerAction, map[string]any{"id": 4, "action": "ban_ip", "name": "203.0.113.9"})
	if out.(map[string]any)["command"] != "ban-ip 203.0.113.9" {
		t.Errorf("ban-ip: %v", out)
	}
	for _, bad := range []map[string]any{
		{"action": "op", "name": "a; stop"}, {"action": "op", "name": "x"}, {"action": "op", "name": strings.Repeat("a", 17)},
		{"action": "ban_ip", "name": "1.2.3"}, {"action": "stop"}, {"action": "ban", "name": "Bob", "reason": "a\nstop"},
	} {
		bad["id"] = 4
		if _, err := call(t, env, gamePlayerAction, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
}

func TestPropertyEscapingRoundTrips(t *testing.T) {
	for in, want := range map[string]string{
		`minecraft\:normal`: "minecraft:normal",
		`Přežití`:           "Přežití",
		`😀 hi`:              "😀 hi",
		`a\\b`:              `a\b`,
		`plain`:             "plain",
	} {
		if got := unescapeProperty(in); got != want {
			t.Errorf("%q: %q, want %q", in, got, want)
		}
		if back := unescapeProperty(escapeProperty(want)); back != want {
			t.Errorf("round trip of %q: %q", want, back)
		}
	}
	if got := escapeProperty("P\u0159e\u017eit\u00ed \U0001F600"); got != `P\u0159e\u017eit\u00ed \ud83d\ude00` {
		t.Errorf("escape: %s", got)
	}
}
