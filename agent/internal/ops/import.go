package ops

import (
	"context"
	"encoding/json"
	"io"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Moving a site in: a cPanel backup (cpmove-USER.tar.gz: homedir/public_html
// and mysql/*.sql) or a plain archive of the site's files, uploaded over
// SFTP into the site's ~/tmp. Everything is read and written as the site
// user; dumps are restored into the site's databases the panel created.

var (
	importFileRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(tar\.gz|tgz)$`)
	cpDumpRe     = regexp.MustCompile(`^(?:\./)?(?:[^/]+/)?mysql/([A-Za-z0-9_]{1,64})\.sql$`)
)

type importParams struct {
	Domain    string            `json:"domain"`
	File      string            `json:"file"`                // in the site's ~/tmp
	Databases map[string]string `json:"databases,omitempty"` // dump name -> the site's database
}

func (p importParams) archive(l siteLayout) (string, error) {
	if !importFileRe.MatchString(p.File) {
		return "", protocol.Invalid("file: a .tar.gz in the site's tmp/")
	}
	return filepath.Join(l.Home, "tmp", p.File), nil
}

// siteImportScan lists what an archive holds: whether it is a cPanel backup,
// and its database dumps.
func siteImportScan(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p importParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	archive, err := p.archive(l)
	if err != nil {
		return nil, err
	}
	var list strings.Builder
	if _, err := env.Exec.RunIO(ctx, nil, &list, "setpriv", append(userCreds(l.User), "/usr/bin/tar", "-tzf", archive)...); err != nil {
		return nil, err
	}
	cpanel, dumps := false, []string{}
	for _, e := range strings.Split(list.String(), "\n") {
		if strings.Contains(e, "homedir/public_html/") {
			cpanel = true
		}
		if m := cpDumpRe.FindStringSubmatch(e); m != nil {
			dumps = append(dumps, m[1])
		}
	}
	sort.Strings(dumps)
	return map[string]any{"cpanel": cpanel, "dumps": dumps}, nil
}

// siteImport unpacks the archive into the site's current release (public/)
// and restores the dumps into the databases given.
func siteImport(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p importParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.Framework != "php" {
		return nil, protocol.Invalid("import: a plain PHP site (frameworks deploy from Git)")
	}
	archive, err := p.archive(l)
	if err != nil {
		return nil, err
	}
	for dump, db := range p.Databases {
		if err := validate.DBName("databases", db); err != nil {
			return nil, err
		}
		if !regexp.MustCompile(`^[A-Za-z0-9_]{1,64}$`).MatchString(dump) {
			return nil, protocol.Invalid("databases: dump name")
		}
	}
	rel, err := currentRelease(l)
	if err != nil {
		return nil, err
	}
	pub := filepath.Join(l.Home, "releases", rel, "public")
	work := filepath.Join(l.Home, "tmp", "import-"+randomHex(6))
	user := userCreds(l.User)
	run := func(argv ...string) error {
		_, err := env.Exec.Run(ctx, "setpriv", append(user, argv...)...)
		return err
	}
	if err := run("/usr/bin/install", "-d", "-m", "0700", work); err != nil {
		return nil, err
	}
	defer func() { _ = run("/usr/bin/rm", "-rf", "--", work) }()
	if err := run("/usr/bin/tar", "-xzf", archive, "-C", work, "--no-same-owner", "--no-same-permissions"); err != nil {
		return nil, err
	}
	// Where the site's files are: cPanel's homedir/public_html, else a
	// public_html/ or public/ at the top, else the whole archive.
	var src strings.Builder
	if _, err := env.Exec.RunIO(ctx, nil, &src, "setpriv", append(user, "/usr/bin/bash", "-c",
		`d="$1"; for c in "$d"/cpmove-*/homedir/public_html "$d"/*/homedir/public_html "$d"/homedir/public_html "$d"/public_html "$d"/public; do [ -d "$c" ] && { echo "$c"; exit 0; }; done; echo "$d"`, "find", work)...); err != nil {
		return nil, err
	}
	from := strings.TrimSpace(src.String())
	if from == "" || !strings.HasPrefix(from, work) {
		from = work
	}
	if err := run("/usr/bin/rm", "-f", filepath.Join(pub, "index.php")); err != nil {
		return nil, err
	}
	if err := run("/usr/bin/cp", "-a", "--no-preserve=ownership", from+"/.", pub+"/"); err != nil {
		return nil, err
	}
	imported := []string{}
	for _, dump := range sortedKeys(p.Databases) {
		db := p.Databases[dump]
		var sql strings.Builder
		file := ""
		if _, err := env.Exec.RunIO(ctx, nil, &sql, "setpriv", append(user, "/usr/bin/bash", "-c",
			`ls -1 "$1"/cpmove-*/mysql/"$2".sql "$1"/*/mysql/"$2".sql "$1"/mysql/"$2".sql 2>/dev/null | head -1`, "find", work, dump)...); err == nil {
			file = strings.TrimSpace(sql.String())
		}
		if file == "" || !strings.HasPrefix(file, work) {
			continue
		}
		eng, err := engineFor(env, "mariadb")
		if err != nil {
			return nil, err
		}
		// Read as the site user, restored with the sandboxed client.
		pr, pw := io.Pipe()
		go func() {
			_, err := env.Exec.RunIO(ctx, nil, pw, "setpriv", append(user, "/usr/bin/cat", file)...)
			pw.CloseWithError(err)
		}()
		if err := eng.restore(ctx, env, db, pr); err != nil {
			_ = pr.Close()
			return nil, err
		}
		imported = append(imported, db)
	}
	out := map[string]any{"domain": p.Domain, "files_from": strings.TrimPrefix(from, work), "databases": imported}
	// A WordPress keeps working: its wp-config gets the site's database.
	if len(imported) > 0 {
		cfgPath := filepath.Join(pub, "wp-config.php")
		if cfg, err := readSiteFile(ctx, env, l, cfgPath); err == nil && cfg != "" {
			if dbEnv, err := readSiteFile(ctx, env, l, filepath.Join(l.Home, "shared", ".env")); err == nil {
				if next := rewriteWPConfigDB(cfg, dotenvValues(dbEnv)); next != cfg {
					if err := writeSiteFile(ctx, env, l, cfgPath, next); err != nil {
						return nil, err
					}
					out["wp_config"] = "updated"
				}
			}
		}
	}
	return out, nil
}

var wpDefineRe = regexp.MustCompile(`define\(\s*['"](DB_NAME|DB_USER|DB_PASSWORD|DB_HOST)['"]\s*,\s*(?:'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")\s*\)`)

// rewriteWPConfigDB points WordPress's DB_* defines at the site's database.
func rewriteWPConfigDB(cfg string, db map[string]string) string {
	if db["DB_DATABASE"] == "" {
		return cfg
	}
	host := db["DB_HOST"]
	if port := db["DB_PORT"]; port != "" && port != "3306" {
		host += ":" + port
	}
	val := map[string]string{"DB_NAME": db["DB_DATABASE"], "DB_USER": db["DB_USERNAME"], "DB_PASSWORD": db["DB_PASSWORD"], "DB_HOST": host}
	q := func(s string) string { return "'" + strings.NewReplacer(`\`, `\\`, `'`, `\'`).Replace(s) + "'" }
	return wpDefineRe.ReplaceAllStringFunc(cfg, func(m string) string {
		key := wpDefineRe.FindStringSubmatch(m)[1]
		return "define('" + key + "', " + q(val[key]) + ")"
	})
}
