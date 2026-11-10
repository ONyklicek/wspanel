package audit

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// After logrotate renames the log away, the next entry starts a new file at
// the path and the rotated one keeps what was written before.
func TestWriteFollowsRotation(t *testing.T) {
	path := filepath.Join(t.TempDir(), "audit.log")
	l, err := Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer l.Close()
	if err := l.Write(Entry{Op: "before"}); err != nil {
		t.Fatal(err)
	}
	if err := os.Rename(path, path+".1"); err != nil {
		t.Fatal(err)
	}
	if err := l.Write(Entry{Op: "after"}); err != nil {
		t.Fatal(err)
	}
	old, _ := os.ReadFile(path + ".1")
	cur, _ := os.ReadFile(path)
	if !strings.Contains(string(old), `"before"`) || strings.Contains(string(old), `"after"`) {
		t.Fatalf("rotated file: %s", old)
	}
	if !strings.Contains(string(cur), `"after"`) || strings.Contains(string(cur), `"before"`) {
		t.Fatalf("new file: %s", cur)
	}
	if fi, _ := os.Stat(path); fi.Mode().Perm() != 0o600 {
		t.Fatalf("new file mode %v", fi.Mode().Perm())
	}
}
