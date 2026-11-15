package ops

import (
	"archive/zip"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Modpacks from Modrinth (.mrpack): a pack names its Minecraft and loader
// and lists its files with their hashes and where to fetch them, beside
// "overrides" (configs, scripts) to lay over the server's folder. It goes
// onto a server of the same Minecraft and loader: the pack's files are
// fetched only from the hosts Modrinth's format allows, each checked against
// its SHA-512, and nothing lands outside the server's data/ folder.

var (
	// What the format allows a pack to download from.
	modpackHosts = []string{"cdn.modrinth.com", "github.com", "raw.githubusercontent.com", "gitlab.com"}
	// A pack's dependency key for each variant's loader.
	modpackLoaders = map[string]string{"neoforge": "neoforge", "forge": "forge", "fabric": "fabric-loader"}
	// Names in data/ a pack may not write: the loader's own start files.
	modpackReserved = []string{"libraries", "user_jvm_args.txt", "run.sh", "run.bat", "eula.txt"}
)

const (
	modpackMaxFiles   = 2000
	modpackMaxFile    = 512 << 20
	modpackMaxTotal   = 6 << 30
	modpackMaxArchive = 1 << 30
)

// installedModpack: what the panel put on the server from a pack, so a
// newer version (or another pack) removes what it no longer has.
type installedModpack struct {
	Project string   `json:"project"`
	Name    string   `json:"name"`
	Version string   `json:"version"`
	Files   []string `json:"files"` // relative to data/
}

func modpackPath(env *Env, id int) string {
	return filepath.Join(gameStateDir(env), strconv.Itoa(id)+".modpack.json")
}

func loadModpack(env *Env, id int) *installedModpack {
	b, err := os.ReadFile(modpackPath(env, id))
	if err != nil {
		return nil
	}
	var m installedModpack
	if json.Unmarshal(b, &m) != nil {
		return nil
	}
	return &m
}

// modpackGame: a Minecraft server whose variant loads mods a pack can name.
func modpackGame(env *Env, raw json.RawMessage, v any, id *int) (gameState, string, error) {
	g, err := minecraftGame(env, raw, v, id)
	if err != nil {
		return g, "", err
	}
	src, err := gameSourceOf(env, g)
	if err != nil {
		return g, "", err
	}
	loader, ok := modpackLoaders[src.Type]
	if !ok {
		return g, "", protocol.Invalid("id: modpacks go onto a NeoForge, Forge or Fabric server")
	}
	return g, loader, nil
}

// gameModpackSearch: Modrinth's modpacks for the server's Minecraft and loader.
func gameModpackSearch(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID    int    `json:"id"`
		Query string `json:"query"`
	}
	g, _, err := modpackGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	p.Query = strings.TrimSpace(p.Query)
	if len(p.Query) > 100 || strings.ContainsAny(p.Query, "\r\n\x00") {
		return nil, protocol.Invalid("query: at most 100 characters")
	}
	cats := []string{}
	for _, l := range addonLoaders(env, g) {
		cats = append(cats, "categories:"+l)
	}
	facets, _ := json.Marshal([][]string{{"project_type:modpack"}, cats, {"versions:" + g.Version}})
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
	hits := []pluginHit{}
	for _, h := range r.Hits {
		hits = append(hits, pluginHit{Source: "modrinth", Project: h.ProjectID, Name: h.Title, Description: h.Description,
			Author: h.Author, Downloads: h.Downloads, Icon: h.Icon, URL: "https://modrinth.com/modpack/" + h.Slug})
	}
	out := map[string]any{"results": hits, "version": g.Version}
	if m := loadModpack(env, g.ID); m != nil {
		out["installed"] = map[string]any{"project": m.Project, "name": m.Name, "version": m.Version, "files": len(m.Files)}
	}
	return out, nil
}

// mrpackIndex: modrinth.index.json, as much of it as the server needs.
type mrpackIndex struct {
	Game  string `json:"game"`
	Name  string `json:"name"`
	Files []struct {
		Path      string            `json:"path"`
		Hashes    map[string]string `json:"hashes"`
		Env       map[string]string `json:"env"`
		Downloads []string          `json:"downloads"`
		Size      int64             `json:"fileSize"`
	} `json:"files"`
	Dependencies map[string]string `json:"dependencies"`
}

// modpackRel cleans a path of the pack into one inside data/, or refuses it.
func modpackRel(p string) (string, error) {
	if p == "" || strings.ContainsAny(p, "\\\x00") || strings.HasPrefix(p, "/") {
		return "", fmt.Errorf("the pack names a file outside the server: %q", p)
	}
	clean := path.Clean(p)
	if clean == "." || clean == ".." || strings.HasPrefix(clean, "../") || slices.Contains(modpackReserved, strings.SplitN(clean, "/", 2)[0]) {
		return "", fmt.Errorf("the pack names a file outside the server or the loader's own: %q", p)
	}
	return clean, nil
}

func modpackURL(list []string) string {
	for _, u := range list {
		parsed, err := url.Parse(u)
		if err == nil && parsed.Scheme == "https" && slices.Contains(modpackHosts, parsed.Hostname()) {
			return u
		}
	}
	return ""
}

type modrinthVersion struct {
	Number string `json:"version_number"`
	Type   string `json:"version_type"`
	Files  []struct {
		Name    string            `json:"filename"`
		URL     string            `json:"url"`
		Primary bool              `json:"primary"`
		Hashes  map[string]string `json:"hashes"`
	} `json:"files"`
}

// resolveModpack: the newest release of the pack for this Minecraft and loader.
func resolveModpack(ctx context.Context, project, mc string, loaders []string) (pluginRelease, error) {
	if !pluginProjRe.MatchString(project) {
		return pluginRelease{}, protocol.Invalid("project: not a Modrinth project")
	}
	var proj struct {
		ID          string `json:"id"`
		Title       string `json:"title"`
		ProjectType string `json:"project_type"`
	}
	if err := fetchJSON(ctx, modrinthAPI+"/project/"+url.PathEscape(project), &proj); err != nil {
		return pluginRelease{}, fmt.Errorf("Modrinth: %w", err)
	}
	if proj.ProjectType != "modpack" {
		return pluginRelease{}, protocol.Invalid("project: " + proj.Title + " is not a modpack")
	}
	lj, _ := json.Marshal(loaders)
	vj, _ := json.Marshal([]string{mc})
	var vs []modrinthVersion
	q := url.Values{"loaders": {string(lj)}, "game_versions": {string(vj)}}
	if err := fetchJSON(ctx, modrinthAPI+"/project/"+url.PathEscape(proj.ID)+"/version?"+q.Encode(), &vs); err != nil {
		return pluginRelease{}, fmt.Errorf("Modrinth: %w", err)
	}
	pick := slices.IndexFunc(vs, func(v modrinthVersion) bool { return v.Type == "release" })
	if pick < 0 && len(vs) > 0 {
		pick = 0
	}
	if pick < 0 {
		return pluginRelease{}, protocol.Invalid(fmt.Sprintf("project: %s has no version for Minecraft %s on this loader", proj.Title, mc))
	}
	v := vs[pick]
	for _, f := range v.Files {
		if strings.HasSuffix(f.Name, ".mrpack") && (f.Primary || len(v.Files) == 1) && len(f.Hashes["sha512"]) == 128 {
			return pluginRelease{Project: proj.ID, Name: proj.Title, Version: v.Number, File: f.Name, URL: f.URL, Sum: "sha512:" + f.Hashes["sha512"]}, nil
		}
	}
	return pluginRelease{}, fmt.Errorf("%s %s has no .mrpack file", proj.Title, v.Number)
}

// gameModpackInstall (async): the pack's newest version for the server,
// with the server stopped meanwhile and started again when it ran. What an
// earlier pack put there and this one does not have is removed; the
// world, the players and what was added by hand stay.
func gameModpackInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID      int    `json:"id"`
		Project string `json:"project"`
	}
	g, loader, err := modpackGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	release, err := claimGame(g.ID)
	if err != nil {
		return nil, err
	}
	defer release()
	rel, err := resolveModpack(ctx, p.Project, g.Version, addonLoaders(env, g))
	if err != nil {
		return nil, err
	}
	if !allowedPluginURL(rel.URL) {
		return nil, protocol.Invalid("project: the pack comes from " + rel.URL + ", not Modrinth's own storage")
	}
	if env.Cfg.DryRun {
		return map[string]any{"name": rel.Name, "version": rel.Version, "files": 0}, nil
	}
	archive, err := downloadVerified(ctx, rel.URL, rel.Sum, modpackMaxArchive)
	if err != nil {
		return nil, fmt.Errorf("downloading %s failed: %w", rel.Name, err)
	}
	defer os.Remove(archive)
	zr, err := zip.OpenReader(archive)
	if err != nil {
		return nil, fmt.Errorf("%s is not a modpack archive: %w", rel.Name, err)
	}
	defer zr.Close()
	idx, err := readMrpackIndex(&zr.Reader)
	if err != nil {
		return nil, err
	}
	if idx.Game != "minecraft" || idx.Dependencies["minecraft"] != g.Version {
		return nil, protocol.Invalid(fmt.Sprintf("project: %s is for Minecraft %s, the server runs %s", rel.Name, idx.Dependencies["minecraft"], g.Version))
	}
	want, ok := idx.Dependencies[loader]
	if !ok {
		return nil, protocol.Invalid(fmt.Sprintf("project: %s needs another loader than this server's", rel.Name))
	}
	// Everything checked before anything is fetched.
	type packFile struct{ rel, url, sum string }
	files := []packFile{}
	var total int64
	for _, f := range idx.Files {
		if f.Env["server"] == "unsupported" {
			continue
		}
		r, err := modpackRel(f.Path)
		if err != nil {
			return nil, protocol.Invalid("project: " + err.Error())
		}
		u := modpackURL(f.Downloads)
		if u == "" {
			return nil, protocol.Invalid(fmt.Sprintf("project: %s fetches %s from a host the modpack format does not allow", rel.Name, f.Path))
		}
		if len(f.Hashes["sha512"]) != 128 || f.Size > modpackMaxFile {
			return nil, protocol.Invalid(fmt.Sprintf("project: %s has %s without a SHA-512 or over 512 MB", rel.Name, f.Path))
		}
		total += f.Size
		files = append(files, packFile{r, u, "sha512:" + f.Hashes["sha512"]})
	}
	if len(files) > modpackMaxFiles || total > modpackMaxTotal {
		return nil, protocol.Invalid(fmt.Sprintf("project: %s has %d files of %d MB, more than the panel installs", rel.Name, len(files), total>>20))
	}
	active, err := stopForChange(ctx, env, g)
	if err != nil {
		return nil, err
	}
	data := filepath.Join(gameHome(env, g.ID), "data")
	written := []string{}
	place := func(rel, src string) error {
		if err := os.Chmod(src, 0o644); err != nil {
			return err
		}
		if err := asUser(ctx, env, g.user(), "/usr/bin/install", "-D", "-m", "0640", src, filepath.Join(data, filepath.FromSlash(rel))); err != nil {
			return err
		}
		written = append(written, rel)
		return nil
	}
	err = func() error {
		for _, f := range files {
			file, err := downloadVerified(ctx, f.url, f.sum, modpackMaxFile)
			if err != nil {
				return fmt.Errorf("downloading %s failed: %w", f.rel, err)
			}
			err = place(f.rel, file)
			os.Remove(file)
			if err != nil {
				return err
			}
		}
		// The overrides, then the server's own over them.
		for _, prefix := range []string{"overrides/", "server-overrides/"} {
			for _, zf := range zr.File {
				name, ok := strings.CutPrefix(zf.Name, prefix)
				if !ok || zf.FileInfo().IsDir() || !zf.Mode().IsRegular() || name == "" {
					continue
				}
				r, err := modpackRel(name)
				if err != nil {
					return protocol.Invalid("project: " + err.Error())
				}
				tmp, err := extractZipFile(zf, modpackMaxFile)
				if err != nil {
					return err
				}
				err = place(r, tmp)
				os.Remove(tmp)
				if err != nil {
					return err
				}
			}
		}
		return nil
	}()
	if err == nil {
		// What an earlier pack had and this one does not.
		if old := loadModpack(env, g.ID); old != nil {
			for _, f := range old.Files {
				if !slices.Contains(written, f) {
					_ = asUser(ctx, env, g.user(), "/usr/bin/rm", "-f", "--", filepath.Join(data, filepath.FromSlash(f)))
				}
			}
		}
		slices.Sort(written)
		b, _ := json.MarshalIndent(installedModpack{Project: rel.Project, Name: rel.Name, Version: rel.Version, Files: slices.Compact(written)}, "", "  ")
		err = env.FS.WriteFile(modpackPath(env, g.ID), b, 0o600, fsx.Root)
	}
	if active {
		if serr := startAfterChange(ctx, env, g); err == nil {
			err = serr
		}
	}
	if err != nil {
		return nil, err
	}
	out := map[string]any{"name": rel.Name, "version": rel.Version, "files": len(written)}
	// The pack was made with another build of the loader; the server keeps its own.
	if want != "" && want != g.Build {
		out["loader_wanted"] = want
	}
	return out, nil
}

func readMrpackIndex(zr *zip.Reader) (mrpackIndex, error) {
	var idx mrpackIndex
	for _, f := range zr.File {
		if f.Name != "modrinth.index.json" {
			continue
		}
		rc, err := f.Open()
		if err != nil {
			return idx, err
		}
		defer rc.Close()
		if err := json.NewDecoder(io.LimitReader(rc, 16<<20)).Decode(&idx); err != nil {
			return idx, fmt.Errorf("the pack's index is not valid: %w", err)
		}
		return idx, nil
	}
	return idx, protocol.Invalid("project: the archive has no modrinth.index.json")
}

// extractZipFile copies one entry into a temporary file, at most max bytes.
func extractZipFile(zf *zip.File, max int64) (string, error) {
	rc, err := zf.Open()
	if err != nil {
		return "", err
	}
	defer rc.Close()
	tmp, err := os.CreateTemp("", "wspanel-mrpack-*")
	if err != nil {
		return "", err
	}
	n, err := io.Copy(tmp, io.LimitReader(rc, max+1))
	tmp.Close()
	if err == nil && n > max {
		err = fmt.Errorf("%s in the pack is over %d MB", zf.Name, max>>20)
	}
	if err != nil {
		os.Remove(tmp.Name())
		return "", err
	}
	return tmp.Name(), nil
}

// stopForChange stops a running server for a change of its files and says
// whether it ran.
func stopForChange(ctx context.Context, env *Env, g gameState) (bool, error) {
	active := false
	err := withMutate(env, func() error {
		if st, err := unitStates(ctx, env, []string{g.unit()}); err == nil && len(st) == 1 {
			active = st[0].Active == "active"
		}
		if active {
			_, err := env.Exec.Run(ctx, "systemctl", "stop", g.unit())
			return err
		}
		return nil
	})
	return active, err
}

func startAfterChange(ctx context.Context, env *Env, g gameState) error {
	return withMutate(env, func() error {
		_, err := env.Exec.Run(ctx, "systemctl", "start", g.unit())
		return err
	})
}
