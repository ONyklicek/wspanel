// Package fsx wraps the few filesystem operations the agent performs.
// In dry-run mode ownership changes are skipped (no root on a dev machine);
// paths themselves come from config, which points into a scratch tree.
package fsx

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

type FS struct {
	DryRun bool
}

// Owner identifies a uid/gid pair; -1 leaves the value unchanged.
type Owner struct{ UID, GID int }

var Root = Owner{0, 0}

func (f *FS) chown(path string, o Owner) error {
	if f.DryRun || (o.UID < 0 && o.GID < 0) {
		return nil
	}
	return os.Lchown(path, o.UID, o.GID)
}

// Mkdir creates a single directory (parent must exist) with exact mode and owner.
func (f *FS) Mkdir(path string, perm os.FileMode, o Owner) error {
	if err := os.Mkdir(path, perm); err != nil {
		return err
	}
	if err := os.Chmod(path, perm); err != nil { // defeat umask
		return err
	}
	return f.chown(path, o)
}

// MkdirAll creates path and missing parents; each created dir gets perm and owner.
func (f *FS) MkdirAll(path string, perm os.FileMode, o Owner) error {
	if fi, err := os.Lstat(path); err == nil {
		if !fi.IsDir() {
			return fmt.Errorf("%s exists and is not a directory", path)
		}
		return nil
	}
	if err := f.MkdirAll(filepath.Dir(path), perm, o); err != nil {
		return err
	}
	return f.Mkdir(path, perm, o)
}

// WriteFile writes data atomically (temp file + rename) with exact mode and owner.
func (f *FS) WriteFile(path string, data []byte, perm os.FileMode, o Owner) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return err
	}
	tmpName := tmp.Name()
	defer os.Remove(tmpName) // no-op after a successful rename
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if err := os.Chmod(tmpName, perm); err != nil {
		return err
	}
	if err := f.chown(tmpName, o); err != nil {
		return err
	}
	return os.Rename(tmpName, path)
}

func (f *FS) Symlink(target, link string, o Owner) error {
	if err := os.Symlink(target, link); err != nil {
		return err
	}
	return f.chown(link, o)
}

func Exists(path string) bool {
	_, err := os.Lstat(path)
	return err == nil
}

// Within reports whether path is strictly inside root (after cleaning).
func Within(root, path string) bool {
	rel, err := filepath.Rel(filepath.Clean(root), filepath.Clean(path))
	return err == nil && rel != "." && rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator)) && !filepath.IsAbs(rel)
}
