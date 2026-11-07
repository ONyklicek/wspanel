// Package fileop is the file manager's worker. It runs as the site user
// (the agent starts itself with setpriv and -fileop <site home>), and every
// path goes through an os.Root opened on the site's home, so neither "..",
// absolute paths nor symlinks can reach outside it. On top of that the kernel
// only lets it touch what the site user may.
//
// Protocol: one JSON request line on stdin, followed by the payload of a
// write/upload; one JSON response line on stdout, followed by the payload of
// a download.
package fileop

import (
	"archive/tar"
	"archive/zip"
	"bufio"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unicode/utf8"
)

const (
	MaxList       = 5000
	MaxEdit       = 2 << 20 // text read into the editor
	MaxUpload     = 1 << 30 // one uploaded file
	MaxExtract    = 4 << 30 // everything an archive expands to
	MaxExtractNum = 100_000 // entries in one archive
	MaxCompress   = 2 << 30 // everything put into one archive
)

// MaxRestore caps what a site backup may hold (counted by apparent size, so
// a sparse file cannot be blown up to fill the backup disk) and what a backup
// being restored may expand to.
const MaxRestore = 50 << 30

type Request struct {
	Action  string   `json:"action"`
	Path    string   `json:"path"`
	To      string   `json:"to,omitempty"`
	Paths   []string `json:"paths,omitempty"` // compress
	Mode    string   `json:"mode,omitempty"`  // chmod, octal
	IfMtime int64    `json:"if_mtime,omitempty"`
	Size    int64    `json:"size,omitempty"` // write/upload: payload length
	Replace bool     `json:"replace,omitempty"`
}

type Entry struct {
	Name   string `json:"name"`
	Type   string `json:"type"` // dir | file | link | other
	Size   int64  `json:"size"`
	Mtime  int64  `json:"mtime"`
	Mode   string `json:"mode"`
	Target string `json:"target,omitempty"`
}

type Response struct {
	OK      bool    `json:"ok"`
	Error   string  `json:"error,omitempty"`
	Code    string  `json:"code,omitempty"` // not_found | exists | invalid | too_large | conflict | binary
	Path    string  `json:"path,omitempty"`
	Entries []Entry `json:"entries,omitempty"`
	Content *string `json:"content,omitempty"`
	Size    int64   `json:"size,omitempty"`
	Mtime   int64   `json:"mtime,omitempty"`
	Count   int     `json:"count,omitempty"`
	Payload bool    `json:"payload,omitempty"` // a download follows
}

type opError struct{ code, msg string }

func (e opError) Error() string { return e.msg }

func fail(code, format string, a ...any) error { return opError{code, fmt.Sprintf(format, a...)} }

// Clean turns what the panel sends into a path inside the root: "" and "/"
// are the root itself ("."). os.Root refuses anything that would leave it.
func Clean(p string) string {
	p = path.Clean("/" + strings.ReplaceAll(p, "\\", "/"))
	if p == "/" {
		return "."
	}
	return strings.TrimPrefix(p, "/")
}

// Serve handles one request.
func Serve(home string, in io.Reader, out io.Writer) error {
	br := bufio.NewReader(in)
	line, err := br.ReadBytes('\n')
	if err != nil && !errors.Is(err, io.EOF) {
		return err
	}
	var req Request
	resp := Response{}
	if err := json.Unmarshal(line, &req); err != nil {
		resp.Error, resp.Code = "bad request", "invalid"
		return writeResponse(out, resp)
	}
	root, err := os.OpenRoot(home)
	if err != nil {
		return err
	}
	defer root.Close()
	payload, err := handle(root, home, req, br, &resp)
	if err != nil {
		var oe opError
		switch {
		case errors.As(err, &oe):
			resp.Error, resp.Code = oe.msg, oe.code
		case errors.Is(err, fs.ErrNotExist):
			resp.Error, resp.Code = "not found: "+req.Path, "not_found"
		case errors.Is(err, fs.ErrExist):
			resp.Error, resp.Code = "already exists", "exists"
		case errors.Is(err, fs.ErrPermission):
			resp.Error, resp.Code = "permission denied", "forbidden"
		default:
			resp.Error, resp.Code = err.Error(), "failed"
		}
		resp.OK = false
		return writeResponse(out, resp)
	}
	resp.OK = true
	if payload != nil {
		defer payload.Close()
		resp.Payload = true
		if err := writeResponse(out, resp); err != nil {
			return err
		}
		_, err := io.Copy(out, payload)
		return err
	}
	return writeResponse(out, resp)
}

func writeResponse(out io.Writer, r Response) error {
	b, _ := json.Marshal(r)
	_, err := out.Write(append(b, '\n'))
	return err
}

// openRegular opens a file for reading only if it is a regular file: never a
// link (O_NOFOLLOW), and never a FIFO or device, which could block the worker
// for ever (O_NONBLOCK, then fstat on the opened handle — no check-then-open race).
func openRegular(root *os.Root, name string) (*os.File, fs.FileInfo, error) {
	f, err := root.OpenFile(name, os.O_RDONLY|syscall.O_NONBLOCK|syscall.O_NOFOLLOW, 0)
	if err != nil {
		if errors.Is(err, syscall.ELOOP) {
			return nil, nil, fail("invalid", "%s is a link; open what it points to", name)
		}
		return nil, nil, err
	}
	fi, err := f.Stat()
	if err != nil {
		f.Close()
		return nil, nil, err
	}
	if !fi.Mode().IsRegular() {
		f.Close()
		return nil, nil, fail("invalid", "%s is not a regular file", name)
	}
	return f, fi, nil
}

func modeString(m fs.FileMode) string { return fmt.Sprintf("%04o", m.Perm()) }

func handle(root *os.Root, home string, req Request, in io.Reader, resp *Response) (io.ReadCloser, error) {
	p := Clean(req.Path)
	resp.Path = p
	switch req.Action {
	case "list":
		return nil, list(root, p, resp)
	case "read":
		return nil, read(root, p, resp)
	case "write", "upload":
		if p == "." {
			return nil, fail("invalid", "a file name is required")
		}
		return nil, write(root, p, req, in, resp)
	case "mkdir":
		if p == "." {
			return nil, fail("invalid", "a folder name is required")
		}
		return nil, root.Mkdir(p, 0o750)
	case "rename":
		to := Clean(req.To)
		if p == "." || to == "." {
			return nil, fail("invalid", "the site's home cannot be renamed")
		}
		if _, err := root.Lstat(to); err == nil {
			return nil, fs.ErrExist
		}
		resp.Path = to
		return nil, root.Rename(p, to)
	case "delete":
		if p == "." {
			return nil, fail("invalid", "the site's home cannot be deleted")
		}
		if _, err := root.Lstat(p); err != nil {
			return nil, err
		}
		return nil, root.RemoveAll(p)
	case "chmod":
		n, err := strconv.ParseUint(req.Mode, 8, 32)
		if err != nil || n > 0o777 {
			return nil, fail("invalid", "mode: three octal digits such as 644 or 755")
		}
		return nil, root.Chmod(p, fs.FileMode(n))
	case "download":
		f, fi, err := openRegular(root, p)
		if err != nil {
			return nil, err
		}
		resp.Size = fi.Size()
		return f, nil
	case "extract":
		return nil, extract(root, p, Clean(req.To), resp)
	case "compress":
		return nil, compress(root, req.Paths, Clean(req.To), resp)
	case "tar":
		return tarStream(root, req.Paths)
	case "untar":
		return nil, untar(root, home, Clean(req.To), in, resp) // size unknown up front: capped inside
	}
	return nil, fail("invalid", "unknown action %q", req.Action)
}

func list(root *os.Root, p string, resp *Response) error {
	f, err := root.Open(p)
	if err != nil {
		return err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return err
	}
	if !fi.IsDir() {
		return fail("invalid", "%s is not a folder", p)
	}
	names, err := f.Readdirnames(MaxList + 1)
	if err != nil && !errors.Is(err, io.EOF) {
		return err
	}
	if len(names) > MaxList {
		return fail("too_large", "more than %d entries; use SFTP for this folder", MaxList)
	}
	entries := make([]Entry, 0, len(names))
	for _, n := range names {
		child := path.Join(p, n)
		li, err := root.Lstat(child)
		if err != nil {
			continue
		}
		e := Entry{Name: n, Size: li.Size(), Mtime: li.ModTime().Unix(), Mode: modeString(li.Mode())}
		switch {
		case li.IsDir():
			e.Type, e.Size = "dir", 0
		case li.Mode()&fs.ModeSymlink != 0:
			e.Type = "link"
			e.Target, _ = root.Readlink(child)
		case li.Mode().IsRegular():
			e.Type = "file"
		default:
			e.Type = "other"
		}
		entries = append(entries, e)
	}
	slices.SortFunc(entries, func(a, b Entry) int {
		if (a.Type == "dir") != (b.Type == "dir") {
			if a.Type == "dir" {
				return -1
			}
			return 1
		}
		return strings.Compare(strings.ToLower(a.Name), strings.ToLower(b.Name))
	})
	resp.Entries = entries
	resp.Count = len(entries)
	return nil
}

func read(root *os.Root, p string, resp *Response) error {
	f, fi, err := openRegular(root, p)
	if err != nil {
		return err
	}
	defer f.Close()
	if fi.Size() > MaxEdit {
		return fail("too_large", "larger than %d MB; download it instead", MaxEdit>>20)
	}
	b, err := io.ReadAll(io.LimitReader(f, MaxEdit+1))
	if err != nil {
		return err
	}
	if !utf8.Valid(b) || slices.Contains(b, 0) {
		return fail("binary", "not a text file; download it instead")
	}
	s := string(b)
	resp.Content, resp.Size, resp.Mtime = &s, fi.Size(), fi.ModTime().Unix()
	return nil
}

// write replaces a file atomically (temp file + rename in the same folder),
// keeping its mode. IfMtime guards against overwriting someone else's edit.
func write(root *os.Root, p string, req Request, in io.Reader, resp *Response) error {
	if req.Size < 0 || req.Size > MaxUpload {
		return fail("too_large", "larger than %d MB; use SFTP", MaxUpload>>20)
	}
	mode := fs.FileMode(0o640)
	if fi, err := root.Lstat(p); err == nil {
		if !fi.Mode().IsRegular() {
			return fail("invalid", "%s is not a regular file", p)
		}
		if req.Action == "upload" && !req.Replace {
			return fs.ErrExist
		}
		if req.IfMtime != 0 && fi.ModTime().Unix() != req.IfMtime {
			return fail("conflict", "the file changed since it was opened")
		}
		mode = fi.Mode().Perm()
	} else if !errors.Is(err, fs.ErrNotExist) {
		return err
	}
	tmp := path.Join(path.Dir(p), fmt.Sprintf(".%s.wspanel-%d", path.Base(p), time.Now().UnixNano()))
	f, err := root.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return err
	}
	n, err := io.Copy(f, io.LimitReader(in, req.Size))
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err == nil && n != req.Size {
		err = fail("invalid", "incomplete upload")
	}
	if err == nil {
		err = root.Chmod(tmp, mode)
	}
	if err == nil {
		err = root.Rename(tmp, p)
	}
	if err != nil {
		_ = root.Remove(tmp)
		return err
	}
	fi, _ := root.Stat(p)
	if fi != nil {
		resp.Size, resp.Mtime = fi.Size(), fi.ModTime().Unix()
	}
	return nil
}

// extract unpacks a .zip into a folder (created if missing). Only files and
// folders are written: links in the archive are skipped, every name goes
// through the root, and size and count are capped.
func extract(root *os.Root, p, to string, resp *Response) error {
	if !strings.HasSuffix(strings.ToLower(p), ".zip") {
		return fail("invalid", "only .zip archives can be extracted here")
	}
	f, fi, err := openRegular(root, p)
	if err != nil {
		return err
	}
	defer f.Close()
	zr, err := zip.NewReader(f, fi.Size())
	if err != nil {
		return fail("invalid", "not a zip archive")
	}
	if len(zr.File) > MaxExtractNum {
		return fail("too_large", "more than %d files in the archive", MaxExtractNum)
	}
	if err := root.MkdirAll(to, 0o750); err != nil {
		return err
	}
	var total int64
	for _, zf := range zr.File {
		// Clean drops any "../" climbing above the archive's own root, so the
		// entry always lands inside the target folder (zip slip).
		rel := Clean(zf.Name)
		if rel == "." {
			continue
		}
		name := path.Join(to, rel)
		m := zf.Mode()
		switch {
		case m.IsDir():
			if err := root.MkdirAll(name, 0o750); err != nil {
				return err
			}
			continue
		case !m.IsRegular():
			continue // symlinks and devices are not unpacked
		}
		if err := root.MkdirAll(path.Dir(name), 0o750); err != nil {
			return err
		}
		rc, err := zf.Open()
		if err != nil {
			return err
		}
		out, err := root.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o640)
		if err != nil {
			rc.Close()
			return err
		}
		n, err := io.Copy(out, io.LimitReader(rc, MaxExtract-total+1))
		rc.Close()
		out.Close()
		if err != nil {
			return err
		}
		total += n
		if total > MaxExtract {
			return fail("too_large", "the archive expands to more than %d GB", MaxExtract>>30)
		}
		resp.Count++
	}
	resp.Path, resp.Size = to, total
	return nil
}

// compress puts files and folders into a new .zip (links are left out).
func compress(root *os.Root, paths []string, to string, resp *Response) error {
	if !strings.HasSuffix(strings.ToLower(to), ".zip") || to == "." {
		return fail("invalid", "the archive name must end with .zip")
	}
	if len(paths) == 0 || len(paths) > 1000 {
		return fail("invalid", "choose 1-1000 items")
	}
	if _, err := root.Lstat(to); err == nil {
		return fs.ErrExist
	}
	out, err := root.OpenFile(to, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o640)
	if err != nil {
		return err
	}
	zw := zip.NewWriter(out)
	var total int64
	add := func(name string, fi fs.FileInfo) error {
		if name == to {
			return nil
		}
		hdr, err := zip.FileInfoHeader(fi)
		if err != nil {
			return err
		}
		hdr.Name = name
		if fi.IsDir() {
			hdr.Name += "/"
			_, err := zw.CreateHeader(hdr)
			return err
		}
		hdr.Method = zip.Deflate
		w, err := zw.CreateHeader(hdr)
		if err != nil {
			return err
		}
		f, _, err := openRegular(root, name)
		if err != nil {
			return err
		}
		defer f.Close()
		n, err := io.Copy(w, f)
		total += n
		if total > MaxCompress {
			return fail("too_large", "more than %d GB to compress; use SFTP", MaxCompress>>30)
		}
		resp.Count++
		return err
	}
	var walkErr error
	for _, raw := range paths {
		p := Clean(raw)
		walkErr = fs.WalkDir(root.FS(), p, func(name string, d fs.DirEntry, err error) error {
			if err != nil {
				return err
			}
			if d.Type()&fs.ModeSymlink != 0 || !(d.IsDir() || d.Type().IsRegular()) {
				return nil
			}
			fi, err := d.Info()
			if err != nil {
				return err
			}
			return add(name, fi)
		})
		if walkErr != nil {
			break
		}
	}
	cerr := zw.Close()
	out.Close()
	if walkErr == nil {
		walkErr = cerr
	}
	if walkErr != nil {
		_ = root.Remove(to)
		return walkErr
	}
	fi, _ := root.Stat(to)
	if fi != nil {
		resp.Size = fi.Size()
	}
	resp.Path = to
	return nil
}

// tarStream writes a .tar.gz of the given paths (files, folders and the
// links themselves, never what they point to) to the returned reader.
func tarStream(root *os.Root, paths []string) (io.ReadCloser, error) {
	if len(paths) == 0 || len(paths) > 100 {
		return nil, fail("invalid", "choose 1-100 paths")
	}
	for _, raw := range paths {
		if _, err := root.Lstat(Clean(raw)); err != nil {
			return nil, err
		}
	}
	pr, pw := io.Pipe()
	go func() {
		tw := tar.NewWriter(pw)
		var total int64 // apparent sizes: a sparse file counts in full
		add := func(name string, fi fs.FileInfo) error {
			if fi.Mode().IsRegular() {
				if total += fi.Size(); total > MaxRestore {
					return fail("too_large", "the site holds more than %d GB, too much for a backup", MaxRestore>>30)
				}
			}
			return tarEntry(root, tw, name, fi)
		}
		err := func() error {
			for _, raw := range paths {
				p := Clean(raw)
				li, err := root.Lstat(p)
				if err != nil {
					return err
				}
				if li.Mode()&fs.ModeSymlink != 0 {
					if err := add(p, li); err != nil {
						return err
					}
					continue
				}
				if err := fs.WalkDir(root.FS(), p, func(name string, d fs.DirEntry, err error) error {
					if err != nil {
						return err
					}
					info, err := root.Lstat(name)
					if err != nil {
						return err
					}
					return add(name, info)
				}); err != nil {
					return err
				}
			}
			return tw.Close()
		}()
		pw.CloseWithError(err)
	}()
	return pr, nil
}

func tarEntry(root *os.Root, tw *tar.Writer, name string, fi fs.FileInfo) error {
	var link string
	switch {
	case fi.Mode()&fs.ModeSymlink != 0:
		l, err := root.Readlink(name)
		if err != nil {
			return err
		}
		link = l
	case fi.IsDir(), fi.Mode().IsRegular():
	default:
		return nil // sockets, fifos, devices
	}
	hdr, err := tar.FileInfoHeader(fi, link)
	if err != nil {
		return err
	}
	hdr.Name = name
	if fi.IsDir() {
		hdr.Name += "/"
	}
	hdr.Uname, hdr.Gname, hdr.Uid, hdr.Gid = "", "", 0, 0
	if err := tw.WriteHeader(hdr); err != nil {
		return err
	}
	if !fi.Mode().IsRegular() {
		return nil
	}
	f, _, err := openRegular(root, name)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = io.Copy(tw, io.LimitReader(f, fi.Size())) // exactly the size the header announced
	return err
}

// untar unpacks a (plain) tar into a folder of the home. Files, folders and links
// are written; a link may only point inside the home (relative, or absolute
// under it), names cannot climb out of the target, and the size is capped.
func untar(root *os.Root, home, to string, in io.Reader, resp *Response) error {
	if to == "." {
		return fail("invalid", "unpack into a folder, not the home itself")
	}
	if _, err := root.Lstat(to); err == nil {
		return fs.ErrExist
	}
	if err := root.MkdirAll(to, 0o750); err != nil {
		return err
	}
	tr := tar.NewReader(in)
	var total int64
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fail("invalid", "broken archive: %v", err)
		}
		rel := Clean(hdr.Name)
		if rel == "." {
			continue
		}
		name := path.Join(to, rel)
		if err := root.MkdirAll(path.Dir(name), 0o750); err != nil {
			return err
		}
		mode := fs.FileMode(hdr.Mode).Perm()
		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := root.MkdirAll(name, 0o750); err != nil {
				return err
			}
			_ = root.Chmod(name, mode|0o700)
		case tar.TypeReg:
			out, err := root.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_TRUNC|syscall.O_NOFOLLOW, mode|0o600)
			if err != nil {
				return err
			}
			n, err := io.Copy(out, io.LimitReader(tr, MaxRestore-total+1))
			out.Close()
			if err != nil {
				return err
			}
			total += n
			if total > MaxRestore {
				return fail("too_large", "the backup expands to more than %d GB", MaxRestore>>30)
			}
		case tar.TypeSymlink:
			target := hdr.Linkname
			if path.IsAbs(target) {
				if !strings.HasPrefix(path.Clean(target)+"/", strings.TrimSuffix(home, "/")+"/") {
					continue // points outside the home: not restored
				}
			} else if strings.HasPrefix(path.Clean(path.Join(path.Dir(rel), target)), "..") {
				continue
			}
			_ = root.Remove(name)
			if err := root.Symlink(target, name); err != nil {
				return err
			}
		default:
			continue
		}
		resp.Count++
	}
	resp.Path, resp.Size = to, total
	return nil
}
