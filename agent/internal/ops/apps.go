package ops

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"math/big"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"wspanel/agent/internal/protocol"
)

// One-click apps that are not a Git repository: WordPress. (Laravel and
// Symfony install as a deploy of their official skeleton, see the panel.)
// Everything runs as the site user, into the site's current release.

var sha1Re = regexp.MustCompile(`^[a-f0-9]{40}`)

func siteAppInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		App    string `json:"app"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.App != "wordpress" {
		return nil, protocol.Invalid("app: wordpress")
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if st.Framework != "php" || st.Mode != "classic" {
		return nil, protocol.Invalid("wordpress: a plain PHP site in classic (PHP-FPM) mode")
	}
	rel, err := currentRelease(l)
	if err != nil {
		return nil, err
	}
	pub := filepath.Join(l.Home, "releases", rel, "public")
	if entries, err := os.ReadDir(pub); err == nil {
		for _, e := range entries {
			if e.Name() != "index.php" { // the panel's placeholder
				return nil, protocol.Conflict("public/ is not empty: WordPress goes only into a new site")
			}
		}
	}
	dbEnv, err := readSiteFile(ctx, env, l, filepath.Join(l.Home, "shared", ".env"))
	if err != nil {
		return nil, err
	}
	db := dotenvValues(dbEnv)
	if db["DB_DATABASE"] == "" {
		return nil, protocol.Conflict("the site has no database yet (create one first)")
	}

	user := userCreds(l.User)
	archive := filepath.Join(l.Home, "tmp", "wordpress.tar.gz")
	if _, err := env.Exec.Run(ctx, "setpriv", append(user, "/usr/bin/curl", "-fsSL", "--max-time", "300", "-o", archive, "https://wordpress.org/latest.tar.gz")...); err != nil {
		return nil, err
	}
	defer func() {
		_, _ = env.Exec.Run(context.WithoutCancel(ctx), "setpriv", append(user, "/usr/bin/rm", "-f", archive)...)
	}()
	var want, got strings.Builder
	if _, err := env.Exec.RunIO(ctx, nil, &want, "setpriv", append(user, "/usr/bin/curl", "-fsSL", "--max-time", "30", "https://wordpress.org/latest.tar.gz.sha1")...); err != nil {
		return nil, err
	}
	if _, err := env.Exec.RunIO(ctx, nil, &got, "setpriv", append(user, "/usr/bin/sha1sum", archive)...); err != nil {
		return nil, err
	}
	if !env.Cfg.DryRun {
		w, g := sha1Re.FindString(strings.TrimSpace(want.String())), sha1Re.FindString(got.String())
		if w == "" || w != g {
			return nil, protocol.Conflict("the WordPress download does not match its checksum")
		}
	}
	if _, err := env.Exec.Run(ctx, "setpriv", append(user, "/usr/bin/rm", "-f", filepath.Join(pub, "index.php"))...); err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "setpriv", append(user, "/usr/bin/tar", "-xzf", archive, "--strip-components=1", "--no-same-owner", "--no-same-permissions", "-C", pub)...); err != nil {
		return nil, err
	}
	// One level above the docroot: WordPress looks there, visitors cannot.
	if err := writeSiteFile(ctx, env, l, filepath.Join(l.Home, "releases", rel, "wp-config.php"), wpConfig(db)); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "app": "wordpress", "next": "open the site and finish the installation (site name, admin account)"}, nil
}

// dotenvValues reads KEY=value lines (quotes removed).
func dotenvValues(content string) map[string]string {
	out := map[string]string{}
	for _, line := range strings.Split(content, "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(line), "=")
		if !ok || strings.HasPrefix(k, "#") {
			continue
		}
		v = strings.TrimSpace(v)
		if len(v) >= 2 && (v[0] == '"' && v[len(v)-1] == '"' || v[0] == '\'' && v[len(v)-1] == '\'') {
			v = strings.NewReplacer(`\"`, `"`, `\\`, `\`, `\$`, `$`).Replace(v[1 : len(v)-1])
		}
		out[strings.TrimSpace(k)] = v
	}
	return out
}

func wpConfig(db map[string]string) string {
	q := func(s string) string { return "'" + strings.NewReplacer(`\`, `\\`, `'`, `\'`).Replace(s) + "'" }
	host := db["DB_HOST"]
	if host == "" {
		host = "127.0.0.1"
	}
	if port := db["DB_PORT"]; port != "" && port != "3306" {
		host += ":" + port
	}
	var b strings.Builder
	b.WriteString("<?php\n// Written by WSPanel (one-click WordPress). Outside the docroot on purpose.\n")
	for _, kv := range [][2]string{{"DB_NAME", db["DB_DATABASE"]}, {"DB_USER", db["DB_USERNAME"]}, {"DB_PASSWORD", db["DB_PASSWORD"]}, {"DB_HOST", host}, {"DB_CHARSET", "utf8mb4"}, {"DB_COLLATE", ""}} {
		b.WriteString("define(" + q(kv[0]) + ", " + q(kv[1]) + ");\n")
	}
	for _, k := range []string{"AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY", "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT"} {
		b.WriteString("define(" + q(k) + ", " + q(randomSalt(64)) + ");\n")
	}
	b.WriteString(`$table_prefix = 'wp_';
// Behind the panel's proxy: TLS ends at the edge.
define('FORCE_SSL_ADMIN', true);
define('WP_DEBUG', false);
// Core, plugins and themes update themselves; files stay editable over SFTP.
define('FS_METHOD', 'direct');
if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__.'/public/');
}
require_once ABSPATH.'wp-settings.php';
`)
	return b.String()
}

func randomSalt(n int) string {
	const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}<>~+=,.;:/?|"
	out := make([]byte, n)
	for i := range out {
		k, _ := rand.Int(rand.Reader, big.NewInt(int64(len(chars))))
		out[i] = chars[k.Int64()]
	}
	return string(out)
}
