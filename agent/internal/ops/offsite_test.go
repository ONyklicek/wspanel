package ops

import (
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

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
