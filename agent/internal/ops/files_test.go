package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

func filesEnv(t *testing.T, answer string) (*Env, *executor.DryRun) {
	env, dr := testEnv(t)
	env.Cfg.ArtifactDir = t.TempDir()
	env.Cfg.AgentBinary = "/usr/local/sbin/panel-agent"
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(strings.Join(c.Args, " "), "-fileop") {
			return executor.Result{Stdout: answer}, nil
		}
		return executor.Result{}, nil
	}
	return env, dr
}

func fileCall(dr *executor.DryRun) executor.Call {
	for _, c := range dr.Calls() {
		if c.Name == "setpriv" && strings.Contains(strings.Join(c.Args, " "), "-fileop") {
			return c
		}
	}
	return executor.Call{}
}

func TestFilesRunAsTheSiteUser(t *testing.T) {
	env, dr := filesEnv(t, `{"ok":true,"path":".","entries":[{"name":"current","type":"dir"}],"count":1}`+"\n")
	out, err := call(t, env, siteFiles, map[string]any{"domain": "app.example.com", "action": "list", "path": "/"})
	if err != nil {
		t.Fatal(err)
	}
	c := fileCall(dr)
	want := "--reuid=web_app_example_com --regid=web_app_example_com --init-groups --reset-env -- /usr/local/sbin/panel-agent -fileop " + env.Cfg.SitesRoot + "/app.example.com"
	if strings.Join(c.Args, " ") != want {
		t.Fatalf("args: %s", strings.Join(c.Args, " "))
	}
	if !strings.HasPrefix(c.Stdin, `{"action":"list","path":"/"`) || out.(map[string]any)["count"] != 1 {
		t.Fatalf("stdin %q out %v", c.Stdin, out)
	}
	if _, err := call(t, env, siteFiles, map[string]any{"domain": "app.example.com", "action": "exec", "path": "/"}); code(err) != protocol.CodeInvalidParams {
		t.Fatal("unknown action accepted")
	}
}

func TestFilesWriteSendsTheContentOnStdin(t *testing.T) {
	env, dr := filesEnv(t, `{"ok":true,"path":"shared/.env","size":7}`+"\n")
	if _, err := call(t, env, siteFiles, map[string]any{"domain": "app.example.com", "action": "write", "path": "shared/.env", "content": "A=1\nB=2"}); err != nil {
		t.Fatal(err)
	}
	c := fileCall(dr)
	if !strings.HasSuffix(c.Stdin, "\nA=1\nB=2") || !strings.Contains(c.Stdin, `"size":7`) || strings.Contains(strings.Join(c.Args, " "), "A=1") {
		t.Fatalf("stdin %q", c.Stdin)
	}
}

func TestFilesUploadOnlyFromThePanelsFile(t *testing.T) {
	env, dr := filesEnv(t, `{"ok":true,"path":"current/public/a.txt","size":5}`+"\n")
	for _, bad := range []string{"../etc/passwd", "dl-1234", "up-xyz"} {
		if _, err := call(t, env, siteFiles, map[string]any{"domain": "app.example.com", "action": "upload", "path": "a.txt", "upload": bad}); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%q accepted: %v", bad, err)
		}
	}
	name := "up-" + strings.Repeat("ab", 16)
	src := filepath.Join(env.Cfg.ArtifactDir, name)
	_ = os.WriteFile(src, []byte("hello"), 0o600)
	link := "up-" + strings.Repeat("cd", 16)
	_ = os.Symlink("/etc/passwd", filepath.Join(env.Cfg.ArtifactDir, link))
	if _, err := call(t, env, siteFiles, map[string]any{"domain": "app.example.com", "action": "upload", "path": "a.txt", "upload": link}); err == nil {
		t.Fatal("followed a symlink")
	}
	if _, err := call(t, env, siteFiles, map[string]any{"domain": "app.example.com", "action": "upload", "path": "current/public/a.txt", "upload": name}); err != nil {
		t.Fatal(err)
	}
	if !strings.HasSuffix(fileCall(dr).Stdin, "\nhello") {
		t.Fatal("upload not streamed")
	}
	if _, err := os.Stat(src); !os.IsNotExist(err) {
		t.Fatal("the panel's upload file was not removed")
	}
}

func TestFilesDownloadGoesToAFile(t *testing.T) {
	env, _ := filesEnv(t, `{"ok":true,"path":"logo.png","size":4,"payload":true}`+"\n"+"\x89PNG")
	out, err := call(t, env, siteFiles, map[string]any{"domain": "app.example.com", "action": "download", "path": "logo.png"})
	if err != nil {
		t.Fatal(err)
	}
	name := out.(map[string]any)["download"].(string)
	b, err := os.ReadFile(filepath.Join(env.Cfg.ArtifactDir, name))
	if err != nil || string(b) != "\x89PNG" {
		t.Fatalf("%q %v", b, err)
	}
}

func TestFilesErrorsMapToCodes(t *testing.T) {
	env, _ := filesEnv(t, `{"ok":false,"code":"conflict","error":"the file changed since it was opened"}`+"\n")
	if _, err := call(t, env, siteFiles, map[string]any{"domain": "app.example.com", "action": "write", "path": "x", "content": ""}); code(err) != protocol.CodeConflict {
		t.Fatal(err)
	}
}

func TestGameFilesRunAsTheGameUserOverItsHome(t *testing.T) {
	env, dr := filesEnv(t, `{"ok":true,"path":".","entries":[],"count":0}`+"\n")
	env.Cfg.GamesRoot = filepath.Join(t.TempDir(), "games")
	if err := saveGame(env, gameState{ID: 5, Name: "x", Game: "minecraft", Flavor: "paper", Version: "1.21.10", Port: 25565, RconPort: 27000, RconPassword: "p", MemoryMB: 2048}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, gameFiles, map[string]any{"game": 5, "action": "list", "path": "/"}); err != nil {
		t.Fatal(err)
	}
	args := strings.Join(fileCall(dr).Args, " ")
	if !strings.HasPrefix(args, "--reuid=wsg_5 ") || !strings.HasSuffix(args, "-fileop "+filepath.Join(env.Cfg.GamesRoot, "5")) {
		t.Fatalf("args: %s", args)
	}
	for _, bad := range []map[string]any{{"action": "list", "path": "/"}, {"game": 5, "domain": "app.example.com", "action": "list", "path": "/"}} {
		if _, err := call(t, env, gameFiles, bad); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: %v", bad, err)
		}
	}
	if _, err := call(t, env, siteFiles, map[string]any{"game": 5, "action": "list", "path": "/"}); code(err) != protocol.CodeInvalidParams {
		t.Errorf("site.files must not open a game's home: %v", err)
	}
	if _, err := call(t, env, gameFiles, map[string]any{"game": 9, "action": "list", "path": "/"}); code(err) != protocol.CodeNotFound {
		t.Errorf("unknown game: %v", err)
	}
}
