package ops

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func factorioEnv(t *testing.T, settings string) *Env {
	env, _ := gameTestEnv(t)
	saveGame(env, gameState{ID: 6, Name: "F", Game: "factorio", Flavor: "stable", Version: "2.1.24", Port: 34197, RconPort: 27002, RconPassword: "p", MemoryMB: 2048})
	data := filepath.Join(gameHome(env, 6), "data")
	os.MkdirAll(filepath.Join(data, "mods"), 0o755)
	os.WriteFile(filepath.Join(data, "server-settings.json"), []byte(settings), 0o640)
	return env
}

func TestFactorioSettingsHideSecretsAndCheckTypes(t *testing.T) {
	env := factorioEnv(t, `{"name":"F","max_players":0,"visibility":{"public":false,"lan":true},"token":"tok","game_password":"","allow_commands":"admins-only","custom":1}`)
	out, err := call(t, env, gameFactorioSettings, map[string]any{"id": 6})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	v, sec := m["values"].(map[string]any), m["secrets"].(map[string]bool)
	if v["name"] != "F" || v["visibility.lan"] != true || v["allow_commands"] != "admins-only" || !sec["token"] || sec["game_password"] {
		t.Errorf("settings: %v %v", v, sec)
	}
	if _, ok := v["token"]; ok {
		t.Error("the token must not leave the agent")
	}
	for _, bad := range []map[string]any{
		{"values": map[string]any{"max_players": "ten"}}, {"values": map[string]any{"token": "x"}}, {"values": map[string]any{"custom": 2}},
		{"values": map[string]any{"allow_commands": "everyone"}}, {"secrets": map[string]string{"name": "x"}}, {"secrets": map[string]string{"token": "a\nb"}},
	} {
		bad["id"] = 6
		if _, err := call(t, env, gameFactorioSetSettings, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, gameFactorioSetSettings, map[string]any{"id": 6, "values": map[string]any{"max_players": 8, "visibility.public": true, "allow_commands": false}, "secrets": map[string]string{"game_password": "pw"}}); err != nil {
		t.Fatal(err)
	}
}

func TestModSearchAndInstallNeedTheAccountAndTheVersion(t *testing.T) {
	var lastQuery string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/mods":
			lastQuery = r.URL.RawQuery
			fmt.Fprint(w, `{"results":[{"name":"far-reach","title":"Far Reach","summary":"reach","downloads_count":5},{"name":"even-distribution","title":"Even Distribution","summary":"items","downloads_count":50}]}`)
		case "/api/mods/even-distribution/full":
			fmt.Fprint(w, `{"releases":[{"download_url":"/download/even-distribution/a","file_name":"even-distribution_2.0.2.zip","version":"2.0.2","sha1":"`+strings.Repeat("a", 40)+`","info_json":{"factorio_version":"2.0"}},
				{"download_url":"/download/even-distribution/b","file_name":"even-distribution_2.1.0.zip","version":"2.1.0","sha1":"`+strings.Repeat("b", 40)+`","info_json":{"factorio_version":"2.1","dependencies":["base >= 2.1","? optional-thing","flib >= 0.16"]}}]}`)
		case "/api/mods/flib/full":
			fmt.Fprint(w, `{"releases":[{"download_url":"/download/flib/c","file_name":"flib_0.16.5.zip","version":"0.16.5","sha1":"`+strings.Repeat("c", 40)+`","info_json":{"factorio_version":"2.1"}}]}`)
		case "/api/mods/old/full":
			fmt.Fprint(w, `{"releases":[{"download_url":"/download/old/d","file_name":"old_1.0.0.zip","version":"1.0.0","sha1":"`+strings.Repeat("d", 40)+`","info_json":{"factorio_version":"1.1"}}]}`)
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(srv.Close)
	old := factorioModAPI
	factorioModAPI = srv.URL
	modListCache.mods = nil
	t.Cleanup(func() { factorioModAPI = old; modListCache.mods = nil })

	env := factorioEnv(t, `{"name":"F"}`)
	out, err := call(t, env, gameModSearch, map[string]any{"id": 6, "query": "item"})
	if err != nil {
		t.Fatal(err)
	}
	if hits := out.(map[string]any)["results"].([]factorioMod); len(hits) != 1 || hits[0].Name != "even-distribution" || !strings.Contains(lastQuery, "version=2.1") {
		t.Errorf("search: %+v %s", hits, lastQuery)
	}
	if _, err := call(t, env, gameModInstall, map[string]any{"id": 6, "name": "even-distribution"}); code(err) != "conflict" {
		t.Errorf("no account: %v", err)
	}
	b, _ := json.Marshal(map[string]any{"name": "F", "username": "me", "token": "tok"})
	os.WriteFile(filepath.Join(gameHome(env, 6), "data", "server-settings.json"), b, 0o640)
	out, err = call(t, env, gameModInstall, map[string]any{"id": 6, "name": "even-distribution"})
	if err != nil {
		t.Fatal(err)
	}
	inst := out.(map[string]any)["installed"].([]map[string]string)
	if len(inst) != 2 || inst[0]["version"] != "2.1.0" || inst[1]["name"] != "flib" {
		t.Errorf("installed (the 2.1 release and its required dependency, not base nor the optional one): %v", inst)
	}
	for _, bad := range []string{"old", "../x", ""} {
		if _, err := call(t, env, gameModInstall, map[string]any{"id": 6, "name": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	for _, bad := range []string{"../server-settings.json", "x.zip", "a/b_1.0.0.zip"} {
		if _, err := call(t, env, gameModRemove, map[string]any{"id": 6, "file": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	os.WriteFile(filepath.Join(gameHome(env, 6), "data", "mods", "flib_0.16.4.zip"), []byte("z"), 0o640)
	list, _ := call(t, env, gameMods, map[string]any{"id": 6, "check": true})
	mods := list.(map[string]any)["mods"].([]map[string]any)
	if len(mods) != 1 || mods[0]["name"] != "flib" || mods[0]["update"] != "0.16.5" || list.(map[string]any)["account"] != true {
		t.Errorf("mods: %v", list)
	}
}
