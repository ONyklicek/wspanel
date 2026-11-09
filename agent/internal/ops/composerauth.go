package ops

import (
	"context"
	"encoding/json"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strings"

	"wspanel/agent/internal/protocol"
)

// A site's Composer credentials (auth.json) for private packages: where a
// deploy's Composer reads them (COMPOSER_HOME=<home>/tmp/composer) and where
// the site user's own composer over SSH does (~/.config/composer). Written
// and read as the site user, 0600; the secrets never leave the agent.

var (
	composerAuthTypes = []string{"http-basic", "github-oauth", "gitlab-token", "gitlab-oauth", "bearer"}
	composerHostRe    = regexp.MustCompile(`^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(:[0-9]{1,5})?$`)
)

func composerAuthPaths(l siteLayout) []string {
	return []string{filepath.Join(l.Home, "tmp", "composer", "auth.json"), filepath.Join(l.Home, ".config", "composer", "auth.json")}
}

func readComposerAuth(ctx context.Context, env *Env, l siteLayout) (map[string]map[string]json.RawMessage, error) {
	content, err := readSiteFile(ctx, env, l, composerAuthPaths(l)[0])
	if err != nil {
		return nil, err
	}
	auth := map[string]map[string]json.RawMessage{}
	if strings.TrimSpace(content) != "" {
		if err := json.Unmarshal([]byte(content), &auth); err != nil {
			return nil, protocol.Invalid("auth.json is not valid JSON")
		}
	}
	return auth, nil
}

type composerAuthEntry struct {
	Type     string `json:"type"`
	Host     string `json:"host"`
	Username string `json:"username,omitempty"`
}

// composerAuthList: the hosts with credentials (and http-basic's username).
func composerAuthList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
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
	auth, err := readComposerAuth(ctx, env, l)
	if err != nil {
		return nil, err
	}
	out := []composerAuthEntry{}
	for typ, hosts := range auth {
		if !slices.Contains(composerAuthTypes, typ) {
			continue
		}
		for host, v := range hosts {
			e := composerAuthEntry{Type: typ, Host: host}
			if typ == "http-basic" {
				var b struct {
					Username string `json:"username"`
				}
				_ = json.Unmarshal(v, &b)
				e.Username = b.Username
			}
			out = append(out, e)
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Type+out[i].Host < out[j].Type+out[j].Host })
	return map[string]any{"entries": out}, nil
}

// composerAuthSet adds or replaces one credential, or removes one.
func composerAuthSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain   string `json:"domain"`
		Type     string `json:"type"`
		Host     string `json:"host"`
		Username string `json:"username,omitempty"`
		Secret   string `json:"secret,omitempty"` // password or token
		Remove   bool   `json:"remove,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	p.Host = strings.ToLower(strings.TrimSpace(p.Host))
	switch {
	case !slices.Contains(composerAuthTypes, p.Type):
		return nil, protocol.Invalid("type: " + strings.Join(composerAuthTypes, ", "))
	case !composerHostRe.MatchString(p.Host):
		return nil, protocol.Invalid("host: a host name, e.g. repo.packagist.com")
	case !p.Remove && (p.Secret == "" || len(p.Secret) > 4096 || strings.ContainsAny(p.Secret, "\r\n\x00")):
		return nil, protocol.Invalid("secret: the password or token, one line")
	case p.Type == "http-basic" && !p.Remove && (p.Username == "" || len(p.Username) > 255 || strings.ContainsAny(p.Username, "\r\n\x00")):
		return nil, protocol.Invalid("username: required for http-basic")
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	auth, err := readComposerAuth(ctx, env, l)
	if err != nil {
		return nil, err
	}
	if p.Remove {
		delete(auth[p.Type], p.Host)
		if len(auth[p.Type]) == 0 {
			delete(auth, p.Type)
		}
	} else {
		if auth[p.Type] == nil {
			auth[p.Type] = map[string]json.RawMessage{}
		}
		// Tokens are plain strings; http-basic is a username and password.
		var v any = p.Secret
		if p.Type == "http-basic" {
			v = map[string]string{"username": p.Username, "password": p.Secret}
		}
		b, _ := json.Marshal(v)
		auth[p.Type][p.Host] = b
	}
	b, _ := json.MarshalIndent(auth, "", "    ")
	for _, path := range composerAuthPaths(l) {
		if err := asUser(ctx, env, l.User, "/usr/bin/install", "-d", "-m", "0700", filepath.Dir(path)); err != nil {
			return nil, err
		}
		if err := writeSiteFile(ctx, env, l, path, string(b)+"\n"); err != nil {
			return nil, err
		}
	}
	return map[string]any{"type": p.Type, "host": p.Host, "removed": p.Remove}, nil
}
