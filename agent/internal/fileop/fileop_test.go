package fileop

import (
	"archive/zip"
	"bufio"
	"bytes"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func run(t *testing.T, home string, req Request, payload []byte) (Response, []byte) {
	t.Helper()
	if payload != nil {
		req.Size = int64(len(payload))
	}
	line, _ := json.Marshal(req)
	in := bytes.NewBuffer(append(line, '\n'))
	in.Write(payload)
	var out bytes.Buffer
	if err := Serve(home, in, &out); err != nil {
		t.Fatal(err)
	}
	br := bufio.NewReader(&out)
	first, _ := br.ReadBytes('\n')
	var resp Response
	if err := json.Unmarshal(first, &resp); err != nil {
		t.Fatalf("response %q: %v", first, err)
	}
	rest, _ := io.ReadAll(br)
	return resp, rest
}

func site(t *testing.T) string {
	home := t.TempDir()
	for p, body := range map[string]string{"current/public/index.php": "<?php echo 1;", "shared/.env": "APP_KEY=x\n"} {
		_ = os.MkdirAll(filepath.Join(home, filepath.Dir(p)), 0o755)
		_ = os.WriteFile(filepath.Join(home, p), []byte(body), 0o640)
	}
	return home
}

func TestListReadWrite(t *testing.T) {
	home := site(t)
	r, _ := run(t, home, Request{Action: "list", Path: "/"}, nil)
	if !r.OK || len(r.Entries) != 2 || r.Entries[0].Name != "current" || r.Entries[0].Type != "dir" {
		t.Fatalf("%+v", r)
	}
	r, _ = run(t, home, Request{Action: "read", Path: "/shared/.env"}, nil)
	if !r.OK || *r.Content != "APP_KEY=x\n" {
		t.Fatalf("%+v", r)
	}
	mtime := r.Mtime
	r, _ = run(t, home, Request{Action: "write", Path: "shared/.env", IfMtime: mtime}, []byte("APP_KEY=y\n"))
	if !r.OK {
		t.Fatalf("%+v", r)
	}
	if b, _ := os.ReadFile(filepath.Join(home, "shared/.env")); string(b) != "APP_KEY=y\n" {
		t.Fatal(string(b))
	}
	if fi, _ := os.Stat(filepath.Join(home, "shared/.env")); fi.Mode().Perm() != 0o640 {
		t.Fatalf("mode not kept: %v", fi.Mode())
	}
	// An edit based on an old copy is refused.
	r, _ = run(t, home, Request{Action: "write", Path: "shared/.env", IfMtime: mtime - 100}, []byte("lost"))
	if r.OK || r.Code != "conflict" {
		t.Fatalf("%+v", r)
	}
	// Binary files are not opened in the editor.
	_ = os.WriteFile(filepath.Join(home, "logo.png"), []byte{0x89, 'P', 'N', 'G', 0, 1}, 0o644)
	if r, _ = run(t, home, Request{Action: "read", Path: "logo.png"}, nil); r.Code != "binary" {
		t.Fatalf("%+v", r)
	}
}

func TestNothingOutsideTheHome(t *testing.T) {
	home := site(t)
	outside := filepath.Join(t.TempDir(), "secret")
	_ = os.WriteFile(outside, []byte("root:x"), 0o644)
	_ = os.Symlink(outside, filepath.Join(home, "escape"))
	_ = os.Symlink(filepath.Dir(outside), filepath.Join(home, "escapedir"))

	for _, req := range []Request{
		{Action: "read", Path: "escape"},
		{Action: "read", Path: "escapedir/secret"},
		{Action: "download", Path: "escape"},
		{Action: "list", Path: "escapedir"},
		{Action: "write", Path: "escapedir/new"},
		{Action: "rename", Path: "current", To: "escapedir/x"},
	} {
		r, _ := run(t, home, req, []byte{})
		if r.OK {
			t.Errorf("%+v went outside the home", req)
		}
	}
	// ".." is folded into the home itself.
	if Clean("../../etc/passwd") != "etc/passwd" || Clean("/") != "." || Clean(`..\..\x`) != "x" {
		t.Fatal("clean")
	}
	if r, _ := run(t, home, Request{Action: "delete", Path: "/"}, nil); r.OK {
		t.Fatal("the home was deleted")
	}
	if b, _ := os.ReadFile(outside); string(b) != "root:x" {
		t.Fatal("outside file touched")
	}
}

func TestUploadDownloadMkdirRenameChmodDelete(t *testing.T) {
	home := site(t)
	if r, _ := run(t, home, Request{Action: "mkdir", Path: "current/public/img"}, nil); !r.OK {
		t.Fatalf("%+v", r)
	}
	if r, _ := run(t, home, Request{Action: "upload", Path: "current/public/img/a.bin"}, []byte{0, 1, 2}); !r.OK || r.Size != 3 {
		t.Fatalf("%+v", r)
	}
	if r, _ := run(t, home, Request{Action: "upload", Path: "current/public/img/a.bin"}, []byte{9}); r.Code != "exists" {
		t.Fatalf("an upload must not overwrite silently: %+v", r)
	}
	r, body := run(t, home, Request{Action: "download", Path: "current/public/img/a.bin"}, nil)
	if !r.OK || !r.Payload || !bytes.Equal(body, []byte{0, 1, 2}) {
		t.Fatalf("%+v %v", r, body)
	}
	if r, _ := run(t, home, Request{Action: "rename", Path: "current/public/img/a.bin", To: "current/public/img/b.bin"}, nil); !r.OK {
		t.Fatalf("%+v", r)
	}
	if r, _ := run(t, home, Request{Action: "chmod", Path: "current/public/img/b.bin", Mode: "600"}, nil); !r.OK {
		t.Fatalf("%+v", r)
	}
	for _, m := range []string{"4755", "999", "rwx"} {
		if r, _ := run(t, home, Request{Action: "chmod", Path: "current/public/img/b.bin", Mode: m}, nil); r.OK {
			t.Errorf("mode %s accepted", m)
		}
	}
	if fi, _ := os.Stat(filepath.Join(home, "current/public/img/b.bin")); fi.Mode().Perm() != 0o600 {
		t.Fatal(fi.Mode())
	}
	if r, _ := run(t, home, Request{Action: "delete", Path: "current/public/img"}, nil); !r.OK {
		t.Fatalf("%+v", r)
	}
	if _, err := os.Stat(filepath.Join(home, "current/public/img")); !os.IsNotExist(err) {
		t.Fatal("not deleted")
	}
}

func TestZipRoundTripAndZipSlip(t *testing.T) {
	home := site(t)
	if r, _ := run(t, home, Request{Action: "compress", Paths: []string{"current"}, To: "backup.zip"}, nil); !r.OK || r.Count != 1 {
		t.Fatalf("%+v", r)
	}
	if r, _ := run(t, home, Request{Action: "extract", Path: "backup.zip", To: "restored"}, nil); !r.OK || r.Count != 1 {
		t.Fatalf("%+v", r)
	}
	if b, _ := os.ReadFile(filepath.Join(home, "restored/current/public/index.php")); string(b) != "<?php echo 1;" {
		t.Fatal(string(b))
	}

	// An archive with "../" names and a symlink: everything stays in the target.
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	w, _ := zw.Create("../../evil.php")
	_, _ = w.Write([]byte("evil"))
	hdr := &zip.FileHeader{Name: "link"}
	hdr.SetMode(os.ModeSymlink | 0o777)
	w, _ = zw.CreateHeader(hdr)
	_, _ = w.Write([]byte("/etc/passwd"))
	_ = zw.Close()
	_ = os.WriteFile(filepath.Join(home, "bad.zip"), buf.Bytes(), 0o640)
	r, _ := run(t, home, Request{Action: "extract", Path: "bad.zip", To: "x"}, nil)
	if !r.OK {
		t.Fatalf("%+v", r)
	}
	if _, err := os.Stat(filepath.Join(home, "x/evil.php")); err != nil {
		t.Fatal("the entry should land inside the target")
	}
	if _, err := os.Lstat(filepath.Join(home, "x/link")); !os.IsNotExist(err) {
		t.Fatal("a symlink was unpacked")
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(home), "evil.php")); !os.IsNotExist(err) {
		t.Fatal("zip slip")
	}
	if strings.Contains(r.Error, "evil") {
		t.Fatal(r.Error)
	}
}
