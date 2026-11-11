package ops

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/fsx"
)

// Server backups: what a new server needs, beside the backups of the sites
// and databases, to become this one again (wspanel-restore):
//
//	manifest.json        the version, the installer's choices, site users and
//	                     client groups (with their ids), enabled units
//	panel/…              the panel's database (a consistent copy) and .env
//	fs/<path>            WSPanel's state and the configuration it generated:
//	                     the agent's state, sites' units, pools, edge blocks,
//	                     SSH keys, mail and DKIM, the certificates and the CA
//	db/…                 database accounts (MariaDB users, PostgreSQL roles)
//	pdns/pdns.sqlite3    the DNS zones (with PowerDNS)
//
// One encrypted file (backupcrypt.go) in <BackupDir>/server, root only; the
// newest few stay, and every one goes to the off-site storage too. The mail
// of each hosted domain goes beside it (<BackupDir>/mail/<domain>).

const serverBackupFormat = 1

// ServerBackupKeep: server backups kept on this server (off-site: KeepDays).
const ServerBackupKeep = 7

// MailBackupKeep: mail backups kept per domain on this server.
const MailBackupKeep = 3

var (
	serverBackupNameRe = regexp.MustCompile(`^server-(\d{8}T\d{6}Z)\.wspbak$`)
	mailBackupNameRe   = regexp.MustCompile(`^(.+)-(\d{8}T\d{6}Z)\.tar\.gz$`)
)

type sysUser struct {
	Name    string `json:"name"`
	UID     int    `json:"uid"`
	GID     int    `json:"gid"`
	Home    string `json:"home"`
	Shell   string `json:"shell"`
	Comment string `json:"comment,omitempty"`
}

type sysGroup struct {
	Name    string   `json:"name"`
	GID     int      `json:"gid"`
	Members []string `json:"members,omitempty"`
}

type serverManifest struct {
	Format      int       `json:"format"`
	Created     time.Time `json:"created"`
	Host        string    `json:"host"`
	Version     string    `json:"version"`
	Flags       []string  `json:"flags"`
	Sites       []string  `json:"sites"`
	MailDomains []string  `json:"mail_domains"`
	DBEngines   []string  `json:"db_engines"`
	Databases   []dbRef   `json:"databases"`
	// engine/name of the databases that lived in a site's dedicated server
	// (restored into the shared one: the dedicated server is set up again).
	Dedicated []string   `json:"dedicated_databases,omitempty"`
	Users     []sysUser  `json:"users"`
	Groups    []sysGroup `json:"groups"`
	Enabled   []string   `json:"enabled_units"`
	// Game servers: their state is in the archive, their data in their own
	// backups; the restore builds each again (Games) and starts those that
	// started with the system (GameUnits).
	Games     []int    `json:"games,omitempty"`
	GameUnits []string `json:"game_units,omitempty"`
	Files     int      `json:"files"`
}

func serverBackupsDir(env *Env) string { return filepath.Join(env.Cfg.BackupDir, "server") }

func serverTarget(env *Env) offsiteTarget {
	return offsiteTarget{dir: "server", local: serverBackupsDir(env), match: serverBackupNameRe.MatchString}
}

func mailBackupsDir(env *Env, domain string) string {
	return filepath.Join(env.Cfg.BackupDir, "mail", domain)
}

func mailTarget(env *Env, domain string) offsiteTarget {
	return offsiteTarget{dir: "mail/" + domain, local: mailBackupsDir(env, domain), match: func(f string) bool {
		m := mailBackupNameRe.FindStringSubmatch(f)
		return m != nil && m[1] == domain
	}}
}

// Where the panel lives (the installer's layout).
var (
	panelShared  = "/srv/panel/shared"
	installFlags = "/etc/wspanel/install.flags"
	pdnsDatabase = "/var/lib/powerdns/pdns.sqlite3"
	rspamdDKIM   = "/var/lib/rspamd/dkim"
)

// serverPaths: WSPanel's state and the configuration it generated. Files of
// the installer's own (Caddyfiles of the panel, agent.json) are not among
// them: the installer writes those again on the new server.
func serverPaths(env *Env) []string {
	c := env.Cfg
	paths := []string{
		c.StateDir, c.SiteConfigDir, c.ProxySitesDir, c.WebSitesDir, c.ProxyCertDir, c.MailEdgeFile,
		c.AuthorizedKeysDir, c.ProxyStorage, c.DKIMDir, rspamdDKIM, c.MailDir, filepath.Dir(c.DovecotUsers),
		c.DBTLSDir, c.SASLDB, c.PostfixSASL, c.SenderLogins, c.LoginSenders, c.SRSExclude,
		c.VDomains, c.VMailboxes, c.VAliases, c.MailTransport, c.MailboxSenders,
		c.RspamdRatelimit, c.RspamdActions, c.RspamdAntivirus, installFlags,
		filepath.Join(panelShared, ".env"), filepath.Join(panelShared, "storage", "app"),
	}
	globs := []string{
		filepath.Join(c.SystemdDir, "wspanel-*"), filepath.Join(c.TmpfilesDir, "wspanel-*"),
		filepath.Join(c.Fail2banDir, "jail.d", "wspanel*"), filepath.Join(c.MariaDBConfDir, "*wspanel*"),
	}
	for _, g := range globs {
		m, _ := filepath.Glob(g)
		paths = append(paths, m...)
	}
	// Each site's PHP-FPM pool (in the folder of its version).
	for _, st := range allSiteStates(c) {
		if l, err := layout(c, st); err == nil && l.PoolFile != "" {
			paths = append(paths, l.PoolFile)
		}
	}
	slices.Sort(paths)
	return slices.Compact(paths)
}

// skipped in a server backup: spools and what other backups already hold
// (the mail is in the mail backups, the sites' files in theirs, the game
// servers' data in theirs). A game server's state is kept; its units are
// written again when the restore builds it (RestoreServerData).
func skipServerPath(env *Env, path string) bool {
	base := filepath.Base(path)
	if filepath.Dir(path) == env.Cfg.SystemdDir && (strings.HasPrefix(base, "wspanel-game-") || strings.HasPrefix(base, "wspanel-games-")) {
		return true
	}
	for _, dir := range []string{"/storage/app/dmarc-incoming", env.Cfg.VmailRoot, env.Cfg.SitesRoot, env.Cfg.BackupDir} {
		if dir == "" {
			continue
		}
		if strings.HasPrefix(dir, "/storage/") {
			if strings.HasSuffix(path, dir) || strings.Contains(path, dir+"/") {
				return true
			}
			continue
		}
		if path == dir || strings.HasPrefix(path, dir+"/") {
			return true
		}
	}
	return false
}

func serverBackup(ctx context.Context, env *Env, _ json.RawMessage) (any, error) {
	b, err := makeServerBackup(ctx, env)
	if err != nil {
		return nil, err
	}
	b = withOffsite(ctx, env, serverTarget(env), b)
	out := map[string]any{"backup": b.File, "size": b.Size, "offsite": b.Offsite, "offsite_error": b.OffsiteError, "pruned": pruneServerBackups(env)}

	var mail []map[string]any
	if st, err := loadMail(env); err == nil {
		for _, d := range sortedKeys(st.Domains) {
			mb, err := makeMailBackup(ctx, env, d)
			if err != nil {
				mail = append(mail, map[string]any{"domain": d, "error": err.Error()})
				continue
			}
			mb = withOffsite(ctx, env, mailTarget(env, d), mb)
			mail = append(mail, map[string]any{"domain": d, "backup": mb.File, "size": mb.Size, "offsite": mb.Offsite, "offsite_error": mb.OffsiteError})
			pruneLocal(mailTarget(env, d), MailBackupKeep)
		}
	}
	out["mail"] = mail
	return out, nil
}

func serverBackups(ctx context.Context, env *Env, _ json.RawMessage) (any, error) {
	out := []map[string]any{}
	for _, b := range listLocal(serverTarget(env)) {
		out = append(out, map[string]any{"file": b.File, "size": b.Size, "created": b.Created})
	}
	return map[string]any{"backups": out}, nil
}

// listLocal: a target's local files, newest first.
func listLocal(t offsiteTarget) []backupInfo {
	entries, _ := os.ReadDir(t.local)
	out := []backupInfo{}
	for _, de := range entries {
		if !de.Type().IsRegular() || !t.match(de.Name()) {
			continue
		}
		fi, err := de.Info()
		if err != nil {
			continue
		}
		created, _ := time.Parse("20060102T150405Z", backupStamp(de.Name()))
		out = append(out, backupInfo{File: de.Name(), Size: fi.Size(), Created: created, Path: filepath.Join(t.local, de.Name())})
	}
	slices.SortFunc(out, func(a, b backupInfo) int { return b.Created.Compare(a.Created) })
	return out
}

func pruneLocal(t offsiteTarget, keep int) int {
	removed := 0
	for i, b := range listLocal(t) {
		if i >= keep && os.Remove(b.Path) == nil {
			removed++
		}
	}
	return removed
}

func pruneServerBackups(env *Env) int { return pruneLocal(serverTarget(env), ServerBackupKeep) }

func makeServerBackup(ctx context.Context, env *Env) (backupInfo, error) {
	key, err := signingKey(env)
	if err != nil {
		return backupInfo{}, err
	}
	dir := serverBackupsDir(env)
	if err := env.FS.MkdirAll(dir, 0o700, fsx.Root); err != nil {
		return backupInfo{}, err
	}
	now := time.Now().UTC()
	file := "server-" + now.Format("20060102T150405Z") + ".wspbak"
	work, err := os.MkdirTemp(dir, ".work-")
	if err != nil {
		return backupInfo{}, err
	}
	defer os.RemoveAll(work)

	man := serverManifest{Format: serverBackupFormat, Created: now, Flags: []string{}, DBEngines: env.Cfg.DBEngines}
	man.Host, _ = os.Hostname()
	man.Version = panelVersion()
	if b, err := os.ReadFile(installFlags); err == nil {
		for _, l := range strings.Split(strings.TrimSpace(string(b)), "\n") {
			if l = strings.TrimSpace(l); l != "" {
				man.Flags = append(man.Flags, l)
			}
		}
	}
	states := allSiteStates(env.Cfg)
	users := map[string]bool{}
	groups := map[string]bool{}
	for _, st := range states {
		man.Sites = append(man.Sites, st.Domain)
		if l, err := layout(env.Cfg, st); err == nil {
			users[l.User] = true
		}
		if st.Group != "" {
			groups[st.Group] = true
		}
	}
	slices.Sort(man.Sites)
	for _, g := range allGames(env) {
		man.Games = append(man.Games, g.ID)
		users[g.user()] = true
		if g.Group != "" {
			groups[g.Group] = true
		}
		if fsx.Exists(filepath.Join(env.Cfg.SystemdDir, "multi-user.target.wants", g.unit())) {
			man.GameUnits = append(man.GameUnits, g.unit())
		}
	}
	if st, err := loadMail(env); err == nil {
		man.MailDomains = sortedKeys(st.Domains)
	}
	man.Users, man.Groups = systemAccounts(users, groups)
	for _, engine := range env.Cfg.DBEngines {
		if e, err := engineFor(env, engine); err == nil {
			list, err := e.list(ctx, env)
			if err != nil {
				return backupInfo{}, fmt.Errorf("listing the %s databases failed: %w", engine, err)
			}
			for _, d := range list {
				man.Databases = append(man.Databases, dbRef{Engine: engine, Name: d.Name})
			}
		}
	}
	for _, i := range allDBInstances(env) {
		for _, n := range i.Databases {
			man.Databases = append(man.Databases, dbRef{Engine: i.Engine, Name: n})
			man.Dedicated = append(man.Dedicated, i.Engine+"/"+n)
		}
	}
	man.Enabled = enabledUnits(env)

	// Consistent copies of the SQLite databases (the panel, PowerDNS).
	extra := map[string]string{}
	if db := filepath.Join(panelShared, "database.sqlite"); fsx.Exists(db) {
		if p, err := sqliteCopy(ctx, env, db, filepath.Join(work, "panel.sqlite")); err == nil {
			extra["panel/database.sqlite"] = p
		} else {
			return backupInfo{}, fmt.Errorf("copying the panel's database failed: %w", err)
		}
	}
	if fsx.Exists(pdnsDatabase) {
		if p, err := sqliteCopy(ctx, env, pdnsDatabase, filepath.Join(work, "pdns.sqlite3")); err == nil {
			extra["pdns/pdns.sqlite3"] = p
		}
	}
	// Database accounts: the databases themselves are in the sites' and
	// databases' own backups, their users and passwords only here.
	if slices.Contains(env.Cfg.DBEngines, "mariadb") {
		p := filepath.Join(work, "mariadb-users.sql")
		if err := dumpTo(p, func(w io.Writer) error {
			_, err := env.Exec.RunIO(ctx, nil, w, "mariadb-dump", "--system=users", "--insert-ignore")
			return err
		}); err != nil {
			return backupInfo{}, fmt.Errorf("the MariaDB accounts: %w", err)
		}
		extra["db/mariadb-users.sql"] = p
	}
	if slices.Contains(env.Cfg.DBEngines, "postgresql") {
		p := filepath.Join(work, "postgres-roles.sql")
		if err := dumpTo(p, func(w io.Writer) error {
			_, err := env.Exec.RunIO(ctx, nil, w, "setpriv", "--reuid=postgres", "--regid=postgres", "--init-groups", "--reset-env", "--",
				"/usr/bin/pg_dumpall", "--roles-only")
			return err
		}); err != nil {
			return backupInfo{}, fmt.Errorf("the PostgreSQL roles: %w", err)
		}
		extra["db/postgres-roles.sql"] = p
	}
	if slices.Contains(env.Cfg.DBEngines, "mongodb") {
		p := filepath.Join(work, "mongodb-users.archive")
		if err := dumpTo(p, func(w io.Writer) error { return mongoUsersDump(ctx, env, w) }); err != nil {
			return backupInfo{}, fmt.Errorf("the MongoDB users: %w", err)
		}
		extra["db/mongodb-users.archive"] = p
	}

	plain := filepath.Join(work, "plain.tar.gz")
	if err := writeServerArchive(env, plain, &man, serverPaths(env), extra, now); err != nil {
		return backupInfo{}, err
	}
	in, err := os.Open(plain)
	if err != nil {
		return backupInfo{}, err
	}
	defer in.Close()
	out, err := os.CreateTemp(dir, ".server-*")
	if err != nil {
		return backupInfo{}, err
	}
	defer os.Remove(out.Name())
	bw := bufio.NewWriter(out)
	err = encryptBackup(bw, in, key)
	if err == nil {
		err = bw.Flush()
	}
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return backupInfo{}, err
	}
	if err := os.Chmod(out.Name(), 0o600); err != nil {
		return backupInfo{}, err
	}
	path := filepath.Join(dir, file)
	if err := os.Rename(out.Name(), path); err != nil {
		return backupInfo{}, err
	}
	fi, _ := os.Stat(path)
	return backupInfo{File: file, Size: fi.Size(), Created: now, Path: path}, nil
}

func dumpTo(path string, fn func(io.Writer) error) error {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	err = fn(f)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	return err
}

// sqliteCopy makes a consistent copy of a live SQLite database (its own
// backup API, safe while the panel writes).
func sqliteCopy(ctx context.Context, env *Env, src, dst string) (string, error) {
	if _, err := env.Exec.Run(ctx, "sqlite3", src, ".backup '"+strings.ReplaceAll(dst, "'", "''")+"'"); err != nil {
		return "", err
	}
	if !fsx.Exists(dst) { // dry run
		return "", errors.New("no copy was made")
	}
	return dst, nil
}

func panelVersion() string {
	b, err := os.ReadFile(filepath.Join(panelShared, ".env"))
	if err != nil {
		return ""
	}
	for _, l := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(strings.TrimSpace(l), "PANEL_VERSION="); ok {
			return strings.Trim(v, `"`)
		}
	}
	return ""
}

// systemAccounts: the site users and client groups as /etc/passwd and
// /etc/group have them (the ids matter: files and quotas use them).
func systemAccounts(users, groups map[string]bool) ([]sysUser, []sysGroup) {
	var us []sysUser
	gids := map[int]bool{}
	if b, err := os.ReadFile("/etc/passwd"); err == nil {
		for _, l := range strings.Split(string(b), "\n") {
			f := strings.Split(l, ":")
			if len(f) < 7 || !users[f[0]] {
				continue
			}
			uid, _ := strconv.Atoi(f[2])
			gid, _ := strconv.Atoi(f[3])
			us = append(us, sysUser{Name: f[0], UID: uid, GID: gid, Comment: f[4], Home: f[5], Shell: f[6]})
			gids[gid] = true
		}
	}
	var gs []sysGroup
	if b, err := os.ReadFile("/etc/group"); err == nil {
		for _, l := range strings.Split(string(b), "\n") {
			f := strings.Split(l, ":")
			if len(f) < 4 {
				continue
			}
			gid, _ := strconv.Atoi(f[2])
			if !groups[f[0]] && !gids[gid] && !users[f[0]] {
				continue
			}
			g := sysGroup{Name: f[0], GID: gid}
			if f[3] != "" {
				g.Members = strings.Split(f[3], ",")
			}
			gs = append(gs, g)
		}
	}
	return us, gs
}

// enabledUnits: WSPanel's units that start with the system (the restore
// enables them again; the symlinks are not copied). A game server's unit is
// written when it is built again, so it is enabled then (GameUnits).
func enabledUnits(env *Env) []string {
	var out []string
	wants, _ := filepath.Glob(filepath.Join(env.Cfg.SystemdDir, "*.wants", "wspanel-*"))
	for _, w := range wants {
		if name := filepath.Base(w); !strings.HasPrefix(name, "wspanel-game-") && !strings.HasPrefix(name, "wspanel-games-") {
			out = append(out, name)
		}
	}
	slices.Sort(out)
	return slices.Compact(out)
}

// writeServerArchive: the manifest, then each path (files, folders,
// symlinks; owners and modes kept) under fs/, then the extra files.
func writeServerArchive(env *Env, dst string, man *serverManifest, paths []string, extra map[string]string, now time.Time) error {
	f, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	gz := gzip.NewWriter(f)
	tw := tar.NewWriter(gz)
	var entries []func() error
	count := 0
	accounts := accountNames()
	for _, root := range paths {
		if root == "" || !fsx.Exists(root) || skipServerPath(env, root) {
			continue
		}
		err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
			if err != nil {
				return nil // unreadable: left out
			}
			if skipServerPath(env, path) {
				if d.IsDir() {
					return filepath.SkipDir
				}
				return nil
			}
			fi, err := os.Lstat(path)
			if err != nil {
				return nil
			}
			mode := fi.Mode()
			if !mode.IsRegular() && !mode.IsDir() && mode&os.ModeSymlink == 0 {
				return nil // sockets, fifos
			}
			p := path
			entries = append(entries, func() error { return tarPath(tw, p, fi, accounts) })
			count++
			return nil
		})
		if err != nil {
			return err
		}
	}
	man.Files = count
	mb, _ := json.MarshalIndent(man, "", "  ")
	if err := tarBytes(tw, "manifest.json", mb, now); err != nil {
		return err
	}
	for _, e := range entries {
		if err := e(); err != nil {
			return err
		}
	}
	names := make([]string, 0, len(extra))
	for n := range extra {
		names = append(names, n)
	}
	slices.Sort(names)
	for _, n := range names {
		if err := tarFile(tw, n, extra[n], now); err != nil {
			return err
		}
	}
	if err := tw.Close(); err != nil {
		return err
	}
	if err := gz.Close(); err != nil {
		return err
	}
	return f.Close()
}

func tarPath(tw *tar.Writer, path string, fi os.FileInfo, names idNames) error {
	link := ""
	if fi.Mode()&os.ModeSymlink != 0 {
		link, _ = os.Readlink(path)
	}
	hdr, err := tar.FileInfoHeader(fi, link)
	if err != nil {
		return err
	}
	hdr.Name = "fs" + path
	if fi.IsDir() {
		hdr.Name += "/"
	}
	// Owners by name too: a system account's id may differ on the new server.
	if sys, ok := fi.Sys().(*syscall.Stat_t); ok {
		hdr.Uid, hdr.Gid = int(sys.Uid), int(sys.Gid)
		hdr.Uname, hdr.Gname = names.user[hdr.Uid], names.group[hdr.Gid]
	}
	if !fi.Mode().IsRegular() {
		return tw.WriteHeader(hdr)
	}
	src, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return nil // gone since the walk
	}
	defer src.Close()
	if err := tw.WriteHeader(hdr); err != nil {
		return err
	}
	_, err = io.Copy(tw, io.LimitReader(src, hdr.Size))
	return err
}

// makeMailBackup: a hosted domain's mail (its Maildirs and Sieve scripts).
func makeMailBackup(ctx context.Context, env *Env, domain string) (backupInfo, error) {
	src := filepath.Join(env.Cfg.VmailRoot, domain)
	dir := mailBackupsDir(env, domain)
	if err := env.FS.MkdirAll(dir, 0o700, fsx.Root); err != nil {
		return backupInfo{}, err
	}
	now := time.Now().UTC()
	file := domain + "-" + now.Format("20060102T150405Z") + ".tar.gz"
	out, err := os.CreateTemp(dir, ".mail-*")
	if err != nil {
		return backupInfo{}, err
	}
	defer os.Remove(out.Name())
	gz := gzip.NewWriter(out)
	tw := tar.NewWriter(gz)
	err = func() error {
		if !fsx.Exists(src) {
			return nil // a domain that has had no mail yet
		}
		return filepath.WalkDir(src, func(path string, d fs.DirEntry, err error) error {
			if err != nil {
				return nil
			}
			if err := ctx.Err(); err != nil {
				return err
			}
			fi, err := os.Lstat(path)
			if err != nil || (!fi.Mode().IsRegular() && !fi.IsDir()) {
				return nil
			}
			rel, _ := filepath.Rel(env.Cfg.VmailRoot, path)
			hdr, err := tar.FileInfoHeader(fi, "")
			if err != nil {
				return err
			}
			hdr.Name = rel
			if fi.IsDir() {
				hdr.Name += "/"
			}
			if sys, ok := fi.Sys().(*syscall.Stat_t); ok {
				hdr.Uid, hdr.Gid = int(sys.Uid), int(sys.Gid)
			}
			if !fi.Mode().IsRegular() {
				return tw.WriteHeader(hdr)
			}
			f, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
			if err != nil {
				return nil // delivered and expunged meanwhile
			}
			defer f.Close()
			if err := tw.WriteHeader(hdr); err != nil {
				return err
			}
			_, err = io.Copy(tw, io.LimitReader(f, hdr.Size))
			return err
		})
	}()
	if err == nil {
		err = tw.Close()
	}
	if err == nil {
		err = gz.Close()
	}
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return backupInfo{}, err
	}
	if err := os.Chmod(out.Name(), 0o600); err != nil {
		return backupInfo{}, err
	}
	path := filepath.Join(dir, file)
	if err := os.Rename(out.Name(), path); err != nil {
		return backupInfo{}, err
	}
	fi, _ := os.Stat(path)
	return backupInfo{File: file, Size: fi.Size(), Created: now, Path: path}, nil
}

// readServerManifest decrypts a server backup and returns its manifest.
func readServerManifest(path string, recoveryKey []byte) (serverManifest, error) {
	var man serverManifest
	err := openServerArchive(path, recoveryKey, func(tr *tar.Reader, hdr *tar.Header) (bool, error) {
		if hdr.Name != "manifest.json" {
			return false, nil
		}
		if err := json.NewDecoder(io.LimitReader(tr, 1<<20)).Decode(&man); err != nil {
			return true, err
		}
		return true, nil
	})
	if err == nil && man.Format == 0 {
		err = errors.New("the backup has no manifest")
	}
	return man, err
}

// openServerArchive decrypts the backup as it streams and calls fn for each
// entry; fn returns true to stop.
func openServerArchive(path string, recoveryKey []byte, fn func(*tar.Reader, *tar.Header) (bool, error)) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	pr, pw := io.Pipe()
	go func() { pw.CloseWithError(decryptBackup(pw, bufio.NewReader(f), recoveryKey)) }()
	defer pr.Close()
	gz, err := gzip.NewReader(pr)
	if err != nil {
		return err
	}
	tr := tar.NewReader(gz)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		stop, err := fn(tr, hdr)
		if err != nil || stop {
			return err
		}
	}
}

// configPath: a sanity check for paths taken from a backup (absolute, clean).
func configPath(name string) (string, bool) {
	p, ok := strings.CutPrefix(name, "fs/")
	if !ok {
		return "", false
	}
	p = "/" + strings.TrimSuffix(p, "/")
	return p, filepath.Clean(p) == p && !strings.Contains(p, "/../")
}

type idNames struct{ user, group map[int]string }

// accountNames: /etc/passwd and /etc/group by id (read for each backup).
func accountNames() idNames {
	n := idNames{user: map[int]string{}, group: map[int]string{}}
	for file, m := range map[string]map[int]string{"/etc/passwd": n.user, "/etc/group": n.group} {
		b, _ := os.ReadFile(file)
		for _, l := range strings.Split(string(b), "\n") {
			f := strings.Split(l, ":")
			if len(f) >= 3 {
				if id, err := strconv.Atoi(f[2]); err == nil {
					m[id] = f[0]
				}
			}
		}
	}
	return n
}
