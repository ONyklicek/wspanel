package ops

import (
	"os"
	"path/filepath"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
)

// writeToolLinks maintains /etc/panel-sites/bin/<user>, which /etc/profile.d
// puts first on the site user's PATH: `php` is the site's PHP (FPM CLI of the
// same version, or FrankenPHP's own PHP CLI in worker mode), python the release's
// virtualenv (Python sites) and node/npm/npx the site's Node (or the newest
// installed, for asset builds of PHP and Python sites).
// Root-owned: the user can use the links, not change them.
func writeToolLinks(env *Env, l siteLayout) error {
	dir := filepath.Join(env.Cfg.ToolsDir, l.User)
	if err := env.FS.MkdirAll(env.Cfg.ToolsDir, 0o755, fsx.Root); err != nil {
		return err
	}
	tmp := dir + ".new"
	_ = os.RemoveAll(tmp)
	if err := env.FS.Mkdir(tmp, 0o755, fsx.Root); err != nil {
		return err
	}
	switch l.Mode {
	case "classic":
		if err := env.FS.Symlink(config.ForVersion(env.Cfg.PHPCLIBinary, l.PHPVersion), filepath.Join(tmp, "php"), fsx.Root); err != nil {
			return err
		}
	case "worker":
		if cli := frankenCLI(env); cli != "" {
			if err := env.FS.Symlink(cli, filepath.Join(tmp, "php"), fsx.Root); err != nil {
				return err
			}
			break
		}
		wrapper := "#!/bin/sh\nexec " + env.Cfg.FrankenPHPBin + " php-cli \"$@\"\n"
		if err := env.FS.WriteFile(filepath.Join(tmp, "php"), []byte(wrapper), 0o755, fsx.Root); err != nil {
			return err
		}
	}
	if l.Mode == "python" {
		// The release's virtualenv: manage.py, pip list, … over SSH as the app sees them.
		for _, tool := range []string{"python", "python3"} {
			if err := env.FS.Symlink(filepath.Join(l.Home, "current", ".venv", "bin", "python"), filepath.Join(tmp, tool), fsx.Root); err != nil {
				return err
			}
		}
	}
	versions := nodeVersions(env.Cfg)
	major := l.NodeVersion
	if major == "" {
		if m := nodeMajors(env.Cfg); len(m) > 0 {
			major = m[len(m)-1]
		}
	}
	if bin, ok := versions[major]; ok {
		for _, tool := range []string{"node", "npm", "npx", "corepack"} {
			target := filepath.Join(filepath.Dir(bin), tool)
			if fsx.Exists(target) || tool == "node" {
				if err := env.FS.Symlink(target, filepath.Join(tmp, tool), fsx.Root); err != nil {
					return err
				}
			}
		}
	}
	_ = os.RemoveAll(dir)
	return os.Rename(tmp, dir)
}

func removeToolLinks(env *Env, user string) {
	_ = os.RemoveAll(filepath.Join(env.Cfg.ToolsDir, user))
}
