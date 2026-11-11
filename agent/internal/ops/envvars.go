package ops

import (
	"context"
	"encoding/json"
	"regexp"
	"slices"
	"sort"
	"strings"
	"unicode/utf8"

	"wspanel/agent/internal/protocol"
)

// A site's environment as Prostředí shows it: variables (values shown) and
// secrets (values never leave the agent), both in the site's shared env
// file, beside what the panel itself keeps there (databases, Valkey, mail).

// envVars: the keys of the env file, the values of those not asked to be
// kept secret, and the keys the panel owns.
func envVars(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain  string   `json:"domain"`
		Secrets []string `json:"secrets,omitempty"` // keys whose values stay here
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	path := envFilePath(l)
	if path == "" {
		return nil, protocol.NotFound(p.Domain + " has no env file")
	}
	content, err := readSiteFile(ctx, env, l, path)
	if err != nil {
		return nil, err
	}
	current := parseDotenv(content)
	infra := infraEnvKeys(env, l, st, current)
	vars := map[string]any{}
	for k, v := range current {
		if infra[k] {
			continue // the panel's: named below, values not shown here
		}
		if slices.Contains(p.Secrets, k) {
			vars[k] = map[string]bool{"set": v != ""}
			continue
		}
		vars[k] = v
	}
	managed := make([]string, 0, len(infra))
	for k := range infra {
		managed = append(managed, k)
	}
	sort.Strings(managed)
	return map[string]any{"vars": vars, "managed": managed, "panel_set": nonNil(st.EnvVars)}, nil
}

// infraEnvKeys: what the panel owns for the servers it runs (not what the
// operator set in Prostředí).
func infraEnvKeys(env *Env, l siteLayout, st siteState, current map[string]string) map[string]bool {
	st.EnvVars = nil
	return panelManagedKeys(env, l, st, current)
}

var envValueRe = regexp.MustCompile("^[^\x00\r\n]*$")

// envVarsSet sets and removes variables and secrets (secrets is the same as
// vars, kept out of the audit log); the rest of the file stays.
func envVarsSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain  string            `json:"domain"`
		Vars    map[string]string `json:"vars,omitempty"`
		Secrets map[string]string `json:"secrets,omitempty"`
		Unset   []string          `json:"unset,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if len(p.Vars)+len(p.Secrets)+len(p.Unset) == 0 || len(p.Vars)+len(p.Secrets)+len(p.Unset) > 200 {
		return nil, protocol.Invalid("vars: 1 to 200 changes")
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	path := envFilePath(l)
	if path == "" {
		return nil, protocol.NotFound(p.Domain + " has no env file")
	}
	content, err := readSiteFile(ctx, env, l, path)
	if err != nil {
		return nil, err
	}
	infra := infraEnvKeys(env, l, st, parseDotenv(content))
	values := map[string]string{}
	for _, m := range []map[string]string{p.Vars, p.Secrets} {
		for k, v := range m {
			values[k] = v
		}
	}
	check := func(k string) error {
		switch {
		case !envKeyRe.MatchString(k):
			return protocol.Invalid("vars: " + k + " is not a variable name (letters, digits, _)")
		case infra[k]:
			return protocol.Invalid("vars: " + k + " is the panel's (databases, Valkey, mail, workers)")
		}
		return nil
	}
	keys := make([]string, 0, len(values))
	for k, v := range values {
		if err := check(k); err != nil {
			return nil, err
		}
		if len(v) > 8192 || !utf8.ValidString(v) || !envValueRe.MatchString(v) {
			return nil, protocol.Invalid("vars: " + k + " must be one line of at most 8192 characters")
		}
		keys = append(keys, k)
	}
	for _, k := range p.Unset {
		if err := check(k); err != nil {
			return nil, err
		}
		if _, ok := values[k]; ok {
			return nil, protocol.Invalid("unset: " + k + " is also set")
		}
	}
	sort.Strings(keys)
	out := mergeEnvSection(content, keys, values, "Prostředí (set in WSPanel)")
	if len(p.Unset) > 0 {
		out = removeEnvKeys(out, p.Unset)
	}
	if len(out) > 64<<10 {
		return nil, protocol.Invalid("vars: the env file would exceed 64 KiB")
	}
	if err := writeSiteFile(ctx, env, l, path, out); err != nil {
		return nil, err
	}
	refreshAppConfig(ctx, env, l)
	set := slices.DeleteFunc(append(slices.Clone(st.EnvVars), keys...), func(k string) bool { return slices.Contains(p.Unset, k) })
	slices.Sort(set)
	st.EnvVars = slices.Compact(set)
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	return map[string]any{"set": keys, "unset": nonNil(p.Unset)}, nil
}

// removeEnvKeys drops the assignments of keys (and a marker left alone).
func removeEnvKeys(content string, keys []string) string {
	lines := []string{}
	for _, line := range strings.Split(strings.TrimRight(content, "\n"), "\n") {
		drop := false
		for _, k := range keys {
			if regexp.MustCompile(`^\s*(export\s+)?` + regexp.QuoteMeta(k) + `\s*=`).MatchString(line) {
				drop = true
			}
		}
		if !drop {
			lines = append(lines, line)
		}
	}
	return strings.Join(lines, "\n") + "\n"
}
