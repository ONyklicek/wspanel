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
	"strconv"
	"strings"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Plugins of a Paper server from the two catalogues that publish a checksum
// of every file: Modrinth (SHA-512) and Hangar, PaperMC's own (SHA-256).
// The agent resolves the version for the server's Minecraft itself, fetches
// only from the catalogues' own CDNs and checks the checksum; a plugin
// whose file lives elsewhere (Hangar's external links) is refused.

var (
	modrinthAPI = "https://api.modrinth.com/v2"
	hangarAPI   = "https://hangar.papermc.io/api/v1"
	// Where the files may come from.
	pluginHosts = []string{"cdn.modrinth.com", "hangarcdn.papermc.io"}
	// What Paper loads: its own plugins and Bukkit/Spigot ones.
	pluginFileRe  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._+()\[\] -]{0,126}\.jar$`)
	pluginProjRe  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`)
	pluginSources = []string{"modrinth", "hangar"}
)

// installedPlugin: a plugin the panel installed (the rest of plugins/ is
// shown as installed by hand).
type installedPlugin struct {
	Source  string `json:"source"`
	Project string `json:"project"` // Modrinth's id, Hangar's slug
	Name    string `json:"name"`
	Version string `json:"version"`
	File    string `json:"file"`
}

func pluginsPath(env *Env, id int) string {
	return filepath.Join(gameStateDir(env), strconv.Itoa(id)+".plugins.json")
}

func loadPlugins(env *Env, id int) []installedPlugin {
	out := []installedPlugin{}
	if b, err := os.ReadFile(pluginsPath(env, id)); err == nil {
		_ = json.Unmarshal(b, &out)
	}
	return out
}

func savePlugins(env *Env, id int, list []installedPlugin) error {
	b, _ := json.MarshalIndent(list, "", "  ")
	return env.FS.WriteFile(pluginsPath(env, id), b, 0o600, fsx.Root)
}

// addonGame: a Minecraft server that loads plugins (Paper) or mods (Fabric, Forge).
func paperGame(env *Env, raw json.RawMessage, v any, id *int) (gameState, error) {
	g, err := minecraftGame(env, raw, v, id)
	if err != nil {
		return g, err
	}
	if addonLoaders(env, g) == nil {
		return g, protocol.Invalid("id: this variant loads no plugins or mods (Vanilla loads none)")
	}
	return g, nil
}

// addonLoaders: what the server loads (Modrinth's loader names), as its
// variant's package says; nil when it loads no plugins or mods.
func addonLoaders(env *Env, g gameState) []string {
	if a := gameAddons(env, g); a != nil {
		return a.ModrinthLoaders
	}
	return nil
}

// addonHangar: whether the variant takes Paper's plugins from Hangar.
func addonHangar(env *Env, g gameState) bool {
	a := gameAddons(env, g)
	return a != nil && a.Hangar
}

// addonDir: plugins/ for plugin servers (Paper, Folia), mods/ for the mod loaders.
func addonDir(env *Env, g gameState) string {
	if a := gameAddons(env, g); a != nil && a.Kind == "plugins" {
		return filepath.Join(gameHome(env, g.ID), "data", "plugins")
	}
	return filepath.Join(gameHome(env, g.ID), "data", "mods")
}

// ---------------------------------------------------------------- search

type pluginHit struct {
	Source      string `json:"source"`
	Project     string `json:"project"`
	Name        string `json:"name"`
	Description string `json:"description"`
	Author      string `json:"author"`
	Downloads   int64  `json:"downloads"`
	Icon        string `json:"icon,omitempty"`
	URL         string `json:"url"`
}

func gamePluginSearch(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID     int    `json:"id"`
		Source string `json:"source"`
		Query  string `json:"query"`
	}
	g, err := paperGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	p.Query = strings.TrimSpace(p.Query)
	if !slices.Contains(pluginSources, p.Source) {
		return nil, protocol.Invalid("source: modrinth or hangar")
	}
	if len(p.Query) > 100 || strings.ContainsAny(p.Query, "\r\n\x00") {
		return nil, protocol.Invalid("query: at most 100 characters")
	}
	hits := []pluginHit{}
	switch p.Source {
	case "modrinth":
		cats := []string{}
		for _, l := range addonLoaders(env, g) {
			cats = append(cats, "categories:"+l)
		}
		facets, _ := json.Marshal([][]string{cats, {"versions:" + g.Version}})
		var r struct {
			Hits []struct {
				ProjectID   string `json:"project_id"`
				Slug        string `json:"slug"`
				Title       string `json:"title"`
				Description string `json:"description"`
				Author      string `json:"author"`
				Downloads   int64  `json:"downloads"`
				Icon        string `json:"icon_url"`
			} `json:"hits"`
		}
		q := url.Values{"query": {p.Query}, "facets": {string(facets)}, "limit": {"20"}, "index": {"relevance"}}
		if err := fetchJSON(ctx, modrinthAPI+"/search?"+q.Encode(), &r); err != nil {
			return nil, fmt.Errorf("Modrinth: %w", err)
		}
		for _, h := range r.Hits {
			hits = append(hits, pluginHit{Source: "modrinth", Project: h.ProjectID, Name: h.Title, Description: h.Description,
				Author: h.Author, Downloads: h.Downloads, Icon: h.Icon, URL: "https://modrinth.com/plugin/" + h.Slug})
		}
	case "hangar":
		if !addonHangar(env, g) {
			return nil, protocol.Invalid("source: Hangar has Paper plugins only; mods come from Modrinth")
		}
		var r struct {
			Result []struct {
				Name      string `json:"name"`
				Namespace struct {
					Owner string `json:"owner"`
					Slug  string `json:"slug"`
				} `json:"namespace"`
				Description string `json:"description"`
				Stats       struct {
					Downloads int64 `json:"downloads"`
				} `json:"stats"`
				Avatar string `json:"avatarUrl"`
			} `json:"result"`
		}
		q := url.Values{"q": {p.Query}, "platform": {"PAPER"}, "version": {g.Version}, "limit": {"20"}}
		if err := fetchJSON(ctx, hangarAPI+"/projects?"+q.Encode(), &r); err != nil {
			return nil, fmt.Errorf("Hangar: %w", err)
		}
		for _, h := range r.Result {
			hits = append(hits, pluginHit{Source: "hangar", Project: h.Namespace.Slug, Name: h.Name, Description: h.Description,
				Author: h.Namespace.Owner, Downloads: h.Stats.Downloads, Icon: h.Avatar, URL: "https://hangar.papermc.io/" + h.Namespace.Owner + "/" + h.Namespace.Slug})
		}
	}
	return map[string]any{"results": hits, "version": g.Version}, nil
}

// ---------------------------------------------------------------- resolving

type pluginRelease struct {
	Project, Name, Version, File, URL, Sum string
	Requires                               []string // Modrinth project ids it needs
	Needs                                  []string // Hangar: names of what it needs (not installed by the panel)
}

func allowedPluginURL(u string) bool {
	parsed, err := url.Parse(u)
	return err == nil && parsed.Scheme == "https" && slices.Contains(pluginHosts, parsed.Hostname())
}

// resolvePlugin: the newest release of the project for this Minecraft
// version (a beta only when there is no release).
func resolvePlugin(ctx context.Context, source, project, mc string, loaderList []string) (pluginRelease, error) {
	if !pluginProjRe.MatchString(project) {
		return pluginRelease{}, protocol.Invalid("project: not a catalogue's project")
	}
	switch source {
	case "modrinth":
		var proj struct {
			ID    string `json:"id"`
			Title string `json:"title"`
		}
		if err := fetchJSON(ctx, modrinthAPI+"/project/"+url.PathEscape(project), &proj); err != nil {
			return pluginRelease{}, fmt.Errorf("Modrinth: %w", err)
		}
		loaders, _ := json.Marshal(loaderList)
		versions, _ := json.Marshal([]string{mc})
		var vs []struct {
			Number string `json:"version_number"`
			Type   string `json:"version_type"`
			Files  []struct {
				Name    string            `json:"filename"`
				URL     string            `json:"url"`
				Primary bool              `json:"primary"`
				Hashes  map[string]string `json:"hashes"`
			} `json:"files"`
			Deps []struct {
				Project string `json:"project_id"`
				Type    string `json:"dependency_type"`
			} `json:"dependencies"`
		}
		q := url.Values{"loaders": {string(loaders)}, "game_versions": {string(versions)}}
		if err := fetchJSON(ctx, modrinthAPI+"/project/"+url.PathEscape(proj.ID)+"/version?"+q.Encode(), &vs); err != nil {
			return pluginRelease{}, fmt.Errorf("Modrinth: %w", err)
		}
		pick := -1
		for i, v := range vs {
			if v.Type == "release" {
				pick = i
				break
			}
		}
		if pick < 0 && len(vs) > 0 {
			pick = 0
		}
		if pick < 0 {
			return pluginRelease{}, protocol.Invalid(fmt.Sprintf("project: %s has no version for Minecraft %s on Paper", proj.Title, mc))
		}
		v := vs[pick]
		for i, f := range v.Files {
			if f.Primary || i == len(v.Files)-1 {
				rel := pluginRelease{Project: proj.ID, Name: proj.Title, Version: v.Number, File: f.Name, URL: f.URL, Sum: "sha512:" + f.Hashes["sha512"]}
				for _, d := range v.Deps {
					if d.Type == "required" && pluginProjRe.MatchString(d.Project) {
						rel.Requires = append(rel.Requires, d.Project)
					}
				}
				return rel, nil
			}
		}
		return pluginRelease{}, fmt.Errorf("%s %s has no file", proj.Title, v.Number)
	case "hangar":
		var proj struct {
			Name string `json:"name"`
		}
		if err := fetchJSON(ctx, hangarAPI+"/projects/"+url.PathEscape(project), &proj); err != nil {
			return pluginRelease{}, fmt.Errorf("Hangar: %w", err)
		}
		var r struct {
			Result []struct {
				Name    string `json:"name"`
				Channel struct {
					Name string `json:"name"`
				} `json:"channel"`
				Downloads map[string]struct {
					FileInfo *struct {
						Name   string `json:"name"`
						SHA256 string `json:"sha256Hash"`
					} `json:"fileInfo"`
					URL      *string `json:"downloadUrl"`
					External *string `json:"externalUrl"`
				} `json:"downloads"`
				Deps map[string][]struct {
					Name     string `json:"name"`
					Required bool   `json:"required"`
				} `json:"pluginDependencies"`
			} `json:"result"`
		}
		q := url.Values{"platform": {"PAPER"}, "platformVersion": {mc}, "limit": {"25"}}
		if err := fetchJSON(ctx, hangarAPI+"/projects/"+url.PathEscape(project)+"/versions?"+q.Encode(), &r); err != nil {
			return pluginRelease{}, fmt.Errorf("Hangar: %w", err)
		}
		pick := -1
		for i, v := range r.Result {
			if strings.EqualFold(v.Channel.Name, "release") {
				pick = i
				break
			}
		}
		if pick < 0 && len(r.Result) > 0 {
			pick = 0
		}
		if pick < 0 {
			return pluginRelease{}, protocol.Invalid(fmt.Sprintf("project: %s has no version for Minecraft %s on Paper", proj.Name, mc))
		}
		v := r.Result[pick]
		d, ok := v.Downloads["PAPER"]
		if !ok || d.URL == nil || d.FileInfo == nil || len(d.FileInfo.SHA256) != 64 {
			return pluginRelease{}, protocol.Invalid(fmt.Sprintf("project: %s is downloaded from outside Hangar, without a checksum: not installed by the panel", proj.Name))
		}
		rel := pluginRelease{Project: project, Name: proj.Name, Version: v.Name, File: d.FileInfo.Name, URL: *d.URL, Sum: d.FileInfo.SHA256}
		for _, dep := range v.Deps["PAPER"] {
			if dep.Required {
				rel.Needs = append(rel.Needs, dep.Name)
			}
		}
		return rel, nil
	}
	return pluginRelease{}, protocol.Invalid("source: modrinth or hangar")
}

// ---------------------------------------------------------------- install

// gamePluginInstall (async): the plugin (or its newer version) and, from
// Modrinth, what it requires. Takes effect at the server's next start.
func gamePluginInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID      int    `json:"id"`
		Source  string `json:"source"`
		Project string `json:"project"`
	}
	g, err := paperGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if !slices.Contains(pluginSources, p.Source) || p.Source == "hangar" && !addonHangar(env, g) {
		return nil, protocol.Invalid("source: modrinth or hangar (Hangar for Paper only)")
	}
	list := loadPlugins(env, g.ID)
	installed := []map[string]string{}
	var needs []string
	queue := []string{p.Project}
	seen := map[string]bool{}
	for len(queue) > 0 && len(installed) < 10 {
		project := queue[0]
		queue = queue[1:]
		if seen[project] {
			continue
		}
		seen[project] = true
		rel, err := resolvePlugin(ctx, p.Source, project, g.Version, addonLoaders(env, g))
		if err != nil {
			if project == p.Project {
				return nil, err
			}
			needs = append(needs, project)
			continue
		}
		// A dependency already there (by the panel) is left as it is.
		at := slices.IndexFunc(list, func(ip installedPlugin) bool { return ip.Source == p.Source && ip.Project == rel.Project })
		if project != p.Project && at >= 0 {
			continue
		}
		if !pluginFileRe.MatchString(rel.File) || !allowedPluginURL(rel.URL) {
			return nil, protocol.Invalid(fmt.Sprintf("project: %s comes from %s, not the catalogue's own storage", rel.Name, rel.URL))
		}
		if err := placePlugin(ctx, env, g, rel); err != nil {
			return nil, err
		}
		entry := installedPlugin{Source: p.Source, Project: rel.Project, Name: rel.Name, Version: rel.Version, File: rel.File}
		if at >= 0 {
			if list[at].File != rel.File {
				_ = asUser(ctx, env, g.user(), "/usr/bin/rm", "-f", "--", filepath.Join(addonDir(env, g), list[at].File))
			}
			list[at] = entry
		} else {
			list = append(list, entry)
		}
		installed = append(installed, map[string]string{"name": rel.Name, "version": rel.Version, "file": rel.File})
		queue = append(queue, rel.Requires...)
		needs = append(needs, rel.Needs...)
	}
	if err := savePlugins(env, g.ID, list); err != nil {
		return nil, err
	}
	return map[string]any{"installed": installed, "needs": nonNil(needs)}, nil
}

func placePlugin(ctx context.Context, env *Env, g gameState, rel pluginRelease) error {
	dir := addonDir(env, g)
	if env.Cfg.DryRun {
		return nil
	}
	if err := asUser(ctx, env, g.user(), "/usr/bin/install", "-d", "-m", "0750", dir); err != nil {
		return err
	}
	file, err := downloadVerified(ctx, rel.URL, rel.Sum, 256<<20)
	if err != nil {
		return fmt.Errorf("downloading %s failed: %w", rel.Name, err)
	}
	defer os.Remove(file)
	if err := os.Chmod(file, 0o644); err != nil {
		return err
	}
	return asUser(ctx, env, g.user(), "/usr/bin/install", "-m", "0640", file, filepath.Join(dir, rel.File))
}

// gamePlugins: the jars in plugins/, those the panel installed with their
// source and version, and (check) the newer versions there are.
func gamePlugins(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID    int  `json:"id"`
		Check bool `json:"check,omitempty"`
	}
	g, err := paperGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	list := loadPlugins(env, g.ID)
	byFile := map[string]installedPlugin{}
	for _, ip := range list {
		byFile[ip.File] = ip
	}
	out := []map[string]any{}
	entries, _ := os.ReadDir(addonDir(env, g))
	present := map[string]bool{}
	for _, e := range entries {
		if e.Type().IsRegular() && strings.HasSuffix(strings.ToLower(e.Name()), ".jar") {
			present[e.Name()] = true
			row := map[string]any{"file": e.Name()}
			if fi, err := e.Info(); err == nil {
				row["size"] = fi.Size()
			}
			if ip, ok := byFile[e.Name()]; ok {
				row["source"], row["project"], row["name"], row["version"] = ip.Source, ip.Project, ip.Name, ip.Version
				if p.Check {
					if rel, err := resolvePlugin(ctx, ip.Source, ip.Project, g.Version, addonLoaders(env, g)); err == nil && rel.Version != ip.Version {
						row["update"] = rel.Version
					}
				}
			}
			out = append(out, row)
		}
	}
	// Records of jars removed by hand are forgotten.
	kept := slices.DeleteFunc(slices.Clone(list), func(ip installedPlugin) bool { return !present[ip.File] })
	if len(kept) != len(list) && !env.Cfg.DryRun {
		_ = savePlugins(env, g.ID, kept)
	}
	sort.Slice(out, func(i, j int) bool {
		return strings.ToLower(out[i]["file"].(string)) < strings.ToLower(out[j]["file"].(string))
	})
	return map[string]any{"plugins": out}, nil
}

// gamePluginRemove deletes one jar (its own configuration folder stays).
func gamePluginRemove(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID   int    `json:"id"`
		File string `json:"file"`
	}
	g, err := paperGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if !pluginFileRe.MatchString(p.File) {
		return nil, protocol.Invalid("file: a plugin's .jar in plugins/")
	}
	if err := asUser(ctx, env, g.user(), "/usr/bin/rm", "-f", "--", filepath.Join(addonDir(env, g), p.File)); err != nil {
		return nil, err
	}
	list := slices.DeleteFunc(loadPlugins(env, g.ID), func(ip installedPlugin) bool { return ip.File == p.File })
	if err := savePlugins(env, g.ID, list); err != nil {
		return nil, err
	}
	return map[string]any{"removed": p.File}, nil
}
