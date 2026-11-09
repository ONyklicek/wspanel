package ops

import (
	"context"
	"fmt"
	"io"
	"net/url"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

// readSiteFile reads a file inside the site home as the site user, so a
// planted symlink cannot make root disclose another file. Missing = "".
func readSiteFile(ctx context.Context, env *Env, l siteLayout, path string) (string, error) {
	var out strings.Builder
	res, err := env.Exec.RunIO(ctx, nil, &out, "setpriv", "--reuid="+l.User, "--regid="+primaryGroup(l.User), "--init-groups", "--reset-env", "--",
		"/usr/bin/cat", path)
	if err != nil {
		if strings.Contains(res.Stderr, "No such file") {
			return "", nil
		}
		return "", err
	}
	return out.String(), nil
}

// writeSiteFile replaces a file inside the site home as the site user:
// written to a temp file next to it, then renamed (atomic for readers).
func writeSiteFile(ctx context.Context, env *Env, l siteLayout, path, content string) error {
	tmp := filepath.Join(filepath.Dir(path), "."+filepath.Base(path)+".wspanel-tmp")
	user := userCreds(l.User)
	// tee reads the inherited stdin descriptor; re-opening /dev/stdin by path
	// would fail, as the pipe belongs to root. The agent's umask (0077) makes
	// the new file 0600.
	if _, err := env.Exec.RunIO(ctx, strings.NewReader(content), io.Discard, "setpriv",
		append(user, "/usr/bin/tee", tmp)...); err != nil {
		return err
	}
	_, err := env.Exec.Run(ctx, "setpriv", append(user, "/usr/bin/mv", "-f", tmp, path)...)
	return err
}

// mergeEnv sets keys in dotenv content: existing assignments are replaced in
// place, missing ones appended under a marker. Other lines are kept.
func mergeEnv(content string, keys []string, values map[string]string) string {
	return mergeEnvSection(content, keys, values, "Database (written by WSPanel)")
}

// mergeEnvSection is mergeEnv with the heading of the appended block.
func mergeEnvSection(content string, keys []string, values map[string]string, heading string) string {
	quote := func(v string) string {
		return `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`, "\n", "", "$", `\$`).Replace(v) + `"`
	}
	done := map[string]bool{}
	lines := strings.Split(strings.TrimRight(content, "\n"), "\n")
	if content == "" {
		lines = nil
	}
	for i, line := range lines {
		for _, k := range keys {
			if regexp.MustCompile(`^\s*(export\s+)?` + regexp.QuoteMeta(k) + `\s*=`).MatchString(line) {
				lines[i] = k + "=" + quote(values[k])
				done[k] = true
			}
		}
	}
	var missing []string
	for _, k := range keys {
		if !done[k] {
			missing = append(missing, k+"="+quote(values[k]))
		}
	}
	if len(missing) > 0 {
		if len(lines) > 0 {
			lines = append(lines, "")
		}
		lines = append(lines, "# "+heading)
		lines = append(lines, missing...)
	}
	return strings.Join(lines, "\n") + "\n"
}

// writeDBEnv puts database credentials into the site's env file in the form
// its framework expects: DB_* for Laravel / plain PHP, DATABASE_URL for
// Symfony (with serverVersion) and Node apps.
func writeDBEnv(ctx context.Context, env *Env, l siteLayout, engine string, e dbEngine, name, pw string) error {
	path := envFilePath(l)
	if path == "" {
		return fmt.Errorf("site %s has no env file", l.Domain)
	}
	c := e.conn()
	port := strconv.Itoa(c.Port)
	var keys []string
	values := map[string]string{}
	if engine == "mongodb" {
		// Laravel (mongodb/laravel-mongodb), Symfony (Doctrine ODM) and the
		// rest read the URI; the app's main database stays what it is.
		keys = []string{"MONGODB_URI", "MONGODB_DATABASE"}
		values = map[string]string{"MONGODB_URI": mongoURI(name, pw), "MONGODB_DATABASE": name}
		old, err := readSiteFile(ctx, env, l, path)
		if err != nil {
			return err
		}
		return writeSiteFile(ctx, env, l, path, mergeEnv(old, keys, values))
	}
	switch l.Framework {
	case "laravel", "php":
		conn := "mariadb"
		if engine == "postgresql" {
			conn = "pgsql"
		}
		// DB_SOCKET: a dedicated instance's socket, emptied again for the shared server.
		keys = []string{"DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_SOCKET", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"}
		values = map[string]string{"DB_CONNECTION": conn, "DB_HOST": c.Host, "DB_PORT": port, "DB_SOCKET": c.Socket,
			"DB_DATABASE": name, "DB_USERNAME": name, "DB_PASSWORD": pw}
	default:
		scheme := "mysql"
		if engine == "postgresql" {
			scheme = "postgresql"
		}
		var u string
		var query []string
		switch {
		case c.Socket != "": // MariaDB instance: Doctrine and Django read unix_socket
			u = fmt.Sprintf("%s://%s:%s@localhost/%s", scheme, name, pw, name)
			query = append(query, "unix_socket="+c.Socket)
		case strings.HasPrefix(c.Host, "/"): // PostgreSQL instance: the socket directory as host
			u = fmt.Sprintf("%s://%s:%s@%s:%s/%s", scheme, name, pw, url.PathEscape(c.Host), port, name)
		default:
			u = fmt.Sprintf("%s://%s:%s@%s:%s/%s", scheme, name, pw, c.Host, port, name)
		}
		if l.Framework == "symfony" { // Doctrine wants the server version
			v, err := e.version(ctx, env)
			if err != nil {
				return err
			}
			if engine == "mariadb" {
				query = append(query, "serverVersion="+v+"-MariaDB", "charset=utf8mb4")
			} else {
				query = append(query, "serverVersion="+v, "charset=utf8")
			}
		}
		if len(query) > 0 {
			u += "?" + strings.Join(query, "&")
		}
		keys = []string{"DATABASE_URL"}
		values["DATABASE_URL"] = u
	}
	old, err := readSiteFile(ctx, env, l, path)
	if err != nil {
		return err
	}
	return writeSiteFile(ctx, env, l, path, mergeEnv(old, keys, values))
}
