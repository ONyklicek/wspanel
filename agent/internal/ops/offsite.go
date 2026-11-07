package ops

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/s3"
)

// Off-site copies of backups in S3-compatible storage. Every new backup
// (site or database, by hand or scheduled; not the snapshots taken before a
// restore) is copied to <prefix>/sites/<domain>/<file> or
// <prefix>/db/<engine>/<name>/<file>, and copies older than KeepDays are
// removed there (the newest one always stays). The credentials live in the
// agent's root-only state; the secret is never handed back.

type offsiteConfig struct {
	Enabled   bool   `json:"enabled"`
	Endpoint  string `json:"endpoint"`
	Region    string `json:"region"`
	Bucket    string `json:"bucket"`
	Prefix    string `json:"prefix"`
	PathStyle bool   `json:"path_style"`
	AccessKey string `json:"access_key"`
	SecretKey string `json:"secret_key"`
	KeepDays  int    `json:"keep_days"`
}

var (
	bucketRe        = regexp.MustCompile(`^[a-z0-9][a-z0-9.\-_]{1,62}$`)
	offsitePrefixRe = regexp.MustCompile(`^[A-Za-z0-9._\-/]{0,200}$`)
	regionRe        = regexp.MustCompile(`^[a-z0-9\-]{0,40}$`)
)

func offsitePath(env *Env) string { return filepath.Join(env.Cfg.StateDir, "offsite.json") }

func loadOffsite(env *Env) offsiteConfig {
	var c offsiteConfig
	if b, err := os.ReadFile(offsitePath(env)); err == nil {
		_ = json.Unmarshal(b, &c)
	}
	if c.KeepDays == 0 {
		c.KeepDays = 30
	}
	return c
}

func (c offsiteConfig) validate() error {
	if !bucketRe.MatchString(c.Bucket) {
		return protocol.Invalid("bucket: 2-63 lowercase letters, digits, dots and dashes")
	}
	if !offsitePrefixRe.MatchString(c.Prefix) || strings.Contains(c.Prefix, "..") {
		return protocol.Invalid("prefix: letters, digits and . _ - /")
	}
	if !regionRe.MatchString(c.Region) {
		return protocol.Invalid("region: e.g. eu-central-1 or auto")
	}
	if c.AccessKey == "" || c.SecretKey == "" || len(c.AccessKey) > 256 || len(c.SecretKey) > 256 || strings.ContainsAny(c.AccessKey+c.SecretKey, " \t\r\n") {
		return protocol.Invalid("access_key and secret_key: required")
	}
	if c.KeepDays < 1 || c.KeepDays > 3650 {
		return protocol.Invalid("keep_days: 1-3650")
	}
	u, err := url.Parse(c.Endpoint)
	if err != nil {
		return protocol.Invalid("endpoint: not an address")
	}
	// Plain http only to this machine (a local MinIO); anything else over TLS.
	if u.Scheme == "http" {
		ip := net.ParseIP(u.Hostname())
		if u.Hostname() != "localhost" && (ip == nil || !ip.IsLoopback()) {
			return protocol.Invalid("endpoint: https is required (http only for this machine)")
		}
	}
	if _, err := c.client(); err != nil {
		return protocol.Invalid(err.Error())
	}
	return nil
}

func (c offsiteConfig) client() (*s3.Client, error) {
	return s3.New(s3.Config{Endpoint: c.Endpoint, Region: c.Region, Bucket: c.Bucket, AccessKey: c.AccessKey, SecretKey: c.SecretKey, PathStyle: c.PathStyle})
}

func (c offsiteConfig) key(parts ...string) string {
	all := append([]string{strings.Trim(c.Prefix, "/")}, parts...)
	return strings.TrimPrefix(path.Join(all...), "/")
}

// offsiteTarget is where a backup's copies live: "sites/<domain>" or
// "db/<engine>/<name>", with the local folder and the file name pattern.
type offsiteTarget struct {
	dir   string // key part under the prefix
	local string
	match func(string) bool
}

func siteTarget(env *Env, domain string) offsiteTarget {
	return offsiteTarget{dir: "sites/" + domain, local: siteBackupsDir(env, domain), match: func(f string) bool {
		m := siteBackupNameRe.FindStringSubmatch(f)
		return m != nil && m[1] == domain && m[3] == ""
	}}
}

func dbTarget(env *Env, engine, name string) offsiteTarget {
	return offsiteTarget{dir: "db/" + engine + "/" + name, local: filepath.Join(env.Cfg.BackupDir, engine, name), match: func(f string) bool {
		m := backupNameRe.FindStringSubmatch(f)
		return m != nil && m[1] == name && m[3] == ""
	}}
}

// targetFor resolves {domain} or {engine, name} from a request.
func targetFor(env *Env, domain, engine, name string) (offsiteTarget, error) {
	if domain != "" {
		if _, _, err := loadSite(env, domain); err != nil {
			return offsiteTarget{}, err
		}
		return siteTarget(env, domain), nil
	}
	if err := (dbParams{Engine: engine, Name: name}).validateOnly(env); err != nil {
		return offsiteTarget{}, err
	}
	return dbTarget(env, engine, name), nil
}

// copyOffsite uploads one local backup and prunes old copies. Disabled
// storage is not an error: the answer is "" then.
func copyOffsite(ctx context.Context, env *Env, t offsiteTarget, file string) (string, error) {
	c := loadOffsite(env)
	if !c.Enabled {
		return "", nil
	}
	cl, err := c.client()
	if err != nil {
		return "", err
	}
	f, err := os.OpenFile(filepath.Join(t.local, file), os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return "", err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil || !fi.Mode().IsRegular() {
		return "", errors.New("not a regular file")
	}
	key := c.key(t.dir, file)
	if err := cl.Put(ctx, key, f, fi.Size()); err != nil {
		return "", fmt.Errorf("upload to %s failed: %w", c.Bucket, err)
	}
	pruneOffsite(ctx, cl, c, t)
	return key, nil
}

// pruneOffsite removes copies older than KeepDays; the newest always stays,
// and only names a backup of this target would have are touched.
func pruneOffsite(ctx context.Context, cl *s3.Client, c offsiteConfig, t offsiteTarget) int {
	objs, err := cl.List(ctx, c.key(t.dir)+"/")
	if err != nil {
		return 0
	}
	var ours []s3.Object
	for _, o := range objs {
		if t.match(path.Base(o.Key)) && path.Dir(o.Key) == c.key(t.dir) {
			ours = append(ours, o)
		}
	}
	slices.SortFunc(ours, func(a, b s3.Object) int { return b.LastModified.Compare(a.LastModified) })
	cutoff := time.Now().Add(-time.Duration(c.KeepDays) * 24 * time.Hour)
	removed := 0
	for i, o := range ours {
		if i > 0 && o.LastModified.Before(cutoff) && cl.Delete(ctx, o.Key) == nil {
			removed++
		}
	}
	return removed
}

// withOffsite copies a fresh backup and records how it went in the answer.
func withOffsite(ctx context.Context, env *Env, t offsiteTarget, b backupInfo) backupInfo {
	key, err := copyOffsite(ctx, env, t, b.File)
	if err != nil {
		b.OffsiteError = err.Error()
	}
	b.Offsite = key
	return b
}

// ---------------------------------------------------------------- operations

func offsiteGet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	c := loadOffsite(env)
	hasSecret := c.SecretKey != ""
	c.SecretKey = ""
	return map[string]any{"config": c, "has_secret": hasSecret, "configured": c.Bucket != ""}, nil
}

func offsiteSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p offsiteConfig
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	old := loadOffsite(env)
	if p.SecretKey == "" && p.AccessKey == old.AccessKey {
		p.SecretKey = old.SecretKey // unchanged: the panel never has it
	}
	p.Prefix = strings.Trim(p.Prefix, "/")
	if p.Region == "" {
		p.Region = "us-east-1"
	}
	if err := p.validate(); err != nil {
		return nil, err
	}
	if p.Enabled {
		if err := testOffsite(ctx, p); err != nil {
			return nil, protocol.Invalid("the storage refused a test upload: " + err.Error())
		}
	}
	b, _ := json.MarshalIndent(p, "", "  ")
	if err := env.FS.WriteFile(offsitePath(env), b, 0o600, fsx.Root); err != nil {
		return nil, err
	}
	return offsiteGet(ctx, env, nil)
}

// testOffsite writes, lists and deletes a small object.
func testOffsite(ctx context.Context, c offsiteConfig) error {
	cl, err := c.client()
	if err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	key := c.key(".wspanel-test-" + randomHex(8))
	body := strings.NewReader("wspanel")
	if err := cl.Put(ctx, key, body, body.Size()); err != nil {
		return err
	}
	if _, err := cl.List(ctx, key); err != nil {
		return err
	}
	return cl.Delete(ctx, key)
}

type offsiteParams struct {
	Domain string `json:"domain,omitempty"`
	Engine string `json:"engine,omitempty"`
	Name   string `json:"name,omitempty"`
	File   string `json:"file,omitempty"`
}

func (p offsiteParams) target(env *Env) (offsiteTarget, offsiteConfig, *s3.Client, error) {
	t, err := targetFor(env, p.Domain, p.Engine, p.Name)
	if err != nil {
		return t, offsiteConfig{}, nil, err
	}
	c := loadOffsite(env)
	if !c.Enabled {
		return t, c, nil, protocol.Conflict("off-site storage is not set up")
	}
	cl, err := c.client()
	return t, c, cl, err
}

// offsiteList lists the copies of one site's or database's backups.
func offsiteList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p offsiteParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	t, c, cl, err := p.target(env)
	if err != nil {
		return nil, err
	}
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	objs, err := cl.List(ctx, c.key(t.dir)+"/")
	if err != nil {
		return nil, err
	}
	out := []map[string]any{}
	for _, o := range objs {
		if name := path.Base(o.Key); t.match(name) && path.Dir(o.Key) == c.key(t.dir) {
			out = append(out, map[string]any{"file": name, "size": o.Size, "modified": o.LastModified})
		}
	}
	return map[string]any{"objects": out}, nil
}

// offsiteUpload copies an existing local backup (e.g. one made before the
// storage was set up).
func offsiteUpload(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p offsiteParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	t, _, _, err := p.target(env)
	if err != nil {
		return nil, err
	}
	if !t.match(p.File) {
		return nil, protocol.Invalid("file: not a backup of this site or database")
	}
	key, err := copyOffsite(ctx, env, t, p.File)
	if err != nil {
		return nil, err
	}
	return map[string]any{"offsite": key}, nil
}

// offsiteFetch downloads a copy back next to the local backups, where it can
// be restored or downloaded like any other.
func offsiteFetch(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p offsiteParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	t, c, cl, err := p.target(env)
	if err != nil {
		return nil, err
	}
	if !t.match(p.File) {
		return nil, protocol.Invalid("file: not a backup of this site or database")
	}
	dest := filepath.Join(t.local, p.File)
	if fsx.Exists(dest) {
		return nil, protocol.Conflict(p.File + " is already on the server")
	}
	// The local folder with the right owner (sites/<domain> or <engine>/<name>).
	var owner fsx.Owner
	if p.Domain != "" {
		_, owner, err = backupDir(env, "sites", p.Domain)
	} else {
		_, owner, err = backupDir(env, p.Engine, p.Name)
	}
	if err != nil {
		return nil, err
	}
	body, _, err := cl.Get(ctx, c.key(t.dir, p.File))
	if err != nil {
		return nil, err
	}
	defer body.Close()
	tmp, err := os.CreateTemp(t.local, ".fetch-*")
	if err != nil {
		return nil, err
	}
	defer os.Remove(tmp.Name())
	n, err := io.Copy(tmp, body)
	if cerr := tmp.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return nil, fmt.Errorf("download failed: %w", err)
	}
	if err := os.Chmod(tmp.Name(), 0o640); err != nil {
		return nil, err
	}
	if !env.Cfg.DryRun {
		if err := os.Chown(tmp.Name(), owner.UID, owner.GID); err != nil {
			return nil, err
		}
	}
	if err := os.Rename(tmp.Name(), dest); err != nil {
		return nil, err
	}
	return map[string]any{"file": p.File, "size": n}, nil
}
