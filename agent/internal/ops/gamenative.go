package ops

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// The native engine: a game the package describes how to run — Counter-
// Strike 2 and the other dedicated servers SteamCMD installs. The manifest
// still holds no commands for root: it names a program inside the game's own
// server directory and its arguments, which run as the game's user in the
// same sandbox as every game server; the values it puts in them are the
// panel's own (port, RCON password, name) or settings checked against the
// rules the manifest declares.

type gameRunSpec struct {
	Command    string            `json:"command"` // relative to server/
	Args       []gameArg         `json:"args"`
	StopSignal string            `json:"stop_signal,omitempty"` // SIGINT | SIGTERM | SIGQUIT
	Console    *gameConsoleSpec  `json:"console,omitempty"`
	Query      string            `json:"query,omitempty"`   // a2s: Steam's server query (players, ready)
	DiskGB     int               `json:"disk_gb,omitempty"` // free space the install needs
	Links      map[string]string `json:"links,omitempty"`   // server path -> data path ("x/" = a directory)
	Backup     *gameBackupSpec   `json:"backup,omitempty"`
	Settings   []gameSettingSpec `json:"settings,omitempty"`
}

// gameArg: one argument, or a group used only while a setting has a value
// ({"when": "gslt", "args": ["+sv_setsteamaccount", "{setting:gslt}"]}).
type gameArg struct {
	Value string
	When  string
	Group []string
}

func (a *gameArg) UnmarshalJSON(b []byte) error {
	if len(b) > 0 && b[0] == '"' {
		return json.Unmarshal(b, &a.Value)
	}
	var g struct {
		When string   `json:"when"`
		Args []string `json:"args"`
	}
	dec := json.NewDecoder(strings.NewReader(string(b)))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&g); err != nil {
		return fmt.Errorf("an argument is a string or {\"when\", \"args\"}: %v", err)
	}
	a.When, a.Group = g.When, g.Args
	return nil
}

func (a gameArg) MarshalJSON() ([]byte, error) {
	if a.When == "" {
		return json.Marshal(a.Value)
	}
	return json.Marshal(map[string]any{"when": a.When, "args": a.Group})
}

type gameConsoleSpec struct {
	Protocol string   `json:"protocol"`           // source-rcon
	Port     string   `json:"port"`               // game: TCP on the game's port (Source) | own: a port of its own ({rcon_port})
	Commands []string `json:"commands,omitempty"` // offered under the panel's console ("say " waits for the rest)
}

type gameBackupSpec struct {
	Exclude []string `json:"exclude,omitempty"` // paths in data/ not worth keeping
}

type gameSettingSpec struct {
	Key      string              `json:"key"`
	Label    string              `json:"label"`
	Type     string              `json:"type"` // text | int | bool | select | secret
	Help     string              `json:"help,omitempty"`
	Default  string              `json:"default,omitempty"`
	Required bool                `json:"required,omitempty"`
	Pattern  string              `json:"pattern,omitempty"` // text, secret
	Min      *int                `json:"min,omitempty"`     // int
	Max      *int                `json:"max,omitempty"`
	Options  []gameSettingOption `json:"options,omitempty"` // select
}

type gameSettingOption struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

var (
	nativePathRe    = regexp.MustCompile(`^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*/?$`)
	nativeKeyRe     = regexp.MustCompile(`^[a-z][a-z0-9_]{0,31}$`)
	nativeOptionRe  = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,64}$`)
	nativeHolderRe  = regexp.MustCompile(`\{([a-z_]+)(?::([a-z0-9_]+))?\}`)
	nativeTextRe    = regexp.MustCompile(`^[^\x00-\x1f\x7f"\\]{0,128}$`)
	nativeSecretRe  = regexp.MustCompile(`^[\x21-\x7e]{1,128}$`)
	nativeCommandRe = regexp.MustCompile(`^[\x20-\x7e]{1,40}$`)
	nativeHolders   = []string{"port", "rcon_port", "rcon_password", "name", "memory_mb", "setting"}
	nativeSignals   = []string{"", "SIGINT", "SIGTERM", "SIGQUIT"}
	errNotNative    = errors.New("not a native game")
	steamcmdTarball = "https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz"
	steamInstallTO  = 3 * time.Hour
)

func safeRelPath(p string) bool {
	if !nativePathRe.MatchString(p) {
		return false
	}
	for _, seg := range strings.Split(strings.TrimSuffix(p, "/"), "/") {
		if seg == "." || seg == ".." {
			return false
		}
	}
	return true
}

// check: the run spec on its own (what it refers to must be in it).
func (r *gameRunSpec) check() error {
	switch {
	case !safeRelPath(r.Command) || strings.HasSuffix(r.Command, "/"):
		return fmt.Errorf("command: a program inside the server directory (e.g. game/bin/linuxsteamrt64/cs2)")
	case len(r.Args) > 64:
		return fmt.Errorf("args: at most 64")
	case !slices.Contains(nativeSignals, r.StopSignal):
		return fmt.Errorf("stop_signal: SIGINT, SIGTERM or SIGQUIT")
	case r.Query != "" && r.Query != "a2s":
		return fmt.Errorf("query: a2s")
	case r.DiskGB < 0 || r.DiskGB > 4000:
		return fmt.Errorf("disk_gb: 0 to 4000")
	case len(r.Links) > 20 || len(r.Settings) > 30:
		return fmt.Errorf("links: at most 20; settings: at most 30")
	}
	keys := map[string]gameSettingSpec{}
	for _, st := range r.Settings {
		if err := st.check(); err != nil {
			return fmt.Errorf("settings.%s: %v", st.Key, err)
		}
		if _, dup := keys[st.Key]; dup {
			return fmt.Errorf("settings: %s twice", st.Key)
		}
		keys[st.Key] = st
	}
	usesRconPort := false
	checkArg := func(a string) error {
		if len(a) == 0 || len(a) > 200 || strings.ContainsAny(a, "\x00\r\n") {
			return fmt.Errorf("args: 1 to 200 characters, one line")
		}
		for _, m := range nativeHolderRe.FindAllStringSubmatch(a, -1) {
			switch {
			case !slices.Contains(nativeHolders, m[1]):
				return fmt.Errorf("args: unknown placeholder {%s} (%s)", m[1], strings.Join(nativeHolders, ", "))
			case m[1] == "setting" && keys[m[2]].Key == "":
				return fmt.Errorf("args: {setting:%s}, which settings does not declare", m[2])
			case m[1] != "setting" && m[2] != "":
				return fmt.Errorf("args: {%s} takes no name", m[1])
			}
			usesRconPort = usesRconPort || m[1] == "rcon_port"
		}
		if strings.Count(a, "{") != len(nativeHolderRe.FindAllString(a, -1)) {
			return fmt.Errorf("args: a { that is no placeholder (%q)", a)
		}
		return nil
	}
	for _, a := range r.Args {
		if a.When == "" {
			if err := checkArg(a.Value); err != nil {
				return err
			}
			continue
		}
		if keys[a.When].Key == "" || len(a.Group) == 0 || len(a.Group) > 8 {
			return fmt.Errorf("args: {\"when\": %q} names a setting and holds 1 to 8 arguments", a.When)
		}
		for _, v := range a.Group {
			if err := checkArg(v); err != nil {
				return err
			}
		}
	}
	if c := r.Console; c != nil {
		switch {
		case c.Protocol != "source-rcon":
			return fmt.Errorf("console.protocol: source-rcon")
		case c.Port != "game" && c.Port != "own":
			return fmt.Errorf("console.port: game (TCP on the game's port) or own ({rcon_port} in args)")
		case c.Port == "own" && !usesRconPort:
			return fmt.Errorf("console.port own: the args must pass {rcon_port}")
		case len(c.Commands) > 12:
			return fmt.Errorf("console.commands: at most 12")
		}
		for _, cmd := range c.Commands {
			if !nativeCommandRe.MatchString(cmd) || strings.TrimSpace(cmd) == "" {
				return fmt.Errorf("console.commands: 1 to 40 printable characters each")
			}
		}
	}
	for from, to := range r.Links {
		if !safeRelPath(from) || !safeRelPath(to) || strings.HasSuffix(from, "/") != strings.HasSuffix(to, "/") {
			return fmt.Errorf("links: paths inside server/ and data/, both files or both directories (x/)")
		}
	}
	if r.Backup != nil {
		for _, ex := range r.Backup.Exclude {
			if !safeRelPath(ex) {
				return fmt.Errorf("backup.exclude: paths inside data/")
			}
		}
	}
	return nil
}

func (s gameSettingSpec) check() error {
	switch {
	case !nativeKeyRe.MatchString(s.Key):
		return fmt.Errorf("key: lower case letters, digits and _")
	case !gameLabelRe.MatchString(s.Label):
		return fmt.Errorf("label: 1 to 64 characters")
	case !gameDescRe.MatchString(s.Help):
		return fmt.Errorf("help: at most 300 characters")
	case !slices.Contains([]string{"text", "int", "bool", "select", "secret"}, s.Type):
		return fmt.Errorf("type: text, int, bool, select or secret")
	case s.Pattern != "" && (len(s.Pattern) > 200 || s.Type != "text" && s.Type != "secret"):
		return fmt.Errorf("pattern: for text and secret, at most 200 characters")
	case (s.Min != nil || s.Max != nil) && s.Type != "int":
		return fmt.Errorf("min, max: for int")
	case (len(s.Options) > 0) != (s.Type == "select") || len(s.Options) > 50:
		return fmt.Errorf("options: 1 to 50, for select only")
	case s.Type == "secret" && s.Default != "":
		return fmt.Errorf("default: a secret has none")
	}
	if s.Pattern != "" {
		if _, err := regexp.Compile(s.Pattern); err != nil {
			return fmt.Errorf("pattern: %v", err)
		}
	}
	for _, o := range s.Options {
		if !nativeOptionRe.MatchString(o.Value) || !gameLabelRe.MatchString(o.Label) {
			return fmt.Errorf("options: value %q, label 1 to 64 characters", o.Value)
		}
	}
	if s.Default != "" {
		if _, err := s.normalize(s.Default); err != nil {
			return fmt.Errorf("default: %v", err)
		}
	}
	return nil
}

// normalize: a value for this setting, as it goes into the arguments.
func (s gameSettingSpec) normalize(v string) (string, error) {
	v = strings.TrimSpace(v)
	if v == "" {
		if s.Required {
			return "", fmt.Errorf("%s is required", s.Key)
		}
		return "", nil
	}
	switch s.Type {
	case "int":
		n, err := strconv.Atoi(v)
		if err != nil || s.Min != nil && n < *s.Min || s.Max != nil && n > *s.Max {
			lo, hi := "", ""
			if s.Min != nil {
				lo = strconv.Itoa(*s.Min)
			}
			if s.Max != nil {
				hi = strconv.Itoa(*s.Max)
			}
			return "", fmt.Errorf("%s: a whole number %s..%s", s.Key, lo, hi)
		}
		return strconv.Itoa(n), nil
	case "bool":
		switch strings.ToLower(v) {
		case "1", "true", "yes", "on":
			return "1", nil
		case "0", "false", "no", "off":
			return "0", nil
		}
		return "", fmt.Errorf("%s: true or false", s.Key)
	case "select":
		if !slices.ContainsFunc(s.Options, func(o gameSettingOption) bool { return o.Value == v }) {
			return "", fmt.Errorf("%s: one of the offered values", s.Key)
		}
		return v, nil
	}
	base := nativeTextRe
	if s.Type == "secret" {
		base = nativeSecretRe
	}
	if !base.MatchString(v) {
		return "", fmt.Errorf("%s: one line without quotes, at most 128 characters", s.Key)
	}
	if s.Pattern != "" && !regexp.MustCompile(s.Pattern).MatchString(v) {
		return "", fmt.Errorf("%s: not in the form the game expects", s.Key)
	}
	return v, nil
}

// nativeRun: the run spec of a server's game (errNotNative for the others).
func nativeRun(env *Env, g gameState) (*gameRunSpec, error) {
	d, ok := gameDefOf(env, g.Game)
	if !ok || d.Engine != "native" || d.Run == nil {
		return nil, errNotNative
	}
	return d.Run, nil
}

// settingValue: what a server has set, else the declared default.
func settingValue(g gameState, s gameSettingSpec) string {
	if v, ok := g.Settings[s.Key]; ok {
		return v
	}
	return s.Default
}

// nativeArgs: the arguments with the placeholders filled in.
func nativeArgs(g gameState, r *gameRunSpec) []string {
	values := map[string]string{
		"port": strconv.Itoa(g.Port), "rcon_port": strconv.Itoa(g.RconPort), "rcon_password": g.RconPassword,
		"name": g.Name, "memory_mb": strconv.Itoa(g.MemoryMB),
	}
	settings := map[string]string{}
	for _, s := range r.Settings {
		settings[s.Key] = settingValue(g, s)
	}
	fill := func(a string) string {
		return nativeHolderRe.ReplaceAllStringFunc(a, func(m string) string {
			sm := nativeHolderRe.FindStringSubmatch(m)
			if sm[1] == "setting" {
				return settings[sm[2]]
			}
			return values[sm[1]]
		})
	}
	out := []string{}
	for _, a := range r.Args {
		if a.When == "" {
			out = append(out, fill(a.Value))
			continue
		}
		if v := settings[a.When]; v != "" && v != "0" {
			for _, x := range a.Group {
				out = append(out, fill(x))
			}
		}
	}
	return out
}

// systemdQuote: one argument of ExecStart= as systemd reads it back.
func systemdQuote(s string) string {
	r := strings.NewReplacer(`\`, `\\`, `"`, `\"`, `%`, `%%`, `$`, `$$`)
	return `"` + r.Replace(s) + `"`
}

func nativeExecStart(env *Env, g gameState, r *gameRunSpec) string {
	parts := []string{systemdQuote(filepath.Join(gameHome(env, g.ID), "server", r.Command))}
	for _, a := range nativeArgs(g, r) {
		parts = append(parts, systemdQuote(a))
	}
	return strings.Join(parts, " ")
}

// nativeHasConsole: whether the panel can send it commands.
func nativeHasConsole(env *Env, g gameState) bool {
	r, err := nativeRun(env, g)
	return err != nil || r.Console != nil
}

// ---------------------------------------------------------------- disk

func freeDiskGB(path string) (int64, error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, err
	}
	return int64(st.Bavail) * int64(st.Bsize) >> 30, nil
}

// checkDisk: refuses an install that cannot fit (before anything is made).
func checkDisk(env *Env, need int) error {
	if need <= 0 || env.Cfg.DryRun {
		return nil
	}
	dir := env.Cfg.GamesRoot
	for !fsx.Exists(dir) && dir != "/" {
		dir = filepath.Dir(dir)
	}
	free, err := freeDiskGB(dir)
	if err != nil {
		return nil
	}
	if free < int64(need) {
		return protocol.Conflict(fmt.Sprintf("the game needs %d GB of free disk, %s has %d GB", need, env.Cfg.GamesRoot, free))
	}
	return nil
}

// ---------------------------------------------------------------- SteamCMD

// ensureSteamDeps: SteamCMD itself is a 32-bit program; Debian's 32-bit C
// runtime libraries for amd64 are enough (no i386 architecture needed).
func ensureSteamDeps(ctx context.Context, env *Env) error {
	if hostArch != "amd64" {
		return protocol.Invalid("SteamCMD runs on amd64 only")
	}
	if env.Cfg.DryRun || fsx.Exists("/usr/lib32/libgcc_s.so.1") {
		return nil
	}
	if _, err := aptRun(ctx, env, "--no-install-recommends", "install", "lib32gcc-s1"); err != nil {
		return fmt.Errorf("installing SteamCMD's 32-bit libraries failed: %w", err)
	}
	return nil
}

// steamInstall installs or updates the dedicated server through SteamCMD,
// as the game's user, into its server directory. SteamCMD checks what it
// downloads against Valve's signed manifests itself; its own bootstrap comes
// from Valve over HTTPS and updates itself the same way.
func steamInstall(ctx context.Context, env *Env, g gameState, src gameSource) error {
	if env.Cfg.DryRun {
		return nil
	}
	home := gameHome(env, g.ID)
	dir := filepath.Join(home, "steamcmd")
	if !fsx.Exists(filepath.Join(dir, "steamcmd.sh")) {
		file, err := downloadUnverified(ctx, steamcmdTarball, 64<<20)
		if err != nil {
			return fmt.Errorf("downloading SteamCMD failed: %w", err)
		}
		defer os.Remove(file)
		if err := os.Chmod(file, 0o644); err != nil {
			return err
		}
		if err := asUser(ctx, env, g.user(), "/usr/bin/install", "-d", "-m", "0750", dir); err != nil {
			return err
		}
		if err := asUser(ctx, env, g.user(), "/usr/bin/tar", "-xzf", file, "-C", dir, "--no-same-owner"); err != nil {
			return fmt.Errorf("unpacking SteamCMD failed: %w", err)
		}
	}
	update := []string{"+force_install_dir", filepath.Join(home, "server"), "+login", "anonymous", "+app_update", strconv.Itoa(src.AppID)}
	if src.Branch != "" && src.Branch != "public" {
		update = append(update, "-beta", src.Branch)
	}
	update = append(update, "validate", "+quit")
	ictx, cancel := context.WithTimeout(ctx, steamInstallTO)
	defer cancel()
	run := func(args ...string) (string, error) {
		full := append(append(userCreds(g.user()), "/usr/bin/env", "HOME="+filepath.Join(home, "tmp"), filepath.Join(dir, "steamcmd.sh")), args...)
		res, err := env.Exec.RunIO(ictx, nil, nil, "setpriv", full...)
		return res.Stdout, err
	}
	// The first run only updates SteamCMD (and exits non-zero doing so).
	_, _ = run("+quit")
	var out string
	var err error
	for attempt := 0; attempt < 3; attempt++ {
		if out, err = run(update...); err == nil && steamBuildID(env, g, src) != "" {
			break
		}
	}
	if steamBuildID(env, g, src) == "" {
		return fmt.Errorf("SteamCMD did not install app %d: %s", src.AppID, tail(out, 800))
	}
	// Steam's client library where dedicated servers look for it.
	for _, l := range [][2]string{{"sdk64", "linux64"}, {"sdk32", "linux32"}} {
		sdk := filepath.Join(home, "tmp", ".steam", l[0])
		if err := asUser(ctx, env, g.user(), "/usr/bin/install", "-d", "-m", "0750", sdk); err != nil {
			return err
		}
		if err := asUser(ctx, env, g.user(), "/usr/bin/ln", "-sfn", filepath.Join(dir, l[1], "steamclient.so"), filepath.Join(sdk, "steamclient.so")); err != nil {
			return err
		}
	}
	return nil
}

var steamBuildRe = regexp.MustCompile(`"buildid"\s+"(\d+)"`)

// steamBuildID: the build SteamCMD installed (its app manifest), "" when none.
func steamBuildID(env *Env, g gameState, src gameSource) string {
	b, err := readGameFile(filepath.Join(gameHome(env, g.ID), "server", "steamapps", fmt.Sprintf("appmanifest_%d.acf", src.AppID)))
	if err != nil {
		return ""
	}
	if m := steamBuildRe.FindSubmatch(b); m != nil {
		return string(m[1])
	}
	return ""
}

// configureNative: the links from the server's tree into data/ (what the
// game keeps of its own, and what a backup holds).
func configureNative(ctx context.Context, env *Env, g gameState, r *gameRunSpec) error {
	if env.Cfg.DryRun {
		return nil
	}
	home := gameHome(env, g.ID)
	from := make([]string, 0, len(r.Links))
	for k := range r.Links {
		from = append(from, k)
	}
	slices.Sort(from)
	for _, f := range from {
		to := r.Links[f]
		target := filepath.Join(home, "data", strings.TrimSuffix(to, "/"))
		link := filepath.Join(home, "server", strings.TrimSuffix(f, "/"))
		if err := asUser(ctx, env, g.user(), "/usr/bin/install", "-d", "-m", "0750", filepath.Dir(target), filepath.Dir(link)); err != nil {
			return err
		}
		if strings.HasSuffix(to, "/") {
			if err := asUser(ctx, env, g.user(), "/usr/bin/install", "-d", "-m", "0750", target); err != nil {
				return err
			}
		} else if !fsx.Exists(target) {
			if err := writeAsUser(ctx, env, g, target, nil, "0640"); err != nil {
				return err
			}
		}
		// What the game shipped there goes aside once, then the link.
		if fi, err := os.Lstat(link); err == nil && fi.Mode()&os.ModeSymlink == 0 {
			_ = asUser(ctx, env, g.user(), "/usr/bin/mv", "-T", "--", link, link+".shipped")
		}
		if err := asUser(ctx, env, g.user(), "/usr/bin/ln", "-sfnT", target, link); err != nil {
			return err
		}
	}
	return nil
}

// ---------------------------------------------------------------- A2S

// a2sQuery asks a Steam game server on 127.0.0.1 who plays (A2S_INFO for the
// counts, A2S_PLAYER for the names), answering challenges as servers want.
func a2sQuery(ctx context.Context, port int) (map[string]any, error) {
	d := net.Dialer{Timeout: time.Second}
	c, err := d.DialContext(ctx, "udp", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)))
	if err != nil {
		return nil, err
	}
	defer c.Close()
	ask := func(req []byte, want byte) ([]byte, error) {
		for i := 0; i < 3; i++ {
			_ = c.SetDeadline(time.Now().Add(1500 * time.Millisecond))
			if _, err := c.Write(req); err != nil {
				return nil, err
			}
			buf := make([]byte, 1400)
			n, err := c.Read(buf)
			if err != nil {
				return nil, err
			}
			b := buf[:n]
			if len(b) < 5 || binary.LittleEndian.Uint32(b) != 0xFFFFFFFF {
				return nil, fmt.Errorf("not an A2S answer")
			}
			switch b[4] {
			case want:
				return b[5:], nil
			case 0x41: // a challenge: ask again with it
				if len(b) < 9 {
					return nil, fmt.Errorf("short challenge")
				}
				if want == 0x44 {
					req = append(req[:5], b[5:9]...)
				} else {
					req = append(req[:25], b[5:9]...)
				}
				continue
			default:
				return nil, fmt.Errorf("unexpected A2S answer 0x%x", b[4])
			}
		}
		return nil, fmt.Errorf("A2S: too many challenges")
	}
	info, err := ask(append([]byte{0xFF, 0xFF, 0xFF, 0xFF, 0x54}, []byte("Source Engine Query\x00")...), 0x49)
	if err != nil {
		return nil, err
	}
	// protocol, then name, map, folder, game (strings), app id, players, max.
	p := 1
	for i := 0; i < 4; i++ {
		end := slices.Index(info[p:], 0)
		if end < 0 {
			return nil, fmt.Errorf("A2S_INFO: short")
		}
		p += end + 1
	}
	if len(info) < p+4 {
		return nil, fmt.Errorf("A2S_INFO: short")
	}
	online, max := int(info[p+2]), int(info[p+3])
	names := []string{}
	if online > 0 {
		if pl, err := ask([]byte{0xFF, 0xFF, 0xFF, 0xFF, 0x55, 0xFF, 0xFF, 0xFF, 0xFF}, 0x44); err == nil && len(pl) > 0 {
			q := 1
			for i := 0; i < int(pl[0]) && q < len(pl); i++ {
				q++ // index
				end := slices.Index(pl[q:], 0)
				if end < 0 {
					break
				}
				if name := string(pl[q : q+end]); name != "" {
					names = append(names, name)
				}
				q += end + 1 + 8 // score, duration
			}
		}
	}
	return map[string]any{"online": online, "max": max, "names": names}, nil
}

// ---------------------------------------------------------------- settings ops

// gameOptions: a native game's settings, as declared and as set (secrets
// only say whether they are set).
func gameOptions(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p gameIDParams
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	r, err := nativeRun(env, g)
	if err != nil {
		return map[string]any{"settings": []gameSettingSpec{}, "values": map[string]string{}, "secrets": map[string]bool{}}, nil
	}
	values, secrets := map[string]string{}, map[string]bool{}
	for _, s := range r.Settings {
		v := settingValue(g, s)
		if s.Type == "secret" {
			secrets[s.Key] = v != ""
			continue
		}
		values[s.Key] = v
	}
	return map[string]any{"settings": r.Settings, "values": values, "secrets": secrets}, nil
}

// gameSetOptions: new settings (a secret left empty keeps its value; clear
// removes it), the unit rewritten and, when asked, a running server restarted.
func gameSetOptions(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID      int               `json:"id"`
		Values  map[string]string `json:"values"`
		Secrets map[string]string `json:"secrets,omitempty"` // the secret settings: redacted in the audit log
		Clear   []string          `json:"clear,omitempty"`
		Restart bool              `json:"restart,omitempty"`
	}
	g, err := decodeGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if err := gameBusy(g.ID); err != nil {
		return nil, err
	}
	r, err := nativeRun(env, g)
	if err != nil {
		return nil, protocol.Invalid("this game has no settings of its own here")
	}
	next := map[string]string{}
	for k, v := range g.Settings {
		next[k] = v
	}
	specs := map[string]gameSettingSpec{}
	for _, s := range r.Settings {
		specs[s.Key] = s
	}
	set := func(field string, k, v string, secret bool) error {
		s, ok := specs[k]
		switch {
		case !ok:
			return protocol.Invalid(field + ": " + k + " is not a setting of this game")
		case (s.Type == "secret") != secret:
			return protocol.Invalid(field + ": " + k + map[bool]string{true: " is not a secret", false: " is a secret (secrets)"}[secret])
		case secret && strings.TrimSpace(v) == "":
			return nil // left empty: kept
		}
		n, err := s.normalize(v)
		if err != nil {
			return protocol.Invalid(field + ": " + err.Error())
		}
		next[k] = n
		return nil
	}
	for k, v := range p.Values {
		if err := set("values", k, v, false); err != nil {
			return nil, err
		}
	}
	for k, v := range p.Secrets {
		if err := set("secrets", k, v, true); err != nil {
			return nil, err
		}
	}
	for _, k := range p.Clear {
		if specs[k].Required {
			return nil, protocol.Invalid("clear: " + k + " is required")
		}
		if _, ok := specs[k]; ok {
			next[k] = ""
		}
	}
	for _, s := range r.Settings {
		if s.Required && settingValue(gameState{Settings: next}, s) == "" {
			return nil, protocol.Invalid("values: " + s.Key + " is required")
		}
	}
	g.Settings = next
	if err := saveGame(env, g); err != nil {
		return nil, err
	}
	if err := writeGameUnit(ctx, env, g); err != nil {
		return nil, err
	}
	restarted := false
	if p.Restart {
		if st, err := unitStates(ctx, env, []string{g.unit()}); err == nil && len(st) == 1 && st[0].Active == "active" {
			if _, err := env.Exec.Run(ctx, "systemctl", "restart", g.unit()); err != nil {
				return nil, err
			}
			restarted = true
		}
	}
	return map[string]any{"saved": true, "restarted": restarted}, nil
}
