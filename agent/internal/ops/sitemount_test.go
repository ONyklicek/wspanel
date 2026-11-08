package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestASiteMountsAnSMBShareWithItsOwnerAndASecretFile(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "nas.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteMountChange, map[string]any{"domain": "nas.example.com", "action": "add", "password": "Tajne-heslo-1",
		"mount": map[string]any{"name": "nas", "type": "smb", "host": "nas.firma.lan", "share": "Data", "path": "/web/uploads/", "user": "web", "domain": "FIRMA", "encrypt": true}}); err != nil {
		t.Fatal(err)
	}
	user := "web_nas_example_com"
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-mount-"+user+".nas.service"))
	for _, want := range []string{
		// Mounted at root's own stage, never through the site user's folders...
		`ExecStart=/usr/bin/mount -t cifs "//nas.firma.lan/Data/web/uploads" "` + filepath.Join(env.Cfg.StateDir, "mnt", "nas.example.com", "nas") + `" -o credentials=`,
		// ...and bound into the home by the agent, symlink-safe.
		`-mount-bind "nas.example.com/nas"`,
		`-mount-unbind "nas.example.com/nas"`,
		"file_mode=0660,dir_mode=0770,vers=3.0,nosuid,nodev,noexec,iocharset=utf8,seal",
		"WantedBy=multi-user.target",
		"Restart=on-failure", "StartLimitIntervalSec=0", // a NAS not up yet at boot is retried
	} {
		if !strings.Contains(unit, want) {
			t.Errorf("missing %q in\n%s", want, unit)
		}
	}
	if strings.Contains(unit, "Tajne-heslo-1") {
		t.Error("the password is in the unit")
	}
	secret := mountSecretFile(env, user, "nas")
	if b := read2(t, secret); b != "username=web\npassword=Tajne-heslo-1\ndomain=FIRMA\n" {
		t.Errorf("secret file: %q", b)
	}
	if fi, _ := os.Stat(secret); fi.Mode().Perm() != 0o600 {
		t.Errorf("secret mode %v", fi.Mode().Perm())
	}
	started := false
	for _, c := range dr.Calls() {
		started = started || (c.Name == "systemctl" && strings.Join(c.Args, " ") == "enable --now wspanel-mount-"+user+".nas.service")
	}
	if !started {
		t.Error("the unit was not started")
	}

	if _, err := call(t, env, siteMountChange, map[string]any{"domain": "nas.example.com", "action": "remove", "mount": map[string]any{"name": "nas"}}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(secret); !os.IsNotExist(err) {
		t.Error("the secret outlived the disk")
	}
	st, _, _ := loadSite(env, "nas.example.com")
	if len(st.Mounts) != 0 {
		t.Errorf("mounts left: %v", st.Mounts)
	}
}

func TestAnFTPDiskGoesThroughRcloneAndRefusesUnitInjection(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "ftp.example.com", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteMountChange, map[string]any{"domain": "ftp.example.com", "action": "add", "password": "x",
		"mount": map[string]any{"name": "box", "type": "ftp", "host": "ftp.example.net", "user": "u$HOME%n", "tls": "explicit", "read_only": true}}); err != nil {
		t.Fatal(err)
	}
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-mount-web_ftp_example_com.box.service"))
	for _, want := range []string{`/usr/bin/rclone mount ":ftp:"`, "--no-modtime", `--ftp-user="u$$HOME%%n"`, "--ftp-explicit-tls", "--read-only", "--default-permissions", "EnvironmentFile="} {
		if !strings.Contains(unit, want) {
			t.Errorf("missing %q in\n%s", want, unit)
		}
	}
	for _, bad := range []map[string]any{
		{"name": "a", "type": "smb", "host": "h", "share": "s", "user": "u\nExecStart=/bin/sh"},
		{"name": "b", "type": "smb", "host": "h", "share": "s/../etc", "user": "u"},
		{"name": "c", "type": "sftp", "host": "-oProxyCommand=x", "user": "u"},
		{"name": "d", "type": "ftp", "host": "h", "user": "u", "path": "../.."},
		{"name": "E", "type": "ftp", "host": "h", "user": "u"},
		{"name": "f", "type": "smb", "host": "h", "share": "s", "user": "u", "version": "2.1", "encrypt": true},
	} {
		if _, err := call(t, env, siteMountChange, map[string]any{"domain": "ftp.example.com", "action": "add", "mount": bad}); code(err) != "invalid_params" {
			t.Errorf("%v: want invalid, got %v", bad, err)
		}
	}
}

func TestAnSFTPDiskTrustsOnlyTheHostKeySeenWhenAdded(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "s.example.com", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, siteMountChange, map[string]any{"domain": "s.example.com", "action": "add", "password": "x",
		"mount": map[string]any{"name": "box", "type": "sftp", "host": "sftp.example.net", "user": "u"}})
	if err != nil {
		t.Fatal(err)
	}
	known := mountKnownHosts(env, "web_s_example_com", "box")
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-mount-web_s_example_com.box.service"))
	if !strings.Contains(unit, `--sftp-known-hosts-file="`+known+`"`) {
		t.Errorf("no known_hosts in\n%s", unit)
	}
	if !strings.Contains(read2(t, known), "ssh-ed25519 ") {
		t.Error("the host key was not recorded")
	}
	if keys, _ := out.(map[string]any)["host_keys"].([]string); len(keys) != 1 || !strings.HasPrefix(keys[0], "ssh-ed25519 SHA256:") {
		t.Errorf("fingerprints: %v", out)
	}
}

func TestDeletingASiteUnmountsOnlyItsOwnDisks(t *testing.T) {
	env, _ := testEnv(t)
	for _, d := range []string{"x.cz", "x.cz-shop.com"} { // web_x_cz is a prefix of web_x_cz-shop_com
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "php", "php_version": "8.4"}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := call(t, env, siteMountChange, map[string]any{"domain": "x.cz-shop.com", "action": "add", "password": "x",
		"mount": map[string]any{"name": "nas", "type": "ftp", "host": "h", "user": "u"}}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteDelete, map[string]string{"domain": "x.cz", "confirm": "x.cz"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(env.Cfg.SystemdDir, "wspanel-mount-web_x_cz-shop_com.nas.service")); err != nil {
		t.Error("deleting x.cz removed the disk of x.cz-shop.com")
	}
}

func TestDiskNamesNeverCollideAcrossSites(t *testing.T) {
	env, _ := testEnv(t)
	for _, d := range []string{"x.cz", "x.cz-shop.com"} {
		if _, err := call(t, env, siteCreate, map[string]string{"domain": d, "framework": "php", "php_version": "8.4"}); err != nil {
			t.Fatal(err)
		}
	}
	add := func(domain, name string) {
		t.Helper()
		if _, err := call(t, env, siteMountChange, map[string]any{"domain": domain, "action": "add", "password": "secret-" + domain,
			"mount": map[string]any{"name": name, "type": "smb", "host": "h", "share": "s", "user": "u"}}); err != nil {
			t.Fatal(err)
		}
	}
	add("x.cz-shop.com", "nas")
	add("x.cz", "shop_com-nas") // web_x_cz + shop_com-nas against web_x_cz-shop_com + nas
	if !strings.Contains(read2(t, mountSecretFile(env, "web_x_cz-shop_com", "nas")), "secret-x.cz-shop.com") {
		t.Error("the other site's password was overwritten")
	}
	if mountUnit("web_x_cz", "shop_com-nas") == mountUnit("web_x_cz-shop_com", "nas") {
		t.Error("unit names collide")
	}
}
