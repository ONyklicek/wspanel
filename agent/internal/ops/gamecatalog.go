package ops

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"time"

	"wspanel/agent/internal/protocol"
)

// Game packages from outside the agent: WSPanel's catalog (signed with the
// key the apt repository is signed with) and the administrator's own.

// gameCatalogDoc: catalog.json, signed beside it (catalog.json.asc).
type gameCatalogDoc struct {
	Format   int               `json:"format"`
	Packages []json.RawMessage `json:"packages"`
}

// verifyCatalog checks the catalog's signature against WSPanel's archive key
// (tests replace it). gpgv reads binary keyrings only: the armored key is
// converted into a private temporary one first (gpg's home there too: the
// agent's own home is out of its reach).
var verifyCatalog = func(ctx context.Context, env *Env, data, sig []byte) error {
	dir, err := os.MkdirTemp("", "wspanel-catalog-*")
	if err != nil {
		return err
	}
	defer os.RemoveAll(dir)
	keyring := filepath.Join(dir, "keyring.gpg")
	if _, err := env.Exec.Run(ctx, "gpg", "--homedir", dir, "--batch", "--yes", "--dearmor", "--output", keyring, env.Cfg.ArchiveKeyring); err != nil {
		return fmt.Errorf("WSPanel's key (%s) cannot be read: %w", env.Cfg.ArchiveKeyring, err)
	}
	for name, b := range map[string][]byte{"catalog.json": data, "catalog.json.asc": sig} {
		if err := os.WriteFile(filepath.Join(dir, name), b, 0o600); err != nil {
			return err
		}
	}
	if _, err := env.Exec.Run(ctx, "gpgv", "--homedir", dir, "--keyring", keyring, filepath.Join(dir, "catalog.json.asc"), filepath.Join(dir, "catalog.json")); err != nil {
		return fmt.Errorf("the catalog's signature does not match WSPanel's key: refused")
	}
	return nil
}

func fetchBytes(ctx context.Context, url string, limit int64) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", gameUserAgent())
	res, err := (&http.Client{Timeout: 30 * time.Second}).Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s: HTTP %d", url, res.StatusCode)
	}
	return io.ReadAll(io.LimitReader(res.Body, limit))
}

// fetchGameCatalog: the catalog's packages, each checked, by id (the
// signature checked first: nothing of an unsigned catalog is read).
func fetchGameCatalog(ctx context.Context, env *Env) (map[string]gamePkg, map[string][]byte, error) {
	data, err := fetchBytes(ctx, env.Cfg.GameCatalogURL, 4<<20)
	if err != nil {
		return nil, nil, fmt.Errorf("WSPanel's game catalog is not reachable: %w", err)
	}
	sig, err := fetchBytes(ctx, env.Cfg.GameCatalogURL+".asc", 64<<10)
	if err != nil {
		return nil, nil, fmt.Errorf("WSPanel's game catalog has no signature: %w", err)
	}
	if err := verifyCatalog(ctx, env, data, sig); err != nil {
		return nil, nil, err
	}
	var doc gameCatalogDoc
	if err := json.Unmarshal(data, &doc); err != nil || doc.Format != 1 {
		return nil, nil, fmt.Errorf("the game catalog is not in a format this agent reads (update WSPanel)")
	}
	pkgs, raw := map[string]gamePkg{}, map[string][]byte{}
	for _, r := range doc.Packages {
		p, err := parseGamePkg(r)
		if err != nil {
			continue // a package for a newer agent: not offered
		}
		var buf bytes.Buffer
		_ = json.Indent(&buf, r, "", "  ")
		pkgs[p.ID], raw[p.ID] = p, buf.Bytes()
	}
	return pkgs, raw, nil
}

// pkgView: a package as the panel shows it.
func pkgView(p gamePkg) map[string]any {
	flavors := []map[string]string{}
	for _, f := range p.Flavors {
		flavors = append(flavors, map[string]string{"id": f.ID, "label": f.Label})
	}
	out := map[string]any{"id": p.ID, "name": p.Name, "version": p.Version, "description": p.Description,
		"origin": p.Origin, "flavors": flavors, "extends": p.Extends}
	if p.Game != nil {
		out["game"] = p.Game.ID
		out["game_label"] = p.Game.Label
		out["engine"] = p.Game.Engine
		out["arch"] = p.Game.Arch
	}
	return out
}

// pkgUsers: the game servers that run something of a package (its game or
// a variant it adds): what stops its removal.
func pkgUsers(env *Env, p gamePkg) []int {
	ids := []int{}
	for _, g := range allGames(env) {
		uses := p.Game != nil && g.Game == p.Game.ID
		if p.Game == nil && g.Game == p.Extends {
			for _, f := range p.Flavors {
				uses = uses || f.ID == g.Flavor
			}
		}
		if uses {
			ids = append(ids, g.ID)
		}
	}
	return ids
}

// gamePackages: the packages in effect, those that are not (and why), and
// with catalog: true what WSPanel's catalog offers.
func gamePackages(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Catalog bool `json:"catalog,omitempty"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, active, problems := gameRegistry(env)
	installed := []map[string]any{}
	versions := map[string]string{}
	for _, pkg := range active {
		v := pkgView(pkg)
		v["servers"] = pkgUsers(env, pkg)
		installed = append(installed, v)
		versions[pkg.ID] = pkg.Version
	}
	out := map[string]any{"packages": installed, "problems": problems}
	if p.Catalog {
		offered := []map[string]any{}
		cat, _, err := fetchGameCatalog(ctx, env)
		if err != nil {
			out["catalog_error"] = err.Error()
		}
		for _, c := range cat {
			v := pkgView(c)
			v["installed"] = versions[c.ID]
			offered = append(offered, v)
		}
		out["catalog"] = offered
	}
	return out, nil
}

// gamePackageInstall: a package from WSPanel's catalog (or its newer
// version), kept with the agent's state.
func gamePackageInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID string `json:"id"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !gamePkgIDRe.MatchString(p.ID) {
		return nil, protocol.Invalid("id: a package's id")
	}
	cat, files, err := fetchGameCatalog(ctx, env)
	if err != nil {
		return nil, err
	}
	pkg, ok := cat[p.ID]
	if !ok {
		return nil, protocol.NotFound("the catalog has no package " + p.ID)
	}
	if err := fitsRegistry(env, pkg, "catalog"); err != nil {
		return nil, err
	}
	if err := writeGamePkg(env, "catalog", pkg, files[p.ID]); err != nil {
		return nil, err
	}
	return map[string]any{"installed": pkgView(pkg)}, nil
}

// gamePackageUpload: the administrator's own package (a manifest uploaded in
// the panel), checked like every other, into /etc/wspanel/games.d.
func gamePackageUpload(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Manifest string `json:"manifest"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	pkg, err := parseGamePkg([]byte(p.Manifest))
	if err != nil {
		return nil, protocol.Invalid("manifest: " + err.Error())
	}
	if err := fitsRegistry(env, pkg, "local"); err != nil {
		return nil, err
	}
	var buf bytes.Buffer
	_ = json.Indent(&buf, []byte(p.Manifest), "", "  ")
	if err := writeGamePkg(env, "local", pkg, buf.Bytes()); err != nil {
		return nil, err
	}
	return map[string]any{"installed": pkgView(pkg)}, nil
}

// fitsRegistry: a new package (or a newer version of one from the same
// place) takes effect beside the others, or says why it would not.
func fitsRegistry(env *Env, pkg gamePkg, origin string) error {
	defs, active, _ := gameRegistry(env)
	for _, a := range active {
		if a.ID == pkg.ID && a.Origin != origin {
			return protocol.Conflict(fmt.Sprintf("package %s is already %s", pkg.ID, map[string]string{"builtin": "built in", "catalog": "installed from the catalog", "local": "the administrator's own"}[a.Origin]))
		}
	}
	replaced := func(owner string) bool { return owner == pkg.ID }
	if pkg.Game != nil {
		if d := defs[pkg.Game.ID]; d != nil && !replaced(d.Package) {
			return protocol.Conflict("the game " + pkg.Game.ID + " is already added by " + d.Package)
		}
		return nil
	}
	d := defs[pkg.Extends]
	if d == nil {
		return protocol.Invalid("extends: no installed package adds the game " + pkg.Extends)
	}
	for _, f := range pkg.Flavors {
		if have, ok := d.flavor(f.ID); ok && !replaced(have.Package) {
			return protocol.Conflict("the variant " + f.ID + " of " + d.ID + " already exists (" + have.Package + ")")
		}
		if !contains(gameEngines[d.Engine], f.Source.Type) {
			return protocol.Invalid(fmt.Sprintf("flavor %s: %s runs no %s source", f.ID, d.Label, f.Source.Type))
		}
	}
	return nil
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

// gamePackageRemove: a package from the catalog or the administrator's own,
// while no game server runs anything of it.
func gamePackageRemove(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		ID string `json:"id"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, active, _ := gameRegistry(env)
	for _, pkg := range active {
		if pkg.ID != p.ID {
			continue
		}
		if pkg.Origin == "builtin" {
			return nil, protocol.Invalid("id: a built-in package stays")
		}
		if users := pkgUsers(env, pkg); len(users) > 0 {
			return nil, protocol.Conflict(fmt.Sprintf("game servers %v run %s: delete them first", users, pkg.Name))
		}
		// Other packages extending its game would be left without it.
		if pkg.Game != nil {
			for _, other := range active {
				if other.Extends == pkg.Game.ID {
					return nil, protocol.Conflict("package " + other.ID + " extends " + pkg.Game.ID + ": remove it first")
				}
			}
		}
		if err := os.Remove(filepath.Join(gamePkgDirs(env)[pkg.Origin], pkg.File)); err != nil {
			return nil, err
		}
		return map[string]any{"removed": p.ID}, nil
	}
	return nil, protocol.NotFound("no package " + p.ID)
}
