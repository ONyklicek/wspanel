package fileop

import (
	"archive/tar"
	"archive/zip"
	"bufio"
	"bytes"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"
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

func TestAFifoNeverBlocks(t *testing.T) {
	home := site(t)
	fifo := filepath.Join(home, "trap.zip")
	if err := syscall.Mkfifo(fifo, 0o644); err != nil {
		t.Skip("no mkfifo here")
	}
	done := make(chan Response, 3)
	go func() {
		for _, req := range []Request{{Action: "extract", Path: "trap.zip", To: "x"}, {Action: "read", Path: "trap.zip"}, {Action: "download", Path: "trap.zip"}} {
			r, _ := run(t, home, req, nil)
			done <- r
		}
	}()
	for i := 0; i < 3; i++ {
		select {
		case r := <-done:
			if r.OK {
				t.Fatal("a FIFO was read")
			}
		case <-time.After(5 * time.Second):
			t.Fatal("blocked on a FIFO")
		}
	}
}

func TestTarUntarRoundTripKeepsLinksInsideTheHome(t *testing.T) {
	home := site(t)
	_ = os.Symlink(filepath.Join(home, "shared/.env"), filepath.Join(home, "current/.env"))
	r, body := run(t, home, Request{Action: "tar", Paths: []string{"current", "shared"}}, nil)
	if !r.OK || !r.Payload {
		t.Fatalf("%+v", r)
	}
	// Add links that point out of the home or climb out of the target.
	var buf bytes.Buffer
	tr := tar.NewReader(bytes.NewReader(body))
	tw := tar.NewWriter(&buf)
	for {
		hdr, err := tr.Next()
		if err != nil {
			break
		}
		_ = tw.WriteHeader(hdr)
		_, _ = io.Copy(tw, tr)
	}
	_ = tw.WriteHeader(&tar.Header{Name: "evil-abs", Typeflag: tar.TypeSymlink, Linkname: "/etc/passwd"})
	_ = tw.WriteHeader(&tar.Header{Name: "evil-rel", Typeflag: tar.TypeSymlink, Linkname: "../../../etc"})
	_ = tw.WriteHeader(&tar.Header{Name: "../../escaped.txt", Typeflag: tar.TypeReg, Size: 1, Mode: 0o644})
	_, _ = tw.Write([]byte("x"))
	_ = tw.Close()

	r, _ = run(t, home, Request{Action: "untar", To: "restore"}, buf.Bytes())
	if !r.OK {
		t.Fatalf("%+v", r)
	}
	if b, _ := os.ReadFile(filepath.Join(home, "restore/current/public/index.php")); string(b) != "<?php echo 1;" {
		t.Fatal(string(b))
	}
	if l, _ := os.Readlink(filepath.Join(home, "restore/current/.env")); l != filepath.Join(home, "shared/.env") {
		t.Fatal("link inside the home not restored:", l)
	}
	for _, n := range []string{"restore/evil-abs", "restore/evil-rel"} {
		if _, err := os.Lstat(filepath.Join(home, n)); !os.IsNotExist(err) {
			t.Errorf("%s restored", n)
		}
	}
	if _, err := os.Stat(filepath.Join(home, "restore/escaped.txt")); err != nil {
		t.Fatal("a climbing name must land inside the target")
	}
	// Never over an existing folder.
	if r, _ := run(t, home, Request{Action: "untar", To: "restore"}, buf.Bytes()); r.OK || r.Code != "exists" {
		t.Fatalf("%+v", r)
	}
}
