package ops

import (
	"context"
	"encoding/json"
	"net/url"
	"strings"
)

// siteCredentials reads what the panel wrote into the site's env file
// (database, Valkey, outgoing mail), so the operator can enter it in an
// application configured by hand (WordPress, an installer). Read as the site
// user; only known keys are returned.
func siteCredentials(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	out := map[string]any{"domain": p.Domain, "env_file": envFilePath(l)}
	path := envFilePath(l)
	if path == "" {
		return out, nil
	}
	content, err := readSiteFile(ctx, env, l, path)
	if err != nil {
		return nil, err
	}
	vals := parseDotenv(content)
	db := map[string]string{}
	for _, k := range []string{"DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"} {
		if v, ok := vals[k]; ok {
			db[strings.ToLower(strings.TrimPrefix(k, "DB_"))] = v
		}
	}
	if u, ok := vals["DATABASE_URL"]; ok {
		if parsed, err := url.Parse(u); err == nil && parsed.User != nil {
			pw, _ := parsed.User.Password()
			db = map[string]string{"connection": parsed.Scheme, "host": parsed.Hostname(), "port": parsed.Port(),
				"database": strings.TrimPrefix(parsed.Path, "/"), "username": parsed.User.Username(), "password": pw}
		}
	}
	if len(db) > 0 {
		out["database"] = db
	}
	pick := func(keys ...string) map[string]string {
		m := map[string]string{}
		for _, k := range keys {
			if v, ok := vals[k]; ok {
				m[k] = v
			}
		}
		return m
	}
	if m := pick("REDIS_HOST", "REDIS_PORT", "REDIS_URL", "REDIS_SOCKET"); len(m) > 0 {
		out["valkey"] = m
	}
	if m := pick("MAIL_HOST", "MAIL_PORT", "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS", "MAILER_DSN", "SMTP_HOST", "SMTP_PORT", "SMTP_USER", "SMTP_PASS"); len(m) > 0 {
		out["mail"] = m
	}
	return out, nil
}

// parseDotenv reads KEY=value lines (quotes and escapes as mergeEnv writes them).
func parseDotenv(s string) map[string]string {
	out := map[string]string{}
	for _, line := range strings.Split(s, "\n") {
		line = strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(line), "export "))
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		v = strings.TrimSpace(v)
		if len(v) >= 2 && (v[0] == '"' || v[0] == '\'') && v[len(v)-1] == v[0] {
			q := v[0]
			v = v[1 : len(v)-1]
			if q == '"' {
				v = strings.NewReplacer(`\"`, `"`, `\$`, `$`, `\\`, `\`).Replace(v)
			}
		}
		out[strings.TrimSpace(k)] = v
	}
	return out
}
