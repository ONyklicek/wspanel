package ops

import (
	"errors"
	"reflect"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestSMBBrowseListsSharesThenFoldersAndTriesWriting(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		args := strings.Join(c.Args, " ")
		switch {
		case strings.HasPrefix(args, "-L //nas.firma.lan"):
			return executor.Result{Stdout: "Disk|Data|Shared data\nDisk|ADMIN$|Remote Admin\nIPC|IPC$|IPC Service\nDisk|backup|\n"}, nil
		case strings.Contains(args, `ls "web\*"`):
			return executor.Result{Stdout: "  .                                   D        0  Mon Nov 23 10:00:00 2026\n" +
				"  uploads                             D        0  Mon Nov 23 10:00:00 2026\n" +
				"  readme.txt                          A       12  Mon Nov 23 10:00:00 2026\n" +
				"  Assets                              D        0  Mon Nov 23 10:00:00 2026\n"}, nil
		case strings.Contains(args, `mkdir "web\.wspanel-write-test`):
			return executor.Result{Stdout: "NT_STATUS_ACCESS_DENIED making remote directory"}, nil
		}
		return executor.Result{}, nil
	}
	for _, bad := range []map[string]any{{"host": "nas;x", "user": "u"}, {"host": "nas", "user": ""}, {"host": "nas", "user": "u", "share": "C$"},
		{"host": "nas", "user": "u", "share": "Data", "path": "web;rm"}} {
		if _, err := call(t, env, smbBrowse, bad); code(err) != "invalid_params" {
			t.Errorf("%v must be refused: %v", bad, err)
		}
	}
	out, err := call(t, env, smbBrowse, map[string]any{"host": "nas.firma.lan", "user": "web", "password": "p\"w"})
	if err != nil {
		t.Fatal(err)
	}
	if got := out.(map[string]any)["shares"]; !reflect.DeepEqual(got, []smbShare{{Name: "backup"}, {Name: "Data", Comment: "Shared data"}}) {
		t.Errorf("shares: %v", got)
	}
	out, err = call(t, env, smbBrowse, map[string]any{"host": "nas.firma.lan", "user": "web", "password": "x", "share": "Data", "path": "/web/"})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	if !reflect.DeepEqual(m["dirs"], []string{"Assets", "uploads"}) || m["writable"] != false || m["path"] != "web" {
		t.Errorf("folders: %v", m)
	}
	for _, c := range dr.Calls() {
		if strings.Contains(strings.Join(c.Args, " "), "p\"w") || strings.Contains(strings.Join(c.Args, " "), "password") {
			t.Fatalf("the password on a command line: %v", c.Args)
		}
	}
}

func TestSMBBrowseSaysWhyTheLoginFailed(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		return executor.Result{Stderr: "session setup failed: NT_STATUS_LOGON_FAILURE"}, errors.New("exit status 1")
	}
	_, err := call(t, env, smbBrowse, map[string]any{"host": "nas", "user": "web", "password": "bad"})
	if err == nil || !strings.Contains(err.Error(), "NT_STATUS_LOGON_FAILURE") {
		t.Fatalf("err: %v", err)
	}
}
