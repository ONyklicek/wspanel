package ops

import (
	"encoding/json"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestComposerAuthIsMergedAndSecretsStayHere(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "ca.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	existing := `{"github-oauth":{"github.com":"ghp_old"},"custom":{"x":"y"}}`
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "setpriv" && strings.Contains(c.String(), "/usr/bin/cat") {
			return executor.Result{Stdout: existing}, nil
		}
		return executor.Result{}, nil
	}
	for _, bad := range []map[string]any{
		{"type": "ssh", "host": "x.com", "secret": "s"}, {"type": "bearer", "host": "bad host", "secret": "s"},
		{"type": "http-basic", "host": "repo.packagist.com", "secret": "s"}, {"type": "bearer", "host": "x.com", "secret": "a\nb"},
	} {
		bad["domain"] = "ca.example.com"
		if _, err := call(t, env, composerAuthSet, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, composerAuthSet, map[string]any{"domain": "ca.example.com", "type": "http-basic", "host": "Repo.Packagist.com", "username": "token", "secret": "pw"}); err != nil {
		t.Fatal(err)
	}
	var written map[string]map[string]any
	if err := json.Unmarshal([]byte(lastEnvWrite(dr)), &written); err != nil {
		t.Fatalf("written: %v %q", err, lastEnvWrite(dr))
	}
	if written["github-oauth"]["github.com"] != "ghp_old" || written["http-basic"]["repo.packagist.com"].(map[string]any)["password"] != "pw" || written["custom"]["x"] != "y" {
		t.Errorf("merged: %v", written)
	}
	n := 0
	for _, c := range dr.Calls() {
		if strings.Contains(c.String(), "/usr/bin/tee") {
			n++
		}
	}
	if n != 2 {
		t.Errorf("written for deploys and for the shell's composer: %d", n)
	}
	out, err := call(t, env, composerAuthList, map[string]any{"domain": "ca.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(out)
	if strings.Contains(string(b), "ghp_old") || !strings.Contains(string(b), `"github.com"`) {
		t.Errorf("the list names hosts, never secrets: %s", b)
	}
}
