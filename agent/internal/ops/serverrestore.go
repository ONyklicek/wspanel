package ops

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/hmac"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/user"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fileop"
	"wspanel/agent/internal/fsx"
)

// Restoring a server (wspanel-restore) on a freshly installed one, with the
// agent stopped, in two steps:
//
//	RestoreServerState  the server backup: the site users and client groups
//	                    (with their ids where free), WSPanel's state and the
//	                    configuration it generated, the panel's database,
//	                    the DNS zones and the database accounts
//	RestoreServerData   then the newest backup of every site (files and its
//	                    databases), of every other database and of the mail
//	                    of every domain: from a folder (a copy of the old
//	                    server's /var/backups/wspanel) or from the off-site
//	                    storage the restored state names, signature checked.

// RestoreReport says what a step did and what it could not do.
type RestoreReport struct {
	Restored []string `json:"restored"`
	Problems []string `json:"problems"`
}

func (r *RestoreReport) ok(format string, a ...any) {
	r.Restored = append(r.Restored, fmt.Sprintf(format, a...))
}
func (r *RestoreReport) problem(format string, a ...any) {
	r.Problems = append(r.Problems, fmt.Sprintf(format, a...))
}

// ReadServerBackupManifest: what a server backup holds (it must open with the key).
func ReadServerBackupManifest(path string, recoveryKey []byte) (serverManifest, error) {
	return readServerManifest(path, recoveryKey)
}

// restoreAllowed: the paths a restore may write, the same that a backup takes.
func restoreAllowed(env *Env, path string) bool {
	c := env.Cfg
	under := func(root string) bool { return root != "" && (path == root || strings.HasPrefix(path, root+"/")) }
	for _, root := range []string{
		c.StateDir, c.SiteConfigDir, c.ProxySitesDir, c.WebSitesDir, c.ProxyCertDir, c.MailEdgeFile,
		c.AuthorizedKeysDir, c.ProxyStorage, c.DKIMDir, rspamdDKIM, c.MailDir, filepath.Dir(c.DovecotUsers),
		c.DBTLSDir, c.SASLDB, c.PostfixSASL, c.SenderLogins, c.LoginSenders, c.SRSExclude,
		c.VDomains, c.VMailboxes, c.VAliases, c.MailTransport, c.MailboxSenders,
		c.RspamdRatelimit, c.RspamdActions, c.RspamdAntivirus, installFlags,
		filepath.Join(panelShared, ".env"), filepath.Join(panelShared, "storage", "app"),
	} {
		if under(root) {
			return true
		}
	}
	dir, base := filepath.Dir(path), filepath.Base(path)
	switch {
	case dir == c.SystemdDir || dir == c.TmpfilesDir:
		return strings.HasPrefix(base, "wspanel-")
	case dir == filepath.Join(c.Fail2banDir, "jail.d"):
		return strings.HasPrefix(base, "wspanel")
	case dir == c.MariaDBConfDir:
		return strings.Contains(base, "wspanel")
	}
	// A site's PHP-FPM pool: <user>.conf in a version's pool folder.
	for _, v := range append(slices.Clone(c.PHPVersions), InstallablePHP...) {
		if dir == config.ForVersion(c.FPMPoolDir, v) && strings.HasSuffix(base, ".conf") &&
			!slices.Contains([]string{"www.conf", "panel.conf", "dbtools.conf"}, base) {
			return true
		}
	}
	return false
}

// idMap: an id of the old server -> the id on this one (when it was taken).
type idMap map[int]int

func (m idMap) of(id int) int {
	if n, ok := m[id]; ok {
		return n
	}
	return id
}

// RestoreServerState applies a server backup to this (freshly installed) server.
func RestoreServerState(ctx context.Context, env *Env, path string, recoveryKey []byte) (serverManifest, RestoreReport, error) {
	var rep RestoreReport
	man, err := readServerManifest(path, recoveryKey)
	if err != nil {
		return man, rep, err
	}
	uids, gids, err := restoreAccounts(ctx, env, man, &rep)
	if err != nil {
		return man, rep, err
	}
	work, err := os.MkdirTemp("", "wspanel-restore-")
	if err != nil {
		return man, rep, err
	}
	defer os.RemoveAll(work)

	files := 0
	extra := map[string]string{}
	err = openServerArchive(path, recoveryKey, func(tr *tar.Reader, hdr *tar.Header) (bool, error) {
		if target, ok := configPath(hdr.Name); ok {
			if !restoreAllowed(env, target) {
				rep.problem("left out (not WSPanel's): %s", target)
				return false, nil
			}
			files++
			return false, restoreEntry(tr, hdr, target, uids, gids)
		}
		switch hdr.Name {
		case "panel/database.sqlite", "pdns/pdns.sqlite3", "db/mariadb-users.sql", "db/postgres-roles.sql", "db/mongodb-users.archive":
			p := filepath.Join(work, filepath.Base(hdr.Name))
			if err := dumpTo(p, func(w io.Writer) error { _, err := io.Copy(w, tr); return err }); err != nil {
				return true, err
			}
			extra[hdr.Name] = p
		}
		return false, nil
	})
	if err != nil {
		return man, rep, fmt.Errorf("unpacking the server backup failed: %w", err)
	}
	rep.ok("%d files of WSPanel's state and configuration", files)

	if p, ok := extra["panel/database.sqlite"]; ok {
		db := filepath.Join(panelShared, "database.sqlite")
		for _, f := range []string{db + "-wal", db + "-shm"} {
			_ = os.Remove(f)
		}
		if err := installAs(p, db, "panel", "panel", 0o600); err != nil {
			return man, rep, fmt.Errorf("the panel's database: %w", err)
		}
		rep.ok("the panel's database")
	}
	if p, ok := extra["pdns/pdns.sqlite3"]; ok {
		if fsx.Exists(filepath.Dir(pdnsDatabase)) {
			if err := installAs(p, pdnsDatabase, "pdns", "pdns", 0o640); err != nil {
				rep.problem("DNS zones: %v", err)
			} else {
				rep.ok("DNS zones (PowerDNS)")
			}
		} else {
			rep.problem("DNS zones: PowerDNS is not installed here (install with --dns=powerdns)")
		}
	}
	if p, ok := extra["db/mariadb-users.sql"]; ok {
		if err := runSQLFile(ctx, env, p, "mariadb"); err != nil {
			rep.problem("MariaDB accounts: %v", err)
		} else {
			rep.ok("MariaDB accounts")
		}
	}
	if p, ok := extra["db/mongodb-users.archive"]; ok {
		if !slices.Contains(env.Cfg.DBEngines, "mongodb") {
			rep.problem("MongoDB users: MongoDB is not installed here (Databáze → Servery → Doinstalovat engine, then restore again)")
		} else if f, err := os.Open(p); err != nil {
			rep.problem("MongoDB users: %v", err)
		} else {
			err := mongoUsersRestore(ctx, env, f)
			f.Close()
			if err != nil {
				rep.problem("MongoDB users: %v", err)
			} else {
				rep.ok("MongoDB users")
			}
		}
	}
	if p, ok := extra["db/postgres-roles.sql"]; ok {
		if err := runSQLFile(ctx, env, p, "postgresql"); err != nil {
			rep.problem("PostgreSQL roles: %v", err)
		} else {
			rep.ok("PostgreSQL roles")
		}
	}

	// Postfix's lookup tables, systemd, the units that started with the system.
	for _, m := range []string{env.Cfg.VDomains, env.Cfg.VMailboxes, env.Cfg.VAliases, env.Cfg.MailTransport, env.Cfg.MailboxSenders,
		env.Cfg.SenderLogins, env.Cfg.LoginSenders, env.Cfg.SRSExclude, env.Cfg.PostfixSASL} {
		if fsx.Exists(m) {
			_, _ = env.Exec.Run(ctx, "postmap", m)
		}
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "daemon-reload")
	for _, u := range man.Enabled {
		if _, err := env.Exec.Run(ctx, "systemctl", "enable", u); err != nil {
			rep.problem("unit %s: %v", u, err)
		}
	}
	return man, rep, nil
}

// restoreAccounts creates the site users and client groups, with their old
// ids when those are free on this server.
func restoreAccounts(ctx context.Context, env *Env, man serverManifest, rep *RestoreReport) (idMap, idMap, error) {
	uids, gids := idMap{}, idMap{}
	for _, g := range man.Groups {
		if existing, err := user.LookupGroup(g.Name); err == nil {
			gid, _ := strconv.Atoi(existing.Gid)
			gids[g.GID] = gid
			continue
		}
		args := []string{"--system"}
		if _, err := user.LookupGroupId(strconv.Itoa(g.GID)); err != nil {
			args = append(args, "--gid", strconv.Itoa(g.GID))
		}
		if _, err := env.Exec.Run(ctx, "groupadd", append(args, g.Name)...); err != nil {
			return nil, nil, fmt.Errorf("group %s: %w", g.Name, err)
		}
		if created, err := user.LookupGroup(g.Name); err == nil {
			gid, _ := strconv.Atoi(created.Gid)
			gids[g.GID] = gid
		}
	}
	groupName := map[int]string{}
	for _, g := range man.Groups {
		groupName[g.GID] = g.Name
	}
	for _, u := range man.Users {
		if existing, err := user.Lookup(u.Name); err == nil {
			uid, _ := strconv.Atoi(existing.Uid)
			uids[u.UID] = uid
			continue
		}
		args := []string{"--system", "--no-create-home", "--home-dir", u.Home, "--shell", u.Shell, "--comment", u.Comment}
		if _, err := user.LookupId(strconv.Itoa(u.UID)); err != nil {
			args = append(args, "--uid", strconv.Itoa(u.UID))
		}
		if name, ok := groupName[u.GID]; ok {
			args = append(args, "--gid", name)
		} else {
			args = append(args, "--user-group")
		}
		if _, err := env.Exec.Run(ctx, "useradd", append(args, u.Name)...); err != nil {
			return nil, nil, fmt.Errorf("user %s: %w", u.Name, err)
		}
		if created, err := user.Lookup(u.Name); err == nil {
			uid, _ := strconv.Atoi(created.Uid)
			uids[u.UID] = uid
			if uid != u.UID {
				rep.problem("user %s has id %d here (was %d)", u.Name, uid, u.UID)
			}
		}
	}
	// Supplementary members (e.g. the edge in a site's group).
	for _, g := range man.Groups {
		for _, m := range g.Members {
			_, _ = env.Exec.Run(ctx, "usermod", "--append", "--groups", g.Name, m)
		}
	}
	rep.ok("%d site users, %d groups", len(man.Users), len(man.Groups))
	return uids, gids, nil
}

// owner of a restored entry: by name where this server has it, else the
// mapped id.
func entryOwner(hdr *tar.Header, uids, gids idMap) (int, int) {
	uid, gid := uids.of(hdr.Uid), gids.of(hdr.Gid)
	if hdr.Uname != "" {
		if u, err := user.Lookup(hdr.Uname); err == nil {
			uid, _ = strconv.Atoi(u.Uid)
		}
	}
	if hdr.Gname != "" {
		if g, err := user.LookupGroup(hdr.Gname); err == nil {
			gid, _ = strconv.Atoi(g.Gid)
		}
	}
	return uid, gid
}

func restoreEntry(tr *tar.Reader, hdr *tar.Header, target string, uids, gids idMap) error {
	uid, gid := entryOwner(hdr, uids, gids)
	mode := os.FileMode(hdr.Mode).Perm()
	if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
		return err
	}
	switch hdr.Typeflag {
	case tar.TypeDir:
		if err := os.MkdirAll(target, mode); err != nil {
			return err
		}
		_ = os.Chmod(target, mode)
	case tar.TypeSymlink:
		_ = os.Remove(target)
		if err := os.Symlink(hdr.Linkname, target); err != nil {
			return err
		}
		return os.Lchown(target, uid, gid)
	case tar.TypeReg:
		// Never through a symlink that is already there.
		if fi, err := os.Lstat(target); err == nil && fi.Mode()&os.ModeSymlink != 0 {
			_ = os.Remove(target)
		}
		tmp := target + ".wspanel-restore"
		f, err := os.OpenFile(tmp, os.O_CREATE|os.O_WRONLY|os.O_TRUNC|syscall.O_NOFOLLOW, mode)
		if err != nil {
			return err
		}
		_, err = io.Copy(f, tr)
		if cerr := f.Close(); err == nil {
			err = cerr
		}
		if err == nil {
			err = os.Chmod(tmp, mode)
		}
		if err == nil {
			err = os.Rename(tmp, target)
		}
		if err != nil {
			_ = os.Remove(tmp)
			return err
		}
	default:
		return nil
	}
	return os.Lchown(target, uid, gid)
}

func installAs(src, dst, owner, group string, mode os.FileMode) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	tmp := dst + ".wspanel-restore"
	out, err := os.OpenFile(tmp, os.O_CREATE|os.O_WRONLY|os.O_TRUNC|syscall.O_NOFOLLOW, mode)
	if err != nil {
		return err
	}
	_, err = io.Copy(out, in)
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		_ = os.Remove(tmp)
		return err
	}
	if u, err := user.Lookup(owner); err == nil {
		g, gerr := user.LookupGroup(group)
		if gerr == nil {
			uid, _ := strconv.Atoi(u.Uid)
			gid, _ := strconv.Atoi(g.Gid)
			_ = os.Chown(tmp, uid, gid)
		}
	}
	_ = os.Chmod(tmp, mode)
	return os.Rename(tmp, dst)
}

// runSQLFile feeds a dump of accounts to the database server (existing
// accounts are kept: MariaDB's dump says IF NOT EXISTS, PostgreSQL's errors
// for those are not fatal).
func runSQLFile(ctx context.Context, env *Env, path, engine string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	if engine == "mariadb" {
		_, err = env.Exec.RunIO(ctx, f, nil, "mariadb", "--force")
		return err
	}
	_, err = env.Exec.RunIO(ctx, f, nil, "setpriv", "--reuid=postgres", "--regid=postgres", "--init-groups", "--reset-env", "--",
		"/usr/bin/psql", "-X", "-q", "-d", "postgres")
	return err
}

// ---------------------------------------------------------------- data

// backupSource: where the backups of sites, databases and mail come from.
type backupSource interface {
	// newest: the newest backup of a target, made available locally (in
	// t.local); "" when there is none.
	newest(ctx context.Context, t offsiteTarget) (string, error)
	Close()
}

// dirSource: a copy of the old server's backup folder (/var/backups/wspanel).
type dirSource struct {
	env  *Env
	root string
}

func (s dirSource) newest(_ context.Context, t offsiteTarget) (string, error) {
	base, root := s.env.Cfg.BackupDir, s.root
	if g := s.env.Cfg.GameBackupDir; g != "" && (t.local == g || strings.HasPrefix(t.local, g+"/")) {
		// Game servers' backups have a folder of their own beside it
		// (/var/backups/wspanel-games): copied next to the given folder.
		base, root = g, filepath.Join(filepath.Dir(filepath.Clean(s.root)), filepath.Base(g))
		if filepath.Clean(s.root) == filepath.Clean(s.env.Cfg.BackupDir) {
			root = g
		}
	}
	rel, err := filepath.Rel(base, t.local)
	if err != nil || strings.HasPrefix(rel, "..") {
		return "", errors.New("not a backup folder")
	}
	found := listLocal(offsiteTarget{dir: t.dir, local: filepath.Join(root, rel), match: t.match})
	if len(found) == 0 {
		return "", nil
	}
	if filepath.Clean(root) == filepath.Clean(base) {
		return found[0].File, nil // already in place
	}
	if err := os.MkdirAll(t.local, 0o750); err != nil {
		return "", err
	}
	if err := copyFile(found[0].Path, filepath.Join(t.local, found[0].File)); err != nil {
		return "", err
	}
	return found[0].File, nil
}

func (dirSource) Close() {}

func copyFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC|syscall.O_NOFOLLOW, 0o640)
	if err != nil {
		return err
	}
	_, err = io.Copy(out, in)
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	return err
}

// offsiteSource: the off-site storage of the restored state; each copy's
// signature must be the restored key's.
type offsiteSource struct {
	env *Env
	c   offsiteConfig
	st  offsiteStore
}

func (s offsiteSource) newest(ctx context.Context, t offsiteTarget) (string, error) {
	objs, err := s.st.List(ctx, s.c.key(t.dir))
	if err != nil {
		return "", err
	}
	newest := ""
	for _, o := range objs {
		if t.match(o.Name) && backupStamp(o.Name) > backupStamp(newest) {
			newest = o.Name
		}
	}
	if newest == "" {
		return "", nil
	}
	if fsx.Exists(filepath.Join(t.local, newest)) {
		return newest, nil
	}
	if err := os.MkdirAll(t.local, 0o750); err != nil {
		return "", err
	}
	return newest, fetchVerified(ctx, s.env, s.c, s.st, t, newest)
}

func (s offsiteSource) Close() { s.st.Close() }

// fetchVerified downloads one copy into t.local; refused unless its
// signature is this server's key's.
func fetchVerified(ctx context.Context, env *Env, c offsiteConfig, st offsiteStore, t offsiteTarget, file string) error {
	remote := c.key(t.dir, file)
	var sig bytes.Buffer
	if _, err := st.Fetch(ctx, remote+".sig", &sig, 1<<10); err != nil {
		return fmt.Errorf("%s has no signature: %w", remote, err)
	}
	tmp, err := os.CreateTemp(t.local, ".fetch-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	n, err := st.Fetch(ctx, remote, tmp, fileop.MaxRestore)
	if err != nil {
		tmp.Close()
		return fmt.Errorf("downloading %s failed: %w", remote, err)
	}
	key, err := signingKey(env)
	if err != nil {
		tmp.Close()
		return err
	}
	mac, err := fileMAC(key, t.dir, file, tmp, n)
	tmp.Close()
	if err != nil {
		return err
	}
	want, _ := hex.DecodeString(strings.TrimSpace(sig.String()))
	if !hmac.Equal(mac, want) {
		return fmt.Errorf("%s: the signature does not match the recovery key", remote)
	}
	_ = os.Chmod(tmp.Name(), 0o640)
	return os.Rename(tmp.Name(), filepath.Join(t.local, file))
}

// OpenBackupSource: a folder, or ("") the restored off-site storage.
func OpenBackupSource(ctx context.Context, env *Env, dir string) (backupSource, error) {
	if dir != "" {
		if !fsx.Exists(dir) {
			return nil, fmt.Errorf("%s does not exist", dir)
		}
		return dirSource{env: env, root: dir}, nil
	}
	c := loadOffsite(env)
	if !c.Enabled {
		return nil, errors.New("the server had no off-site storage: give the folder with its backups (--backups=<dir>)")
	}
	st, err := c.open(ctx, env)
	if err != nil {
		return nil, fmt.Errorf("connecting to the off-site storage failed: %w", err)
	}
	return offsiteSource{env: env, c: c, st: st}, nil
}

// RestoreServerData brings back the sites, the databases, the mail and the
// game servers.
func RestoreServerData(ctx context.Context, env *Env, man serverManifest, src backupSource, logf func(string, ...any)) RestoreReport {
	var rep RestoreReport
	done := map[dbRef]bool{}
	// Python came from Server → Moduly on the old server, not from the
	// installer's flags: what its sites run is installed before they return.
	for _, v := range missingPythons(env) {
		logf("Python %s for the Python sites", v)
		raw, _ := json.Marshal(map[string]string{"version": v})
		if _, err := pythonInstall(ctx, env, raw); err != nil {
			rep.problem("Python %s: %v", v, err)
			continue
		}
		rep.ok("Python %s", v)
	}
	for _, domain := range man.Sites {
		file, err := src.newest(ctx, siteTarget(env, domain))
		switch {
		case err != nil:
			rep.problem("site %s: %v", domain, err)
			continue
		case file == "":
			rep.problem("site %s: no backup (the site stays empty)", domain)
			continue
		}
		logf("site %s from %s", domain, file)
		dbs, err := restoreSiteFresh(ctx, env, domain, file)
		for _, d := range dbs {
			done[d] = true
		}
		if err != nil {
			rep.problem("site %s: %v", domain, err)
			continue
		}
		rep.ok("site %s (%s)", domain, file)
	}
	for _, d := range man.Databases {
		if done[d] {
			continue
		}
		e, err := engineFor(env, d.Engine)
		if err != nil {
			rep.problem("database %s: %v", d.Name, err)
			continue
		}
		if err := ensureDatabase(ctx, env, d); err != nil {
			rep.problem("database %s: %v", d.Name, err)
			continue
		}
		t := dbTarget(env, d.Engine, d.Name)
		file, err := src.newest(ctx, t)
		if err != nil || file == "" {
			rep.problem("database %s: no backup (it stays empty)%s", d.Name, errSuffix(err))
			continue
		}
		logf("database %s from %s", d.Name, file)
		if slices.Contains(man.Dedicated, d.Engine+"/"+d.Name) {
			rep.problem("database %s was on its site's dedicated server: restored into the shared %s server (set the dedicated server up again and move it)", d.Name, d.Engine)
		}
		if err := restoreDump(ctx, env, e, d.Name, filepath.Join(t.local, file)); err != nil {
			rep.problem("database %s: %v", d.Name, err)
			continue
		}
		rep.ok("database %s (%s)", d.Name, file)
	}
	for _, domain := range man.MailDomains {
		t := mailTarget(env, domain)
		file, err := src.newest(ctx, t)
		if err != nil || file == "" {
			rep.problem("mail of %s: no backup%s", domain, errSuffix(err))
			continue
		}
		logf("mail of %s from %s", domain, file)
		if err := restoreMail(env, domain, filepath.Join(t.local, file)); err != nil {
			rep.problem("mail of %s: %v", domain, err)
			continue
		}
		rep.ok("mail of %s (%s)", domain, file)
	}
	restoreGames(ctx, env, man, src, &rep, logf)
	return rep
}

func errSuffix(err error) string {
	if err == nil {
		return ""
	}
	return ": " + err.Error()
}

// ensureDatabase creates an empty database for its restored account.
func ensureDatabase(ctx context.Context, env *Env, d dbRef) error {
	if err := (dbParams{Engine: d.Engine, Name: d.Name}).validateOnly(env); err != nil {
		return err
	}
	switch d.Engine {
	case "mongodb": // a database exists once it holds data; its owner comes with the users
		return nil
	case "mariadb":
		_, err := (mariadb{}).sql(ctx, env, fmt.Sprintf("CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", d.Name))
		return err
	default:
		p := postgres{}
		out, err := p.psql(ctx, env, "postgres", fmt.Sprintf(`SELECT 1 FROM pg_database WHERE datname = '%s'`, d.Name))
		if err != nil {
			return err
		}
		if strings.TrimSpace(out) == "1" {
			return nil
		}
		_, err = p.psql(ctx, env, "postgres", fmt.Sprintf(`CREATE DATABASE "%[1]s" OWNER "%[1]s" ENCODING 'UTF8' TEMPLATE template0`, d.Name))
		return err
	}
}

// restoreSiteFresh: a site whose configuration is restored but whose home
// is empty: the backup's release becomes its release, its shared folder
// its shared folder, its databases are created and filled. Returns the
// databases it restored.
func restoreSiteFresh(ctx context.Context, env *Env, domain, file string) ([]dbRef, error) {
	path, err := siteBackupFile(env, domain, file)
	if err != nil {
		return nil, err
	}
	man, err := readManifest(path)
	if err != nil {
		return nil, err
	}
	if man.Domain != domain || !releaseNameRe.MatchString(man.Release) {
		return nil, errors.New("the backup belongs to another site")
	}
	_, l, err := loadSite(env, domain)
	if err != nil {
		return nil, err
	}
	owner, err := lookupOwner(env, l.User)
	if err != nil {
		return nil, err
	}
	if !fsx.Exists(l.Home) {
		if err := env.FS.MkdirAll(env.Cfg.SitesRoot, 0o751, fsx.Root); err != nil {
			return nil, err
		}
		if err := env.FS.Mkdir(l.Home, 0o750, owner); err != nil {
			return nil, err
		}
	}
	if err := asUser(ctx, env, l.User, "/usr/bin/mkdir", "-p", "--", filepath.Join(l.Home, "releases")); err != nil {
		return nil, err
	}

	want := map[string]manifestDB{}
	for _, d := range man.Databases {
		e, err := engineFor(env, d.Engine)
		if err != nil || d.File != "db/"+d.Engine+"-"+d.Name+e.ext() {
			continue
		}
		want[d.File] = d
	}
	stamp := time.Now().UTC().Format("20060102T150405Z")
	staging := ".wspanel-restore-" + stamp
	stagingPath := filepath.Join(l.Home, staging)
	cleanup := func() { _ = asUser(context.WithoutCancel(ctx), env, l.User, "/usr/bin/rm", "-rf", "--", stagingPath) }
	dumps := map[string]string{}
	defer func() {
		for _, d := range dumps {
			_ = os.Remove(d)
		}
	}()
	unpacked := false
	err = func() error {
		f, err := os.Open(path)
		if err != nil {
			return err
		}
		defer f.Close()
		gz, err := gzip.NewReader(f)
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
				return fmt.Errorf("broken backup: %w", err)
			}
			if _, ok := want[hdr.Name]; ok && hdr.Typeflag == tar.TypeReg {
				tmp, err := os.CreateTemp(siteBackupsDir(env, domain), ".restore-db-*")
				if err != nil {
					return err
				}
				dumps[hdr.Name] = tmp.Name()
				_, err = io.Copy(tmp, tr)
				if cerr := tmp.Close(); err == nil {
					err = cerr
				}
				if err != nil {
					return err
				}
				continue
			}
			if strings.HasPrefix(hdr.Name, "files/") {
				unpacked = true
				return streamToUnpacker(ctx, env, l, staging, tr, hdr)
			}
		}
	}()
	if err != nil {
		if unpacked {
			cleanup()
		}
		return nil, err
	}

	var restored []dbRef
	for name, d := range want {
		src, ok := dumps[name]
		if !ok {
			continue
		}
		ref := dbRef{Engine: d.Engine, Name: d.Name}
		if err := ensureDatabase(ctx, env, ref); err != nil {
			cleanup()
			return restored, fmt.Errorf("database %s: %w", d.Name, err)
		}
		e, _ := engineFor(env, d.Engine)
		if err := restoreDump(ctx, env, e, d.Name, src); err != nil {
			cleanup()
			return restored, fmt.Errorf("database %s: %w", d.Name, err)
		}
		restored = append(restored, ref)
	}
	if !unpacked {
		return restored, errors.New("the backup holds no files")
	}

	mv := func(from, to string) error { return asUser(ctx, env, l.User, "/usr/bin/mv", "-T", "--", from, to) }
	if err := mv(filepath.Join(stagingPath, "releases", man.Release), filepath.Join(l.Home, "releases", stamp)); err != nil {
		cleanup()
		return restored, err
	}
	if man.Shared {
		if fsx.Exists(filepath.Join(l.Home, "shared")) {
			_ = mv(filepath.Join(l.Home, "shared"), filepath.Join(l.Home, "shared.before-restore-"+stamp))
		}
		if err := mv(filepath.Join(stagingPath, "shared"), filepath.Join(l.Home, "shared")); err != nil {
			cleanup()
			return restored, err
		}
	}
	cleanup()
	var logBuf strings.Builder
	d := &deployment{env: env, l: l, release: stamp, log: &logBuf}
	if l.Mode == "classic" {
		if err := grantWebAccess(ctx, env, l, stamp); err != nil {
			return restored, err
		}
	}
	if err := relinkPythonVenv(ctx, env, l, stamp); err != nil {
		return restored, err
	}
	if err := d.switchTo(ctx, stamp); err != nil {
		return restored, err
	}
	d.restartServices(ctx)
	return restored, nil
}

// restoreMail unpacks a domain's mail backup into its folder (paths inside
// the domain only, owners by name).
func restoreMail(env *Env, domain, path string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	gz, err := gzip.NewReader(f)
	if err != nil {
		return err
	}
	tr := tar.NewReader(gz)
	root := filepath.Join(env.Cfg.VmailRoot, domain)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		name := strings.TrimSuffix(hdr.Name, "/")
		target := filepath.Join(env.Cfg.VmailRoot, name)
		if filepath.Clean(target) != target || (target != root && !strings.HasPrefix(target, root+"/")) {
			return fmt.Errorf("%s is outside %s", hdr.Name, domain)
		}
		if hdr.Typeflag != tar.TypeDir && hdr.Typeflag != tar.TypeReg {
			continue
		}
		if err := restoreEntry(tr, hdr, target, idMap{}, idMap{}); err != nil {
			return err
		}
	}
}

// missingPythons: Python versions the sites' state asks for that this server
// does not have.
func missingPythons(env *Env) []string {
	have := pythonVersions(env.Cfg)
	var out []string
	for _, st := range allSiteStates(env.Cfg) {
		if st.Mode == "python" && st.PythonVersion != "" && have[st.PythonVersion] == "" && !slices.Contains(out, st.PythonVersion) {
			out = append(out, st.PythonVersion)
		}
	}
	slices.Sort(out)
	return out
}
