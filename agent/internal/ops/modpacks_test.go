package ops

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestAModpackWritesOnlyInsideTheServersData(t *testing.T) {
	for p, ok := range map[string]bool{
		"mods/create-6.0.10.jar": true, "config/create-common.toml": true, "kubejs/server_scripts/a.js": true,
		"../etc/passwd": false, "/etc/passwd": false, "mods/../../x": false, "mods\\x.jar": false, "": false, ".": false,
		"libraries/net/x.jar": false, "user_jvm_args.txt": false, "eula.txt": false, "run.sh": false,
	} {
		if _, err := modpackRel(p); (err == nil) != ok {
			t.Errorf("%q: allowed=%v", p, err == nil)
		}
	}
	if modpackURL([]string{"http://cdn.modrinth.com/a.jar", "https://evil.test/a.jar"}) != "" {
		t.Error("plain HTTP and foreign hosts")
	}
	if u := modpackURL([]string{"https://evil.test/a.jar", "https://cdn.modrinth.com/data/x/a.jar"}); u != "https://cdn.modrinth.com/data/x/a.jar" {
		t.Errorf("first allowed URL: %q", u)
	}
}

func TestModpacksAreForModServersAndTheirMinecraft(t *testing.T) {
	var asked string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/search":
			asked = r.URL.Query().Get("facets")
			fmt.Fprint(w, `{"hits":[{"project_id":"AbCd1234","slug":"create-plus","title":"Create Plus","author":"x","downloads":5}]}`)
		case r.URL.Path == "/project/create-plus":
			fmt.Fprint(w, `{"id":"AbCd1234","title":"Create Plus","project_type":"modpack"}`)
		case r.URL.Path == "/project/create":
			fmt.Fprint(w, `{"id":"LNytGWDc","title":"Create","project_type":"mod"}`)
		case r.URL.Path == "/project/AbCd1234/version":
			fmt.Fprint(w, `[{"version_number":"2.0-beta","version_type":"beta","files":[]},{"version_number":"1.4","version_type":"release","files":[{"filename":"Create Plus 1.4.mrpack","url":"https://cdn.modrinth.com/data/AbCd1234/p.mrpack","primary":true,"hashes":{"sha512":"`+strings.Repeat("e", 128)+`"}}]}]`)
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	old := modrinthAPI
	modrinthAPI = srv.URL
	t.Cleanup(func() { modrinthAPI = old })

	fakePublishers(t)
	env, _ := gameTestEnv(t)
	neo := gameState{ID: 4, Name: "Create", Game: "minecraft", Flavor: "neoforge", Version: "1.21.1", Build: "21.1.215", Port: 25566, RconPort: 27001, RconPassword: "p", MemoryMB: 4096}
	paper := gameState{ID: 5, Name: "Lobby", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, RconPort: 27000, RconPassword: "p", MemoryMB: 2048}
	for _, g := range []gameState{neo, paper} {
		if err := saveGame(env, g); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := call(t, env, gameModpackSearch, map[string]any{"id": 5, "query": "create"}); err == nil || !strings.Contains(err.Error(), "NeoForge, Forge or Fabric") {
		t.Errorf("a Paper server takes no modpack: %v", err)
	}
	out, err := call(t, env, gameModpackSearch, map[string]any{"id": 4, "query": "create"})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(asked, `"project_type:modpack"`) || !strings.Contains(asked, `"categories:neoforge"`) || !strings.Contains(asked, `"versions:1.21.1"`) {
		t.Errorf("facets %s", asked)
	}
	if hits := out.(map[string]any)["results"].([]pluginHit); len(hits) != 1 || hits[0].URL != "https://modrinth.com/modpack/create-plus" {
		t.Errorf("hits %v", hits)
	}
	rel, err := resolveModpack(context.Background(), "create-plus", "1.21.1", []string{"neoforge"})
	if err != nil || rel.Version != "1.4" || rel.Sum != "sha512:"+strings.Repeat("e", 128) {
		t.Errorf("the newest release, not the beta: %+v %v", rel, err)
	}
	if _, err := resolveModpack(context.Background(), "create", "1.21.1", []string{"neoforge"}); err == nil || !strings.Contains(err.Error(), "not a modpack") {
		t.Errorf("a mod is not a pack: %v", err)
	}
}
