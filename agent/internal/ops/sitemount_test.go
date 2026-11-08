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
		"mount": map[string]any{"name": "nas", "type": "smb", "host": "nas.firma.lan", "share": "Data", "path": "/web/uploads/", "user": "web", "domain": "FIRMA"}}); err != nil {
		t.Fatal(err)
	}
	user := "web_nas_example_com"
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-mount-"+user+"-nas.service"))
	for _, want := range []string{
		`ExecStart=/usr/bin/mount -t cifs "//nas.firma.lan/Data/web/uploads" "` + filepath.Join(env.Cfg.SitesRoot, "nas.example.com", "mounts", "nas") + `" -o credentials=`,
		"file_mode=0660,dir_mode=0770,vers=3.0,nosuid,nodev,noexec",
		"WantedBy=multi-user.target",
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
		started = started || (c.Name == "systemctl" && strings.Join(c.Args, " ") == "enable --now wspanel-mount-"+user+"-nas.service")
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
	unit := read2(t, filepath.Join(env.Cfg.SystemdDir, "wspanel-mount-web_ftp_example_com-box.service"))
	for _, want := range []string{`/usr/bin/rclone mount ":ftp:"`, `--ftp-user="u$$HOME%%n"`, "--ftp-explicit-tls", "--read-only", "--default-permissions", "EnvironmentFile="} {
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
	} {
		if _, err := call(t, env, siteMountChange, map[string]any{"domain": "ftp.example.com", "action": "add", "mount": bad}); code(err) != "invalid_params" {
			t.Errorf("%v: want invalid, got %v", bad, err)
		}
	}
}
