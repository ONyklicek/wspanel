package ops

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"slices"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Game servers (an optional module, Server → Moduly): each one runs as its
// own system user (wsg_<id>) in its own sandboxed unit with the memory and
// CPU its package allows, binds only its own ports, and is reached by the
// panel over RCON on 127.0.0.1, which nothing but root may connect to.
//
// What a game is comes from its package (gamepkgs.go): its engine (how the
// agent runs it), port, memory and variants, each variant's server from a
// source verified by the checksum its publisher gives.

// gameState is what the agent keeps of a game server.
type gameState struct {
	ID           int    `json:"id"`
	Name         string `json:"name"`
	Game         string `json:"game"`
	Flavor       string `json:"flavor"`
	Proto        string `json:"proto,omitempty"` // the game port's protocol, from its package
	Version      string `json:"version"`
	Build        string `json:"build,omitempty"` // Paper's build number
	Java         int    `json:"java,omitempty"`  // Java major (Minecraft)
	Port         int    `json:"port"`
	RconPort     int    `json:"rcon_port"`
	RconPassword string `json:"rcon_password"`
	MemoryMB     int    `json:"memory_mb"`
	CPUPercent   int    `json:"cpu_percent,omitempty"` // of one core; 0 = no limit
	Group        string `json:"group,omitempty"`       // the client's group
	// The firewall rule is the panel's (a rule the admin had for the port
	// before stays, and stays on delete).
	OwnsFirewall bool `json:"owns_firewall,omitempty"`
	SFTP         bool `json:"sftp,omitempty"`          // SFTP into its home
	SFTPPassword bool `json:"sftp_password,omitempty"` // with a password (else keys only)
	// Settings: a native game's own (its package declares them); secrets too,
	// so never in what the panel is shown.
	Settings map[string]string `json:"settings,omitempty"`
}

func (g gameState) user() string       { return "wsg_" + strconv.Itoa(g.ID) }
func (g gameState) unit() string       { return "wspanel-game-" + strconv.Itoa(g.ID) + ".service" }
func gameHome(env *Env, id int) string { return filepath.Join(env.Cfg.GamesRoot, strconv.Itoa(id)) }
func gameStateDir(env *Env) string     { return filepath.Join(env.Cfg.StateDir, "games") }
func gameStatePath(env *Env, id int) string {
	return filepath.Join(gameStateDir(env), strconv.Itoa(id)+".json")
}

func loadGame(env *Env, id int) (gameState, error) {
	var g gameState
	b, err := os.ReadFile(gameStatePath(env, id))
	if os.IsNotExist(err) {
		return g, protocol.NotFound(fmt.Sprintf("game server %d does not exist", id))
	}
	if err != nil {
		return g, err
	}
	return g, json.Unmarshal(b, &g)
}

func saveGame(env *Env, g gameState) error {
	if err := env.FS.MkdirAll(gameStateDir(env), 0o700, fsx.Root); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(g, "", "  ")
	return env.FS.WriteFile(gameStatePath(env, g.ID), b, 0o600, fsx.Root)
}

func allGames(env *Env) []gameState {
	files, _ := filepath.Glob(filepath.Join(gameStateDir(env), "*.json"))
	out := []gameState{}
	for _, f := range files {
		id, err := strconv.Atoi(strings.TrimSuffix(filepath.Base(f), ".json"))
		if err != nil {
			continue
		}
		if g, err := loadGame(env, id); err == nil {
			out = append(out, g)
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out
}

// ---------------------------------------------------------------- catalog

var (
	gameUserAgent = func() string { return "WSPanel/" + Version + " (https://github.com/ONyklicek/wspanel)" }
	// The publishers' APIs (tests point them at a local server).
	paperAPIBase   = "https://fill.papermc.io/v3/projects" // + /<project> (paper, folia)
	fabricMeta     = "https://meta.fabricmc.net/v2"
	forgeMaven     = "https://maven.minecraftforge.net/net/minecraftforge/forge"
	forgePromos    = "https://files.minecraftforge.net/net/minecraftforge/forge/promotions_slim.json"
	mojangManifest = "https://piston-meta.mojang.com/mc/game/version_manifest_v2.json"
	factorioAPI    = "https://factorio.com"
	mcVersionRe    = regexp.MustCompile(`^\d+\.\d+(\.\d+)?$`)
	catalogCache   struct {
		sync.Mutex
		at   time.Time
		data map[string]any
	}
)

func fetchJSON(ctx context.Context, url string, v any) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return err
	}
	req.Header.Set("User-Agent", gameUserAgent())
	res, err := (&http.Client{Timeout: 30 * time.Second}).Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return fmt.Errorf("%s: HTTP %d", url, res.StatusCode)
	}
	return json.NewDecoder(io.LimitReader(res.Body, 8<<20)).Decode(v)
}

func fetchText(ctx context.Context, url string) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("User-Agent", gameUserAgent())
	res, err := (&http.Client{Timeout: 30 * time.Second}).Do(req)
	if err != nil {
		return "", err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return "", fmt.Errorf("%s: HTTP %d", url, res.StatusCode)
	}
	b, err := io.ReadAll(io.LimitReader(res.Body, 4<<20))
	return string(b), err
}

// mcJava: the Java a Minecraft version needs (versions before 1.20.5 need
// Java 17, which Debian 13 does not ship: not offered).
func mcJava(version string) int {
	p := strings.Split(version, ".")
	major, _ := strconv.Atoi(p[0])
	minor, patch := 0, 0
	if len(p) > 1 {
		minor, _ = strconv.Atoi(p[1])
	}
	if len(p) > 2 {
		patch, _ = strconv.Atoi(p[2])
	}
	switch {
	case major >= 26:
		return 25
	case major == 1 && (minor > 20 || minor == 20 && patch >= 5):
		return 21
	}
	return 0
}

// gameCatalog: the games the packages add and the versions each variant can
// install now (each source asked once an hour).
func gameCatalog(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	defs, _, _ := gameRegistry(env)
	out := map[string]any{}
	games := map[string]any{}
	for id, d := range defs {
		versions := map[string][]string{}
		flavors := []map[string]any{}
		for _, f := range d.Flavors {
			v := []string{}
			if d.available() {
				v = sourceVersions(ctx, f.Source)
			}
			versions[f.ID] = v
			flavors = append(flavors, map[string]any{"id": f.ID, "label": f.Label, "package": f.Package, "addons": f.Addons})
		}
		out[id] = versions
		game := map[string]any{"name": d.Label, "engine": d.Engine, "proto": d.Proto, "default_port": d.DefaultPort,
			"min_memory_mb": d.MinMemoryMB, "available": d.available(), "package": d.Package, "flavors": flavors, "console": true}
		if d.Run != nil {
			game["console"], game["disk_gb"], game["settings"] = d.Run.Console != nil, d.Run.DiskGB, len(d.Run.Settings) > 0
			if d.Run.Console != nil {
				game["commands"] = d.Run.Console.Commands
			}
		}
		games[id] = game
	}
	out["games"] = games
	return out, nil
}

// sourceVersions: the versions a source offers (an hour's cache per source;
// a source that does not answer offers nothing until it does).
func sourceVersions(ctx context.Context, src gameSource) []string {
	key := src.Type + "/" + src.Project
	catalogCache.Lock()
	defer catalogCache.Unlock()
	if catalogCache.data == nil {
		catalogCache.data = map[string]any{}
	}
	if c, ok := catalogCache.data[key].(cachedVersions); ok && time.Since(c.at) < time.Hour {
		return c.versions
	}
	v, err := fetchSourceVersions(ctx, src)
	if err != nil {
		return []string{}
	}
	v = sortVersionsDesc(v)
	if len(v) > 0 {
		catalogCache.data[key] = cachedVersions{at: time.Now(), versions: v}
	}
	return v
}

type cachedVersions struct {
	at       time.Time
	versions []string
}

func fetchSourceVersions(ctx context.Context, src gameSource) ([]string, error) {
	out := []string{}
	mc := func(v string) bool { return mcVersionRe.MatchString(v) && mcJava(v) > 0 }
	switch src.Type {
	case "papermc":
		var paper struct {
			Versions map[string][]string `json:"versions"`
		}
		if err := fetchJSON(ctx, paperAPIBase+"/"+src.Project, &paper); err != nil {
			return nil, err
		}
		for _, list := range paper.Versions {
			for _, v := range list {
				if mc(v) {
					out = append(out, v)
				}
			}
		}
	case "mojang":
		var mojang struct {
			Versions []struct {
				ID   string `json:"id"`
				Type string `json:"type"`
			} `json:"versions"`
		}
		if err := fetchJSON(ctx, mojangManifest, &mojang); err != nil {
			return nil, err
		}
		for _, v := range mojang.Versions {
			if v.Type == "release" && mc(v.ID) {
				out = append(out, v.ID)
			}
		}
	case "fabric":
		var games []struct {
			Version string `json:"version"`
			Stable  bool   `json:"stable"`
		}
		if err := fetchJSON(ctx, fabricMeta+"/versions/game", &games); err != nil {
			return nil, err
		}
		for _, v := range games {
			if v.Stable && mc(v.Version) {
				out = append(out, v.Version)
			}
		}
	case "forge":
		promos, err := forgePromotions(ctx)
		if err != nil {
			return nil, err
		}
		for k := range promos {
			if v, _, _ := strings.Cut(k, "-"); mc(v) {
				out = append(out, v)
			}
		}
	case "steamcmd":
		// Steam has no versions to choose: the branch's newest build.
		out = append(out, "latest")
	case "factorio":
		var fac struct {
			Stable struct {
				Headless string `json:"headless"`
			} `json:"stable"`
		}
		if err := fetchJSON(ctx, factorioAPI+"/api/latest-releases", &fac); err != nil {
			return nil, err
		}
		if mcVersionRe.MatchString(fac.Stable.Headless) {
			out = append(out, fac.Stable.Headless)
		}
	default:
		return nil, fmt.Errorf("unknown source %s", src.Type)
	}
	return out, nil
}

func sortVersionsDesc(v []string) []string {
	key := func(s string) []int {
		n := []int{}
		for _, p := range strings.Split(s, ".") {
			i, _ := strconv.Atoi(p)
			n = append(n, i)
		}
		return n
	}
	sort.Slice(v, func(i, j int) bool { return slices.Compare(key(v[i]), key(v[j])) > 0 })
	return slices.Compact(v)
}

// forgePromotions: Forge's recommended and latest build per Minecraft version.
func forgePromotions(ctx context.Context) (map[string]string, error) {
	var p struct {
		Promos map[string]string `json:"promos"`
	}
	if err := fetchJSON(ctx, forgePromos, &p); err != nil {
		return nil, err
	}
	return p.Promos, nil
}

// ---------------------------------------------------------------- downloads

type gameDownload struct {
	URL, Sum string // Sum: sha256 hex, or "sha1:<hex>" (Mojang)
	Build    string
	Java     int
	Size     int64
}

func resolveGameDownload(ctx context.Context, src gameSource, version string) (gameDownload, error) {
	switch src.Type {
	case "papermc":
		var b struct {
			ID        int `json:"id"`
			Downloads map[string]struct {
				Checksums struct {
					SHA256 string `json:"sha256"`
				} `json:"checksums"`
				Size int64  `json:"size"`
				URL  string `json:"url"`
			} `json:"downloads"`
		}
		if err := fetchJSON(ctx, paperAPIBase+"/"+src.Project+"/versions/"+version+"/builds/latest", &b); err != nil {
			return gameDownload{}, err
		}
		d, ok := b.Downloads["server:default"]
		if !ok || len(d.Checksums.SHA256) != 64 || !strings.HasPrefix(d.URL, "https://") {
			return gameDownload{}, fmt.Errorf("%s %s: no server download", src.Project, version)
		}
		return gameDownload{URL: d.URL, Sum: d.Checksums.SHA256, Build: strconv.Itoa(b.ID), Java: mcJava(version), Size: d.Size}, nil
	case "fabric":
		// The official installer (its SHA-256 from Fabric's maven) sets the
		// server up as the game's user, with the newest stable loader.
		var installers, loaders []struct {
			Version string `json:"version"`
			URL     string `json:"url"`
			Stable  bool   `json:"stable"`
		}
		if err := fetchJSON(ctx, fabricMeta+"/versions/installer", &installers); err != nil {
			return gameDownload{}, err
		}
		if err := fetchJSON(ctx, fabricMeta+"/versions/loader", &loaders); err != nil {
			return gameDownload{}, err
		}
		inst := slices.IndexFunc(installers, func(v struct {
			Version string `json:"version"`
			URL     string `json:"url"`
			Stable  bool   `json:"stable"`
		}) bool {
			return v.Stable && strings.HasPrefix(v.URL, "https://maven.fabricmc.net/")
		})
		load := slices.IndexFunc(loaders, func(v struct {
			Version string `json:"version"`
			URL     string `json:"url"`
			Stable  bool   `json:"stable"`
		}) bool {
			return v.Stable && mcVersionRe.MatchString(v.Version)
		})
		if inst < 0 || load < 0 {
			return gameDownload{}, fmt.Errorf("fabric: no stable installer or loader")
		}
		sum, err := fetchText(ctx, installers[inst].URL+".sha256")
		if err != nil || len(strings.TrimSpace(sum)) < 64 {
			return gameDownload{}, fmt.Errorf("fabric installer %s: no published checksum", installers[inst].Version)
		}
		return gameDownload{URL: installers[inst].URL, Sum: strings.TrimSpace(sum)[:64], Build: loaders[load].Version, Java: mcJava(version)}, nil
	case "forge":
		promos, err := forgePromotions(ctx)
		if err != nil {
			return gameDownload{}, err
		}
		build := promos[version+"-recommended"]
		if build == "" {
			build = promos[version+"-latest"]
		}
		if !regexp.MustCompile(`^\d+\.\d+\.\d+$`).MatchString(build) {
			return gameDownload{}, protocol.Invalid("version: Forge has no build for Minecraft " + version)
		}
		u := fmt.Sprintf("%s/%s-%s/forge-%s-%s-installer.jar", forgeMaven, version, build, version, build)
		sum, err := fetchText(ctx, u+".sha256")
		if err != nil || len(strings.TrimSpace(sum)) < 64 {
			return gameDownload{}, fmt.Errorf("forge %s-%s: no published checksum", version, build)
		}
		return gameDownload{URL: u, Sum: strings.TrimSpace(sum)[:64], Build: build, Java: mcJava(version)}, nil
	case "mojang":
		var m struct {
			Versions []struct {
				ID, URL, SHA1 string
			} `json:"versions"`
		}
		if err := fetchJSON(ctx, mojangManifest, &m); err != nil {
			return gameDownload{}, err
		}
		for _, v := range m.Versions {
			if v.ID != version {
				continue
			}
			var meta struct {
				JavaVersion struct {
					MajorVersion int `json:"majorVersion"`
				} `json:"javaVersion"`
				Downloads struct {
					Server struct {
						SHA1 string `json:"sha1"`
						Size int64  `json:"size"`
						URL  string `json:"url"`
					} `json:"server"`
				} `json:"downloads"`
			}
			if err := fetchJSON(ctx, v.URL, &meta); err != nil {
				return gameDownload{}, err
			}
			s := meta.Downloads.Server
			if len(s.SHA1) != 40 || !strings.HasPrefix(s.URL, "https://") {
				return gameDownload{}, fmt.Errorf("minecraft %s: no server download", version)
			}
			java := meta.JavaVersion.MajorVersion
			if java == 0 {
				java = mcJava(version)
			}
			return gameDownload{URL: s.URL, Sum: "sha1:" + s.SHA1, Java: java, Size: s.Size}, nil
		}
		return gameDownload{}, protocol.Invalid("version: Minecraft " + version + " does not exist")
	case "factorio":
		sums, err := fetchText(ctx, factorioAPI+"/download/sha256sums/")
		if err != nil {
			return gameDownload{}, err
		}
		name := "factorio-headless_linux_" + version + ".tar.xz"
		for _, line := range strings.Split(sums, "\n") {
			if f := strings.Fields(line); len(f) == 2 && f[1] == name && len(f[0]) == 64 {
				return gameDownload{URL: factorioAPI + "/get-download/" + version + "/headless/linux64", Sum: f[0]}, nil
			}
		}
		return gameDownload{}, fmt.Errorf("factorio %s: no published checksum", version)
	}
	if src.Type == "steamcmd" {
		if version != "latest" {
			return gameDownload{}, protocol.Invalid("version: latest (Steam installs the newest build)")
		}
		return gameDownload{}, nil // SteamCMD fetches it itself (steamInstall)
	}
	return gameDownload{}, protocol.Invalid("flavor: unknown source " + src.Type)
}

// ---------------------------------------------------------------- RCON

// rconExec runs one command over the Source RCON protocol (Minecraft and
// Factorio both speak it) and returns its answer.
func rconExec(ctx context.Context, port int, password, command string) (string, error) {
	d := net.Dialer{Timeout: 3 * time.Second}
	conn, err := d.DialContext(ctx, "tcp", "127.0.0.1:"+strconv.Itoa(port))
	if err != nil {
		return "", err
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(10 * time.Second))
	send := func(id, typ int32, body string) error {
		b := make([]byte, 0, 14+len(body))
		b = appendLE(b, int32(10+len(body)))
		b = appendLE(b, id)
		b = appendLE(b, typ)
		b = append(b, body...)
		b = append(b, 0, 0)
		_, err := conn.Write(b)
		return err
	}
	read := func() (int32, string, error) {
		head := make([]byte, 12)
		if _, err := io.ReadFull(conn, head); err != nil {
			return 0, "", err
		}
		size := int32(le(head[0:4]))
		if size < 10 || size > 1<<20 {
			return 0, "", fmt.Errorf("rcon: bad packet")
		}
		body := make([]byte, size-8)
		if _, err := io.ReadFull(conn, body); err != nil {
			return 0, "", err
		}
		return int32(le(head[4:8])), strings.TrimRight(string(body), "\x00"), nil
	}
	if err := send(1, 3, password); err != nil {
		return "", err
	}
	for {
		id, _, err := read()
		if err != nil {
			return "", err
		}
		if id == -1 {
			return "", fmt.Errorf("rcon: wrong password")
		}
		if id == 1 {
			break
		}
	}
	if err := send(2, 2, command); err != nil {
		return "", err
	}
	_, out, err := read()
	return out, err
}

func appendLE(b []byte, v int32) []byte {
	return append(b, byte(v), byte(v>>8), byte(v>>16), byte(v>>24))
}

func le(b []byte) uint32 { return uint32(b[0]) | uint32(b[1])<<8 | uint32(b[2])<<16 | uint32(b[3])<<24 }

// rconGuard: only root (the agent) may connect to the servers' RCON ports;
// a site user on the same server could otherwise try the password.
func applyRconGuard(ctx context.Context, env *Env) error {
	ports := []string{}
	for _, g := range allGames(env) {
		if g.RconPort > 0 {
			ports = append(ports, strconv.Itoa(g.RconPort))
		}
	}
	slices.Sort(ports)
	ports = slices.Compact(ports)
	file := filepath.Join(gameStateDir(env), "rcon-guard.nft")
	rules := "table inet wspanel_games\ndelete table inet wspanel_games\n"
	if len(ports) > 0 {
		rules += "table inet wspanel_games {\n  chain output {\n    type filter hook output priority 0; policy accept;\n" +
			"    tcp dport { " + strings.Join(ports, ", ") + " } meta skuid != 0 reject\n  }\n}\n"
	}
	if err := env.FS.MkdirAll(gameStateDir(env), 0o700, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(file, []byte(rules), 0o600, fsx.Root); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "nft", "-f", file); err != nil {
		return fmt.Errorf("loading the RCON guard: %w", err)
	}
	unit := filepath.Join(env.Cfg.SystemdDir, "wspanel-games-rcon.service")
	content := "# Managed by WSPanel agent.\n[Unit]\nDescription=WSPanel: game servers' RCON for root only\nBefore=network-pre.target\n\n" +
		"[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/sbin/nft -f " + file + "\n\n[Install]\nWantedBy=multi-user.target\n"
	if changed, err := writeIfChanged(env, unit, content); err != nil {
		return err
	} else if changed {
		_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
		_, _ = env.Exec.Run(ctx, "systemctl", "enable", "wspanel-games-rcon.service")
	}
	return nil
}

// ---------------------------------------------------------------- ports

// gamePortFree: not another game's, not protected, not the sites' range,
// and nothing listens there now.
func gamePortFree(env *Env, port int, proto string, except int) error {
	if port < 1025 || port > 65535 {
		return protocol.Invalid("port: 1025 to 65535")
	}
	if slices.Contains(env.Cfg.ProtectedPorts, port) || port >= env.Cfg.NodePortMin && port <= env.Cfg.NodePortMax {
		return protocol.Invalid(fmt.Sprintf("port: %d is the panel's", port))
	}
	// A game's port is opened to the world: only in the games' range, never
	// RCON's (27000-27999) nor one a known service uses within it.
	if except >= 0 && proto != "rcon" {
		if port < gamePortMin || port > gamePortMax || port >= 27000 && port <= 27999 || slices.Contains(reservedGamePorts, port) {
			return protocol.Invalid(fmt.Sprintf("port: game servers use %d to %d (not 27000-27999)", gamePortMin, gamePortMax))
		}
	}
	if proto == "rcon" {
		proto = "tcp"
	}
	for _, g := range allGames(env) {
		if g.ID != except && (g.Port == port || g.RconPort == port) {
			return protocol.Conflict(fmt.Sprintf("port %d is used by game server %d", port, g.ID))
		}
	}
	if env.Cfg.DryRun {
		return nil
	}
	if proto == "udp" {
		c, err := net.ListenPacket("udp", ":"+strconv.Itoa(port))
		if err != nil {
			return protocol.Conflict(fmt.Sprintf("port %d/udp is in use", port))
		}
		c.Close()
		return nil
	}
	ln, err := net.Listen("tcp", ":"+strconv.Itoa(port))
	if err != nil {
		return protocol.Conflict(fmt.Sprintf("port %d/tcp is in use", port))
	}
	ln.Close()
	return nil
}

// The range game ports are opened in, and services within it that must not be.
var (
	gamePortMin, gamePortMax = 20000, 40000
	reservedGamePorts        = []int{25575, 27017, 27018, 27019, 28017, 33060, 32400}
)

// freeRconPort: a TCP port for RCON, from 27000 up.
func freeRconPort(env *Env, except int) (int, error) {
	for p := 27000; p < 28000; p++ {
		if gamePortFree(env, p, "rcon", except) == nil {
			return p, nil
		}
	}
	return 0, fmt.Errorf("no free RCON port in 27000-27999")
}

// ufwAllows: whether the firewall already lets port/proto in (a rule the
// admin made): the panel then neither adds one nor removes it later.
func ufwAllows(ctx context.Context, env *Env, port int, proto string) bool {
	res, err := env.Exec.Run(ctx, "ufw", "status")
	if err != nil {
		return false
	}
	_, rules := parseUFWStatus(res.Stdout)
	for _, r := range rules {
		if r.Port == port && (r.Proto == proto || r.Proto == "") && strings.EqualFold(r.Action, "allow") && (r.From == "" || strings.HasPrefix(r.From, "Anywhere")) {
			return true
		}
	}
	return false
}

// ufwHasGameRule: a rule for the server's port with the panel's comment for it.
func ufwHasGameRule(ctx context.Context, env *Env, g gameState) bool {
	res, err := env.Exec.Run(ctx, "ufw", "status")
	if err != nil {
		return false
	}
	_, rules := parseUFWStatus(res.Stdout)
	for _, r := range rules {
		if r.Port == g.Port && r.Comment == "wspanel game "+strconv.Itoa(g.ID) {
			return true
		}
	}
	return false
}

// ---------------------------------------------------------------- install

// ensureJava installs Debian's OpenJDK of that major, headless (on demand:
// the base stays without Java).
func ensureJava(ctx context.Context, env *Env, major int) (string, error) {
	if major != 21 && major != 25 {
		return "", protocol.Invalid(fmt.Sprintf("version: needs Java %d, which Debian 13 does not ship", major))
	}
	java := filepath.Join(env.Cfg.JavaDir, fmt.Sprintf("java-%d-openjdk-%s", major, runtime.GOARCH), "bin", "java")
	if fsx.Exists(java) || env.Cfg.DryRun {
		return java, nil
	}
	if _, err := aptRun(ctx, env, "--no-install-recommends", "install", fmt.Sprintf("openjdk-%d-jre-headless", major)); err != nil {
		return "", fmt.Errorf("installing Java %d failed: %w", major, err)
	}
	return java, nil
}

func gameJava(env *Env, g gameState) string {
	return filepath.Join(env.Cfg.JavaDir, fmt.Sprintf("java-%d-openjdk-%s", g.Java, runtime.GOARCH), "bin", "java")
}

func gameExecStart(env *Env, g gameState) string {
	home := gameHome(env, g.ID)
	switch gameEngine(env, g) {
	case "minecraft":
		jvm := fmt.Sprintf("%s -Xms%dM -Xmx%dM -XX:+UseG1GC -XX:+ParallelRefProcEnabled -XX:MaxGCPauseMillis=200", gameJava(env, g), g.MemoryMB/2, g.MemoryMB)
		src, _ := gameSourceOf(env, g)
		switch src.Type {
		case "fabric":
			return jvm + " -jar " + home + "/data/fabric-server-launch.jar nogui"
		case "forge":
			// Forge's own argument file (its classpath, relative to data/).
			return fmt.Sprintf("%s @libraries/net/minecraftforge/forge/%s-%s/unix_args.txt nogui", jvm, g.Version, g.Build)
		}
		return jvm + " -jar " + home + "/server/server.jar --nogui"
	case "native":
		if r, err := nativeRun(env, g); err == nil {
			return nativeExecStart(env, g, r)
		}
	case "factorio":
		return fmt.Sprintf("%s/server/factorio/bin/x64/factorio --config %s/data/config.ini --start-server-load-latest --server-settings %s/data/server-settings.json --port %d --rcon-bind 127.0.0.1:%d --rcon-password %s",
			home, home, home, g.Port, g.RconPort, g.RconPassword)
	}
	return ""
}

// writeGameUnit: the sandboxed unit with the package's limits; it binds only
// the game's port and RCON.
func writeGameUnit(ctx context.Context, env *Env, g gameState) error {
	home := gameHome(env, g.ID)
	memMax := g.MemoryMB
	engine := gameEngine(env, g)
	if engine == "minecraft" {
		memMax = g.MemoryMB*5/4 + 256 // the heap plus the JVM's own
	}
	extra := []string{
		"CapabilityBoundingSet=",
		// Its command line (RCON password, a server token) is nobody else's.
		"ProtectProc=invisible",
		"SocketBindAllow=" + gameProto(env, g) + ":" + strconv.Itoa(g.Port),
	}
	if g.RconPort > 0 {
		extra = append(extra, "SocketBindAllow=tcp:"+strconv.Itoa(g.RconPort))
	}
	extra = append(extra,
		"SocketBindDeny=any",
		fmt.Sprintf("MemoryMax=%dM", memMax),
		"MemorySwapMax=0",
		"SuccessExitStatus=143",
		"Nice=5",
	)
	if g.CPUPercent > 0 {
		extra = append(extra, fmt.Sprintf("CPUQuota=%d%%", g.CPUPercent))
	}
	if engine == "factorio" {
		extra = append(extra, "KillSignal=SIGINT") // saves the map on the way out
	}
	if r, err := nativeRun(env, g); err == nil && r.StopSignal != "" {
		extra = append(extra, "KillSignal="+r.StopSignal)
	}
	unit, err := render("unit.service.tmpl", unitData{
		Description: fmt.Sprintf("WSPanel game server %d (%s): %s", g.ID, gameLabel(env, g.Game), g.Name),
		User:        g.user(), Home: home, WorkDir: filepath.Join(home, "data"),
		ExecStart: gameExecStart(env, g), StopTimeout: 120, Extra: extra,
	})
	if err != nil {
		return err
	}
	// The unit holds Factorio's RCON password: root only.
	path := filepath.Join(env.Cfg.SystemdDir, g.unit())
	if err := env.FS.WriteFile(path, unit, 0o600, fsx.Root); err != nil {
		return err
	}
	if _, err := env.Exec.Run(ctx, "systemd-analyze", "verify", path); err != nil {
		return fmt.Errorf("game unit invalid: %w", err)
	}
	_, err = env.Exec.Run(ctx, "systemctl", "daemon-reload")
	return err
}

// placeDownload fetches the server and puts it into the home as the game's
// user (the home is the game's: root never writes into it by path).
func placeDownload(ctx context.Context, env *Env, g gameState, d gameDownload) error {
	home := gameHome(env, g.ID)
	if env.Cfg.DryRun {
		return nil
	}
	if src, err := gameSourceOf(env, g); err == nil && src.Type == "steamcmd" {
		return steamInstall(ctx, env, g, src)
	}
	file, err := downloadVerified(ctx, d.URL, d.Sum, 2<<30)
	if err != nil {
		return fmt.Errorf("downloading %s %s failed: %w", gameLabel(env, g.Game), g.Version, err)
	}
	defer os.Remove(file)
	if err := os.Chmod(file, 0o644); err != nil {
		return err
	}
	src, err := gameSourceOf(env, g)
	if err != nil {
		return err
	}
	switch src.Type {
	case "papermc", "mojang", "fabric", "forge":
		switch src.Type {
		case "fabric", "forge":
			// The loader's installer, run as the game's user with its Java.
			installer := filepath.Join(home, "server", src.Type+"-installer.jar")
			if err := asUser(ctx, env, g.user(), "/usr/bin/install", "-m", "0640", file, installer); err != nil {
				return err
			}
			args := []string{"-jar", installer, "--installServer", filepath.Join(home, "data")}
			if src.Type == "fabric" {
				args = []string{"-jar", installer, "server", "-dir", filepath.Join(home, "data"), "-mcversion", g.Version, "-loader", g.Build, "-downloadMinecraft"}
			}
			ictx, cancel := context.WithTimeout(ctx, 20*time.Minute)
			defer cancel()
			full := append(append(userCreds(g.user()), gameJava(env, g), "-Duser.home="+filepath.Join(home, "tmp")), args...)
			if res, err := env.Exec.RunIO(ictx, nil, nil, "setpriv", full...); err != nil {
				return fmt.Errorf("the %s installer failed: %s", src.Type, tail(res.Stdout, 600))
			}
			return nil
		}
		return asUser(ctx, env, g.user(), "/usr/bin/install", "-m", "0640", file, filepath.Join(home, "server", "server.jar"))
	case "factorio":
		// A new copy beside the old one, then swapped: a failed update keeps it.
		next := filepath.Join(home, "server", "factorio.new")
		_ = asUser(ctx, env, g.user(), "/usr/bin/rm", "-rf", "--", next)
		if err := asUser(ctx, env, g.user(), "/usr/bin/install", "-d", "-m", "0750", next); err != nil {
			return err
		}
		if err := asUser(ctx, env, g.user(), "/usr/bin/tar", "-xJf", file, "-C", next, "--strip-components=1", "--no-same-owner"); err != nil {
			return fmt.Errorf("unpacking Factorio failed: %w", err)
		}
		cur := filepath.Join(home, "server", "factorio")
		_ = asUser(ctx, env, g.user(), "/usr/bin/rm", "-rf", "--", cur)
		return asUser(ctx, env, g.user(), "/usr/bin/mv", "-T", "--", next, cur)
	}
	return nil
}

// writeAsUser writes a small file into the game's home as its user.
func writeAsUser(ctx context.Context, env *Env, g gameState, path string, data []byte, mode string) error {
	if env.Cfg.DryRun {
		return nil
	}
	tmp, err := os.CreateTemp("", "wspanel-game-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	tmp.Close()
	if err := os.Chmod(tmp.Name(), 0o644); err != nil {
		return err
	}
	return asUser(ctx, env, g.user(), "/usr/bin/install", "-m", mode, tmp.Name(), path)
}

// configureGame writes the settings the panel owns (port, RCON) and keeps
// the rest of the game's own configuration.
func configureGame(ctx context.Context, env *Env, g gameState, eula bool) error {
	data := filepath.Join(gameHome(env, g.ID), "data")
	switch gameEngine(env, g) {
	case "native":
		r, err := nativeRun(env, g)
		if err != nil {
			return err
		}
		return configureNative(ctx, env, g, r)
	case "minecraft":
		if eula {
			if err := writeAsUser(ctx, env, g, filepath.Join(data, "eula.txt"),
				[]byte("# Accepted in WSPanel by the administrator (https://aka.ms/MinecraftEULA)\neula=true\n"), "0640"); err != nil {
				return err
			}
		}
		props := map[string]string{
			"server-port": strconv.Itoa(g.Port), "enable-rcon": "true", "rcon.port": strconv.Itoa(g.RconPort),
			"rcon.password": g.RconPassword, "broadcast-rcon-to-ops": "false", "enable-query": "false",
		}
		cur := ""
		if b, err := readGameFile(filepath.Join(data, "server.properties")); err == nil {
			cur = string(b)
		} else {
			props["motd"] = escapeProperty(strings.NewReplacer("\n", " ").Replace(g.Name))
		}
		return writeAsUser(ctx, env, g, filepath.Join(data, "server.properties"), []byte(setProperties(cur, props)), "0640")
	case "factorio":
		cfg := "; Managed by WSPanel: where Factorio keeps saves, mods and settings.\n[path]\nread-data=__PATH__executable__/../../data\nwrite-data=" + data + "\n"
		if err := writeAsUser(ctx, env, g, filepath.Join(data, "config.ini"), []byte(cfg), "0640"); err != nil {
			return err
		}
		if _, err := readGameFile(filepath.Join(data, "server-settings.json")); err != nil {
			settings, _ := json.MarshalIndent(map[string]any{
				"name": g.Name, "description": "", "visibility": map[string]bool{"public": false, "lan": true},
				"require_user_verification": true, "max_players": 0, "auto_pause": true, "autosave_interval": 10,
				"autosave_slots": 5, "only_admins_can_pause_the_game": true,
			}, "", "  ")
			return writeAsUser(ctx, env, g, filepath.Join(data, "server-settings.json"), settings, "0640")
		}
	}
	return nil
}

// readGameFile reads a file of a game's home as root without following a
// link or blocking on a FIFO the game could have put there.
func readGameFile(path string) ([]byte, error) { return readVenvCfg(path) }

// setProperties sets keys in a Java properties text, keeping the others.
func setProperties(text string, set map[string]string) string {
	lines := []string{}
	done := map[string]bool{}
	for _, l := range strings.Split(strings.TrimRight(text, "\n"), "\n") {
		k, _, ok := strings.Cut(l, "=")
		k = strings.TrimSpace(k)
		if v, mine := set[k]; ok && mine && !strings.HasPrefix(strings.TrimSpace(l), "#") {
			if done[k] {
				continue
			}
			l, done[k] = k+"="+v, true
		}
		if l != "" || len(lines) > 0 {
			lines = append(lines, l)
		}
	}
	keys := make([]string, 0, len(set))
	for k := range set {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		if !done[k] {
			lines = append(lines, k+"="+set[k])
		}
	}
	return strings.Join(lines, "\n") + "\n"
}

var gameNameRe = regexp.MustCompile(`^[^\x00-\x1f\x7f"\\]{1,64}$`)

type gameParams struct {
	ID         int    `json:"id"`
	Name       string `json:"name"`
	Game       string `json:"game"`
	Flavor     string `json:"flavor"`
	Version    string `json:"version"`
	Port       int    `json:"port"`
	MemoryMB   int    `json:"memory_mb"`
	CPUPercent int    `json:"cpu_percent"`
	Group      string `json:"group,omitempty"`
	EULA       bool   `json:"eula,omitempty"`
}

func (p *gameParams) validate(env *Env) error {
	defs, _, _ := gameRegistry(env)
	r := defs[p.Game]
	flavors := []string{}
	if r != nil {
		for _, f := range r.Flavors {
			flavors = append(flavors, f.ID)
		}
	}
	switch {
	case p.ID < 1 || p.ID > 1_000_000:
		return protocol.Invalid("id: 1 to 1000000")
	case r == nil:
		return protocol.Invalid("game: " + strings.Join(sortedKeys(defs), " or ") + " (the installed game packages)")
	case !r.available():
		return protocol.Invalid("game: " + r.Label + " has no server for this processor (" + hostArch + ")")
	case !slices.Contains(flavors, p.Flavor):
		return protocol.Invalid("flavor: " + strings.Join(flavors, " or "))
	case r.Engine == "native" && p.Version != "latest":
		return protocol.Invalid("version: latest")
	case r.Engine != "native" && !mcVersionRe.MatchString(p.Version):
		return protocol.Invalid("version: e.g. 1.21.10")
	case r.Engine == "minecraft" && mcJava(p.Version) == 0:
		return protocol.Invalid("version: Minecraft before 1.20.5 needs Java 17, which Debian 13 does not ship")
	case !gameNameRe.MatchString(p.Name):
		return protocol.Invalid("name: 1 to 64 characters, no quotes or control characters")
	case p.MemoryMB < r.MinMemoryMB || p.MemoryMB > 256*1024:
		return protocol.Invalid(fmt.Sprintf("memory_mb: %d to 262144", r.MinMemoryMB))
	case p.CPUPercent < 0 || p.CPUPercent > 6400:
		return protocol.Invalid("cpu_percent: 0 (no limit) to 6400")
	case r.Engine == "minecraft" && !p.EULA:
		return protocol.Invalid("eula: Minecraft's server needs its EULA accepted")
	}
	if p.Group != "" {
		if err := validate.ClientGroup("group", p.Group); err != nil {
			return err
		}
	}
	if p.Port == 0 {
		p.Port = r.DefaultPort
	}
	return nil
}

// gameCreate (async): the user, the home, Java when needed, the server from
// its publisher, its settings, the unit, the firewall, and a start. Not a
// mutating op as a whole: it holds the agent's lock for the privileged steps
// only, so a long download (a SteamCMD game is tens of GB) does not stop the
// rest of the panel; the server is claimed meanwhile.
func gameCreate(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p gameParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	release, err := claimGame(p.ID)
	if err != nil {
		return nil, err
	}
	defer release()
	var g gameState
	var def gameDef
	var src gameSource
	var dl gameDownload
	var rollback undo
	defer func() {
		if err != nil {
			_ = withMutate(env, func() error { rollback.run(); return nil })
		}
	}()
	if err = withMutate(env, func() error {
		if err := p.validate(env); err != nil {
			return err
		}
		if _, err := loadGame(env, p.ID); err == nil {
			return protocol.Conflict(fmt.Sprintf("game server %d already exists", p.ID))
		}
		def, _ = gameDefOf(env, p.Game)
		if def.Run != nil {
			if err := checkDisk(env, def.Run.DiskGB); err != nil {
				return err
			}
		}
		if err := gamePortFree(env, p.Port, def.Proto, p.ID); err != nil {
			return err
		}
		g = gameState{ID: p.ID, Name: p.Name, Game: p.Game, Flavor: p.Flavor, Proto: def.Proto, Version: p.Version, Port: p.Port,
			RconPassword: randomHex(24), MemoryMB: p.MemoryMB, CPUPercent: p.CPUPercent, Group: p.Group}
		switch {
		case def.Run == nil:
			rcon, err := freeRconPort(env, p.ID)
			if err != nil {
				return err
			}
			g.RconPort = rcon
		case def.Run.Console != nil && def.Run.Console.Port == "own":
			rcon, err := freeRconPort(env, p.ID)
			if err != nil {
				return err
			}
			g.RconPort = rcon
		case def.Run.Console != nil:
			g.RconPort = g.Port // Source RCON: TCP on the game's port
		}
		var err error
		if src, err = gameSourceOf(env, g); err != nil {
			return err
		}
		if dl, err = resolveGameDownload(ctx, src, g.Version); err != nil {
			return err
		}
		g.Build, g.Java = dl.Build, dl.Java
		switch {
		case def.Engine == "minecraft":
			if _, err := ensureJava(ctx, env, g.Java); err != nil {
				return err
			}
		case src.Type == "steamcmd":
			if err := ensureSteamDeps(ctx, env); err != nil {
				return err
			}
		}
		home := gameHome(env, g.ID)
		groupArgs := []string{"--user-group"}
		if g.Group != "" {
			groupArgs = []string{"--gid", g.Group}
		}
		if _, err := env.Exec.Run(ctx, "useradd", append(append([]string{"--system"}, groupArgs...), "--no-create-home",
			"--home-dir", home, "--shell", "/usr/sbin/nologin", "--comment", "WSPanel game server "+strconv.Itoa(g.ID), g.user())...); err != nil {
			return err
		}
		rollback.add(func() { _, _ = env.Exec.Run(context.WithoutCancel(ctx), "userdel", g.user()) })
		owner, err := lookupOwner(env, g.user())
		if err != nil {
			return err
		}
		if err := env.FS.MkdirAll(env.Cfg.GamesRoot, 0o751, fsx.Root); err != nil {
			return err
		}
		if err := env.FS.Mkdir(home, 0o750, owner); err != nil {
			return err
		}
		rollback.add(func() { _ = os.RemoveAll(home) })
		for _, d := range []string{"server", "data", "tmp"} {
			if err := env.FS.Mkdir(filepath.Join(home, d), 0o750, owner); err != nil {
				return err
			}
		}
		// Kept now, so its ports are taken while it downloads.
		if err := saveGame(env, g); err != nil {
			return err
		}
		rollback.add(func() { _ = os.Remove(gameStatePath(env, g.ID)) })
		return nil
	}); err != nil {
		return nil, err
	}

	// The long part, as the game's user, without the lock.
	if err = placeDownload(ctx, env, g, dl); err != nil {
		return nil, err
	}

	err = withMutate(env, func() error {
		home := gameHome(env, g.ID)
		if src.Type == "steamcmd" {
			g.Build = steamBuildID(env, g, src)
		}
		if err := configureGame(ctx, env, g, p.EULA); err != nil {
			return err
		}
		if def.Engine == "factorio" && !env.Cfg.DryRun {
			// The first map, made by the game itself.
			if err := asUser(ctx, env, g.user(), filepath.Join(home, "server/factorio/bin/x64/factorio"),
				"--config", filepath.Join(home, "data/config.ini"), "--create", filepath.Join(home, "data/saves/world.zip")); err != nil {
				return fmt.Errorf("creating the Factorio map failed: %w", err)
			}
		}
		if err := saveGame(env, g); err != nil {
			return err
		}
		if err := applyRconGuard(ctx, env); err != nil {
			return err
		}
		if err := writeGameUnit(ctx, env, g); err != nil {
			return err
		}
		rollback.add(func() {
			_ = os.Remove(filepath.Join(env.Cfg.SystemdDir, g.unit()))
			_, _ = env.Exec.Run(context.WithoutCancel(ctx), "systemctl", "daemon-reload")
		})
		if !ufwAllows(ctx, env, g.Port, gameProto(env, g)) {
			if _, err := env.Exec.Run(ctx, "ufw", "allow", fmt.Sprintf("%d/%s", g.Port, gameProto(env, g)), "comment", "wspanel game "+strconv.Itoa(g.ID)); err != nil {
				return err
			}
			g.OwnsFirewall = true
			if err := saveGame(env, g); err != nil {
				return err
			}
			rollback.add(func() {
				_, _ = env.Exec.Run(context.WithoutCancel(ctx), "ufw", "delete", "allow", fmt.Sprintf("%d/%s", g.Port, gameProto(env, g)))
			})
		}
		_, err := env.Exec.Run(ctx, "systemctl", "enable", "--now", g.unit())
		return err
	})
	if err != nil {
		return nil, err
	}
	return gameView(ctx, env, g, false), nil
}

// A game server's long job (an install, an update) claims it: deleting or
// restoring it meanwhile is refused.
var gameJobs = struct {
	sync.Mutex
	busy map[int]bool
}{busy: map[int]bool{}}

func claimGame(id int) (func(), error) {
	gameJobs.Lock()
	defer gameJobs.Unlock()
	if gameJobs.busy[id] {
		return nil, protocol.Conflict(fmt.Sprintf("game server %d is being installed or updated", id))
	}
	gameJobs.busy[id] = true
	return func() {
		gameJobs.Lock()
		delete(gameJobs.busy, id)
		gameJobs.Unlock()
	}, nil
}

func gameBusy(id int) error {
	gameJobs.Lock()
	defer gameJobs.Unlock()
	if gameJobs.busy[id] {
		return protocol.Conflict(fmt.Sprintf("game server %d is being installed or updated: wait until it ends", id))
	}
	return nil
}

// gameView: the server as the panel shows it (never the RCON password).
func gameView(ctx context.Context, env *Env, g gameState, players bool) map[string]any {
	out := map[string]any{
		"id": g.ID, "name": g.Name, "game": g.Game, "flavor": g.Flavor, "version": g.Version, "build": g.Build,
		"port": g.Port, "proto": gameProto(env, g), "memory_mb": g.MemoryMB, "cpu_percent": g.CPUPercent, "unit": g.unit(),
	}
	if st, err := unitStates(ctx, env, []string{g.unit()}); err == nil && len(st) == 1 {
		out["state"], out["sub_state"] = st[0].Active, st[0].Sub
	}
	// Memory now, the CPU time used so far (two samples give the load) and
	// since when it runs: what the panel's live view draws.
	if res, err := env.Exec.Run(ctx, "systemctl", "show", "--timestamp=unix", "--property=MemoryCurrent,CPUUsageNSec,ActiveEnterTimestamp,NRestarts", g.unit()); err == nil {
		for _, line := range strings.Split(res.Stdout, "\n") {
			k, v, _ := strings.Cut(strings.TrimSpace(line), "=")
			n, err := strconv.ParseInt(strings.TrimPrefix(v, "@"), 10, 64)
			if err != nil {
				continue // [not set] / an empty timestamp
			}
			switch k {
			case "MemoryCurrent":
				out["memory_bytes"] = n
			case "CPUUsageNSec":
				out["cpu_nsec"] = n
			case "ActiveEnterTimestamp":
				if n > 0 {
					out["since"] = n
				}
			case "NRestarts":
				out["restarts"] = n
			}
		}
	}
	if players && out["state"] == "active" {
		list, err := gamePlayers(ctx, env, g)
		if err == nil {
			out["players"] = list
		}
		// Ready to play: its console answers (a unit is "active" long before
		// the world is loaded). An engine with no way to ask is ready once active.
		out["ready"] = err == nil || errors.Is(err, errNoPlayerQuery)
	}
	out["console"] = nativeHasConsole(env, g)
	return out
}

var mcListRe = regexp.MustCompile(`(?i)there are (\d+) of a max(?:imum)? of (\d+) players online:?\s*(.*)$`)

// gamePlayers: who is online (an RCON question each game words its way).
func gamePlayers(ctx context.Context, env *Env, g gameState) (map[string]any, error) {
	switch engine := gameEngine(env, g); engine {
	case "native":
		if r, err := nativeRun(env, g); err == nil && r.Query == "a2s" {
			return a2sQuery(ctx, g.Port)
		}
	case "minecraft":
		out, err := rconExec(ctx, g.RconPort, g.RconPassword, "list")
		if err != nil {
			return nil, err
		}
		m := mcListRe.FindStringSubmatch(strings.TrimSpace(stripMCColors(out)))
		if m == nil {
			return nil, fmt.Errorf("unexpected answer")
		}
		n, _ := strconv.Atoi(m[1])
		limit, _ := strconv.Atoi(m[2])
		names := []string{}
		for _, s := range strings.Split(m[3], ",") {
			if s = strings.TrimSpace(s); s != "" {
				names = append(names, s)
			}
		}
		return map[string]any{"online": n, "max": limit, "names": names}, nil
	case "factorio":
		out, err := rconExec(ctx, g.RconPort, g.RconPassword, "/players online")
		if err != nil {
			return nil, err
		}
		names := []string{}
		for _, l := range strings.Split(out, "\n") {
			if l = strings.TrimSpace(l); l != "" && !strings.HasPrefix(l, "Online players") {
				names = append(names, strings.TrimSuffix(l, " (online)"))
			}
		}
		return map[string]any{"online": len(names), "names": names}, nil
	}
	return nil, errNoPlayerQuery
}

// errNoPlayerQuery: the engine has no way to tell who plays.
var errNoPlayerQuery = errors.New("the game's engine cannot tell who plays")

var mcColorRe = regexp.MustCompile(`§.`)

func stripMCColors(s string) string { return mcColorRe.ReplaceAllString(s, "") }

type gameIDParams struct {
	ID int `json:"id"`
}

func decodeGame(env *Env, raw json.RawMessage, v any, id *int) (gameState, error) {
	if err := decode(raw, v); err != nil {
		return gameState{}, err
	}
	if *id < 1 {
		return gameState{}, protocol.Invalid("id: required")
	}
	return loadGame(env, *id)
}

func gameStatus(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p gameIDParams
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	return gameView(ctx, env, g, true), nil
}

// gameList: every server's state; with players, who is online on each
// running one too (one RCON question per server).
func gameList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Players bool `json:"players"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	out := []map[string]any{}
	for _, g := range allGames(env) {
		out = append(out, gameView(ctx, env, g, p.Players))
	}
	return map[string]any{"servers": out}, nil
}

func gameAction(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID     int    `json:"id"`
		Action string `json:"action"` // start | stop | restart
	}
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	switch p.Action {
	case "start", "restart":
		_, err = env.Exec.Run(ctx, "systemctl", "enable", g.unit())
		if err == nil {
			_, err = env.Exec.Run(ctx, "systemctl", p.Action, g.unit())
		}
	case "stop":
		// A stopped server stays stopped after a reboot too.
		_, err = env.Exec.Run(ctx, "systemctl", "disable", "--now", g.unit())
	default:
		return nil, protocol.Invalid("action: start, stop or restart")
	}
	if err != nil {
		return nil, err
	}
	return gameView(ctx, env, g, false), nil
}

// gameCommand: one console command over RCON.
func gameCommand(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID      int    `json:"id"`
		Command string `json:"command"`
	}
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	p.Command = strings.TrimSpace(p.Command)
	if p.Command == "" || len(p.Command) > 1000 || strings.ContainsAny(p.Command, "\r\n\x00") {
		return nil, protocol.Invalid("command: one line, at most 1000 characters")
	}
	if gameEngine(env, g) == "minecraft" {
		p.Command = strings.TrimPrefix(p.Command, "/")
	}
	if !nativeHasConsole(env, g) || g.RconPort == 0 {
		return nil, protocol.Invalid("this game has no console the panel can use")
	}
	if env.Cfg.DryRun {
		return map[string]any{"output": ""}, nil
	}
	out, err := rconExec(ctx, g.RconPort, g.RconPassword, p.Command)
	if err != nil {
		return nil, fmt.Errorf("the server does not answer on its console (is it running?): %w", err)
	}
	return map[string]any{"output": stripMCColors(out)}, nil
}

func gameLogs(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID    int `json:"id"`
		Lines int `json:"lines,omitempty"`
	}
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if p.Lines <= 0 || p.Lines > 2000 {
		p.Lines = 200
	}
	res, err := env.Exec.Run(ctx, "journalctl", "-u", g.unit(), "-n", strconv.Itoa(p.Lines), "-o", "short-iso", "--no-pager")
	if err != nil {
		return nil, err
	}
	// RCON's own lines carry nothing worth reading and would bury the log.
	lines := []string{}
	for _, l := range strings.Split(strings.TrimRight(res.Stdout, "\n"), "\n") {
		if !strings.Contains(l, "Thread RCON Client") && !strings.Contains(l, "RCON Listener") {
			lines = append(lines, stripMCColors(l))
		}
	}
	return map[string]any{"lines": lines}, nil
}

// gameSettings: name, memory, CPU; the unit is rewritten and a running
// server restarted (a restart is what applies new limits to Java).
func gameSettings(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID         int    `json:"id"`
		Name       string `json:"name"`
		MemoryMB   int    `json:"memory_mb"`
		CPUPercent int    `json:"cpu_percent"`
	}
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if err := gameBusy(g.ID); err != nil {
		return nil, err
	}
	check := gameParams{ID: g.ID, Name: p.Name, Game: g.Game, Flavor: g.Flavor, Version: g.Version, Port: g.Port, MemoryMB: p.MemoryMB, CPUPercent: p.CPUPercent, EULA: true}
	if err := check.validate(env); err != nil {
		return nil, err
	}
	g.Name, g.MemoryMB, g.CPUPercent = p.Name, p.MemoryMB, p.CPUPercent
	if err := saveGame(env, g); err != nil {
		return nil, err
	}
	if err := writeGameUnit(ctx, env, g); err != nil {
		return nil, err
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "try-restart", g.unit())
	return gameView(ctx, env, g, false), nil
}

// gameUpdate (async): the newest build of the version (Paper), another
// version, or the newest Factorio; the server stops for the swap.
func gameUpdate(ctx context.Context, env *Env, raw json.RawMessage) (_ any, err error) {
	var p struct {
		ID      int    `json:"id"`
		Version string `json:"version,omitempty"`
	}
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	release, err := claimGame(g.ID)
	if err != nil {
		return nil, err
	}
	defer release()
	version := g.Version
	if p.Version != "" {
		version = p.Version
	}
	engine := gameEngine(env, g)
	switch {
	case engine == "native" && version != "latest":
		return nil, protocol.Invalid("version: latest")
	case engine != "native" && (!mcVersionRe.MatchString(version) || engine == "minecraft" && mcJava(version) == 0):
		return nil, protocol.Invalid("version: e.g. 1.21.10")
	case engine == "minecraft" && compareVersions(version, g.Version) < 0:
		return nil, protocol.Invalid("version: a world cannot go back to an older Minecraft")
	}
	src, err := gameSourceOf(env, g)
	if err != nil {
		return nil, err
	}
	dl, err := resolveGameDownload(ctx, src, version)
	if err != nil {
		return nil, err
	}
	if version == g.Version && dl.Build == g.Build && engine == "minecraft" {
		return map[string]any{"changed": false, "version": g.Version, "build": g.Build}, nil
	}
	active := false
	if err := withMutate(env, func() error {
		if engine == "minecraft" {
			if _, err := ensureJava(ctx, env, dl.Java); err != nil {
				return err
			}
		}
		if st, err := unitStates(ctx, env, []string{g.unit()}); err == nil && len(st) == 1 {
			active = st[0].Active == "active"
		}
		if active {
			if _, err := env.Exec.Run(ctx, "systemctl", "stop", g.unit()); err != nil {
				return err
			}
		}
		return nil
	}); err != nil {
		return nil, err
	}
	from := strings.TrimSpace(g.Version + " " + g.Build)
	g.Version, g.Build = version, dl.Build
	if dl.Java > 0 {
		g.Java = dl.Java
	}
	// The download, as the game's user, without the lock.
	err = placeDownload(ctx, env, g, dl)
	if src.Type == "steamcmd" {
		g.Build = steamBuildID(env, g, src)
	}
	if lerr := withMutate(env, func() error {
		if err == nil {
			err = saveGame(env, g)
		}
		if err == nil {
			err = configureGame(ctx, env, g, false)
		}
		if err == nil {
			err = writeGameUnit(ctx, env, g)
		}
		if active {
			if _, serr := env.Exec.Run(ctx, "systemctl", "start", g.unit()); err == nil {
				err = serr
			}
		}
		return err
	}); lerr != nil {
		return nil, lerr
	}
	to := strings.TrimSpace(g.Version + " " + g.Build)
	return map[string]any{"changed": from != to, "from": from, "version": g.Version, "build": g.Build}, nil
}

func compareVersions(a, b string) int {
	key := func(s string) []int {
		n := []int{}
		for _, p := range strings.Split(s, ".") {
			i, _ := strconv.Atoi(p)
			n = append(n, i)
		}
		return n
	}
	return slices.Compare(key(a), key(b))
}

// ---------------------------------------------------------------- backups

var gameBackupExcludes = map[string][]string{
	"minecraft": {"libraries", "versions", "cache", "logs", "crash-reports", ".fabric", "server.jar"},
	"factorio":  {"temp", "factorio-current.log", "factorio-previous.log"},
}

// game-<id>-<UTC stamp>.tar.gz (the stamp as the other backups have it, which
// off-site pruning orders by); the first test builds wrote 20060102-150405.
var gameBackupRe = regexp.MustCompile(`^game-(\d+)-(\d{8}T\d{6}Z|\d{8}-\d{6})\.tar\.gz$`)

// gameTarget: where a game server's backups go off-site (games/<id>).
func gameTarget(env *Env, id int) offsiteTarget {
	return offsiteTarget{dir: "games/" + strconv.Itoa(id), local: gameBackupDir(env, id), match: func(f string) bool {
		m := gameBackupRe.FindStringSubmatch(f)
		return m != nil && m[1] == strconv.Itoa(id)
	}}
}

func gameBackupDir(env *Env, id int) string {
	return filepath.Join(env.Cfg.GameBackupDir, strconv.Itoa(id))
}

// gameBackup (async): the game's data (worlds, saves, settings, mods), read
// as the game's user; a running Minecraft is told to flush and hold saving.
func gameBackup(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p gameIDParams
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	dir := gameBackupDir(env, g.ID)
	if err := env.FS.MkdirAll(dir, 0o700, fsx.Root); err != nil {
		return nil, err
	}
	name := fmt.Sprintf("game-%d-%s.tar.gz", g.ID, time.Now().UTC().Format("20060102T150405Z"))
	if gameEngine(env, g) == "minecraft" {
		if _, err := rconExec(ctx, g.RconPort, g.RconPassword, "save-off"); err == nil {
			defer func() { _, _ = rconExec(context.WithoutCancel(ctx), g.RconPort, g.RconPassword, "save-on") }()
			_, _ = rconExec(ctx, g.RconPort, g.RconPassword, "save-all flush")
		}
	}
	f, err := os.OpenFile(filepath.Join(dir, name), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return nil, err
	}
	args := append(userCreds(g.user()), "/usr/bin/tar", "-czf", "-", "-C", gameHome(env, g.ID))
	// What the server makes again from its own jar on start (Paper's and
	// Mojang's bundler), and logs: not the world, and most of the size.
	excludes := gameBackupExcludes[gameEngine(env, g)]
	if r, err := nativeRun(env, g); err == nil && r.Backup != nil {
		excludes = r.Backup.Exclude
	}
	for _, ex := range excludes {
		args = append(args, "--exclude=data/"+strings.TrimSuffix(ex, "/"))
	}
	args = append(args, "data")
	_, err = env.Exec.RunIO(ctx, nil, f, "setpriv", args...)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		os.Remove(filepath.Join(dir, name))
		return nil, fmt.Errorf("backup of game server %d failed: %w", g.ID, err)
	}
	fi, _ := os.Stat(filepath.Join(dir, name))
	var size int64
	if fi != nil {
		size = fi.Size()
	}
	pruneGameBackups(dir, 10)
	out := map[string]any{"file": name, "size": size}
	// A copy off-site too, when the storage is set up (Server → Zálohy mimo server).
	if key, err := copyOffsite(ctx, env, gameTarget(env, g.ID), name); err != nil {
		out["offsite_error"] = err.Error()
	} else if key != "" {
		out["offsite"] = key
	}
	return out, nil
}

// pruneGameBackups keeps the newest n.
func pruneGameBackups(dir string, keep int) {
	files, _ := filepath.Glob(filepath.Join(dir, "game-*.tar.gz"))
	sort.Strings(files)
	for len(files) > keep {
		_ = os.Remove(files[0])
		files = files[1:]
	}
}

func gameBackups(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p gameIDParams
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	out := []map[string]any{}
	entries, _ := os.ReadDir(gameBackupDir(env, g.ID))
	for i := len(entries) - 1; i >= 0; i-- {
		e := entries[i]
		if fi, err := e.Info(); err == nil && gameBackupRe.MatchString(e.Name()) && fi.Mode().IsRegular() {
			out = append(out, map[string]any{"file": e.Name(), "size": fi.Size(), "created_at": fi.ModTime().UTC().Format(time.RFC3339)})
		}
	}
	return map[string]any{"backups": out}, nil
}

// gameRestore (async): the data as it was in a backup; the server stops for
// it and starts again when it ran. The data before goes aside first.
func gameRestore(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID   int    `json:"id"`
		File string `json:"file"`
	}
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if err := gameBusy(g.ID); err != nil {
		return nil, err
	}
	if !gameTarget(env, g.ID).match(p.File) {
		return nil, protocol.Invalid("file: a backup of this game server")
	}
	path := filepath.Join(gameBackupDir(env, g.ID), p.File)
	f, err := os.Open(path)
	if err != nil {
		return nil, protocol.NotFound("backup " + p.File + " not found")
	}
	defer f.Close()
	active := false
	if st, err := unitStates(ctx, env, []string{g.unit()}); err == nil && len(st) == 1 {
		active = st[0].Active == "active"
	}
	if active {
		if _, err := env.Exec.Run(ctx, "systemctl", "stop", g.unit()); err != nil {
			return nil, err
		}
	}
	home := gameHome(env, g.ID)
	aside := filepath.Join(home, "data.before-restore")
	_ = asUser(ctx, env, g.user(), "/usr/bin/rm", "-rf", "--", aside)
	if err := asUser(ctx, env, g.user(), "/usr/bin/mv", "-T", "--", filepath.Join(home, "data"), aside); err != nil {
		return nil, err
	}
	args := append(userCreds(g.user()), "/usr/bin/tar", "-xzf", "-", "-C", home, "--no-same-owner", "--no-same-permissions", "data")
	if _, err := env.Exec.RunIO(ctx, f, nil, "setpriv", args...); err != nil {
		_ = asUser(context.WithoutCancel(ctx), env, g.user(), "/usr/bin/rm", "-rf", "--", filepath.Join(home, "data"))
		_ = asUser(context.WithoutCancel(ctx), env, g.user(), "/usr/bin/mv", "-T", "--", aside, filepath.Join(home, "data"))
		return nil, fmt.Errorf("restore failed, nothing changed: %w", err)
	}
	// The panel's settings (port, RCON) win over the backup's.
	if err := configureGame(ctx, env, g, false); err != nil {
		return nil, err
	}
	_ = asUser(ctx, env, g.user(), "/usr/bin/rm", "-rf", "--", aside)
	if active {
		if _, err := env.Exec.Run(ctx, "systemctl", "start", g.unit()); err != nil {
			return nil, err
		}
	}
	return map[string]any{"restored": p.File}, nil
}

// gameDelete: the unit, the firewall rule, the user, the home; the backups
// too unless keep_backups.
func gameDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID          int  `json:"id"`
		KeepBackups bool `json:"keep_backups,omitempty"`
	}
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if err := gameBusy(g.ID); err != nil {
		return nil, err
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "disable", "--now", g.unit())
	if err := os.Remove(filepath.Join(env.Cfg.SystemdDir, g.unit())); err != nil && !os.IsNotExist(err) {
		return nil, err
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
	_, _ = env.Exec.Run(ctx, "systemctl", "reset-failed", g.unit())
	// Servers from before OwnsFirewall (1.2.0-rc10): the rule is the panel's
	// when it carries the panel's comment for this server.
	if g.OwnsFirewall || ufwHasGameRule(ctx, env, g) {
		_, _ = env.Exec.Run(ctx, "ufw", "delete", "allow", fmt.Sprintf("%d/%s", g.Port, gameProto(env, g)))
	}
	if !env.Cfg.DryRun {
		_ = killAllProcesses(ctx, env, g.user())
	}
	if _, err := env.Exec.Run(ctx, "userdel", g.user()); err != nil && !env.Cfg.DryRun {
		return nil, err
	}
	_ = os.Remove(filepath.Join(env.Cfg.AuthorizedKeysDir, g.user()))
	if err := os.RemoveAll(gameHome(env, g.ID)); err != nil {
		return nil, err
	}
	if !p.KeepBackups {
		_ = os.RemoveAll(gameBackupDir(env, g.ID))
	}
	if err := os.Remove(gameStatePath(env, g.ID)); err != nil {
		return nil, err
	}
	if err := applyRconGuard(ctx, env); err != nil {
		return nil, err
	}
	return map[string]any{"deleted": g.ID}, nil
}

// gameSFTP: SFTP into the server's home as its user (never a shell), with
// keys and/or a password, as sites have it.
func gameSFTP(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID           int      `json:"id"`
		Enabled      bool     `json:"enabled"`
		Keys         []string `json:"keys"`
		PasswordMode string   `json:"password_mode,omitempty"` // keep | set | remove
		Password     string   `json:"password,omitempty"`
	}
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if p.PasswordMode == "" {
		p.PasswordMode = "keep"
	}
	if err := validate.OneOf("password_mode", p.PasswordMode, []string{"keep", "set", "remove"}); err != nil {
		return nil, err
	}
	if p.PasswordMode == "set" && !sshPasswordRe.MatchString(p.Password) {
		return nil, protocol.Invalid("password: 12–128 printable ASCII characters, no colon")
	}
	if p.PasswordMode != "set" && p.Password != "" {
		return nil, protocol.Invalid("password: only with password_mode set")
	}
	if len(p.Keys) > 20 {
		return nil, protocol.Invalid("keys: at most 20 keys")
	}
	keys := make([]string, 0, len(p.Keys))
	for _, k := range p.Keys {
		parsed, err := parsePublicKey(k)
		if err != nil {
			return nil, err
		}
		keys = append(keys, parsed)
	}
	hasPassword := p.Enabled && (g.SFTPPassword && p.PasswordMode == "keep" || p.PasswordMode == "set")
	if p.Enabled && len(keys) == 0 && !hasPassword {
		return nil, protocol.Invalid("keys: at least one public key or a password is required")
	}
	access := "off"
	if p.Enabled {
		access = "sftp"
	}
	if err := applySSHAccess(ctx, env, g.user(), access, keys, hasPassword, p.PasswordMode, p.Password); err != nil {
		return nil, err
	}
	g.SFTP, g.SFTPPassword = p.Enabled, hasPassword
	if err := saveGame(env, g); err != nil {
		return nil, err
	}
	return map[string]any{"id": g.ID, "user": g.user(), "sftp": g.SFTP, "keys": len(keys), "password": hasPassword, "home": gameHome(env, g.ID)}, nil
}
