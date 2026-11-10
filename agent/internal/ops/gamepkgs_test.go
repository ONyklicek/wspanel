package ops

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const foliaPkg = `{
  "id": "minecraft-folia", "name": "Folia pro Minecraft", "version": "1.0.0",
  "description": "Vícevláknový server PaperMC pro velké servery.",
  "extends": "minecraft",
  "flavors": [{"id": "folia", "label": "Folia (vícevláknový)", "source": {"type": "papermc", "project": "folia"},
    "addons": {"kind": "plugins", "modrinth_loaders": ["folia"]}}]
}`

func TestBuiltinPackagesAddMinecraftAndFactorio(t *testing.T) {
	env, _ := gameTestEnv(t)
	defs, active, problems := gameRegistry(env)
	if len(problems) != 0 || len(active) != 2 {
		t.Fatalf("built-in packages: %v %v", active, problems)
	}
	mc := defs["minecraft"]
	if mc.Engine != "minecraft" || len(mc.Flavors) != 4 || mc.Flavors[0].ID != "paper" || !mc.Flavors[0].Addons.Hangar {
		t.Errorf("minecraft: %+v", mc)
	}
	if defs["factorio"].Proto != "udp" || defs["factorio"].Flavors[0].Source.Type != "factorio" {
		t.Errorf("factorio: %+v", defs["factorio"])
	}
}

// Folia, a package extending Minecraft: a new variant with its own source,
// offered, installed and run like Paper, its plugins Folia's own.
func TestFoliaExtendsMinecraftThroughAPackage(t *testing.T) {
	fakePublishers(t)
	env, _ := gameTestEnv(t)
	up, err := call(t, env, gamePackageUpload, map[string]string{"manifest": foliaPkg})
	if err != nil {
		t.Fatal(err)
	}
	if up.(map[string]any)["installed"].(map[string]any)["origin"] != "local" {
		t.Errorf("an uploaded package is the administrator's own: %v", up)
	}
	if !fileExists(filepath.Join(env.Cfg.GamePackagesDir, "minecraft-folia.json")) {
		t.Fatal("the package is kept in games.d")
	}
	out, err := call(t, env, gameCatalog, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	if v := out.(map[string]any)["minecraft"].(map[string][]string)["folia"]; strings.Join(v, ",") != "1.21.8" {
		t.Errorf("folia versions: %v", v)
	}
	d, err := resolveGameDownload(context.Background(), gameSource{Type: "papermc", Project: "folia"}, "1.21.8")
	if err != nil || d.Sum != strings.Repeat("f", 64) || d.Java != 21 {
		t.Errorf("folia download: %+v %v", d, err)
	}
	if _, err := call(t, env, gameCreate, map[string]any{"id": 9, "name": "Velký svět", "game": "minecraft", "flavor": "folia",
		"version": "1.21.8", "memory_mb": 4096, "eula": true}); err != nil {
		t.Fatal(err)
	}
	g, _ := loadGame(env, 9)
	if g.Flavor != "folia" || g.Proto != "tcp" || !strings.HasSuffix(gameExecStart(env, g), "/server/server.jar --nogui") {
		t.Errorf("a Folia server runs its jar: %+v %s", g, gameExecStart(env, g))
	}
	if strings.Join(addonLoaders(env, g), ",") != "folia" || addonHangar(env, g) || !strings.HasSuffix(addonDir(env, g), "/data/plugins") {
		t.Error("Folia's plugins come from Modrinth's folia loader into plugins/, not Hangar")
	}
	// In use: the package stays until its servers are gone.
	if _, err := call(t, env, gamePackageRemove, map[string]string{"id": "minecraft-folia"}); code(err) != "conflict" {
		t.Errorf("removing a package in use: %v", err)
	}
	if err := os.Remove(gameStatePath(env, 9)); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, gamePackageRemove, map[string]string{"id": "minecraft-folia"}); err != nil {
		t.Errorf("remove: %v", err)
	}
	if _, ok := gameDefOf(env, "minecraft"); !ok {
		t.Fatal("minecraft stays")
	}
	if def, _ := gameDefOf(env, "minecraft"); len(def.Flavors) != 4 {
		t.Errorf("Folia gone with its package: %v", def.Flavors)
	}
	if _, err := call(t, env, gamePackageRemove, map[string]string{"id": "minecraft"}); code(err) != "invalid_params" {
		t.Errorf("a built-in package stays: %v", err)
	}
}

// A manifest names the agent's mechanisms and nothing else.
func TestAManifestIsCheckedBeforeItCounts(t *testing.T) {
	env, _ := gameTestEnv(t)
	bad := map[string]string{
		"an unknown engine":     `{"id":"x","name":"X","version":"1","game":{"id":"xgame","label":"X","engine":"shell","proto":"tcp","default_port":27015,"min_memory_mb":512,"arch":["amd64"]},"flavors":[{"id":"a","label":"A","source":{"type":"mojang"}}]}`,
		"a path as a project":   strings.Replace(foliaPkg, `"project": "folia"`, `"project": "../../etc"`, 1),
		"Hangar without Paper":  strings.Replace(strings.Replace(foliaPkg, `"type": "papermc", "project": "folia"`, `"type": "mojang"`, 1), `"modrinth_loaders": ["folia"]`, `"modrinth_loaders": ["folia"], "hangar": true`, 1),
		"an unknown field":      strings.Replace(foliaPkg, `"version": "1.0.0",`, `"version": "1.0.0", "exec": "rm -rf /",`, 1),
		"both game and extends": strings.Replace(foliaPkg, `"extends": "minecraft",`, `"extends": "minecraft", "game": {"id":"x2","label":"X","engine":"minecraft","proto":"tcp","default_port":25570,"min_memory_mb":512,"arch":["amd64"]},`, 1),
		"a variant that exists": strings.Replace(foliaPkg, `"id": "folia", "label"`, `"id": "paper", "label"`, 1),
		"an unknown game":       strings.Replace(foliaPkg, `"extends": "minecraft"`, `"extends": "terraria"`, 1),
		"a source the engine cannot run": strings.Replace(foliaPkg, `"type": "papermc", "project": "folia"},
    "addons": {"kind": "plugins", "modrinth_loaders": ["folia"]}`, `"type": "factorio"}`, 1),
	}
	for why, manifest := range bad {
		if _, err := call(t, env, gamePackageUpload, map[string]string{"manifest": manifest}); err == nil {
			t.Errorf("%s: accepted", why)
		}
	}
	files, _ := filepath.Glob(filepath.Join(env.Cfg.GamePackagesDir, "*.json"))
	if len(files) != 0 {
		t.Errorf("a refused package left a file: %v", files)
	}
}

// WSPanel's catalog: offered and installed only with a valid signature.
func TestCatalogPackagesNeedWSPanelsSignature(t *testing.T) {
	env, _ := gameTestEnv(t)
	catalog := fmt.Sprintf(`{"format":1,"packages":[%s]}`, foliaPkg)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/games/catalog.json":
			fmt.Fprint(w, catalog)
		case "/games/catalog.json.asc":
			fmt.Fprint(w, "-----BEGIN PGP SIGNATURE-----")
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(srv.Close)
	env.Cfg.GameCatalogURL = srv.URL + "/games/catalog.json"
	valid := true
	old := verifyCatalog
	verifyCatalog = func(_ context.Context, _ *Env, data, sig []byte) error {
		if !valid || string(data) != catalog || !strings.HasPrefix(string(sig), "-----BEGIN PGP") {
			return errors.New("the catalog's signature does not match WSPanel's key: refused")
		}
		return nil
	}
	t.Cleanup(func() { verifyCatalog = old })

	out, err := call(t, env, gamePackages, map[string]bool{"catalog": true})
	if err != nil {
		t.Fatal(err)
	}
	if offered := out.(map[string]any)["catalog"].([]map[string]any); len(offered) != 1 || offered[0]["id"] != "minecraft-folia" || offered[0]["installed"] != "" {
		t.Errorf("offered: %v", offered)
	}
	valid = false
	if _, err := call(t, env, gamePackageInstall, map[string]string{"id": "minecraft-folia"}); err == nil || !strings.Contains(err.Error(), "signature") {
		t.Errorf("a bad signature must refuse: %v", err)
	}
	if _, ok := gameDefOf(env, "minecraft"); ok {
		if def, _ := gameDefOf(env, "minecraft"); len(def.Flavors) != 4 {
			t.Error("nothing of an unsigned catalog may take effect")
		}
	}
	valid = true
	if _, err := call(t, env, gamePackageInstall, map[string]string{"id": "minecraft-folia"}); err != nil {
		t.Fatal(err)
	}
	if !fileExists(filepath.Join(env.Cfg.StateDir, "game-packages", "minecraft-folia.json")) {
		t.Error("installed with the agent's state")
	}
	out, _ = call(t, env, gamePackages, map[string]any{})
	found := false
	for _, p := range out.(map[string]any)["packages"].([]map[string]any) {
		found = found || p["id"] == "minecraft-folia" && p["origin"] == "catalog"
	}
	if !found {
		t.Errorf("listed as from the catalog: %v", out)
	}
	// The same package as the administrator's own: refused, it is the catalog's.
	if _, err := call(t, env, gamePackageUpload, map[string]string{"manifest": foliaPkg}); code(err) != "conflict" {
		t.Errorf("one package from two places: %v", err)
	}
}
