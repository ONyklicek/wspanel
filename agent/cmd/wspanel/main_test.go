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
