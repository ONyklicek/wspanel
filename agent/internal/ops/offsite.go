package ops

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
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

	"wspanel/agent/internal/fileop"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
)

// Off-site copies of backups: S3-compatible storage, FTP(S) or SMB. Every
// new backup (site or database, by hand or scheduled; not the snapshots
// taken before a restore) is copied to <prefix>/sites/<domain>/<file> or
// <prefix>/db/<engine>/<name>/<file>, and copies older than KeepDays are
// removed there (the newest one always stays).
//
// The storage is outside this server, so what comes back from it is not
// trusted: each copy gets <file>.sig, an HMAC-SHA256 under a key only the
// agent holds, and a fetched copy whose signature does not match is refused
// (unless the admin explicitly accepts it). The key can be exported and
// imported again, so a new server can verify the old server's backups.
// Credentials and the key live in the agent's root-only state; the secrets
// are never handed back.

type offsiteConfig struct {
	Enabled bool   `json:"enabled"`
	Type    string `json:"type"` // s3 (default) | ftp | smb
	Prefix  string `json:"prefix"`
	// S3
	Endpoint  string `json:"endpoint,omitempty"`
	Region    string `json:"region,omitempty"`
	Bucket    string `json:"bucket,omitempty"`
	PathStyle bool   `json:"path_style,omitempty"`
	AccessKey string `json:"access_key,omitempty"`
	SecretKey string `json:"secret_key,omitempty"`
	// FTP, SMB
	Host       string `json:"host,omitempty"`
	Port       int    `json:"port,omitempty"`
	Username   string `json:"username,omitempty"`
	Password   string `json:"password,omitempty"`
	FTPTLS     string `json:"ftp_tls,omitempty"`     // explicit | implicit | none
	TLSPin     string `json:"tls_pin,omitempty"`     // FTP: SHA-256 fingerprint of a NAS's own certificate
	Share      string `json:"share,omitempty"`       // SMB
	Domain     string `json:"smb_domain,omitempty"`  // SMB workgroup / domain
	SMBEncrypt bool   `json:"smb_encrypt,omitempty"` // SMB3 encryption required
	KeepDays   int    `json:"keep_days"`
}

var (
	bucketRe        = regexp.MustCompile(`^[a-z0-9][a-z0-9.\-_]{1,62}$`)
	offsitePrefixRe = regexp.MustCompile(`^[A-Za-z0-9._\-/]{0,200}$`)
	regionRe        = regexp.MustCompile(`^[a-z0-9\-]{0,40}$`)
	hostRe          = regexp.MustCompile(`^[A-Za-z0-9.\-:\[\]]{1,253}$`)
	shareRe         = regexp.MustCompile(`^[A-Za-z0-9._\-$ ]{1,80}$`)
	smbUserRe       = regexp.MustCompile(`^[^\r\n"]{1,128}$`)
	pinRe           = regexp.MustCompile(`^([0-9A-Fa-f]{2}:?){31}[0-9A-Fa-f]{2}$`)
)

func offsitePath(env *Env) string { return filepath.Join(env.Cfg.StateDir, "offsite.json") }

func loadOffsite(env *Env) offsiteConfig {
	var c offsiteConfig
	if b, err := os.ReadFile(offsitePath(env)); err == nil {
		_ = json.Unmarshal(b, &c)
	}
	if c.Type == "" {
		c.Type = "s3"
	}
	if c.KeepDays == 0 {
		c.KeepDays = 30
	}
	return c
}

func (c offsiteConfig) validate() error {
	if !offsitePrefixRe.MatchString(c.Prefix) || strings.Contains(c.Prefix, "..") {
		return protocol.Invalid("prefix: letters, digits and . _ - /")
	}
	if c.KeepDays < 1 || c.KeepDays > 3650 {
		return protocol.Invalid("keep_days: 1-3650")
	}
	switch c.Type {
	case "s3":
		if !bucketRe.MatchString(c.Bucket) {
			return protocol.Invalid("bucket: 2-63 lowercase letters, digits, dots and dashes")
		}
		if !regionRe.MatchString(c.Region) {
			return protocol.Invalid("region: e.g. eu-central-1 or auto")
		}
		if c.AccessKey == "" || c.SecretKey == "" || len(c.AccessKey) > 256 || len(c.SecretKey) > 256 || strings.ContainsAny(c.AccessKey+c.SecretKey, " \t\r\n") {
			return protocol.Invalid("access_key and secret_key: required")
		}
		u, err := url.Parse(c.Endpoint)
		if err != nil {
			return protocol.Invalid("endpoint: not an address")
		}
		// Plain http only to this machine (a local MinIO); anything else over TLS.
		if u.Scheme == "http" && !isLocalHost(u.Hostname()) {
			return protocol.Invalid("endpoint: https is required (http only for this machine)")
		}
	case "ftp", "smb":
		if !hostRe.MatchString(c.Host) || strings.HasPrefix(c.Host, "-") {
			return protocol.Invalid("host: a host name or IP address")
		}
		if c.Port < 0 || c.Port > 65535 {
			return protocol.Invalid("port: 1-65535")
		}
		if !smbUserRe.MatchString(c.Username) || c.Password == "" || len(c.Password) > 256 || strings.ContainsAny(c.Password, "\r\n") {
			return protocol.Invalid("username and password: required")
		}
		if c.Type == "ftp" && !slices.Contains([]string{"explicit", "implicit", "none"}, c.FTPTLS) {
			return protocol.Invalid("ftp_tls: explicit, implicit or none")
		}
		if c.TLSPin != "" && !pinRe.MatchString(c.TLSPin) {
			return protocol.Invalid("tls_pin: a SHA-256 fingerprint (64 hexadecimal characters)")
		}
		if c.Type == "smb" {
			if !shareRe.MatchString(c.Share) {
				return protocol.Invalid("share: the name of the shared folder")
			}
			if c.Domain != "" && !smbUserRe.MatchString(c.Domain) {
				return protocol.Invalid("smb_domain: one line")
			}
			if strings.ContainsAny(c.Username, "\\%") {
				return protocol.Invalid("username: put the domain in its own field")
			}
		}
	default:
		return protocol.Invalid("type: s3, ftp or smb")
	}
	return nil
}

func isLocalHost(h string) bool {
	ip := net.ParseIP(h)
	return h == "localhost" || (ip != nil && ip.IsLoopback())
}

func (c offsiteConfig) key(parts ...string) string {
	all := append([]string{strings.Trim(c.Prefix, "/")}, parts...)
	return strings.TrimPrefix(path.Join(all...), "/")
}

// hasSecret / clearSecrets / keepSecrets: the panel never sees a secret; a
// save without one keeps the stored one when the identity is the same.
func (c offsiteConfig) hasSecret() bool { return c.SecretKey != "" || c.Password != "" }

func (c offsiteConfig) withoutSecrets() offsiteConfig {
	c.SecretKey, c.Password = "", ""
	return c
}

func (c *offsiteConfig) keepSecrets(old offsiteConfig) {
	if c.Type != old.Type {
		return
	}
	if c.Type == "s3" && c.SecretKey == "" && c.AccessKey == old.AccessKey && c.Endpoint == old.Endpoint && c.Bucket == old.Bucket && c.Region == old.Region {
		c.SecretKey = old.SecretKey
	}
	// The password goes only where it went before, as protected as before:
	// any change of server, port, account, encryption, pin or share needs it
	// typed again.
	if c.Type != "s3" && c.Password == "" && c.Host == old.Host && c.Port == old.Port && c.Username == old.Username &&
		c.FTPTLS == old.FTPTLS && c.TLSPin == old.TLSPin && c.Share == old.Share && c.Domain == old.Domain && c.SMBEncrypt == old.SMBEncrypt {
		c.Password = old.Password
	}
}

// ---------------------------------------------------------------- signing

func signingKeyPath(env *Env) string { return filepath.Join(env.Cfg.StateDir, "backup-signing.key") }

// signingKey returns the agent's key for backup signatures, creating it once.
func signingKey(env *Env) ([]byte, error) {
	if b, err := os.ReadFile(signingKeyPath(env)); err == nil {
		if k, err := hex.DecodeString(strings.TrimSpace(string(b))); err == nil && len(k) == 32 {
			return k, nil
		}
		return nil, errors.New("the backup signing key is damaged")
	}
	k := make([]byte, 32)
	if _, err := rand.Read(k); err != nil {
		return nil, err
	}
	if err := env.FS.MkdirAll(env.Cfg.StateDir, 0o700, fsx.Root); err != nil {
		return nil, err
	}
	if err := env.FS.WriteFile(signingKeyPath(env), []byte(hex.EncodeToString(k)+"\n"), 0o600, fsx.Root); err != nil {
		return nil, err
	}
	return k, nil
}

// fileMAC signs a copy together with where it belongs: the target folder
// (sites/<domain>, db/<engine>/<name>), the file name and the size. A valid
// copy of one site's backup moved under another site's name does not verify.
func fileMAC(key []byte, dir, name string, f io.ReaderAt, size int64) ([]byte, error) {
	m := hmac.New(sha256.New, key)
	fmt.Fprintf(m, "wspanel-backup-v1\n%s/%s\n%d\n", dir, name, size)
	if _, err := io.Copy(m, io.NewSectionReader(f, 0, size)); err != nil {
		return nil, err
	}
	return m.Sum(nil), nil
}

// ---------------------------------------------------------------- targets

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

// ---------------------------------------------------------------- copying

// copyOffsite uploads one local backup with its signature and prunes old
// copies. Disabled storage is not an error: the answer is "" then.
func copyOffsite(ctx context.Context, env *Env, t offsiteTarget, file string) (string, error) {
	c := loadOffsite(env)
	if !c.Enabled {
		return "", nil
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
	key, err := signingKey(env)
	if err != nil {
		return "", err
	}
	mac, err := fileMAC(key, t.dir, file, f, fi.Size())
	if err != nil {
		return "", err
	}
	st, err := c.open(ctx, env)
	if err != nil {
		return "", fmt.Errorf("connecting to the storage failed: %w", err)
	}
	defer st.Close()
	remote := c.key(t.dir, file)
	if err := st.Put(ctx, remote, f, fi.Size()); err != nil {
		return "", fmt.Errorf("upload to %s failed: %w", c.where(), err)
	}
	if err := st.PutBytes(ctx, remote+".sig", []byte(hex.EncodeToString(mac)+"\n")); err != nil {
		return "", fmt.Errorf("upload to %s failed: %w", c.where(), err)
	}
	pruneOffsite(ctx, st, c, t)
	return remote, nil
}

// where names the storage in messages.
func (c offsiteConfig) where() string {
	if c.Type == "s3" {
		return c.Bucket
	}
	return c.Host
}

// pruneOffsite removes copies older than KeepDays with their signatures; the
// newest always stays, and only names a backup of this target would have
// are touched.
func pruneOffsite(ctx context.Context, st offsiteStore, c offsiteConfig, t offsiteTarget) int {
	objs, err := st.List(ctx, c.key(t.dir))
	if err != nil {
		return 0
	}
	var ours []remoteObject
	for _, o := range objs {
		if t.match(o.Name) {
			ours = append(ours, o)
		}
	}
	// Newest first, by the time in the name (storage clocks and listings differ).
	slices.SortFunc(ours, func(a, b remoteObject) int { return strings.Compare(backupStamp(b.Name), backupStamp(a.Name)) })
	cutoff := time.Now().UTC().Add(-time.Duration(c.KeepDays) * 24 * time.Hour).Format("20060102T150405Z")
	removed := 0
	for i, o := range ours {
		if i > 0 && backupStamp(o.Name) < cutoff && st.Delete(ctx, c.key(t.dir, o.Name)) == nil {
			_ = st.Delete(ctx, c.key(t.dir, o.Name)+".sig")
			removed++
		}
	}
	return removed
}

var stampRe = regexp.MustCompile(`\d{8}T\d{6}Z`)

func backupStamp(name string) string { return stampRe.FindString(name) }

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
	configured := (c.Type == "s3" && c.Bucket != "") || (c.Type != "s3" && c.Host != "")
	return map[string]any{"config": c.withoutSecrets(), "has_secret": c.hasSecret(), "configured": configured}, nil
}

func offsiteSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		offsiteConfig
		SigningKey string `json:"signing_key,omitempty"` // refused: see ImportRecoveryKey
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	c := p.offsiteConfig
	if c.Type == "" {
		c.Type = "s3"
	}
	c.Prefix = strings.Trim(c.Prefix, "/")
	if c.Type == "s3" && c.Region == "" {
		c.Region = "us-east-1"
	}
	if c.Type == "ftp" && c.FTPTLS == "" {
		c.FTPTLS = "explicit"
	}
	c.keepSecrets(loadOffsite(env)) // after the defaults: compared as stored
	if err := c.validate(); err != nil {
		return nil, err
	}
	if p.SigningKey != "" {
		return nil, protocol.Invalid("signing_key: imported on the server only (panel-agent -import-recovery-key)")
	}
	if c.Type == "smb" {
		if err := ensureSMBClient(ctx, env); err != nil {
			return nil, err
		}
	}
	if c.Enabled {
		if err := testOffsite(ctx, env, c); err != nil {
			return nil, protocol.Invalid("the storage refused a test upload: " + err.Error())
		}
	}
	b, _ := json.MarshalIndent(c, "", "  ")
	if err := env.FS.WriteFile(offsitePath(env), b, 0o600, fsx.Root); err != nil {
		return nil, err
	}
	return offsiteGet(ctx, env, nil)
}

// The recovery key leaves and enters only through root's own command
// (panel-agent -recovery-key, -import-recovery-key), never the socket. It
// signs the off-site copies and is what server backups are encrypted with,
// and a server backup holds what the panel user may never read (the agent's
// state, every site's TLS key, DKIM keys, the mail logins). A panel that
// could read it or set its own could decrypt all of that, or have backups
// made under a key it knows.

// RecoveryKey returns the key (hexadecimal), creating it once.
func RecoveryKey(env *Env) (string, error) {
	k, err := signingKey(env)
	if err != nil {
		return "", err
	}
	return hex.EncodeToString(k), nil
}

// ImportRecoveryKey replaces the key with another server's, for restoring
// that server's backups here.
func ImportRecoveryKey(env *Env, key string) error {
	k, err := hex.DecodeString(strings.TrimSpace(key))
	if err != nil || len(k) != 32 {
		return errors.New("the recovery key is 64 hexadecimal characters")
	}
	if err := env.FS.MkdirAll(env.Cfg.StateDir, 0o700, fsx.Root); err != nil {
		return err
	}
	return env.FS.WriteFile(signingKeyPath(env), []byte(hex.EncodeToString(k)+"\n"), 0o600, fsx.Root)
}

// ensureSMBClient installs Samba's client when SMB is chosen.
func ensureSMBClient(ctx context.Context, env *Env) error {
	if env.Cfg.DryRun || fsx.Exists("/usr/bin/smbclient") {
		return nil
	}
	if _, err := env.Exec.RunIO(ctx, nil, nil, "apt-get", "install", "-y", "-q", "--no-install-recommends", "smbclient"); err != nil {
		return fmt.Errorf("installing smbclient failed: %w", err)
	}
	return nil
}

// testOffsite writes, reads back and deletes a small object.
func testOffsite(ctx context.Context, env *Env, c offsiteConfig) error {
	ctx, cancel := context.WithTimeout(ctx, 60*time.Second)
	defer cancel()
	st, err := c.open(ctx, env)
	if err != nil {
		return err
	}
	defer st.Close()
	key := c.key(".wspanel-test-" + randomHex(8))
	want := []byte("wspanel " + randomHex(8))
	if err := st.PutBytes(ctx, key, want); err != nil {
		return err
	}
	var got bytes.Buffer
	_, ferr := st.Fetch(ctx, key, &got, 1<<10)
	derr := st.Delete(ctx, key)
	if ferr != nil {
		return ferr
	}
	if !bytes.Equal(got.Bytes(), want) {
		return errors.New("what was read back differs from what was written")
	}
	return derr
}

type offsiteParams struct {
	Game       int    `json:"game,omitempty"` // a game server's backups
	Domain     string `json:"domain,omitempty"`
	Engine     string `json:"engine,omitempty"`
	Name       string `json:"name,omitempty"`
	File       string `json:"file,omitempty"`
	Unverified bool   `json:"unverified,omitempty"` // fetch: accept a copy without a valid signature
}

// resolve: the backups the params name (a game server, a site, a database).
func (p offsiteParams) resolve(env *Env) (offsiteTarget, error) {
	if p.Game > 0 {
		if _, err := loadGame(env, p.Game); err != nil {
			return offsiteTarget{}, err
		}
		return gameTarget(env, p.Game), nil
	}
	return targetFor(env, p.Domain, p.Engine, p.Name)
}

func (p offsiteParams) target(ctx context.Context, env *Env) (offsiteTarget, offsiteConfig, offsiteStore, error) {
	t, err := p.resolve(env)
	if err != nil {
		return t, offsiteConfig{}, nil, err
	}
	c := loadOffsite(env)
	if !c.Enabled {
		return t, c, nil, protocol.Conflict("off-site storage is not set up")
	}
	st, err := c.open(ctx, env)
	if err != nil {
		return t, c, nil, fmt.Errorf("connecting to the storage failed: %w", err)
	}
	return t, c, st, nil
}

// offsiteList lists the copies of one site's or database's backups.
func offsiteList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p offsiteParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	t, c, st, err := p.target(ctx, env)
	if err != nil {
		return nil, err
	}
	defer st.Close()
	objs, err := st.List(ctx, c.key(t.dir))
	if err != nil {
		return nil, err
	}
	out := []map[string]any{}
	for _, o := range objs {
		if t.match(o.Name) {
			out = append(out, map[string]any{"file": o.Name, "size": o.Size, "modified": o.Modified})
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
	t, err := p.resolve(env)
	if err != nil {
		return nil, err
	}
	if !loadOffsite(env).Enabled {
		return nil, protocol.Conflict("off-site storage is not set up")
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
// be restored or downloaded like any other — only when its signature is
// this server's (or the admin accepts it without one).
func offsiteFetch(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p offsiteParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	t, c, st, err := p.target(ctx, env)
	if err != nil {
		return nil, err
	}
	defer st.Close()
	if !t.match(p.File) {
		return nil, protocol.Invalid("file: not a backup of this site or database")
	}
	dest := filepath.Join(t.local, p.File)
	if fsx.Exists(dest) {
		return nil, protocol.Conflict(p.File + " is already on the server")
	}
	// The local folder with the right owner (sites/<domain> or <engine>/<name>).
	var owner fsx.Owner
	if p.Game > 0 {
		// Game servers' backups are root's (read and written as the game's user).
		owner = fsx.Root
		if err = env.FS.MkdirAll(t.local, 0o700, fsx.Root); err != nil {
			return nil, err
		}
	} else if p.Domain != "" {
		_, owner, err = backupDir(env, "sites", p.Domain)
	} else {
		_, owner, err = backupDir(env, p.Engine, p.Name)
	}
	if err != nil {
		return nil, err
	}
	remote := c.key(t.dir, p.File)
	var sig bytes.Buffer
	_, sigErr := st.Fetch(ctx, remote+".sig", &sig, 1<<10)

	tmp, err := os.CreateTemp(t.local, ".fetch-*")
	if err != nil {
		return nil, err
	}
	defer os.Remove(tmp.Name())
	n, err := st.Fetch(ctx, remote, tmp, fileop.MaxRestore)
	if err != nil {
		tmp.Close()
		if errors.Is(err, errTooLarge) {
			return nil, protocol.Invalid(fmt.Sprintf("the copy is larger than %d GB, not a backup of this server", fileop.MaxRestore>>30))
		}
		return nil, fmt.Errorf("download failed: %w", err)
	}

	verified := false
	if sigErr == nil {
		key, err := signingKey(env)
		if err != nil {
			tmp.Close()
			return nil, err
		}
		mac, err := fileMAC(key, t.dir, p.File, tmp, n)
		if err != nil {
			tmp.Close()
			return nil, err
		}
		want, _ := hex.DecodeString(strings.TrimSpace(sig.String()))
		verified = hmac.Equal(mac, want)
	}
	if err := tmp.Close(); err != nil {
		return nil, err
	}
	if !verified && !p.Unverified {
		return nil, protocol.Invalid("the copy's signature does not match: it was not made by this server or it was changed")
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
	return map[string]any{"file": p.File, "size": n, "verified": verified}, nil
}
