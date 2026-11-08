package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestStagingCopiesAsEachSitesOwnUserWithoutTheEnv(t *testing.T) {
	env, dr := testEnv(t)
	env.Cfg.ArtifactDir = t.TempDir()
	for _, d := range []string{"shop.example.com", "staging.shop.example.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "laravel", "php_version": "8.4"}); err != nil {
			t.Fatal(err)
		}
	}
	out, err := call(t, env, sitePack, map[string]string{"domain": "shop.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	name := out.(map[string]any)["artifact"].(string)
	if !artifactNameRe.MatchString(name) {
		t.Errorf("a valid artifact name for deploy.run: %s", name)
	}
	if _, err := os.Stat(filepath.Join(env.Cfg.ArtifactDir, name)); err != nil {
		t.Errorf("the artifact exists: %v", err)
	}
	if _, err := call(t, env, siteCopyShared, map[string]string{"from": "shop.example.com", "to": "staging.shop.example.com"}); err != nil {
		t.Fatal(err)
	}
	var pack, unpack string
	for _, c := range dr.Calls() {
		s := c.String()
		if strings.Contains(s, "/usr/bin/tar --create") && strings.Contains(s, "/shared") {
			pack = s
		}
		if strings.Contains(s, "/usr/bin/tar --extract") {
			unpack = s
		}
	}
	if !strings.Contains(pack, "--reuid=web_shop_example_com") || !strings.Contains(pack, "--exclude=./.env") {
		t.Errorf("read as the source site's user, without .env: %s", pack)
	}
	if !strings.Contains(unpack, "--reuid=web_staging_shop_example_com") || !strings.Contains(unpack, "--no-same-owner") {
		t.Errorf("written as the target site's user: %s", unpack)
	}
	if left, _ := filepath.Glob(filepath.Join(env.Cfg.ArtifactDir, "shared-*")); len(left) != 0 {
		t.Errorf("the temporary archive is removed: %v", left)
	}
}

func TestDBCopyRefusesBadNamesAndItself(t *testing.T) {
	env, _ := testEnv(t)
	for name, p := range map[string]map[string]string{
		"injected": {"engine": "mariadb", "from": "a; DROP", "to": "b"},
		"itself":   {"engine": "mariadb", "from": "shop", "to": "shop"},
	} {
		if _, err := call(t, env, dbCopy, p); code(err) != "invalid_params" {
			t.Errorf("%s: %v", name, err)
		}
	}
}
