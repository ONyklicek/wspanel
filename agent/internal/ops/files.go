package ops

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/fileop"
	"wspanel/agent/internal/protocol"
)

// The file manager. The work is done by the agent binary itself started as
// the site user (see package fileop): root never opens a site's file here.
// Uploads and downloads go through the artifact directory, as CI artifacts do,
// because a request on the socket is capped at 1 MB.

var fileActions = []string{"list", "read", "write", "mkdir", "rename", "delete", "chmod", "upload", "download", "extract", "compress"}

var uploadNameRe = regexp.MustCompile(`^up-[0-9a-f]{32}$`)

const downloadPrefix = "dl-"

func siteFiles(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		fileop.Request
		Content *string `json:"content,omitempty"` // write: the new text (≤ the request cap)
		Upload  string  `json:"upload,omitempty"`  // upload: file the panel put into the artifact dir
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !slices.Contains(fileActions, p.Action) {
		return nil, protocol.Invalid("action: one of " + strings.Join(fileActions, ", "))
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if _, err := lookupOwner(env, l.User); err != nil {
		return nil, protocol.NotFound("system user " + l.User + " does not exist")
	}

	req := p.Request
	var payload io.Reader = bytes.NewReader(nil)
	switch p.Action {
	case "write":
		if p.Content == nil {
			return nil, protocol.Invalid("content: required")
		}
		req.Size = int64(len(*p.Content))
		payload = strings.NewReader(*p.Content)
	case "upload":
		f, size, err := openPanelFile(env, p.Upload, uploadNameRe)
		if err != nil {
			return nil, err
		}
		defer func() {
			f.Close()
			_ = os.Remove(filepath.Join(env.Cfg.ArtifactDir, p.Upload))
		}()
		req.Size, payload = size, f
	}

	line, _ := json.Marshal(req)
	stdin := io.MultiReader(bytes.NewReader(append(line, '\n')), payload)

	// The response line is parsed; a download's bytes go straight to a file the
	// panel may read (never through memory or the socket).
	var dl *os.File
	var dlName string
	sink := &responseSplitter{}
	if p.Action == "download" {
		owner, err := lookupOwner(env, env.Cfg.PanelUser)
		if err != nil {
			return nil, err
		}
		dlName = downloadPrefix + randomHex(16)
		dl, err = os.OpenFile(filepath.Join(env.Cfg.ArtifactDir, dlName), os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0o600)
		if err != nil {
			return nil, err
		}
		if owner.UID >= 0 {
			_ = dl.Chown(owner.UID, owner.GID)
		}
		defer dl.Close()
		sink.rest = dl
	}
	// A stuck worker must not hold the request for ever.
	limit := 2 * time.Minute
	if slices.Contains([]string{"upload", "download", "extract", "compress"}, p.Action) {
		limit = 30 * time.Minute
	}
	ctx, cancel := context.WithTimeout(ctx, limit)
	defer cancel()
	args := append(userCreds(l.User), env.Cfg.AgentBinary, "-fileop", l.Home)
	if _, err := env.Exec.RunIO(ctx, stdin, sink, "setpriv", args...); err != nil && len(sink.head) == 0 {
		cleanupDownload(env, dlName)
		return nil, fmt.Errorf("file manager failed: %w", err)
	}
	var resp fileop.Response
	if err := json.Unmarshal(bytes.TrimSpace(sink.head), &resp); err != nil {
		cleanupDownload(env, dlName)
		return nil, fmt.Errorf("file manager gave no answer")
	}
	if !resp.OK {
		cleanupDownload(env, dlName)
		switch resp.Code {
		case "not_found":
			return nil, protocol.NotFound(resp.Error)
		case "exists", "conflict":
			return nil, protocol.Conflict(resp.Error)
		case "forbidden":
			return nil, protocol.Forbidden(resp.Error)
		case "invalid", "too_large", "binary":
			return nil, protocol.Invalid(resp.Error)
		}
		return nil, errors.New(resp.Error)
	}
	out := map[string]any{"ok": true, "path": resp.Path, "size": resp.Size, "mtime": resp.Mtime, "count": resp.Count}
	if resp.Entries != nil {
		out["entries"] = resp.Entries
	}
	if resp.Content != nil {
		out["content"] = *resp.Content
	}
	if dlName != "" {
		out["download"] = dlName
	}
	return out, nil
}

func cleanupDownload(env *Env, name string) {
	if name != "" {
		_ = os.Remove(filepath.Join(env.Cfg.ArtifactDir, name))
	}
}

// responseSplitter keeps the first line (the JSON response) and forwards the
// rest (a download's bytes) to rest, if any.
type responseSplitter struct {
	head []byte
	done bool
	rest io.Writer
}

func (s *responseSplitter) Write(b []byte) (int, error) {
	n := len(b)
	if !s.done {
		if i := bytes.IndexByte(b, '\n'); i >= 0 {
			s.head = append(s.head, b[:i+1]...)
			s.done = true
			b = b[i+1:]
		} else {
			if len(s.head)+len(b) > 4<<20 {
				return 0, errors.New("response line too long")
			}
			s.head = append(s.head, b...)
			return n, nil
		}
	}
	if len(b) > 0 && s.rest != nil {
		if _, err := s.rest.Write(b); err != nil {
			return 0, err
		}
	}
	return n, nil
}

// openPanelFile opens a file the panel put into the artifact dir: a regular
// file (no symlink), with one link, owned by the panel user.
func openPanelFile(env *Env, name string, re *regexp.Regexp) (*os.File, int64, error) {
	if !re.MatchString(name) {
		return nil, 0, protocol.Invalid("upload: not a file name the panel makes")
	}
	f, err := os.OpenFile(filepath.Join(env.Cfg.ArtifactDir, name), os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return nil, 0, protocol.NotFound("upload: " + name)
	}
	fi, err := f.Stat()
	if err != nil || !fi.Mode().IsRegular() {
		f.Close()
		return nil, 0, protocol.Invalid("upload: not a regular file")
	}
	if owner, err := lookupOwner(env, env.Cfg.PanelUser); err == nil && !env.Cfg.DryRun {
		if st, ok := fi.Sys().(*syscall.Stat_t); !ok || int(st.Uid) != owner.UID || st.Nlink != 1 {
			f.Close()
			return nil, 0, protocol.Invalid("upload: must be a file written by the panel")
		}
	}
	if fi.Size() > fileop.MaxUpload {
		f.Close()
		return nil, 0, protocol.Invalid(fmt.Sprintf("upload: larger than %d MB", fileop.MaxUpload>>20))
	}
	return f, fi.Size(), nil
}

// CleanupTransfers removes downloads the panel did not fetch and uploads it
// did not hand over within an hour. Called every minute.
func CleanupTransfers(env *Env) {
	for _, pattern := range []string{downloadPrefix + "*", "up-*"} {
		files, _ := filepath.Glob(filepath.Join(env.Cfg.ArtifactDir, pattern))
		for _, f := range files {
			if fi, err := os.Lstat(f); err == nil && time.Since(fi.ModTime()) > time.Hour {
				_ = os.Remove(f)
			}
		}
	}
}

// RunFileOp is the -fileop mode of the agent binary (as the site user).
func RunFileOp(home string) error {
	return fileop.Serve(home, bufio.NewReader(os.Stdin), os.Stdout)
}
