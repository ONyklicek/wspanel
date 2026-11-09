package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strings"
	"sync"
	"time"

	"wspanel/agent/internal/protocol"
)

// Factorio: its server-settings.json as a form, and mods from the mod portal
// (mods.factorio.com), whose downloads need a factorio.com account: the
// username and token kept in server-settings.json (where Factorio wants
// them for the public server list anyway). The token never leaves the agent.

var factorioModAPI = "https://mods.factorio.com"

func factorioGame(env *Env, raw json.RawMessage, v any, id *int) (gameState, error) {
	g, err := decodeGame(env, raw, v, id)
	if err != nil {
		return g, err
	}
	if g.Game != "factorio" {
		return g, protocol.Invalid("id: a Factorio server")
	}
	return g, nil
}

// factorioMinor: "2.1" of "2.1.24" (what mods declare).
func factorioMinor(v string) string {
	p := strings.Split(v, ".")
	if len(p) < 2 {
		return v
	}
	return p[0] + "." + p[1]
}

// ---------------------------------------------------------------- settings

// factorioSettingTypes: what the form changes, by key (bool, int, string,
// list of strings, or the two visibility switches). The rest of the file stays.
var factorioSettingTypes = map[string]string{
	"name": "string", "description": "string", "tags": "list", "max_players": "int",
	"visibility.public": "bool", "visibility.lan": "bool",
	"username": "string", "require_user_verification": "bool",
	"max_upload_in_kilobytes_per_second": "int", "max_upload_slots": "int",
	"minimum_latency_in_ticks": "int", "ignore_player_limit_for_returning_players": "bool",
	"allow_commands": "commands", "autosave_interval": "int", "autosave_slots": "int",
	"afk_autokick_interval": "int", "auto_pause": "bool", "auto_pause_when_players_connect": "bool",
	"only_admins_can_pause_the_game": "bool", "autosave_only_on_server": "bool", "non_blocking_saving": "bool",
}

// factorioSecrets: written, never read back.
var factorioSecrets = []string{"token", "game_password", "password"}

func factorioSettingsPath(env *Env, g gameState) string {
	return filepath.Join(gameHome(env, g.ID), "data", "server-settings.json")
}

func readFactorioSettings(env *Env, g gameState) (map[string]any, error) {
	b, err := readGameFileMax(factorioSettingsPath(env, g), 256<<10)
	if err != nil {
		return nil, err
	}
	m := map[string]any{}
	if err := json.Unmarshal(b, &m); err != nil {
		return nil, fmt.Errorf("server-settings.json is not valid JSON: %w", err)
	}
	return m, nil
}

func gameFactorioSettings(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p gameIDParams
	g, err := factorioGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	m, err := readFactorioSettings(env, g)
	if err != nil {
		return nil, err
	}
	out := map[string]any{}
	for k := range factorioSettingTypes {
		if top, sub, ok := strings.Cut(k, "."); ok {
			if vis, ok := m[top].(map[string]any); ok {
				out[k] = vis[sub]
			}
			continue
		}
		if v, ok := m[k]; ok {
			out[k] = v
		}
	}
	secrets := map[string]bool{}
	for _, k := range factorioSecrets {
		s, _ := m[k].(string)
		secrets[k] = s != ""
	}
	return map[string]any{"values": out, "secrets": secrets}, nil
}

// gameFactorioSetSettings: the given keys (typed as the form has them);
// secrets set when given, cleared when listed in clear.
func gameFactorioSetSettings(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID      int                        `json:"id"`
		Values  map[string]json.RawMessage `json:"values"`
		Secrets map[string]string          `json:"secrets,omitempty"`
		Clear   []string                   `json:"clear,omitempty"`
		Restart bool                       `json:"restart,omitempty"`
	}
	g, err := factorioGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	m, err := readFactorioSettings(env, g)
	if err != nil {
		return nil, err
	}
	set := func(k string, v any) {
		if top, sub, ok := strings.Cut(k, "."); ok {
			vis, _ := m[top].(map[string]any)
			if vis == nil {
				vis = map[string]any{}
			}
			vis[sub] = v
			m[top] = vis
			return
		}
		m[k] = v
	}
	for k, rv := range p.Values {
		typ, ok := factorioSettingTypes[k]
		if !ok {
			return nil, protocol.Invalid("values: " + k + " is not a setting the panel changes")
		}
		bad := protocol.Invalid("values: " + k + " has the wrong type")
		switch typ {
		case "bool":
			var b bool
			if json.Unmarshal(rv, &b) != nil {
				return nil, bad
			}
			set(k, b)
		case "int":
			var n int64
			if json.Unmarshal(rv, &n) != nil || n < 0 || n > 1_000_000 {
				return nil, protocol.Invalid("values: " + k + " must be a whole number 0 to 1000000")
			}
			set(k, n)
		case "string":
			var s string
			if json.Unmarshal(rv, &s) != nil || len(s) > 2000 || strings.ContainsAny(s, "\x00") {
				return nil, bad
			}
			set(k, s)
		case "list":
			var l []string
			if json.Unmarshal(rv, &l) != nil || len(l) > 50 {
				return nil, bad
			}
			set(k, l)
		case "commands":
			var s string
			var b bool
			switch {
			case json.Unmarshal(rv, &s) == nil && s == "admins-only":
				set(k, s)
			case json.Unmarshal(rv, &b) == nil:
				set(k, b)
			default:
				return nil, protocol.Invalid("values: allow_commands is true, false or admins-only")
			}
		}
	}
	for k, v := range p.Secrets {
		if !slices.Contains(factorioSecrets, k) || v == "" || len(v) > 200 || strings.ContainsAny(v, "\r\n\x00") {
			return nil, protocol.Invalid("secrets: token or game_password, one line")
		}
		m[k] = v
	}
	for _, k := range p.Clear {
		if !slices.Contains(factorioSecrets, k) {
			return nil, protocol.Invalid("clear: token or game_password")
		}
		m[k] = ""
	}
	b, _ := json.MarshalIndent(m, "", "  ")
	if err := writeAsUser(ctx, env, g, factorioSettingsPath(env, g), append(b, '\n'), "0640"); err != nil {
		return nil, err
	}
	if p.Restart {
		if _, err := env.Exec.Run(ctx, "systemctl", "try-restart", g.unit()); err != nil {
			return nil, err
		}
	}
	return map[string]any{"saved": true, "restarted": p.Restart}, nil
}

// ---------------------------------------------------------------- mods

type factorioMod struct {
	Name      string `json:"name"`
	Title     string `json:"title"`
	Owner     string `json:"owner"`
	Summary   string `json:"summary"`
	Downloads int64  `json:"downloads_count"`
	Category  string `json:"category,omitempty"`
	Latest    *struct {
		Version string `json:"version"`
	} `json:"latest_release,omitempty"`
}

var modListCache struct {
	sync.Mutex
	at   map[string]time.Time
	mods map[string][]factorioMod
}

// modList: every mod for a Factorio minor version (the portal has no text
// search without an account; the list is ~4 MB, kept for 6 hours).
func modList(ctx context.Context, minor string) ([]factorioMod, error) {
	modListCache.Lock()
	defer modListCache.Unlock()
	if modListCache.mods == nil {
		modListCache.at, modListCache.mods = map[string]time.Time{}, map[string][]factorioMod{}
	}
	if l, ok := modListCache.mods[minor]; ok && time.Since(modListCache.at[minor]) < 6*time.Hour {
		return l, nil
	}
	var r struct {
		Results []factorioMod `json:"results"`
	}
	q := url.Values{"page_size": {"max"}, "version": {minor}, "hide_deprecated": {"true"}}
	if err := fetchJSON(ctx, factorioModAPI+"/api/mods?"+q.Encode(), &r); err != nil {
		return nil, fmt.Errorf("the mod portal: %w", err)
	}
	modListCache.mods[minor], modListCache.at[minor] = r.Results, time.Now()
	return r.Results, nil
}

func gameModSearch(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID    int    `json:"id"`
		Query string `json:"query"`
	}
	g, err := factorioGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	q := strings.ToLower(strings.TrimSpace(p.Query))
	if len(q) > 100 {
		return nil, protocol.Invalid("query: at most 100 characters")
	}
	all, err := modList(ctx, factorioMinor(g.Version))
	if err != nil {
		return nil, err
	}
	hits := []factorioMod{}
	for _, m := range all {
		if q == "" || strings.Contains(strings.ToLower(m.Name+" "+m.Title+" "+m.Summary), q) {
			hits = append(hits, m)
		}
	}
	sort.SliceStable(hits, func(i, j int) bool { return hits[i].Downloads > hits[j].Downloads })
	if len(hits) > 30 {
		hits = hits[:30]
	}
	return map[string]any{"results": hits, "version": factorioMinor(g.Version)}, nil
}

var (
	modNameRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9 _.-]{0,99}$`)
	modFileRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9 _.-]{0,99}_\d+\.\d+\.\d+\.zip$`)
	// The game's own content: never downloaded.
	builtinMods = []string{"base", "core", "space-age", "quality", "elevated-rails"}
)

type modRelease struct {
	Name, Version, File, URL, SHA1 string
	Requires                       []string
}

func resolveMod(ctx context.Context, name, minor string) (modRelease, error) {
	if !modNameRe.MatchString(name) {
		return modRelease{}, protocol.Invalid("name: a mod's name")
	}
	var full struct {
		Title    string `json:"title"`
		Releases []struct {
			URL     string `json:"download_url"`
			File    string `json:"file_name"`
			Version string `json:"version"`
			SHA1    string `json:"sha1"`
			Info    struct {
				Factorio string   `json:"factorio_version"`
				Deps     []string `json:"dependencies"`
			} `json:"info_json"`
		} `json:"releases"`
	}
	if err := fetchJSON(ctx, factorioModAPI+"/api/mods/"+url.PathEscape(name)+"/full", &full); err != nil {
		return modRelease{}, fmt.Errorf("the mod portal: %w", err)
	}
	for i := len(full.Releases) - 1; i >= 0; i-- {
		r := full.Releases[i]
		if r.Info.Factorio != minor {
			continue
		}
		if !strings.HasPrefix(r.URL, "/download/") || !modFileRe.MatchString(r.File) || len(r.SHA1) != 40 {
			return modRelease{}, protocol.Invalid("name: " + name + " has no file the panel can check")
		}
		rel := modRelease{Name: name, Version: r.Version, File: r.File, URL: factorioModAPI + r.URL, SHA1: r.SHA1}
		for _, d := range r.Info.Deps {
			d = strings.TrimSpace(d)
			// "? optional", "(?) hidden", "! incompatible", "~ no load order" are not needed.
			if d == "" || strings.ContainsAny(d[:1], "?!(~") {
				continue
			}
			dep := strings.Fields(d)[0]
			if !slices.Contains(builtinMods, dep) && modNameRe.MatchString(dep) {
				rel.Requires = append(rel.Requires, dep)
			}
		}
		return rel, nil
	}
	return modRelease{}, protocol.Invalid(fmt.Sprintf("name: %s has no version for Factorio %s", name, minor))
}

// gameModInstall (async): the mod and what it requires, with the account
// in server-settings.json; enabled at the server's next start.
func gameModInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID   int    `json:"id"`
		Name string `json:"name"`
	}
	g, err := factorioGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	settings, err := readFactorioSettings(env, g)
	if err != nil {
		return nil, err
	}
	user, _ := settings["username"].(string)
	token, _ := settings["token"].(string)
	if user == "" || token == "" {
		return nil, protocol.Conflict("mods need a factorio.com account: its username and token in the server's settings")
	}
	dir := filepath.Join(gameHome(env, g.ID), "data", "mods")
	if !env.Cfg.DryRun {
		if err := asUser(ctx, env, g.user(), "/usr/bin/install", "-d", "-m", "0750", dir); err != nil {
			return nil, err
		}
	}
	installed := []map[string]string{}
	queue, seen := []string{p.Name}, map[string]bool{}
	for len(queue) > 0 && len(installed) < 15 {
		name := queue[0]
		queue = queue[1:]
		if seen[name] {
			continue
		}
		seen[name] = true
		if name != p.Name && modPresent(dir, name) {
			continue
		}
		rel, err := resolveMod(ctx, name, factorioMinor(g.Version))
		if err != nil {
			return nil, err
		}
		if !env.Cfg.DryRun {
			u := rel.URL + "?" + url.Values{"username": {user}, "token": {token}}.Encode()
			file, err := downloadVerified(ctx, u, "sha1:"+rel.SHA1, 1<<30)
			if err != nil {
				// The address holds the token: never in a message.
				return nil, fmt.Errorf("downloading %s failed: %s", rel.Name, strings.ReplaceAll(strings.ReplaceAll(err.Error(), u, rel.URL), token, "***"))
			}
			if err := os.Chmod(file, 0o644); err != nil {
				os.Remove(file)
				return nil, err
			}
			err = asUser(ctx, env, g.user(), "/usr/bin/install", "-m", "0640", file, filepath.Join(dir, rel.File))
			os.Remove(file)
			if err != nil {
				return nil, err
			}
			// An older version of the same mod goes.
			olds, _ := filepath.Glob(filepath.Join(dir, rel.Name+"_*.zip"))
			for _, o := range olds {
				if filepath.Base(o) != rel.File && modFileRe.MatchString(filepath.Base(o)) {
					_ = asUser(ctx, env, g.user(), "/usr/bin/rm", "-f", "--", o)
				}
			}
		}
		installed = append(installed, map[string]string{"name": rel.Name, "version": rel.Version, "file": rel.File})
		queue = append(queue, rel.Requires...)
	}
	return map[string]any{"installed": installed}, nil
}

func modPresent(dir, name string) bool {
	m, _ := filepath.Glob(filepath.Join(dir, name+"_*.zip"))
	return len(m) > 0
}

var modVersionRe = regexp.MustCompile(`^(.+)_(\d+\.\d+\.\d+)\.zip$`)

// gameMods: the mods in data/mods (name and version from the file name);
// check: a newer version on the portal.
func gameMods(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID    int  `json:"id"`
		Check bool `json:"check,omitempty"`
	}
	g, err := factorioGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	out := []map[string]any{}
	entries, _ := os.ReadDir(filepath.Join(gameHome(env, g.ID), "data", "mods"))
	for _, e := range entries {
		m := modVersionRe.FindStringSubmatch(e.Name())
		if !e.Type().IsRegular() || m == nil {
			continue
		}
		row := map[string]any{"file": e.Name(), "name": m[1], "version": m[2]}
		if fi, err := e.Info(); err == nil {
			row["size"] = fi.Size()
		}
		if p.Check {
			if rel, err := resolveMod(ctx, m[1], factorioMinor(g.Version)); err == nil && rel.Version != m[2] {
				row["update"] = rel.Version
			}
		}
		out = append(out, row)
	}
	settings, _ := readFactorioSettings(env, g)
	user, _ := settings["username"].(string)
	token, _ := settings["token"].(string)
	return map[string]any{"mods": out, "account": user != "" && token != ""}, nil
}

func gameModRemove(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID   int    `json:"id"`
		File string `json:"file"`
	}
	g, err := factorioGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if !modFileRe.MatchString(p.File) {
		return nil, protocol.Invalid("file: a mod's .zip in mods/")
	}
	if err := asUser(ctx, env, g.user(), "/usr/bin/rm", "-f", "--", filepath.Join(gameHome(env, g.ID), "data", "mods", p.File)); err != nil {
		return nil, err
	}
	return map[string]any{"removed": p.File}, nil
}
