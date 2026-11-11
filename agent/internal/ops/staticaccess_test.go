package ops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

// installStatic puts the static file server's unit in place, as the
// installer does.
func installStatic(t *testing.T, env *Env) {
	t.Helper()
	if err := os.MkdirAll(env.Cfg.SystemdDir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(env.Cfg.SystemdDir, env.Cfg.StaticUnit), []byte("[Service]\n"), 0o644); err != nil {
		t.Fatal(err)
	}
}

// setfacls are the ACL changes the agent made, as the site user.
func setfacls(dr *executor.DryRun) []string {
	var out []string
	for _, c := range dr.Calls() {
		if args := strings.Join(c.Args, " "); c.Name == "setpriv" && strings.Contains(args, "/usr/bin/setfacl") {
			out = append(out, args[strings.Index(args, "/usr/bin/setfacl"):])
		}
	}
	return out
}

func anyContains(list []string, want string) bool {
	for _, s := range list {
		if strings.Contains(s, want) {
			return true
		}
	}
	return false
}

// A site links to another site's file: Caddy follows any symlink, so the
// web tier must not be what reads the files, nor be able to.
func TestTheWebTierHandsStaticFilesToTheServerThatRefusesForeignSymlinks(t *testing.T) {
	env, dr := testEnv(t)
	installStatic(t, env)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "a.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	l, _ := layoutFor(t, env, "a.example.com")
	block := read(t, l.WebFile)
	if strings.Contains(block, "file_server") {
		t.Error("the web tier still serves the site's files itself")
	}
	for _, want := range []string{"reverse_proxy unix//run/panel-static/static.sock", "header_up X-Wsp-Root " + l.Home + "/current/public"} {
		if !strings.Contains(block, want) {
			t.Errorf("web block lacks %q", want)
		}
	}
	if acls := setfacls(dr); !anyContains(acls, "u:panel-web:rX,d:u:panel-web:rX,u:panel-static:rX,d:u:panel-static:rX") {
		t.Errorf("public/ is not the static server's to read: %v", acls)
	}
	// Caddy reorders directives (reverse_proxy before php_fastcgi): only a
	// route keeps PHP pages from going to the static server.
	route := block[strings.Index(block, "route {"):]
	hidden, php, static := strings.Index(route, "respond @hidden 404"), strings.Index(route, "php_fastcgi"), strings.Index(route, "reverse_proxy unix//run/panel-static")
	if strings.Index(block, "route {") < 0 || hidden < 0 || php < hidden || static < php {
		t.Errorf("hidden files, PHP and static files are not handled in this order inside a route:\n%s", block)
	}
}

func TestWithoutTheStaticServerTheWebTierKeepsServingFiles(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "a.example.com", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	l, st := layoutFor(t, env, "a.example.com")
	if !strings.Contains(read(t, l.WebFile), "file_server") || st.StaticAccess != 0 {
		t.Error("an agent ahead of its installer handed files to a static server that is not there")
	}
	if !anyContains(setfacls(dr), "u:panel-web:rX,d:u:panel-web:rX") {
		t.Error("the web tier cannot read the files it serves")
	}
}

// An upgrade: the installer brings the static server, the agent's start
// grants it every existing site's files, once, and only then hands them over.
func TestExistingSitesMoveToTheStaticServerOnceTheirFilesAreGranted(t *testing.T) {
	env, dr := testEnv(t)
	for _, d := range []string{"old.example.com", "broken.example.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "php", "php_version": "8.4"}); err != nil {
			t.Fatal(err)
		}
	}
	installStatic(t, env)
	broken, _ := layoutFor(t, env, "broken.example.com")
	if err := os.Remove(filepath.Join(broken.Home, "current")); err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	logf := func(string, ...any) {}

	if n := grantStaticAccess(ctx, env, logf); n != 1 {
		t.Fatalf("granted %d sites, want 1", n)
	}
	if _, err := renderWebBlocks(ctx, env); err != nil {
		t.Fatal(err)
	}
	l, st := layoutFor(t, env, "old.example.com")
	if st.StaticAccess != staticAccessVersion || !strings.Contains(read(t, l.WebFile), "reverse_proxy unix//run/panel-static/static.sock") {
		t.Error("the existing site was not moved to the static server")
	}
	if !anyContains(setfacls(dr), "u:panel-static:rX") {
		t.Error("the static server was not granted the existing site's files")
	}
	if !strings.Contains(read(t, broken.WebFile), "file_server") {
		t.Error("a site whose grant failed was handed to a static server that cannot read it")
	}

	before := len(dr.Calls())
	if n := grantStaticAccess(ctx, env, logf); n != 0 {
		t.Errorf("the next start granted %d sites again", n)
	}
	for _, c := range dr.Calls()[before:] {
		if strings.Contains(strings.Join(c.Args, " "), "old.example.com") {
			t.Errorf("the next start went through a granted site's tree again: %s", c)
		}
	}
}
