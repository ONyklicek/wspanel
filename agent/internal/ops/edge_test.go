package ops

import (
	"encoding/json"
	"errors"
	"os"
	"regexp"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

const testBcrypt = "$2a$14$KgDOdONU2TibK6051EW5zOf6AKa2x4Iq3vV/fq8AliJihtLnTo1pC"

func edgeEnv(t *testing.T) (*Env, *executor.DryRun) {
	env, dr := testEnv(t)
	env.Cfg.PanelDomain = "panel.example.com"
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "frankenphp" && len(c.Args) > 0 && c.Args[0] == "hash-password" {
			return executor.Result{Stdout: testBcrypt + "\n"}, nil
		}
		return executor.Result{}, nil
	}
	for _, d := range []string{"shop.example.com", "blog.example.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "laravel", "php_version": "8.4"}); err != nil {
			t.Fatal(err)
		}
	}
	return env, dr
}

func TestEdgeSettingsRender(t *testing.T) {
	env, dr := edgeEnv(t)
	out, err := call(t, env, siteEdge, map[string]any{
		"domain":         "shop.example.com",
		"aliases":        []string{"Shop.example.net"},
		"redirect_hosts": []string{"www.shop.example.com"},
		"redirects":      []map[string]any{{"from": "/old", "to": "/new"}, {"from": "/blog/*", "to": "https://blog.example.com/", "code": 302}},
		"auth_path":      "/admin", "auth_user": "boss", "auth_password": "s3cret-pass",
		"allow_ips": []string{"203.0.113.7", "10.8.0.9/24"}, "allow_path": "/",
		"hsts": true, "security_headers": true,
		"headers": []map[string]string{{"name": "X-Robots-Tag", "value": "noindex"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(strings.ToLower(asJSON(out)), "s3cret") || strings.Contains(asJSON(out), testBcrypt) {
		t.Fatal("the password or its hash went back to the panel")
	}
	l, st := layoutFor(t, env, "shop.example.com")
	edge, _ := os.ReadFile(l.EdgeFile)
	for _, want := range []string{
		"shop.example.com, shop.example.net {",
		`header Strict-Transport-Security "max-age=31536000; includeSubDomains"`,
		"X-Content-Type-Options nosniff",
		`header X-Robots-Tag "noindex"`,
		"route {",
		"not remote_ip 203.0.113.7/32 10.8.0.0/24",
		`@wsp_auth path_regexp (?i)^(/+[^/]*\.php)?/+admin(/.*)?$`,
		"boss " + testBcrypt,
		"redir /old /new 301",
		"redir /blog/* https://blog.example.com/ 302",
		"www.shop.example.com {\n\tredir https://shop.example.com{uri} 308",
	} {
		if !strings.Contains(string(edge), want) {
			t.Errorf("edge missing %q:\n%s", want, edge)
		}
	}
	// Protection comes before the app inside the route block.
	if strings.Index(string(edge), "basic_auth") > strings.LastIndex(string(edge), "reverse_proxy") {
		t.Fatal("basic_auth after reverse_proxy")
	}
	// No deploy webhook route unless the panel sets one for this site.
	if strings.Contains(string(edge), "wspanel-deploy") {
		t.Fatalf("a hook route nobody asked for:\n%s", edge)
	}
	for _, c := range dr.Calls() {
		if strings.Contains(strings.Join(c.Args, " "), "s3cret") {
			t.Fatalf("password in argv: %s", c)
		}
	}
	if st.Edge.AuthHash != testBcrypt {
		t.Fatal("hash not kept for the next save")
	}

	// Saving again without a password keeps the hash for the same user.
	if _, err := call(t, env, siteEdge, map[string]any{"domain": "shop.example.com", "auth_path": "/", "auth_user": "boss"}); err != nil {
		t.Fatal(err)
	}
	// A new user needs a password.
	if _, err := call(t, env, siteEdge, map[string]any{"domain": "shop.example.com", "auth_path": "/", "auth_user": "other"}); code(err) != protocol.CodeInvalidParams {
		t.Fatal("a new user without a password")
	}
	// Empty settings = back to the plain block.
	if _, err := call(t, env, siteEdge, map[string]any{"domain": "shop.example.com"}); err != nil {
		t.Fatal(err)
	}
	if _, st := layoutFor(t, env, "shop.example.com"); st.Edge != nil {
		t.Fatal("empty settings kept")
	}
}

func TestEdgeSettingsAreValidated(t *testing.T) {
	env, _ := edgeEnv(t)
	for _, bad := range []map[string]any{
		{"aliases": []string{"blog.example.com"}},  // another site
		{"aliases": []string{"panel.example.com"}}, // the panel
		{"aliases": []string{"bad domain"}},
		{"aliases": []string{"a.example.com"}, "redirect_hosts": []string{"a.example.com"}},
		{"redirects": []map[string]any{{"from": "old", "to": "/new"}}}, // not a path
		{"redirects": []map[string]any{{"from": "/a", "to": "javascript:alert(1)"}}},
		{"redirects": []map[string]any{{"from": "/a", "to": "/b\n}\nadmin off"}}},
		{"redirects": []map[string]any{{"from": "/a", "to": "/b", "code": 200}}},
		{"auth_path": "/", "auth_user": "bad user", "auth_password": "12345678"},
		{"auth_path": "/", "auth_user": "u", "auth_password": "short"},
		{"allow_ips": []string{"0.0.0.0/0"}},
		{"auth_path": "/%61dmin", "auth_user": "u", "auth_password": "12345678"},
		{"allow_ips": []string{"10.0.0.1"}, "allow_path": "/%61dmin"},
		{"allow_ips": []string{"office"}},
		{"headers": []map[string]string{{"name": "Set-Cookie", "value": "x=1"}}},
		{"headers": []map[string]string{{"name": "X-A", "value": `a" }`}}},
		{"headers": []map[string]string{{"name": "X-A", "value": "{env.SECRET}"}}},
		{"auth_hash": testBcrypt, "auth_path": "/", "auth_user": "u"}, // a hash from outside is ignored -> password missing
	} {
		bad["domain"] = "shop.example.com"
		if _, err := call(t, env, siteEdge, bad); err == nil {
			t.Errorf("%v accepted", bad)
		}
	}
}

func TestEdgeIsRolledBackWhenCaddyRefusesIt(t *testing.T) {
	env, dr := edgeEnv(t)
	l, _ := layoutFor(t, env, "shop.example.com")
	before, _ := os.ReadFile(l.EdgeFile)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "frankenphp" && len(c.Args) > 0 && c.Args[0] == "validate" {
			return executor.Result{ExitCode: 1}, errors.New("adapting config: unrecognized directive")
		}
		return executor.Result{}, nil
	}
	if _, err := call(t, env, siteEdge, map[string]any{"domain": "shop.example.com", "hsts": true}); err == nil {
		t.Fatal("kept an edge config Caddy refused")
	}
	if after, _ := os.ReadFile(l.EdgeFile); string(after) != string(before) {
		t.Fatal("edge block not restored")
	}
	if _, st := layoutFor(t, env, "shop.example.com"); st.Edge != nil {
		t.Fatal("state saved")
	}
}

func asJSON(v any) string {
	b, _ := json.Marshal(v)
	return string(b)
}

func TestProtectedPathCoversTheFrontController(t *testing.T) {
	m := edgePathMatcher("/admin")
	re := regexp.MustCompile(strings.TrimPrefix(m, "path_regexp "))
	for _, p := range []string{"/admin", "/admin/", "/admin/users", "/ADMIN/x", "/index.php/admin", "/index.php/admin/users", "/app.php/Admin", "//admin", "//index.php//admin/x"} {
		if !re.MatchString(p) {
			t.Errorf("%s is not covered", p)
		}
	}
	for _, p := range []string{"/", "/administrator", "/blog/admin", "/index.php", "/index.php/adminx"} {
		if re.MatchString(p) {
			t.Errorf("%s is covered but should not be", p)
		}
	}
	if edgePathMatcher("/") != "path *" || !strings.Contains(edgePathMatcher("/blog/*"), `/+blog(/.*)?$`) {
		t.Fatal(edgePathMatcher("/blog/*"))
	}
}

// A new agent brings existing sites' edge blocks up to its template, and only
// the changed ones; nothing to do = no reload.
func TestRenderEdgesRefreshesStaleBlocks(t *testing.T) {
	env, dr := edgeEnv(t)
	l, _ := layoutFor(t, env, "shop.example.com")
	if err := os.WriteFile(l.EdgeFile, []byte("# an older agent's block\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	n, err := renderEdges(t.Context(), env)
	if err != nil || n != 1 {
		t.Fatalf("refreshed %d, %v", n, err)
	}
	if edge, _ := os.ReadFile(l.EdgeFile); !strings.Contains(string(edge), "shop.example.com") {
		t.Fatalf("not re-rendered:\n%s", edge)
	}
	before := len(dr.Calls())
	if n, err := renderEdges(t.Context(), env); err != nil || n != 0 {
		t.Fatalf("second run refreshed %d, %v", n, err)
	}
	if len(dr.Calls()) != before {
		t.Error("nothing changed, yet the proxy was validated or reloaded")
	}
}

// The panel sets the site's own webhook route (a panel the internet does not
// reach): exactly that site's hook id, ahead of its IP and password rules;
// removing it leaves nothing behind.
func TestSiteHookRoute(t *testing.T) {
	env, _ := edgeEnv(t)
	if _, err := call(t, env, siteEdge, map[string]any{"domain": "shop.example.com", "allow_ips": []string{"203.0.113.7"}, "allow_path": "/"}); err != nil {
		t.Fatal(err)
	}
	id := "0123456789abcdef0123456789abcdef"
	if _, err := call(t, env, siteHook, map[string]string{"domain": "shop.example.com", "hook_id": id}); err != nil {
		t.Fatal(err)
	}
	l, _ := layoutFor(t, env, "shop.example.com")
	edge, _ := os.ReadFile(l.EdgeFile)
	for _, want := range []string{"@wsp_deploy_hook path /.well-known/wspanel-deploy/" + id, "rewrite * /hooks/deploy/" + id, "header_up Host wspanel-panel.internal"} {
		if !strings.Contains(string(edge), want) {
			t.Errorf("missing %q:\n%s", want, edge)
		}
	}
	if strings.Index(string(edge), "@wsp_deploy_hook") > strings.Index(string(edge), "@wsp_not_allowed") {
		t.Error("the hook route must come before the site's IP list (GitHub is not on it)")
	}
	if other, _ := os.ReadFile(func() string { l, _ := layoutFor(t, env, "blog.example.com"); return l.EdgeFile }()); strings.Contains(string(other), "wspanel-deploy") {
		t.Error("another site got the route")
	}
	for _, bad := range []string{"x", "../../etc", strings.ToUpper(id)} {
		if _, err := call(t, env, siteHook, map[string]string{"domain": "shop.example.com", "hook_id": bad}); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%q: %v", bad, err)
		}
	}
	if _, err := call(t, env, siteHook, map[string]string{"domain": "shop.example.com", "hook_id": ""}); err != nil {
		t.Fatal(err)
	}
	if edge, _ := os.ReadFile(l.EdgeFile); strings.Contains(string(edge), "wspanel-deploy") {
		t.Error("route left after removal")
	}
}
