package ops

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// One database shared by a game server and a site: each that uses it gets
// a login of its own with rights on that database only (a Minecraft
// plugin's LuckPerms or CoreProtect, the site's shop or statistics page).
// The database's owner and its password stay as they are, and so does a
// site's own DB_* in .env: a site gets the shared one under a prefix of its
// choosing. MariaDB on the shared server only: a dedicated instance listens
// on a socket that admits its site alone.

type dbLink struct {
	Engine   string `json:"engine"`
	DB       string `json:"db"`
	User     string `json:"user"`
	Password string `json:"password"`
	Game     int    `json:"game,omitempty"`
	Domain   string `json:"domain,omitempty"`
	Prefix   string `json:"prefix,omitempty"` // the site's .env keys
}

var dbLinkPrefixRe = regexp.MustCompile(`^[A-Z][A-Z0-9_]{0,30}$`)

func dbLinksPath(env *Env) string { return filepath.Join(env.Cfg.StateDir, "dblinks.json") }

func loadDBLinks(env *Env) []dbLink {
	out := []dbLink{}
	if b, err := os.ReadFile(dbLinksPath(env)); err == nil {
		_ = json.Unmarshal(b, &out)
	}
	return out
}

func saveDBLinks(env *Env, list []dbLink) error {
	if err := env.FS.MkdirAll(env.Cfg.StateDir, 0o700, fsx.Root); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(list, "", "  ")
	return env.FS.WriteFile(dbLinksPath(env), b, 0o600, fsx.Root)
}

// dbLinkUser: a login name that is the same for the same holder and
// database, short enough for MariaDB (32 characters), and never a
// database's owner (those carry the database's own name).
func dbLinkUser(holder, db string) string {
	sum := sha256.Sum256([]byte(holder + "\x00" + db))
	return "wsl_" + hex.EncodeToString(sum[:])[:12]
}

func (l dbLink) view(env *Env) map[string]any {
	conn := mariadb{}.conn()
	out := map[string]any{"engine": l.Engine, "name": l.DB, "user": l.User, "password": l.Password,
		"host": conn.Host, "port": conn.Port, "jdbc": fmt.Sprintf("jdbc:mariadb://%s:%d/%s", conn.Host, conn.Port, l.DB)}
	if l.Game > 0 {
		out["game"] = l.Game
	}
	if l.Domain != "" {
		out["domain"], out["prefix"] = l.Domain, l.Prefix
	}
	return out
}

// dbLinkHosts: where a shared database's logins may connect from.
var dbLinkHosts = []string{"localhost", "127.0.0.1"}

type dbLinkParams struct {
	Engine string `json:"engine"`
	Name   string `json:"name"`
	Game   int    `json:"game,omitempty"`
	Domain string `json:"domain,omitempty"`
	Prefix string `json:"prefix,omitempty"`
}

// holder checks who the login is for: one game server or one site.
func (p *dbLinkParams) holder(env *Env) (string, *siteLayout, error) {
	if p.Engine != "mariadb" {
		return "", nil, protocol.Invalid("engine: a database is shared over MariaDB only")
	}
	if err := validate.DBName("name", p.Name); err != nil {
		return "", nil, err
	}
	if _, dedicated := instanceOf(env, p.Engine, p.Name); dedicated {
		return "", nil, protocol.Conflict(p.Name + " is on a site's dedicated server, which admits that site alone: share a database on the shared server")
	}
	switch {
	case p.Game > 0 && p.Domain == "":
		if _, err := loadGame(env, p.Game); err != nil {
			return "", nil, protocol.NotFound(fmt.Sprintf("game server %d does not exist", p.Game))
		}
		return "game:" + strconv.Itoa(p.Game), nil, nil
	case p.Domain != "" && p.Game == 0:
		_, l, err := loadSite(env, p.Domain)
		if err != nil {
			return "", nil, err
		}
		if p.Prefix == "" {
			p.Prefix = "GAME_DB"
		}
		if !dbLinkPrefixRe.MatchString(p.Prefix) || p.Prefix == "DB" {
			return "", nil, protocol.Invalid("prefix: capitals, digits and _ (e.g. GAME_DB); DB is the site's own database")
		}
		return "site:" + p.Domain, &l, nil
	}
	return "", nil, protocol.Invalid("game or domain: who the database is shared with (one of them)")
}

// dbLinkCreate: a login for the game server or the site on the database;
// asked again, it gives the same login a new password.
func dbLinkCreate(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dbLinkParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	holder, site, err := p.holder(env)
	if err != nil {
		return nil, err
	}
	m := mariadb{}
	if !env.Cfg.DryRun {
		list, err := m.list(ctx, env)
		if err != nil {
			return nil, err
		}
		if !slices.ContainsFunc(list, func(d dbInfo) bool { return d.Name == p.Name }) {
			return nil, protocol.NotFound("database " + p.Name + " does not exist")
		}
	}
	link := dbLink{Engine: p.Engine, DB: p.Name, User: dbLinkUser(holder, p.Name), Password: newPassword(), Game: p.Game, Domain: p.Domain, Prefix: p.Prefix}
	// Created or given the new password; the rights on this database only.
	// From this server alone (the socket and 127.0.0.1): a game server and a
	// site run here, so the login never takes the published server's
	// network hosts, even with TLS.
	q := ""
	for _, h := range dbLinkUserHosts(ctx, env, link.User) {
		if !slices.Contains(dbLinkHosts, h) {
			q += fmt.Sprintf("DROP USER IF EXISTS '%s'@'%s'; ", link.User, h)
		}
	}
	for _, h := range dbLinkHosts {
		q += fmt.Sprintf("CREATE USER IF NOT EXISTS '%[1]s'@'%[3]s' IDENTIFIED BY '%[2]s'; ALTER USER '%[1]s'@'%[3]s' IDENTIFIED BY '%[2]s'; "+
			"GRANT ALL PRIVILEGES ON `%[4]s`.* TO '%[1]s'@'%[3]s'; ", link.User, link.Password, h, grantPattern(p.Name))
	}
	if _, err := m.sql(ctx, env, q); err != nil {
		return nil, err
	}
	if site != nil {
		path := envFilePath(*site)
		if path == "" {
			return nil, fmt.Errorf("site %s has no env file", site.Domain)
		}
		conn := m.conn()
		keys := []string{p.Prefix + "_HOST", p.Prefix + "_PORT", p.Prefix + "_DATABASE", p.Prefix + "_USERNAME", p.Prefix + "_PASSWORD"}
		values := map[string]string{keys[0]: conn.Host, keys[1]: strconv.Itoa(conn.Port), keys[2]: p.Name, keys[3]: link.User, keys[4]: link.Password}
		old, err := readSiteFile(ctx, env, *site, path)
		if err != nil {
			return nil, err
		}
		if err := writeSiteFile(ctx, env, *site, path, mergeEnvSection(old, keys, values, "Shared database (written by WSPanel)")); err != nil {
			return nil, err
		}
		refreshAppConfig(ctx, env, *site)
	}
	list := slices.DeleteFunc(loadDBLinks(env), func(l dbLink) bool { return l.User == link.User })
	if err := saveDBLinks(env, append(list, link)); err != nil {
		return nil, err
	}
	return link.view(env), nil
}

// dbLinkDelete: the login goes; the database and the rest of .env stay.
func dbLinkDelete(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p dbLinkParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	holder, _, err := p.holder(env)
	if err != nil {
		return nil, err
	}
	user := dbLinkUser(holder, p.Name)
	if err := dropDBLinks(ctx, env, func(l dbLink) bool { return l.User == user }); err != nil {
		return nil, err
	}
	return map[string]any{"name": p.Name, "removed": true}, nil
}

// dbLinkList: the logins of one game server, one site or one database.
func dbLinkList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Game   int    `json:"game,omitempty"`
		Domain string `json:"domain,omitempty"`
		Name   string `json:"name,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	out := []map[string]any{}
	for _, l := range loadDBLinks(env) {
		if (p.Game > 0 && l.Game == p.Game) || (p.Domain != "" && l.Domain == p.Domain) || (p.Name != "" && l.DB == p.Name) {
			out = append(out, l.view(env))
		}
	}
	return map[string]any{"links": out}, nil
}

// dropDBLinks removes the matching logins (a game server, a site or a
// database going away takes its own with it).
func dropDBLinks(ctx context.Context, env *Env, match func(dbLink) bool) error {
	list := loadDBLinks(env)
	keep := []dbLink{}
	for _, l := range list {
		if !match(l) {
			keep = append(keep, l)
			continue
		}
		// Every host the login exists on, not only those it is made with now.
		hosts := slices.Clone(dbLinkHosts)
		for _, h := range dbLinkUserHosts(ctx, env, l.User) {
			if !slices.Contains(hosts, h) {
				hosts = append(hosts, h)
			}
		}
		q := ""
		for _, h := range hosts {
			q += fmt.Sprintf("DROP USER IF EXISTS '%s'@'%s'; ", l.User, h)
		}
		if _, err := (mariadb{}).sql(ctx, env, q); err != nil && !env.Cfg.DryRun {
			return err
		}
	}
	if len(keep) == len(list) {
		return nil
	}
	return saveDBLinks(env, keep)
}

// dbLinkUserHosts: the hosts a login exists on now (quotes dropped: a host
// name never has one, and it goes back into SQL).
func dbLinkUserHosts(ctx context.Context, env *Env, user string) []string {
	out, err := (mariadb{}).sql(ctx, env, fmt.Sprintf("SELECT Host FROM mysql.user WHERE User = '%s'", user))
	if err != nil {
		return nil
	}
	hosts := []string{}
	for _, h := range strings.Fields(out) {
		hosts = append(hosts, strings.NewReplacer("'", "", "\\", "").Replace(h))
	}
	return hosts
}
