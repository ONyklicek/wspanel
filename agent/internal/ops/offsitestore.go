package ops

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/ftp"
	"wspanel/agent/internal/s3"
)

// offsiteStore is one kind of off-site storage. Keys are slash-separated
// paths under the configured base folder; List lists the files directly in
// one folder (a missing folder is empty).
type offsiteStore interface {
	Put(ctx context.Context, key string, f *os.File, size int64) error
	PutBytes(ctx context.Context, key string, b []byte) error
	// Fetch writes the object into dst, refusing more than max bytes.
	Fetch(ctx context.Context, key string, dst io.Writer, max int64) (int64, error)
	Delete(ctx context.Context, key string) error
	List(ctx context.Context, dir string) ([]remoteObject, error)
	Close()
}

type remoteObject struct {
	Name     string
	Size     int64
	Modified time.Time
}

var errTooLarge = errors.New("the copy is larger than a backup can be")

// capWriter fails once more than max bytes were written.
type capWriter struct {
	w   io.Writer
	max int64
	n   int64
}

func (c *capWriter) Write(p []byte) (int, error) {
	if c.n+int64(len(p)) > c.max {
		return 0, errTooLarge
	}
	n, err := c.w.Write(p)
	c.n += int64(n)
	return n, err
}

func (c offsiteConfig) open(ctx context.Context, env *Env) (offsiteStore, error) {
	switch c.Type {
	case "ftp":
		return openFTP(ctx, c)
	case "smb":
		return openSMB(env, c)
	default:
		cl, err := s3.New(s3.Config{Endpoint: c.Endpoint, Region: c.Region, Bucket: c.Bucket, AccessKey: c.AccessKey, SecretKey: c.SecretKey, PathStyle: c.PathStyle})
		if err != nil {
			return nil, err
		}
		return s3Store{cl}, nil
	}
}

// ---------------------------------------------------------------- S3

type s3Store struct{ cl *s3.Client }

func (s s3Store) Put(ctx context.Context, key string, f *os.File, size int64) error {
	return s.cl.Put(ctx, key, f, size)
}

func (s s3Store) PutBytes(ctx context.Context, key string, b []byte) error {
	return s.cl.Put(ctx, key, bytes.NewReader(b), int64(len(b)))
}

func (s s3Store) Fetch(ctx context.Context, key string, dst io.Writer, max int64) (int64, error) {
	body, size, err := s.cl.Get(ctx, key)
	if err != nil {
		return 0, err
	}
	defer body.Close()
	if size > max {
		return 0, errTooLarge
	}
	return io.Copy(&capWriter{w: dst, max: max}, body)
}

func (s s3Store) Delete(ctx context.Context, key string) error { return s.cl.Delete(ctx, key) }

func (s s3Store) List(ctx context.Context, dir string) ([]remoteObject, error) {
	prefix := ""
	if dir != "" {
		prefix = dir + "/"
	}
	objs, err := s.cl.List(ctx, prefix)
	if err != nil {
		return nil, err
	}
	var out []remoteObject
	for _, o := range objs {
		if path.Dir(o.Key) == dir || (dir == "" && !strings.Contains(o.Key, "/")) {
			out = append(out, remoteObject{Name: path.Base(o.Key), Size: o.Size, Modified: o.LastModified})
		}
	}
	return out, nil
}

func (s s3Store) Close() {}

// ---------------------------------------------------------------- FTP

type ftpStore struct{ c *ftp.Conn }

func openFTP(ctx context.Context, c offsiteConfig) (offsiteStore, error) {
	conn, err := ftp.Dial(ctx, ftp.Config{Host: c.Host, Port: c.Port, Username: c.Username, Password: c.Password,
		TLS: ftp.TLSMode(c.FTPTLS), PinSHA256: c.TLSPin})
	if err != nil {
		return nil, err
	}
	return ftpStore{conn}, nil
}

func (s ftpStore) Put(ctx context.Context, key string, f *os.File, size int64) error {
	if err := s.c.MkdirAll(path.Dir(key)); err != nil {
		return err
	}
	return s.c.Store(key, io.NewSectionReader(f, 0, size))
}

func (s ftpStore) PutBytes(ctx context.Context, key string, b []byte) error {
	if err := s.c.MkdirAll(path.Dir(key)); err != nil {
		return err
	}
	return s.c.Store(key, bytes.NewReader(b))
}

func (s ftpStore) Fetch(ctx context.Context, key string, dst io.Writer, max int64) (int64, error) {
	return s.c.Retrieve(key, &capWriter{w: dst, max: max})
}

func (s ftpStore) Delete(ctx context.Context, key string) error { return s.c.Delete(key) }

func (s ftpStore) List(ctx context.Context, dir string) ([]remoteObject, error) {
	if dir == "" {
		dir = "."
	}
	entries, err := s.c.List(dir)
	if err != nil {
		return nil, err
	}
	out := make([]remoteObject, 0, len(entries))
	for _, e := range entries {
		out = append(out, remoteObject{Name: e.Name, Size: e.Size, Modified: e.Modified})
	}
	return out, nil
}

func (s ftpStore) Close() { _ = s.c.Close() }

// ---------------------------------------------------------------- SMB

// smbStore drives Samba's smbclient. The password is in a root-only
// credentials file for the time the store is open, never on a command line.
type smbStore struct {
	env  *Env
	c    offsiteConfig
	auth string
	tmp  string
}

// Everything that ends up inside an smbclient command is from this set
// (paths are made of validated domains, backup names and the prefix).
var smbSafeRe = regexp.MustCompile(`^[A-Za-z0-9._\-/ ]*$`)

func openSMB(env *Env, c offsiteConfig) (offsiteStore, error) {
	dir := filepath.Join(env.Cfg.StateDir, "offsite-tmp")
	if err := env.FS.MkdirAll(dir, 0o700, fsx.Root); err != nil {
		return nil, err
	}
	f, err := os.CreateTemp(dir, ".smb-auth-*")
	if err != nil {
		return nil, err
	}
	content := "username = " + c.Username + "\npassword = " + c.Password + "\n"
	if c.Domain != "" {
		content += "domain = " + c.Domain + "\n"
	}
	_, err = f.WriteString(content)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		_ = os.Remove(f.Name())
		return nil, err
	}
	return &smbStore{env: env, c: c, auth: f.Name(), tmp: dir}, nil
}

func (s *smbStore) Close() { _ = os.Remove(s.auth) }

var ntStatusRe = regexp.MustCompile(`NT_STATUS_[A-Z_]+`)

// run runs one smbclient command line.
func (s *smbStore) run(ctx context.Context, command string) (string, error) {
	port := s.c.Port
	if port == 0 {
		port = 445
	}
	args := []string{"//" + s.c.Host + "/" + s.c.Share, "-A", s.auth, "-p", strconv.Itoa(port),
		"--option=client min protocol=SMB2_02", "-m", "SMB3"}
	if s.c.SMBEncrypt {
		args = append(args, "--client-protection=encrypt")
	}
	args = append(args, "-c", command)
	res, err := s.env.Exec.RunIO(ctx, nil, nil, "smbclient", args...)
	out := res.Stdout + res.Stderr
	if err != nil {
		if m := ntStatusRe.FindString(out + err.Error()); m != "" {
			return out, errors.New(m)
		}
		return out, fmt.Errorf("smbclient failed: %s", lastLine(out))
	}
	// smbclient may report a failed command and still exit 0.
	if m := ntStatusRe.FindString(out); m != "" && m != "NT_STATUS_OK" {
		return out, errors.New(m)
	}
	return out, nil
}

func smbPath(key string) (string, error) {
	if !smbSafeRe.MatchString(key) {
		return "", errors.New("unexpected characters in a path")
	}
	return strings.ReplaceAll(key, "/", `\`), nil
}

func (s *smbStore) mkdirAll(ctx context.Context, dir string) error {
	dir = strings.Trim(path.Clean(dir), "/")
	if dir == "." || dir == "" {
		return nil
	}
	acc := ""
	for _, part := range strings.Split(dir, "/") {
		acc = path.Join(acc, part)
		p, err := smbPath(acc)
		if err != nil {
			return err
		}
		if _, err := s.run(ctx, `mkdir "`+p+`"`); err != nil && err.Error() != "NT_STATUS_OBJECT_NAME_COLLISION" {
			return err
		}
	}
	return nil
}

func (s *smbStore) Put(ctx context.Context, key string, f *os.File, size int64) error {
	remote, err := smbPath(key)
	if err != nil {
		return err
	}
	if !smbSafeRe.MatchString(f.Name()) {
		return errors.New("unexpected characters in a local path")
	}
	if err := s.mkdirAll(ctx, path.Dir(key)); err != nil {
		return err
	}
	_, err = s.run(ctx, `put "`+f.Name()+`" "`+remote+`"`)
	return err
}

func (s *smbStore) PutBytes(ctx context.Context, key string, b []byte) error {
	f, err := os.CreateTemp(s.tmp, ".put-*")
	if err != nil {
		return err
	}
	defer os.Remove(f.Name())
	defer f.Close()
	if _, err := f.Write(b); err != nil {
		return err
	}
	return s.Put(ctx, key, f, int64(len(b)))
}

func (s *smbStore) Fetch(ctx context.Context, key string, dst io.Writer, maxSize int64) (int64, error) {
	remote, err := smbPath(key)
	if err != nil {
		return 0, err
	}
	// Size first: smbclient would write all of it before we could stop it.
	objs, err := s.List(ctx, path.Dir(key))
	if err != nil {
		return 0, err
	}
	found := false
	for _, o := range objs {
		if o.Name == path.Base(key) {
			found = true
			if o.Size > maxSize {
				return 0, errTooLarge
			}
		}
	}
	if !found {
		return 0, errors.New("NT_STATUS_OBJECT_NAME_NOT_FOUND")
	}
	f, err := os.CreateTemp(s.tmp, ".get-*")
	if err != nil {
		return 0, err
	}
	name := f.Name()
	f.Close()
	defer os.Remove(name)
	if _, err := s.run(ctx, `get "`+remote+`" "`+name+`"`); err != nil {
		return 0, err
	}
	in, err := os.Open(name)
	if err != nil {
		return 0, err
	}
	defer in.Close()
	return io.Copy(&capWriter{w: dst, max: maxSize}, in)
}

func (s *smbStore) Delete(ctx context.Context, key string) error {
	remote, err := smbPath(key)
	if err != nil {
		return err
	}
	_, err = s.run(ctx, `del "`+remote+`"`)
	return err
}

// "  name.tar.gz      A   2184  Fri Nov  7 19:01:48 2026"
var smbLsRe = regexp.MustCompile(`^  (.+?)\s+([A-Z]*)\s+(\d+)\s+(\w{3} \w{3} [ \d]\d \d\d:\d\d:\d\d \d{4})$`)

func (s *smbStore) List(ctx context.Context, dir string) ([]remoteObject, error) {
	pattern := "*"
	if dir != "" {
		p, err := smbPath(dir)
		if err != nil {
			return nil, err
		}
		pattern = p + `\*`
	}
	out, err := s.run(ctx, `ls "`+pattern+`"`)
	if err != nil {
		if strings.Contains(err.Error(), "NOT_FOUND") || err.Error() == "NT_STATUS_NO_SUCH_FILE" {
			return nil, nil
		}
		return nil, err
	}
	var objs []remoteObject
	for _, line := range strings.Split(out, "\n") {
		m := smbLsRe.FindStringSubmatch(strings.TrimRight(line, "\r"))
		if m == nil || strings.Contains(m[2], "D") || m[1] == "." || m[1] == ".." {
			continue
		}
		size, _ := strconv.ParseInt(m[3], 10, 64)
		t, _ := time.ParseInLocation("Mon Jan _2 15:04:05 2006", m[4], time.Local)
		objs = append(objs, remoteObject{Name: m[1], Size: size, Modified: t})
	}
	return objs, nil
}
