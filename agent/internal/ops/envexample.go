package ops

import (
	"context"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// The template a repository ships (.env.example) fills the site's env file
// with the keys it does not have yet, so a first deploy starts from what the
// application expects rather than an empty file, and a key a new version
// adds arrives with its default. Nothing already there is replaced; the
// panel's keys (database, mail, Valkey, Reverb) stay the panel's; empty
// values are left out (an empty value overrides a config default, a missing
// one does not). Lines are copied as written, so "${APP_NAME}" still expands.

// exampleOverrides: development values a template commonly carries that must
// not reach a production site.
func exampleOverrides(domain string) map[string]string {
	return map[string]string{
		"APP_ENV":     "production",
		"APP_DEBUG":   "false",
		"APP_URL":     "https://" + domain,
		"NODE_ENV":    "production",
		"DEBUG":       "False",
		"FLASK_DEBUG": "0",
	}
}

// exampleSecretRe: keys never copied, even with a value: a value committed
// to a repository is public ("REVERB_APP_SECRET=my-secret", "JWT_SECRET=
// changeme"). The framework's own is generated (ensureAppSecrets); the rest
// the deploy log names for the operator to set.
var exampleSecretRe = regexp.MustCompile(`(?i)(KEY|SECRET|TOKEN|PASSWORD|PASSWD|PASS|SALT|PRIVATE|CREDENTIAL|DSN)`)

// envFromExample returns the lines to add to the site's env file and their
// keys, and the secret-looking keys with a value it left for the operator.
func envFromExample(example, current string, managed map[string]bool, domain string) (lines, keys, secrets []string) {
	have := parseDotenv(current)
	overrides := exampleOverrides(domain)
	seen := map[string]bool{}
	for _, raw := range strings.Split(example, "\n") {
		line := strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(raw), "export "))
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		k, v = strings.TrimSpace(k), strings.TrimSpace(v)
		if !ok || !envKeyRe.MatchString(k) || seen[k] || managed[k] {
			continue
		}
		seen[k] = true
		if _, there := have[k]; there {
			continue
		}
		if exampleSecretRe.MatchString(k) {
			if parseDotenv(line)[k] != "" && k != "APP_KEY" && k != "APP_SECRET" {
				secrets = append(secrets, k)
			}
			continue
		}
		if o, ok := overrides[k]; ok {
			lines, keys = append(lines, k+"="+o), append(keys, k)
			continue
		}
		// A quoted value that goes on below this line: not copied in part.
		if q := v; len(q) > 0 && (q[0] == '"' || q[0] == '\'') && (len(q) < 2 || q[len(q)-1] != q[0]) {
			continue
		}
		if parseDotenv(line)[k] == "" {
			continue
		}
		lines, keys = append(lines, k+"="+v), append(keys, k)
	}
	return lines, keys, secrets
}

// envFromExampleFile adds what the release's .env.example has and the
// shared env file lacks (Symfony's own .env is that template already).
func (d *deployment) envFromExampleFile(ctx context.Context) error {
	l := d.l
	if sharedEnvFile(l.Framework) != ".env" {
		return nil
	}
	rel := filepath.Join(d.dir(), ".env.example")
	if fi, err := os.Lstat(rel); err != nil || !fi.Mode().IsRegular() {
		return nil
	}
	example, err := readSiteFile(ctx, d.env, l, rel)
	if err != nil {
		return err
	}
	sharedPath := filepath.Join(l.Home, "shared", ".env")
	current, err := readSiteFile(ctx, d.env, l, sharedPath)
	if err != nil {
		return err
	}
	st, _, err := loadSite(d.env, l.Domain)
	if err != nil {
		return err
	}
	lines, keys, secrets := envFromExample(example, current, panelManagedKeys(d.env, l, st, parseDotenv(current)), l.Domain)
	if len(keys) == 0 && len(secrets) == 0 {
		return nil
	}
	d.logf("\n==> .env.example\n")
	if len(keys) > 0 {
		d.logf("%d keys the shared .env did not have: %s\n", len(keys), strings.Join(keys, ", "))
	}
	if len(secrets) > 0 {
		d.logf("not copied (a value in the repository is public), set them in the site's .env: %s\n", strings.Join(secrets, ", "))
	}
	if len(keys) == 0 {
		return nil
	}
	out := strings.TrimRight(current, "\n")
	if out != "" {
		out += "\n\n"
	}
	out += "# From .env.example (added by WSPanel; edit freely, it is never overwritten)\n" + strings.Join(lines, "\n") + "\n"
	return writeSiteFile(ctx, d.env, l, sharedPath, out)
}
