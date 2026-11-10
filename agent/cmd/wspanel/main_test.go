package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
)

func TestCLIUsesTheTokenAndShowsTheAPIsErrors(t *testing.T) {
	var gotAuth, gotRef string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotAuth = r.Header.Get("Authorization")
		switch r.URL.Path {
		case "/api/v1/sites/app.cz/deploy":
			var b map[string]string
			_ = json.NewDecoder(r.Body).Decode(&b)
			gotRef = b["ref"]
			w.WriteHeader(202)
			_, _ = w.Write([]byte(`{"release":"20261108-1","status":"running"}`))
		case "/api/v1/sites/bob.cz/restart":
			w.WriteHeader(403)
			_, _ = w.Write([]byte(`{"error":"this token is read-only"}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	t.Setenv("WSPANEL_URL", srv.URL)
	t.Setenv("WSPANEL_TOKEN", "wspa_test")
	t.Setenv("HOME", t.TempDir())
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	devnull, _ := os.Open(os.DevNull)
	stdout := os.Stdout
	os.Stdout = devnull
	defer func() { os.Stdout = stdout }()

	if err := run([]string{"deploy", "app.cz", "v2"}, false); err != nil {
		t.Fatal(err)
	}
	if gotAuth != "Bearer wspa_test" || gotRef != "v2" {
		t.Errorf("auth %q, ref %q", gotAuth, gotRef)
	}
	if err := run([]string{"restart", "bob.cz"}, false); err == nil || !strings.Contains(err.Error(), "read-only (HTTP 403)") {
		t.Errorf("the API's error is shown: %v", err)
	}
	if err := run([]string{"login", "http://panel.example.com", "x"}, false); err == nil {
		t.Error("only https panels")
	}
}

// A game package from a file: the manifest goes as the request's body.
func TestCLIGamePackages(t *testing.T) {
	var got []string
	var body string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = append(got, r.Method+" "+r.URL.Path)
		switch r.Method + " " + r.URL.Path {
		case "POST /api/v1/game-packages":
			b := make([]byte, 4096)
			n, _ := r.Body.Read(b)
			body = string(b[:n])
			w.WriteHeader(201)
			_, _ = w.Write([]byte(`{"data":{"id":"minecraft-folia"}}`))
		case "POST /api/v1/game-packages/minecraft-folia/install":
			w.WriteHeader(201)
			_, _ = w.Write([]byte(`{"data":{"id":"minecraft-folia"}}`))
		case "DELETE /api/v1/game-packages/minecraft-folia":
			w.WriteHeader(409)
			_, _ = w.Write([]byte(`{"error":"game servers [9] run Folia pro Minecraft: delete them first"}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()
	t.Setenv("WSPANEL_URL", srv.URL)
	t.Setenv("WSPANEL_TOKEN", "wspa_test")
	t.Setenv("HOME", t.TempDir())
	t.Setenv("XDG_CONFIG_HOME", t.TempDir())
	devnull, _ := os.Open(os.DevNull)
	stdout := os.Stdout
	os.Stdout = devnull
	defer func() { os.Stdout = stdout }()

	manifest := t.TempDir() + "/folia.json"
	_ = os.WriteFile(manifest, []byte(`{"id":"minecraft-folia","extends":"minecraft"}`), 0o600)
	if err := run([]string{"game-package", "upload", manifest}, false); err != nil {
		t.Fatal(err)
	}
	if body != `{"id":"minecraft-folia","extends":"minecraft"}` {
		t.Errorf("the manifest is the body: %q", body)
	}
	notJSON := t.TempDir() + "/x.json"
	_ = os.WriteFile(notJSON, []byte("not json"), 0o600)
	if err := run([]string{"game-package", "upload", notJSON}, false); err == nil {
		t.Error("a file that is not JSON is not sent")
	}
	if err := run([]string{"game-package", "install", "minecraft-folia"}, false); err != nil {
		t.Fatal(err)
	}
	if err := run([]string{"game-package", "remove", "minecraft-folia"}, false); err == nil || !strings.Contains(err.Error(), "delete them first (HTTP 409)") {
		t.Errorf("the refusal is shown: %v", err)
	}
	if strings.Join(got, ",") != "POST /api/v1/game-packages,POST /api/v1/game-packages/minecraft-folia/install,DELETE /api/v1/game-packages/minecraft-folia" {
		t.Errorf("requests: %v", got)
	}
}
