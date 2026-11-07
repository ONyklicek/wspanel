package ops

import (
	"fmt"
	"maps"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/s3/s3test"
)

func offsiteEnv(t *testing.T) (*Env, *s3test.Fake, string) {
	env, _, _ := backupEnv(t)
	fake := s3test.New("backups", "AK")
	srv := httptest.NewServer(fake)
	t.Cleanup(srv.Close)
	return env, fake, srv.URL
}

func setOffsite(t *testing.T, env *Env, endpoint string, extra map[string]any) (any, error) {
	p := map[string]any{"enabled": true, "endpoint": endpoint, "bucket": "backups", "prefix": "/srv1/", "path_style": true, "access_key": "AK", "secret_key": "SK", "keep_days": 7}
	for k, v := range extra {
		p[k] = v
	}
	return call(t, env, offsiteSet, p)
}

func TestOffsiteSetupTestsTheStorageAndHidesTheSecret(t *testing.T) {
	env, fake, url := offsiteEnv(t)
	if _, err := setOffsite(t, env, url, map[string]any{"access_key": "WRONG"}); code(err) != protocol.CodeInvalidParams || !strings.Contains(err.Error(), "AccessDenied") {
		t.Fatalf("wrong credentials accepted: %v", err)
	}
	if _, err := os.Stat(offsitePath(env)); !os.IsNotExist(err) {
		t.Fatal("saved although the test failed")
	}
	for _, bad := range []map[string]any{{"endpoint": "http://s3.example.com"}, {"bucket": "Bad Bucket"}, {"prefix": "../x"}, {"keep_days": 0}} {
		if _, err := setOffsite(t, env, url, bad); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v accepted", bad)
		}
	}
	out, err := setOffsite(t, env, url, nil)
	if err != nil {
		t.Fatal(err)
	}
	if got := out.(map[string]any); got["has_secret"] != true || got["config"].(offsiteConfig).SecretKey != "" || got["config"].(offsiteConfig).Prefix != "srv1" {
		t.Fatalf("%+v", got)
	}
	if len(fake.Keys()) != 0 {
		t.Fatal("test object left behind", fake.Keys())
	}
	// Saving again without the secret keeps it.
	if _, err := setOffsite(t, env, url, map[string]any{"secret_key": "", "keep_days": 14}); err != nil {
		t.Fatal(err)
	}
	if c := loadOffsite(env); c.SecretKey != "SK" || c.KeepDays != 14 {
		t.Fatalf("%+v", c)
	}
	if fi, _ := os.Stat(offsitePath(env)); fi.Mode().Perm() != 0o600 {
		t.Fatal(fi.Mode())
	}
}

func TestBackupsAreCopiedOffsitePrunedAndFetchedBack(t *testing.T) {
	env, fake, url := offsiteEnv(t)
	if _, err := setOffsite(t, env, url, nil); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, siteBackup, map[string]any{"domain": "app.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	b := out.(backupInfo)
	if b.Offsite != "srv1/sites/app.example.com/"+b.File || b.OffsiteError != "" {
		t.Fatalf("%+v", b)
	}
	local, _ := os.ReadFile(b.Path)
	if string(fake.Objects[b.Offsite]) != string(local) {
		t.Fatal("the copy differs")
	}

	// Old copies go, the newest stays even when old, foreign objects stay.
	old := "srv1/sites/app.example.com/app.example.com-20200101T000000Z.tar.gz"
	foreign := "srv1/sites/app.example.com/notes.txt"
	fake.Objects[old], fake.Modified[old] = []byte("x"), time.Now().AddDate(-1, 0, 0)
	fake.Objects[foreign], fake.Modified[foreign] = []byte("x"), time.Now().AddDate(-1, 0, 0)
	if _, err := call(t, env, offsiteUpload, map[string]any{"domain": "app.example.com", "file": b.File}); err != nil {
		t.Fatal(err)
	}
	if _, ok := fake.Objects[old]; ok {
		t.Fatal("old copy kept")
	}
	if _, ok := fake.Objects[foreign]; !ok {
		t.Fatal("a foreign object was deleted")
	}

	list, err := call(t, env, offsiteList, map[string]any{"domain": "app.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	if objs := list.(map[string]any)["objects"].([]map[string]any); len(objs) != 1 || objs[0]["file"] != b.File {
		t.Fatalf("%v", objs)
	}

	// Fetch back after the local file is gone.
	_ = os.Remove(b.Path)
	if _, err := call(t, env, offsiteFetch, map[string]any{"domain": "app.example.com", "file": b.File}); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(b.Path); string(got) != string(local) {
		t.Fatal("fetched copy differs")
	}
	if _, err := call(t, env, offsiteFetch, map[string]any{"domain": "app.example.com", "file": b.File}); code(err) != protocol.CodeConflict {
		t.Fatal("overwrote a local backup")
	}
	for _, bad := range []string{"../../x.tar.gz", "other.com-20260101T000000Z.tar.gz", "notes.txt"} {
		if _, err := call(t, env, offsiteFetch, map[string]any{"domain": "app.example.com", "file": bad}); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%s fetched", bad)
		}
	}
	if left, _ := filepath.Glob(filepath.Join(filepath.Dir(b.Path), ".fetch-*")); len(left) != 0 {
		t.Fatal(left)
	}
}

func TestAFailedCopyKeepsTheBackup(t *testing.T) {
	env, fake, url := offsiteEnv(t)
	if _, err := setOffsite(t, env, url, nil); err != nil {
		t.Fatal(err)
	}
	fake.Fail = 100
	out, err := call(t, env, dbBackup, map[string]any{"engine": "mariadb", "name": "app"})
	if err != nil {
		t.Fatal(err)
	}
	b := out.(backupInfo)
	if b.OffsiteError == "" || b.Offsite != "" {
		t.Fatalf("%+v", b)
	}
	if _, err := os.Stat(b.Path); err != nil {
		t.Fatal("local backup lost")
	}
	if _, err := RunScheduledBackup(t.Context(), env, "mariadb/app", 3); err == nil {
		t.Fatal("a scheduled backup without its off-site copy must fail the unit")
	}
}

func TestAFetchedCopyMustBeSignedByThisServer(t *testing.T) {
	env, fake, url := offsiteEnv(t)
	if _, err := setOffsite(t, env, url, nil); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, siteBackup, map[string]any{"domain": "app.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	b := out.(backupInfo)
	if _, ok := fake.Objects[b.Offsite+".sig"]; !ok {
		t.Fatal("no signature uploaded")
	}
	_ = os.Remove(b.Path)

	// Someone with access to the bucket swaps the archive.
	original := fake.Objects[b.Offsite]
	fake.Objects[b.Offsite] = append([]byte(nil), original...)
	fake.Objects[b.Offsite][len(original)-1] ^= 0xff
	fetch := map[string]any{"domain": "app.example.com", "file": b.File}
	if _, err := call(t, env, offsiteFetch, fetch); code(err) != protocol.CodeInvalidParams || !strings.Contains(err.Error(), "signature") {
		t.Fatalf("a changed copy was accepted: %v", err)
	}
	if _, err := os.Stat(b.Path); !os.IsNotExist(err) {
		t.Fatal("the refused copy was kept")
	}
	// Only on explicit request, and it says so.
	fetch["unverified"] = true
	res, err := call(t, env, offsiteFetch, fetch)
	if err != nil || res.(map[string]any)["verified"] != false {
		t.Fatalf("%v %v", res, err)
	}
	_ = os.Remove(b.Path)

	// A new server verifies the old server's copies once it has its key.
	fake.Objects[b.Offsite] = original
	k, _ := call(t, env, offsiteSigningKey, nil)
	oldKey := k.(map[string]any)["signing_key"].(string)
	_ = os.Remove(signingKeyPath(env))
	if _, err := call(t, env, offsiteFetch, map[string]any{"domain": "app.example.com", "file": b.File}); err == nil {
		t.Fatal("verified with a different key")
	}
	if _, err := setOffsite(t, env, url, map[string]any{"signing_key": oldKey}); err != nil {
		t.Fatal(err)
	}
	res, err = call(t, env, offsiteFetch, map[string]any{"domain": "app.example.com", "file": b.File})
	if err != nil || res.(map[string]any)["verified"] != true {
		t.Fatalf("%v %v", res, err)
	}
	if _, err := setOffsite(t, env, url, map[string]any{"signing_key": "abc"}); code(err) != protocol.CodeInvalidParams {
		t.Fatal("a broken key was imported")
	}
}

func TestARestoreOnlyTouchesTheDatabasesItWasGiven(t *testing.T) {
	env, dr, _ := backupEnv(t)
	// The backup holds app and (as if planted) another site's database.
	out, err := call(t, env, siteBackup, map[string]any{"domain": "app.example.com", "databases": []map[string]string{{"engine": "mariadb", "name": "app"}, {"engine": "mariadb", "name": "other_db"}}})
	if err != nil {
		t.Fatal(err)
	}
	res, err := call(t, env, siteRestore, map[string]any{"domain": "app.example.com", "backup": out.(backupInfo).File, "files": false,
		"databases": []map[string]string{{"engine": "mariadb", "name": "app"}}, "confirm": "app.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	if got := res.(map[string]any)["databases"].([]string); len(got) != 1 || got[0] != "app" {
		t.Fatalf("restored %v", got)
	}
	for _, c := range dr.Calls() {
		if c.Name == "mariadb" && slices.Contains(c.Args, "other_db") {
			t.Fatal("another database was restored")
		}
	}
}

func TestSMBStoreDrivesSmbclientWithoutThePasswordOnTheCommandLine(t *testing.T) {
	env, dr, _ := backupEnv(t)
	remote := map[string][]byte{}
	var authSeen string
	site := dr.Responder
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name != "smbclient" {
			return site(c)
		}
		i := slices.Index(c.Args, "-A")
		b, _ := os.ReadFile(c.Args[i+1])
		authSeen = string(b)
		cmd := c.Args[len(c.Args)-1]
		arg := func(n int) string { return strings.Split(cmd, `"`)[2*n+1] }
		switch {
		case strings.HasPrefix(cmd, "mkdir"):
			return executor.Result{}, nil
		case strings.HasPrefix(cmd, "put"):
			data, _ := os.ReadFile(arg(0))
			remote[arg(1)] = data
		case strings.HasPrefix(cmd, "get"):
			data, ok := remote[arg(0)]
			if !ok {
				return executor.Result{Stdout: "NT_STATUS_OBJECT_NAME_NOT_FOUND opening remote file"}, nil
			}
			_ = os.WriteFile(arg(1), data, 0o600)
		case strings.HasPrefix(cmd, "del"):
			delete(remote, arg(0))
		case strings.HasPrefix(cmd, "ls"):
			dir := strings.TrimSuffix(arg(0), `\*`)
			out := "  .                                   D        0  Fri Nov  7 10:00:00 2026\n"
			for k, v := range remote {
				if strings.HasPrefix(k, dir+`\`) && !strings.Contains(strings.TrimPrefix(k, dir+`\`), `\`) {
					out += fmt.Sprintf("  %-35s A %8d  Sat Nov  7 19:01:48 2026\n", strings.TrimPrefix(k, dir+`\`), len(v))
				}
			}
			return executor.Result{Stdout: out + "\n\t\t123 blocks of size 1024. 100 blocks available\n"}, nil
		}
		return executor.Result{}, nil
	}
	cfg := map[string]any{"enabled": true, "type": "smb", "host": "nas.lan", "share": "zalohy", "username": "backup",
		"password": "Tajne heslo", "smb_domain": "WORKGROUP", "smb_encrypt": true, "prefix": "srv1", "keep_days": 7}
	if _, err := call(t, env, offsiteSet, cfg); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, siteBackup, map[string]any{"domain": "app.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	b := out.(backupInfo)
	if b.OffsiteError != "" || remote[`srv1\sites\app.example.com\`+b.File] == nil || remote[`srv1\sites\app.example.com\`+b.File+".sig"] == nil {
		t.Fatalf("%+v %v", b, len(remote))
	}
	if !strings.Contains(authSeen, "password = Tajne heslo") || !strings.Contains(authSeen, "domain = WORKGROUP") {
		t.Fatal(authSeen)
	}
	for _, c := range dr.Calls() {
		if c.Name == "smbclient" {
			if strings.Contains(strings.Join(c.Args, " "), "Tajne") {
				t.Fatal("password on the command line")
			}
			if !slices.Contains(c.Args, "--client-protection=encrypt") || c.Args[0] != "//nas.lan/zalohy" || c.Args[slices.Index(c.Args, "-p")+1] != "445" {
				t.Fatal(c.Args)
			}
		}
	}
	if left, _ := filepath.Glob(filepath.Join(env.Cfg.StateDir, "offsite-tmp", ".smb-auth-*")); len(left) != 0 {
		t.Fatal("credentials file left behind")
	}
	list, err := call(t, env, offsiteList, map[string]any{"domain": "app.example.com"})
	if err != nil {
		t.Fatal(err)
	}
	if objs := list.(map[string]any)["objects"].([]map[string]any); len(objs) != 1 || objs[0]["file"] != b.File {
		t.Fatalf("%v", objs)
	}
	_ = os.Remove(b.Path)
	if res, err := call(t, env, offsiteFetch, map[string]any{"domain": "app.example.com", "file": b.File}); err != nil || res.(map[string]any)["verified"] != true {
		t.Fatalf("%v %v", res, err)
	}
	// Bad input never reaches smbclient.
	for _, bad := range []map[string]any{{"host": "-oProxy"}, {"share": `a"b`}, {"username": `dom\user`}} {
		c := maps.Clone(cfg)
		maps.Copy(c, bad)
		if _, err := call(t, env, offsiteSet, c); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v accepted", bad)
		}
	}
}
