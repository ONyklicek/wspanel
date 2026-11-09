package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"unicode/utf16"

	"wspanel/agent/internal/protocol"
)

// A Minecraft server's own settings (server.properties) and its players
// (whitelist, operators, bans), as the panel shows and changes them.

// panelProperties: the keys the panel owns. The port and RCON are how the
// panel reaches the server; query and the management server would bind ports
// the unit does not allow.
var panelProperties = map[string]bool{
	"server-port": true, "enable-rcon": true, "rcon.port": true, "rcon.password": true,
	"broadcast-rcon-to-ops": true, "enable-query": true, "query.port": true,
	"management-server-enabled": true, "management-server-host": true, "management-server-port": true,
	"management-server-secret": true, "management-server-tls-enabled": true, "management-server-tls-keystore": true,
	"management-server-tls-keystore-password": true, "management-server-allowed-origins": true,
}

var propertyKeyRe = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{0,63}$`)

// readGameFileMax reads a file of a game's home as root without following a
// link or blocking on a FIFO, at most max bytes.
func readGameFileMax(path string, max int64) ([]byte, error) {
	f, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	if st, err := f.Stat(); err != nil || !st.Mode().IsRegular() {
		return nil, fmt.Errorf("%s: not a regular file", path)
	}
	return io.ReadAll(io.LimitReader(f, max))
}

type property struct {
	Key   string `json:"key"`
	Value string `json:"value"`
	Panel bool   `json:"panel,omitempty"` // the panel's: shown, not changed
}

// parseProperties: key=value lines in the file's order (comments left out).
func parseProperties(text string) []property {
	out := []property{}
	for _, l := range strings.Split(text, "\n") {
		l = strings.TrimRight(l, "\r")
		if t := strings.TrimSpace(l); t == "" || strings.HasPrefix(t, "#") || strings.HasPrefix(t, "!") {
			continue
		}
		k, v, ok := strings.Cut(l, "=")
		if !ok {
			continue
		}
		k = strings.TrimSpace(k)
		p := property{Key: k, Value: unescapeProperty(v), Panel: panelProperties[k]}
		if k == "rcon.password" || k == "management-server-secret" || k == "management-server-tls-keystore-password" {
			p.Value = "" // never leaves the agent
		}
		out = append(out, p)
	}
	return out
}

func minecraftGame(env *Env, raw json.RawMessage, v any, id *int) (gameState, error) {
	g, err := decodeGame(env, raw, v, id)
	if err != nil {
		return g, err
	}
	if g.Game != "minecraft" {
		return g, protocol.Invalid("id: a Minecraft server")
	}
	return g, nil
}

// gameProperties: server.properties as the server wrote it (none before
// its first start).
func gameProperties(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p gameIDParams
	g, err := minecraftGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	b, err := readGameFileMax(filepath.Join(gameHome(env, g.ID), "data", "server.properties"), 256<<10)
	if err != nil {
		return map[string]any{"properties": []property{}, "exists": false}, nil
	}
	return map[string]any{"properties": parseProperties(string(b)), "exists": true}, nil
}

// gameSetProperties changes the given keys and keeps the rest of the file;
// the panel's own keys are refused (and written again as the panel has them).
func gameSetProperties(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID      int               `json:"id"`
		Values  map[string]string `json:"values"`
		Restart bool              `json:"restart,omitempty"`
	}
	g, err := minecraftGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	if len(p.Values) == 0 || len(p.Values) > 200 {
		return nil, protocol.Invalid("values: 1 to 200 settings")
	}
	keys := make([]string, 0, len(p.Values))
	for k, v := range p.Values {
		switch {
		case !propertyKeyRe.MatchString(k):
			return nil, protocol.Invalid(fmt.Sprintf("values: %q is not a setting name", k))
		case panelProperties[k]:
			return nil, protocol.Invalid("values: " + k + " is the panel's")
		case len(v) > 4096 || strings.ContainsAny(v, "\r\n\x00"):
			return nil, protocol.Invalid("values: " + k + " must be one line of at most 4096 characters")
		}
		keys = append(keys, k)
	}
	sort.Strings(keys)
	path := filepath.Join(gameHome(env, g.ID), "data", "server.properties")
	cur := ""
	if b, err := readGameFileMax(path, 256<<10); err == nil {
		cur = string(b)
	}
	escaped := make(map[string]string, len(p.Values))
	for k, v := range p.Values {
		escaped[k] = escapeProperty(v)
	}
	if err := writeAsUser(ctx, env, g, path, []byte(setProperties(cur, escaped)), "0640"); err != nil {
		return nil, err
	}
	// The port and RCON as the panel has them, whatever the file said.
	if err := configureGame(ctx, env, g, false); err != nil {
		return nil, err
	}
	restarted := false
	if p.Restart {
		if _, err := env.Exec.Run(ctx, "systemctl", "try-restart", g.unit()); err != nil {
			return nil, err
		}
		restarted = true
	}
	return map[string]any{"changed": keys, "restarted": restarted}, nil
}

// unescapeProperty: a value as Java's Properties reads it (\uXXXX, \:, \\…).
func unescapeProperty(v string) string {
	if !strings.Contains(v, `\`) {
		return v
	}
	// As UTF-16 units: a character past U+FFFF is written as two \u escapes.
	var units []uint16
	add := func(r rune) { units = append(units, utf16.Encode([]rune{r})...) }
	r := []rune(v)
	for i := 0; i < len(r); i++ {
		if r[i] != '\\' || i+1 >= len(r) {
			add(r[i])
			continue
		}
		i++
		switch r[i] {
		case 'u':
			if i+4 < len(r) {
				if n, err := strconv.ParseUint(string(r[i+1:i+5]), 16, 16); err == nil {
					units = append(units, uint16(n))
					i += 4
					continue
				}
			}
			add('u')
		case 't':
			add('\t')
		default:
			add(r[i])
		}
	}
	return string(utf16.Decode(units))
}

// escapeProperty: a value safe for any Java Properties reader (ASCII, the
// rest as \uXXXX), which is how Minecraft writes them itself.
func escapeProperty(v string) string {
	var b strings.Builder
	for _, c := range utf16.Encode([]rune(v)) {
		switch {
		case c == '\\':
			b.WriteString(`\\`)
		case c < 0x20 || c > 0x7e:
			fmt.Fprintf(&b, `\u%04x`, c)
		default:
			b.WriteRune(rune(c))
		}
	}
	return b.String()
}

// ---------------------------------------------------------------- players

var mcNameRe = regexp.MustCompile(`^[A-Za-z0-9_]{3,16}$`)

type mcPlayer struct {
	Name   string `json:"name"`
	UUID   string `json:"uuid,omitempty"`
	Level  int    `json:"level,omitempty"`  // ops
	Reason string `json:"reason,omitempty"` // bans
	IP     string `json:"ip,omitempty"`     // IP bans
}

func readPlayerList(env *Env, g gameState, file string) []mcPlayer {
	out := []mcPlayer{}
	b, err := readGameFileMax(filepath.Join(gameHome(env, g.ID), "data", file), 4<<20)
	if err != nil {
		return out
	}
	_ = json.Unmarshal(b, &out)
	if len(out) > 5000 {
		out = out[:5000]
	}
	return out
}

// gamePlayers: who may play (whitelist), the operators, the bans, and
// whether the whitelist is on.
func gamePlayerLists(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p gameIDParams
	g, err := minecraftGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	whitelist := false
	if b, err := readGameFileMax(filepath.Join(gameHome(env, g.ID), "data", "server.properties"), 256<<10); err == nil {
		for _, pr := range parseProperties(string(b)) {
			if pr.Key == "white-list" {
				whitelist = strings.TrimSpace(pr.Value) == "true"
			}
		}
	}
	return map[string]any{
		"whitelist_on": whitelist,
		"whitelist":    readPlayerList(env, g, "whitelist.json"),
		"ops":          readPlayerList(env, g, "ops.json"),
		"banned":       readPlayerList(env, g, "banned-players.json"),
		"banned_ips":   readPlayerList(env, g, "banned-ips.json"),
	}, nil
}

// gamePlayerAction: one change through the server's console, so it applies
// at once (and the server writes its own list files). The server runs.
func gamePlayerAction(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID     int    `json:"id"`
		Action string `json:"action"`
		Name   string `json:"name,omitempty"`
		Reason string `json:"reason,omitempty"`
	}
	g, err := minecraftGame(env, raw, &p, &p.ID)
	if err != nil {
		return nil, err
	}
	reason := strings.TrimSpace(p.Reason)
	if len(reason) > 200 || strings.ContainsAny(reason, "\r\n\x00") {
		return nil, protocol.Invalid("reason: one line, at most 200 characters")
	}
	name := func() (string, error) {
		if !mcNameRe.MatchString(p.Name) {
			return "", protocol.Invalid("name: a Minecraft player name (3 to 16 letters, digits, _)")
		}
		return p.Name, nil
	}
	var cmd string
	switch p.Action {
	case "whitelist_on":
		cmd = "whitelist on"
	case "whitelist_off":
		cmd = "whitelist off"
	case "whitelist_add", "whitelist_remove", "op", "deop", "pardon":
		n, err := name()
		if err != nil {
			return nil, err
		}
		cmd = map[string]string{"whitelist_add": "whitelist add ", "whitelist_remove": "whitelist remove ", "op": "op ", "deop": "deop ", "pardon": "pardon "}[p.Action] + n
	case "ban", "kick":
		n, err := name()
		if err != nil {
			return nil, err
		}
		cmd = strings.TrimSpace(p.Action + " " + n + " " + reason)
	case "ban_ip", "pardon_ip":
		a, err := netip.ParseAddr(p.Name)
		if err != nil {
			return nil, protocol.Invalid("name: an IP address")
		}
		cmd = strings.TrimSpace(map[string]string{"ban_ip": "ban-ip ", "pardon_ip": "pardon-ip "}[p.Action] + a.String() + " " + map[bool]string{true: reason, false: ""}[p.Action == "ban_ip"])
	default:
		return nil, protocol.Invalid("action: whitelist_on, whitelist_off, whitelist_add, whitelist_remove, op, deop, kick, ban, pardon, ban_ip or pardon_ip")
	}
	if env.Cfg.DryRun {
		return map[string]any{"command": cmd, "output": ""}, nil
	}
	out, err := rconExec(ctx, g.RconPort, g.RconPassword, cmd)
	if err != nil {
		return nil, fmt.Errorf("the server does not answer on its console (is it running?): %w", err)
	}
	return map[string]any{"command": cmd, "output": stripMCColors(out)}, nil
}
