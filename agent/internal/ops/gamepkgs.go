package ops

import (
	"embed"
	"encoding/json"
	"fmt"
	"path/filepath"
	"regexp"
	"runtime"
	"slices"
	"sort"
	"strings"

	"wspanel/agent/internal/fsx"
)

// Game packages: what a game server can run is described, never coded. A
// package is a JSON manifest that either adds a game (its engine, port,
// memory, the processors it runs on, and its variants) or extends a game
// with more variants (Folia for Minecraft). The agent runs as root, so a
// manifest holds no commands: it names the agent's own mechanisms (an
// engine, a download source) and their parameters, each checked here.
//
// Packages come from three places, in this order (the first wins a clash):
// built into the agent (Minecraft, Factorio), installed from WSPanel's signed
// catalog (gamecatalog.go), and the administrator's own (/etc/wspanel/games.d,
// also uploaded through the panel).

//go:embed gamepkgs/*.json
var builtinGamePkgs embed.FS

type gamePkg struct {
	ID          string          `json:"id"`
	Name        string          `json:"name"`
	Version     string          `json:"version"`
	Description string          `json:"description,omitempty"`
	Game        *gamePkgGame    `json:"game,omitempty"`
	Extends     string          `json:"extends,omitempty"`
	Flavors     []gamePkgFlavor `json:"flavors"`

	Origin string `json:"-"` // builtin | catalog | local
	File   string `json:"-"`
}

type gamePkgGame struct {
	ID          string   `json:"id"`
	Label       string   `json:"label"`
	Engine      string   `json:"engine"`
	Proto       string   `json:"proto"`
	DefaultPort int      `json:"default_port"`
	MinMemoryMB int      `json:"min_memory_mb"`
	Arch        []string `json:"arch"`
}

type gamePkgFlavor struct {
	ID     string         `json:"id"`
	Label  string         `json:"label"`
	Source gameSource     `json:"source"`
	Addons *gameAddonSpec `json:"addons,omitempty"`
}

// gameSource: where a variant's server comes from, by one of the agent's
// own resolvers (games.go resolveGameDownload).
type gameSource struct {
	Type    string `json:"type"`
	Project string `json:"project,omitempty"` // papermc: the PaperMC project (paper, folia)
}

// gameAddonSpec: plugins or mods the variant loads, from Modrinth (by its
// loader names) and, for Paper's own, Hangar.
type gameAddonSpec struct {
	Kind            string   `json:"kind"` // plugins | mods
	ModrinthLoaders []string `json:"modrinth_loaders"`
	Hangar          bool     `json:"hangar,omitempty"`
}

// The agent's mechanisms a manifest may name.
var (
	gameEngines = map[string][]string{ // engine -> the source types it can run
		"minecraft": {"papermc", "mojang", "fabric", "forge"},
		"factorio":  {"factorio"},
	}
	gamePkgIDRe     = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{1,40}$`)
	gameIDRe        = regexp.MustCompile(`^[a-z][a-z0-9]{1,31}$`)
	gameFlavorIDRe  = regexp.MustCompile(`^[a-z][a-z0-9-]{0,31}$`)
	gamePkgVerRe    = regexp.MustCompile(`^\d{1,4}(\.\d{1,4}){0,2}$`)
	gameSlugRe      = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,31}$`)
	gameLabelRe     = regexp.MustCompile(`^[^\x00-\x1f\x7f<>]{1,64}$`)
	gameDescRe      = regexp.MustCompile(`^[^\x00-\x09\x0b-\x1f\x7f<>]{0,300}$`)
	gamePkgMaxBytes = 64 << 10
)

// parseGamePkg reads and checks a manifest on its own (what it extends is
// checked against the others in gameRegistry).
func parseGamePkg(b []byte) (gamePkg, error) {
	var p gamePkg
	if len(b) > gamePkgMaxBytes {
		return p, fmt.Errorf("the manifest is larger than 64 KB")
	}
	dec := json.NewDecoder(strings.NewReader(string(b)))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return p, fmt.Errorf("not a game package manifest: %v", err)
	}
	switch {
	case !gamePkgIDRe.MatchString(p.ID):
		return p, fmt.Errorf("id: lower case letters, digits and dashes (2-41)")
	case !gameLabelRe.MatchString(p.Name):
		return p, fmt.Errorf("name: 1 to 64 characters")
	case !gamePkgVerRe.MatchString(p.Version):
		return p, fmt.Errorf("version: e.g. 1.0.0")
	case !gameDescRe.MatchString(p.Description):
		return p, fmt.Errorf("description: at most 300 characters")
	case (p.Game == nil) == (p.Extends == ""):
		return p, fmt.Errorf("a package either adds a game (game) or extends one (extends)")
	case len(p.Flavors) < 1 || len(p.Flavors) > 20:
		return p, fmt.Errorf("flavors: 1 to 20")
	}
	if p.Extends != "" && !gameIDRe.MatchString(p.Extends) {
		return p, fmt.Errorf("extends: a game's id")
	}
	if g := p.Game; g != nil {
		switch {
		case !gameIDRe.MatchString(g.ID):
			return p, fmt.Errorf("game.id: lower case letters and digits (2-32)")
		case !gameLabelRe.MatchString(g.Label):
			return p, fmt.Errorf("game.label: 1 to 64 characters")
		case gameEngines[g.Engine] == nil:
			return p, fmt.Errorf("game.engine: %s", strings.Join(sortedKeys(gameEngines), " or "))
		case g.Proto != "tcp" && g.Proto != "udp":
			return p, fmt.Errorf("game.proto: tcp or udp")
		case g.DefaultPort < 1024 || g.DefaultPort > 65535:
			return p, fmt.Errorf("game.default_port: 1024 to 65535")
		case g.MinMemoryMB < 256 || g.MinMemoryMB > 65536:
			return p, fmt.Errorf("game.min_memory_mb: 256 to 65536")
		case len(g.Arch) == 0:
			return p, fmt.Errorf("game.arch: amd64 and/or arm64")
		}
		for _, a := range g.Arch {
			if a != "amd64" && a != "arm64" {
				return p, fmt.Errorf("game.arch: amd64 and/or arm64")
			}
		}
	}
	seen := map[string]bool{}
	for _, f := range p.Flavors {
		switch {
		case !gameFlavorIDRe.MatchString(f.ID) || seen[f.ID]:
			return p, fmt.Errorf("flavors: an id is lower case letters, digits and dashes, each once (%q)", f.ID)
		case !gameLabelRe.MatchString(f.Label):
			return p, fmt.Errorf("flavor %s: label 1 to 64 characters", f.ID)
		case !slices.ContainsFunc(sortedKeys(gameEngines), func(e string) bool { return slices.Contains(gameEngines[e], f.Source.Type) }):
			return p, fmt.Errorf("flavor %s: source.type unknown (%q)", f.ID, f.Source.Type)
		case f.Source.Type == "papermc" && !gameSlugRe.MatchString(f.Source.Project):
			return p, fmt.Errorf("flavor %s: source.project, the PaperMC project (paper, folia)", f.ID)
		case f.Source.Type != "papermc" && f.Source.Project != "":
			return p, fmt.Errorf("flavor %s: source.project is for papermc only", f.ID)
		}
		seen[f.ID] = true
		if a := f.Addons; a != nil {
			if a.Kind != "plugins" && a.Kind != "mods" {
				return p, fmt.Errorf("flavor %s: addons.kind plugins or mods", f.ID)
			}
			if len(a.ModrinthLoaders) < 1 || len(a.ModrinthLoaders) > 10 {
				return p, fmt.Errorf("flavor %s: addons.modrinth_loaders 1 to 10", f.ID)
			}
			for _, l := range a.ModrinthLoaders {
				if !gameSlugRe.MatchString(l) {
					return p, fmt.Errorf("flavor %s: addons.modrinth_loaders, Modrinth's loader names", f.ID)
				}
			}
			if a.Hangar && f.Source.Type != "papermc" {
				return p, fmt.Errorf("flavor %s: Hangar is for PaperMC servers", f.ID)
			}
		}
	}
	return p, nil
}

// gameDef: a game as the packages make it (its own package and the variants
// every package adds).
type gameDef struct {
	gamePkgGame
	Package string
	Flavors []gameFlavorDef
}

type gameFlavorDef struct {
	gamePkgFlavor
	Package string
}

func (d gameDef) flavor(id string) (gameFlavorDef, bool) {
	i := slices.IndexFunc(d.Flavors, func(f gameFlavorDef) bool { return f.ID == id })
	if i < 0 {
		return gameFlavorDef{}, false
	}
	return d.Flavors[i], true
}

func (d gameDef) available() bool { return slices.Contains(d.Arch, runtime.GOARCH) }

// gamePkgProblem: a package that is not in effect, and why.
type gamePkgProblem struct {
	ID     string `json:"id,omitempty"`
	Origin string `json:"origin"`
	File   string `json:"file"`
	Error  string `json:"error"`
}

func gamePkgDirs(env *Env) map[string]string {
	return map[string]string{
		"catalog": filepath.Join(env.Cfg.StateDir, "game-packages"),
		"local":   env.Cfg.GamePackagesDir,
	}
}

// loadGamePkgs: every package, built-in first, then installed from the
// catalog, then the administrator's own (each set by file name).
func loadGamePkgs(env *Env) ([]gamePkg, []gamePkgProblem) {
	var pkgs []gamePkg
	var problems []gamePkgProblem
	add := func(origin, file string, b []byte) {
		p, err := parseGamePkg(b)
		if err != nil {
			problems = append(problems, gamePkgProblem{Origin: origin, File: file, Error: err.Error()})
			return
		}
		p.Origin, p.File = origin, file
		pkgs = append(pkgs, p)
	}
	entries, _ := builtinGamePkgs.ReadDir("gamepkgs")
	for _, e := range entries {
		b, _ := builtinGamePkgs.ReadFile("gamepkgs/" + e.Name())
		add("builtin", e.Name(), b)
	}
	for _, origin := range []string{"catalog", "local"} {
		dir := gamePkgDirs(env)[origin]
		if dir == "" {
			continue
		}
		files, _ := filepath.Glob(filepath.Join(dir, "*.json"))
		sort.Strings(files)
		for _, f := range files {
			b, err := readGameFile(f) // no links, no FIFOs
			if err != nil {
				problems = append(problems, gamePkgProblem{Origin: origin, File: filepath.Base(f), Error: err.Error()})
				continue
			}
			add(origin, filepath.Base(f), b)
		}
	}
	return pkgs, problems
}

// gameRegistry: the games the packages in effect make. A package that
// clashes with an earlier one (its id, its game, a variant's id), or extends
// a game nobody adds, is left out and said why.
func gameRegistry(env *Env) (map[string]*gameDef, []gamePkg, []gamePkgProblem) {
	pkgs, problems := loadGamePkgs(env)
	defs := map[string]*gameDef{}
	used := map[string]bool{}
	var active []gamePkg
	skip := func(p gamePkg, why string) {
		problems = append(problems, gamePkgProblem{ID: p.ID, Origin: p.Origin, File: p.File, Error: why})
	}
	// Games first, then what extends them (in the same order).
	for _, pass := range []bool{true, false} {
		for _, p := range pkgs {
			if (p.Game != nil) != pass {
				continue
			}
			if used[p.ID] {
				skip(p, "a package with this id is already in effect")
				continue
			}
			var def *gameDef
			if p.Game != nil {
				if defs[p.Game.ID] != nil {
					skip(p, "the game "+p.Game.ID+" is already added by "+defs[p.Game.ID].Package)
					continue
				}
				def = &gameDef{gamePkgGame: *p.Game, Package: p.ID}
			} else if def = defs[p.Extends]; def == nil {
				skip(p, "extends "+p.Extends+", which no package adds")
				continue
			}
			ok := true
			for _, f := range p.Flavors {
				if _, dup := def.flavor(f.ID); dup {
					skip(p, "the variant "+f.ID+" of "+def.ID+" already exists")
					ok = false
					break
				}
				if !slices.Contains(gameEngines[def.Engine], f.Source.Type) {
					skip(p, fmt.Sprintf("the variant %s: %s cannot run a %s source", f.ID, def.Engine, f.Source.Type))
					ok = false
					break
				}
			}
			if !ok {
				continue
			}
			for _, f := range p.Flavors {
				def.Flavors = append(def.Flavors, gameFlavorDef{gamePkgFlavor: f, Package: p.ID})
			}
			if p.Game != nil {
				defs[p.Game.ID] = def
			}
			used[p.ID] = true
			active = append(active, p)
		}
	}
	return defs, active, problems
}

// gameDefOf: the game a server runs (what its packages make of it now).
func gameDefOf(env *Env, game string) (gameDef, bool) {
	defs, _, _ := gameRegistry(env)
	if d := defs[game]; d != nil {
		return *d, true
	}
	return gameDef{}, false
}

// gameEngine: how the agent runs a server's game ("" when no package adds
// it any more: the server stays, it just cannot be set up again).
func gameEngine(env *Env, g gameState) string {
	if d, ok := gameDefOf(env, g.Game); ok {
		return d.Engine
	}
	return legacyGameEngine(g.Game)
}

// legacyGameEngine: the two games servers existed for before packages.
func legacyGameEngine(game string) string {
	if game == "minecraft" || game == "factorio" {
		return game
	}
	return ""
}

// gameProto: the game port's protocol, kept in the state since packages
// (a removed package must not leave the firewall guessing).
func gameProto(env *Env, g gameState) string {
	if g.Proto != "" {
		return g.Proto
	}
	if d, ok := gameDefOf(env, g.Game); ok {
		return d.Proto
	}
	if g.Game == "factorio" {
		return "udp"
	}
	return "tcp"
}

// gameAddons: the plugins or mods a server's variant loads (nil: none).
func gameAddons(env *Env, g gameState) *gameAddonSpec {
	if d, ok := gameDefOf(env, g.Game); ok {
		if f, ok := d.flavor(g.Flavor); ok {
			return f.Addons
		}
	}
	return nil
}

// gameSourceOf: where a server's variant comes from.
func gameSourceOf(env *Env, g gameState) (gameSource, error) {
	d, ok := gameDefOf(env, g.Game)
	if !ok {
		return gameSource{}, fmt.Errorf("no installed game package adds %s any more", g.Game)
	}
	f, ok := d.flavor(g.Flavor)
	if !ok {
		return gameSource{}, fmt.Errorf("no installed game package adds the %s variant of %s any more", g.Flavor, g.Game)
	}
	return f.Source, nil
}

// gameLabel: the game's name for people (units, messages).
func gameLabel(env *Env, game string) string {
	if d, ok := gameDefOf(env, game); ok {
		return d.Label
	}
	return game
}

// writeGamePkg puts a checked manifest where its origin keeps them.
func writeGamePkg(env *Env, origin string, p gamePkg, b []byte) error {
	dir := gamePkgDirs(env)[origin]
	if dir == "" {
		return fmt.Errorf("no directory for %s packages", origin)
	}
	if err := env.FS.MkdirAll(dir, 0o755, fsx.Root); err != nil {
		return err
	}
	return env.FS.WriteFile(filepath.Join(dir, p.ID+".json"), b, 0o644, fsx.Root)
}
