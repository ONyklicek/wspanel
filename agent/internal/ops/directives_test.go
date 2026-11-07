package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestDirectivesAllowed(t *testing.T) {
	for _, src := range []string{
		`header X-Robots-Tag "noindex"`,
		"encode gzip zstd",
		"@static path_regexp \\.(css|js|png)$\nheader @static Cache-Control \"public, max-age=2592000\"",
		"@old {\n\tpath /old /old/*\n\tnot remote_ip 10.0.0.0/8\n}\nredir @old /new{uri} 301",
		"handle_path /legacy/* {\n\trewrite * /index.php?legacy={path}\n}",
		"request_body {\n\tmax_size 64MB\n}",
		"respond /healthz \"ok\" 200",
		"request_header X-App-Mode panel",
		"# a comment\nheader -Server",
	} {
		if err := checkDirectives(src); err != nil {
			t.Errorf("%q: %v", src, err)
		}
	}
}

func TestDirectivesRefused(t *testing.T) {
	for src, why := range map[string]string{
		"reverse_proxy 127.0.0.1:8025":                         "proxy to Mailpit",
		"handle /x {\n\treverse_proxy unix//run/other.sock\n}": "proxy hidden in a container",
		"file_server":                                     "serving files",
		"root * /etc":                                     "files",
		"import /etc/passwd":                              "imports",
		`respond "{file./etc/shadow}"`:                    "file placeholder",
		`header X-Secret {env.AGENT_KEY}`:                 "env placeholder",
		"header X-Y {$HOME}":                              "parse-time env",
		"request_header Host victim.example.com":          "Host would route to another site",
		"request_header {\n\t+X-Forwarded-For 1.2.3.4\n}": "spoofed client address",
		"@m expression {path}.startsWith('/a')":           "expression matcher",
		"@wsp_auth path /":                                "panel's own matcher names",
		"tls internal":                                    "certificates",
		"header X {\n":                                    "unbalanced",
		"}\nheader X y":                                   "unbalanced",
		"respond <<EOF\nhi\nEOF":                          "heredoc",
		"handle_errors {\n\trespond 500\n}":               "not a route directive",
	} {
		if err := checkDirectives(src); err == nil {
			t.Errorf("%s must be refused: %q", why, src)
		}
	}
	if err := checkDirectives(strings.Repeat("# x\n", 5000)); err == nil {
		t.Error("too long")
	}
}

func TestSiteDirectivesInEdgeRoute(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "rules.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteDirectives, map[string]string{"domain": "rules.example.com", "directives": "header X-Rule yes\r\nredir /old /new 301"}); err != nil {
		t.Fatal(err)
	}
	edge, _ := os.ReadFile(filepath.Join(env.Cfg.ProxySitesDir, "rules.example.com.caddy"))
	route := string(edge)[strings.Index(string(edge), "route {"):]
	rules := strings.Index(route, "\t\theader X-Rule yes\n\t\tredir /old /new 301")
	if rules < 0 || rules > strings.Index(route, "reverse_proxy") {
		t.Fatalf("rules must sit in the route, before the backend:\n%s", edge)
	}
	if _, err := call(t, env, siteDirectives, map[string]string{"domain": "rules.example.com", "directives": "reverse_proxy 127.0.0.1:8025"}); code(err) != "invalid_params" {
		t.Fatalf("forbidden directive: %v", err)
	}
	if st, _, _ := loadSite(env, "rules.example.com"); st.Directives != "header X-Rule yes\nredir /old /new 301" {
		t.Errorf("a refused snippet must not replace the stored one: %q", st.Directives)
	}
}
