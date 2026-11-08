package ops

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
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
	if rules < 0 || rules > strings.LastIndex(route, "reverse_proxy") {
		t.Fatalf("rules must sit in the route, before the backend:\n%s", edge)
	}
	if _, err := call(t, env, siteDirectives, map[string]string{"domain": "rules.example.com", "directives": "reverse_proxy 127.0.0.1:8025"}); code(err) != "invalid_params" {
		t.Fatalf("forbidden directive: %v", err)
	}
	if st, _, _ := loadSite(env, "rules.example.com"); st.Directives != "header X-Rule yes\nredir /old /new 301" {
		t.Errorf("a refused snippet must not replace the stored one: %q", st.Directives)
	}
}

func TestDirectivesParseTimeConstructsRefusedInAnyBlock(t *testing.T) {
	for _, src := range []string{
		"header {\n\timport /etc/panel-agent/agent.json\n}",
		"request_body {\n\timport x\n}",
		"(snip) {\n\treverse_proxy x\n}",
		"header {\n\t\"}\"\nreverse_proxy 127.0.0.1:8025\n}",
	} {
		if err := checkDirectives(src); err == nil {
			t.Errorf("must be refused: %q", src)
		}
	}
}

func TestAdaptedConfigIsCheckedAsCaddyReadsIt(t *testing.T) {
	parse := func(s string) any {
		var v any
		if err := json.Unmarshal([]byte(s), &v); err != nil {
			t.Fatal(err)
		}
		return v
	}
	ok := `{"apps":{"http":{"servers":{"srv0":{"routes":[{"match":[{"host":["wsp-check.invalid"]}],"handle":[{"handler":"subroute","routes":[{"handle":[{"handler":"static_response","headers":{"Location":["/new/{http.regexp.r1.1}"]},"status_code":301}],"match":[{"path_regexp":{"name":"r1","pattern":"^/old/(.*)$"}}]},{"handle":[{"handler":"headers","response":{"set":{"X-A":["b"]}}}]},{"handle":[{"handler":"static_response","status_code":403}],"match":[{"not":[{"remote_ip":{"ranges":["10.0.0.0/8"]}}]}]}]}]}]}}}}}`
	if err := checkAdapted(parse(ok)); err != nil {
		t.Fatalf("allowed config refused: %v", err)
	}
	for name, bad := range map[string]string{
		"proxy":          `{"routes":[{"handle":[{"handler":"subroute","routes":[{"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"127.0.0.1:8025"}]}]}]}]}]}`,
		"file server":    `{"handle":[{"handler":"file_server","root":"/etc"}]}`,
		"expression":     `{"match":[{"expression":"true"}],"handle":[]}`,
		"nested not":     `{"match":[{"not":[{"file":{"try_files":["/x"]}}]}],"handle":[]}`,
		"host header":    `{"handle":[{"handler":"headers","request":{"set":{"Host":["victim.example.com"]}}}]}`,
		"file in a body": `{"handle":[{"handler":"static_response","body":"{file./etc/shadow}"}]}`,
	} {
		if err := checkAdapted(parse(bad)); err == nil {
			t.Errorf("%s must be refused", name)
		}
	}
}

func TestAdaptErrorsShowOnlyTheRulesLine(t *testing.T) {
	file := "/tmp/wsp-rules-123/Caddyfile"
	msg := adaptError(`Error: adapting config using caddyfile: /tmp/wsp-rules-123/Caddyfile:8: unrecognized directive: encodex`, file)
	if msg != "line 3: unrecognized directive: encodex" || strings.Contains(msg, "/tmp") {
		t.Errorf("got %q", msg)
	}
	if adaptError("panic: something /etc/panel-proxy/sites/other.caddy", file) != "the web server could not read them" {
		t.Error("anything not about the rules file must not be shown")
	}
}

func TestSiteCredentialsReadTheEnvFile(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "wp.example.com", "framework": "php", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(c.String(), "/usr/bin/cat") {
			return executor.Result{Stdout: "APP_NAME=x\nDB_HOST=\"127.0.0.1\"\nDB_DATABASE=\"c1_wp\"\nDB_USERNAME=\"c1_wp\"\nDB_PASSWORD=\"p\\\"w\\$d\"\nREDIS_HOST=\"/run/s.sock\"\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, siteCredentials, map[string]string{"domain": "wp.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	db := out.(map[string]any)["database"].(map[string]string)
	if db["database"] != "c1_wp" || db["password"] != `p"w$d` || out.(map[string]any)["valkey"].(map[string]string)["REDIS_HOST"] != "/run/s.sock" {
		t.Errorf("credentials %v", out)
	}
	if !hasCall(dr, "setpriv --reuid=web_wp_example_com") {
		t.Error("the env file must be read as the site user")
	}
}

func TestDatabaseURLIsSplit(t *testing.T) {
	v := parseDotenv(`DATABASE_URL="mysql://shop:s3cr%40t@127.0.0.1:3306/shop?serverVersion=11"`)
	if v["DATABASE_URL"] == "" {
		t.Fatal("not parsed")
	}
}
