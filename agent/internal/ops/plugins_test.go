package ops

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func fakeCatalogues(t *testing.T) {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		switch r.URL.Path {
		case "/modrinth/search":
			if !strings.Contains(q.Get("facets"), `"versions:1.21.10"`) || !strings.Contains(q.Get("facets"), "categories:paper") {
				http.Error(w, "facets", 400)
				return
			}
			fmt.Fprint(w, `{"hits":[{"project_id":"Vebnzrzj","slug":"luckperms","title":"LuckPerms","description":"perms","author":"Luck","downloads":5}]}`)
		case "/modrinth/project/Vebnzrzj", "/modrinth/project/luckperms":
			fmt.Fprint(w, `{"id":"Vebnzrzj","title":"LuckPerms"}`)
		case "/modrinth/project/Vebnzrzj/version":
			fmt.Fprint(w, `[{"version_number":"5.6-beta","version_type":"beta","files":[]},
				{"version_number":"5.5.71","version_type":"release","files":[{"filename":"LuckPerms-Bukkit-5.5.71.jar","url":"https://cdn.modrinth.com/data/x/LuckPerms.jar","primary":true,"hashes":{"sha512":"`+strings.Repeat("a", 128)+`"}}],
				"dependencies":[{"project_id":"Dep00001","dependency_type":"required"},{"project_id":"Opt00001","dependency_type":"optional"}]}]`)
		case "/modrinth/project/Dep00001":
			fmt.Fprint(w, `{"id":"Dep00001","title":"Lib"}`)
		case "/modrinth/project/Dep00001/version":
			fmt.Fprint(w, `[{"version_number":"1.0","version_type":"release","files":[{"filename":"Lib-1.0.jar","url":"https://cdn.modrinth.com/data/y/Lib.jar","primary":true,"hashes":{"sha512":"`+strings.Repeat("b", 128)+`"}}]}]`)
		case "/modrinth/project/evil":
			fmt.Fprint(w, `{"id":"evil0001","title":"Evil"}`)
		case "/modrinth/project/evil0001/version":
			fmt.Fprint(w, `[{"version_number":"1","version_type":"release","files":[{"filename":"Evil.jar","url":"https://example.com/evil.jar","primary":true,"hashes":{"sha512":"c"}}]}]`)
		case "/modrinth/project/old":
			fmt.Fprint(w, `{"id":"old00001","title":"Old"}`)
		case "/modrinth/project/old00001/version":
			fmt.Fprint(w, `[]`)
		case "/hangar/projects/ViaVersion":
			fmt.Fprint(w, `{"name":"ViaVersion"}`)
		case "/hangar/projects/ViaVersion/versions":
			fmt.Fprint(w, `{"result":[{"name":"5.12.3-SNAPSHOT","channel":{"name":"Snapshot"},"downloads":{"PAPER":{"fileInfo":{"name":"ViaVersion-SNAP.jar","sha256Hash":"`+strings.Repeat("d", 64)+`"},"downloadUrl":"https://hangarcdn.papermc.io/x/snap.jar"}}},
				{"name":"5.12.2","channel":{"name":"Release"},"downloads":{"PAPER":{"fileInfo":{"name":"ViaVersion-5.12.2.jar","sha256Hash":"`+strings.Repeat("e", 64)+`"},"downloadUrl":"https://hangarcdn.papermc.io/x/rel.jar"}},"pluginDependencies":{"PAPER":[{"name":"ViaBackwards","required":true}]}}]}`)
		case "/hangar/projects/External":
			fmt.Fprint(w, `{"name":"External"}`)
		case "/hangar/projects/External/versions":
			fmt.Fprint(w, `{"result":[{"name":"1","channel":{"name":"Release"},"downloads":{"PAPER":{"fileInfo":null,"downloadUrl":null,"externalUrl":"https://example.com/x.jar"}}}]}`)
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(srv.Close)
	m, h := modrinthAPI, hangarAPI
	modrinthAPI, hangarAPI = srv.URL+"/modrinth", srv.URL+"/hangar"
	t.Cleanup(func() { modrinthAPI, hangarAPI = m, h })
}

func paperEnv(t *testing.T) *Env {
	env, _ := gameTestEnv(t)
	saveGame(env, gameState{ID: 4, Name: "S", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, RconPort: 27000, RconPassword: "p", MemoryMB: 2048})
	saveGame(env, gameState{ID: 5, Name: "V", Game: "minecraft", Flavor: "vanilla", Version: "26.3", Port: 25566, RconPort: 27001, RconPassword: "p", MemoryMB: 2048})
	return env
}

func TestPluginSearchAsksForThisServersVersion(t *testing.T) {
	fakeCatalogues(t)
	env := paperEnv(t)
	out, err := call(t, env, gamePluginSearch, map[string]any{"id": 4, "source": "modrinth", "query": "perms"})
	if err != nil {
		t.Fatal(err)
	}
	if hits := out.(map[string]any)["results"].([]pluginHit); len(hits) != 1 || hits[0].Project != "Vebnzrzj" {
		t.Errorf("hits: %+v", hits)
	}
	if _, err := call(t, env, gamePluginSearch, map[string]any{"id": 5, "source": "modrinth", "query": "x"}); code(err) != "invalid_params" {
		t.Errorf("Vanilla has no plugins: %v", err)
	}
	if _, err := call(t, env, gamePluginSearch, map[string]any{"id": 4, "source": "spigot", "query": "x"}); code(err) != "invalid_params" {
		t.Errorf("unknown source: %v", err)
	}
}

func TestPluginInstallTakesTheReleaseItsDependencyAndTheCatalogueOnly(t *testing.T) {
	fakeCatalogues(t)
	env := paperEnv(t)
	out, err := call(t, env, gamePluginInstall, map[string]any{"id": 4, "source": "modrinth", "project": "luckperms"})
	if err != nil {
		t.Fatal(err)
	}
	inst := out.(map[string]any)["installed"].([]map[string]string)
	if len(inst) != 2 || inst[0]["version"] != "5.5.71" || inst[1]["file"] != "Lib-1.0.jar" {
		t.Errorf("installed: %v", inst)
	}
	list := loadPlugins(env, 4)
	if len(list) != 2 || list[0].Project != "Vebnzrzj" || list[0].Source != "modrinth" {
		t.Errorf("records: %+v", list)
	}
	for _, bad := range []map[string]any{
		{"source": "modrinth", "project": "evil"},   // a file outside the CDN
		{"source": "modrinth", "project": "old"},    // nothing for this version
		{"source": "hangar", "project": "External"}, // an external link
		{"source": "modrinth", "project": "../x"},
	} {
		bad["id"] = 4
		if _, err := call(t, env, gamePluginInstall, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	out, err = call(t, env, gamePluginInstall, map[string]any{"id": 4, "source": "hangar", "project": "ViaVersion"})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	if m["installed"].([]map[string]string)[0]["version"] != "5.12.2" || strings.Join(m["needs"].([]string), ",") != "ViaBackwards" {
		t.Errorf("hangar: the release, not the snapshot; its needs listed: %v", m)
	}
}

func TestPluginRemoveTakesOnlyAJarName(t *testing.T) {
	env := paperEnv(t)
	for _, bad := range []string{"../server.jar", "x.txt", "", "a/b.jar"} {
		if _, err := call(t, env, gamePluginRemove, map[string]any{"id": 4, "file": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
}

func TestModLoadersTakeModrinthModsOnly(t *testing.T) {
	fakeCatalogues(t)
	env, _ := gameTestEnv(t)
	saveGame(env, gameState{ID: 8, Name: "M", Game: "minecraft", Flavor: "fabric", Version: "1.21.10", Build: "0.20.1", Port: 25567, RconPort: 27003, RconPassword: "p", MemoryMB: 2048})
	if _, err := call(t, env, gamePluginSearch, map[string]any{"id": 8, "source": "hangar", "query": "via"}); code(err) != "invalid_params" {
		t.Errorf("Hangar has Paper plugins only: %v", err)
	}
	if _, err := call(t, env, gamePluginInstall, map[string]any{"id": 8, "source": "hangar", "project": "ViaVersion"}); code(err) != "invalid_params" {
		t.Errorf("install from Hangar on Fabric: %v", err)
	}
	// The fake catalogue answers searches with categories:paper only: Fabric asks for fabric.
	if _, err := call(t, env, gamePluginSearch, map[string]any{"id": 8, "source": "modrinth", "query": "x"}); err == nil {
		t.Error("a Fabric server must search for Fabric mods")
	}
}
