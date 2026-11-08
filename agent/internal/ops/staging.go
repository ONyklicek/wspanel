package ops

import (
	"compress/gzip"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Staging: a copy of a site, and back. A site's current release is packed as
// a build artifact (as the site's user) and deployed to the other site like
// any CI artifact, so the copy goes through the same steps as a deploy:
// shared files linked, health check, rollback on failure, workers rotated.

// sitePack packs the site's current release into the artifact directory and
// returns the artifact's name (owned by the panel, as a CI upload would be).
func sitePack(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	rel, err := currentRelease(l)
	if err != nil {
		return nil, err
	}
	name := "copy-" + strings.ReplaceAll(p.Domain, ".", "-") + "-" + time.Now().UTC().Format("20060102T150405") + ".tar.gz"
	path := filepath.Join(env.Cfg.ArtifactDir, name)
	// The release's links to shared/ go along as links; the target links its own.
	if err := packAsUser(ctx, env, l.User, filepath.Join(l.Home, "releases", rel), path, nil); err != nil {
		return nil, err
	}
	if owner, err := lookupOwner(env, env.Cfg.PanelUser); err == nil && owner.UID >= 0 {
		_ = os.Chown(path, owner.UID, owner.GID)
	}
	return map[string]any{"artifact": name, "release": rel}, nil
}

// siteCopyShared copies a site's shared files (uploads, storage) to another
// site, without its .env (each site keeps its own settings and secrets).
func siteCopyShared(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		From string `json:"from"`
		To   string `json:"to"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.From == p.To {
		return nil, protocol.Invalid("to: another site")
	}
	_, src, err := loadSite(env, p.From)
	if err != nil {
		return nil, err
	}
	_, dst, err := loadSite(env, p.To)
	if err != nil {
		return nil, err
	}
	tmp := filepath.Join(env.Cfg.ArtifactDir, "shared-"+randomHex(8)+".tar.gz")
	defer os.Remove(tmp)
	if err := packAsUser(ctx, env, src.User, filepath.Join(src.Home, "shared"), tmp, []string{"./.env", "./.env.*", "./storage/logs", "./var/log", "./var/cache"}); err != nil {
		return nil, err
	}
	if err := unpackAsUser(ctx, env, dst.User, tmp, filepath.Join(dst.Home, "shared")); err != nil {
		return nil, err
	}
	return map[string]any{"from": p.From, "to": p.To, "copied": true}, nil
}

// packAsUser writes a tar.gz of dir, read as user (a planted link cannot make
// root read anything else), into a new root-owned file.
func packAsUser(ctx context.Context, env *Env, user, dir, out string, exclude []string) error {
	if err := env.FS.MkdirAll(filepath.Dir(out), 0o700, fsx.Root); err != nil {
		return err
	}
	f, err := os.OpenFile(out, os.O_CREATE|os.O_EXCL|os.O_WRONLY|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	zw := gzip.NewWriter(f)
	args := append(userCreds(user), "/usr/bin/tar", "--create", "--file=-", "--directory="+dir)
	for _, e := range exclude {
		args = append(args, "--exclude="+e)
	}
	args = append(args, ".")
	if _, err := env.Exec.RunIO(ctx, nil, zw, "setpriv", args...); err != nil {
		_ = os.Remove(out)
		return fmt.Errorf("packing %s: %w", dir, err)
	}
	return zw.Close()
}

// unpackAsUser extracts a tar.gz into dir as user (capped like a CI artifact).
func unpackAsUser(ctx context.Context, env *Env, user, archive, dir string) error {
	f, err := os.OpenFile(archive, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return err
	}
	defer f.Close()
	zr, err := gzip.NewReader(f)
	if err != nil {
		if env.Cfg.DryRun && err == io.EOF {
			return nil
		}
		return err
	}
	capped := &capReader{r: zr, left: maxArtifactExpanded}
	_, err = env.Exec.RunIO(ctx, capped, io.Discard, "setpriv", append(userCreds(user),
		"/usr/bin/tar", "--extract", "--file=-", "--directory="+dir, "--no-same-owner", "--no-same-permissions")...)
	if capped.exceeded {
		return fmt.Errorf("more than %d GB", maxArtifactExpanded>>30)
	}
	return err
}

// dbCopy replaces one database's content with another's (same engine):
// staging from production, or back.
func dbCopy(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Engine string `json:"engine"`
		From   string `json:"from"`
		To     string `json:"to"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	for _, n := range []string{p.From, p.To} {
		if err := validate.DBName("database", n); err != nil {
			return nil, err
		}
	}
	if p.From == p.To {
		return nil, protocol.Invalid("to: another database")
	}
	eng, err := engineFor(env, p.Engine)
	if err != nil {
		return nil, err
	}
	tmp := filepath.Join(env.Cfg.ArtifactDir, "db-"+randomHex(8)+eng.ext())
	if err := env.FS.MkdirAll(env.Cfg.ArtifactDir, 0o700, fsx.Root); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(tmp, os.O_CREATE|os.O_EXCL|os.O_RDWR|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return nil, err
	}
	defer os.Remove(tmp)
	defer f.Close()
	if err := eng.dump(ctx, env, p.From, f); err != nil {
		return nil, fmt.Errorf("dump of %s: %w", p.From, err)
	}
	if _, err := f.Seek(0, io.SeekStart); err != nil {
		return nil, err
	}
	if err := eng.restore(ctx, env, p.To, f); err != nil {
		return nil, fmt.Errorf("restore into %s: %w", p.To, err)
	}
	return map[string]any{"from": p.From, "to": p.To, "copied": true}, nil
}
