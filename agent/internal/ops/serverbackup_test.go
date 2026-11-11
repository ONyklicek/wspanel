package ops

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/rand"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

func TestBackupEncryptionRoundTripsAndDetectsTampering(t *testing.T) {
	key := make([]byte, 32)
	_, _ = rand.Read(key)
	plain := bytes.Repeat([]byte("WSPanel "), backupChunk/4) // two and a bit chunks
	var enc bytes.Buffer
	if err := encryptBackup(&enc, bytes.NewReader(plain), key); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	if err := decryptBackup(&out, bytes.NewReader(enc.Bytes()), key); err != nil || !bytes.Equal(out.Bytes(), plain) {
		t.Fatalf("round trip: %v", err)
	}
	other := make([]byte, 32)
	_, _ = rand.Read(other)
	if err := decryptBackup(&bytes.Buffer{}, bytes.NewReader(enc.Bytes()), other); err == nil {
		t.Error("the wrong recovery key opened it")
	}
	// Cut after the first chunk: that chunk was not sealed as the last one.
	first := len(backupMagic) + 8 + 4 + backupChunk + 16
	if err := decryptBackup(&bytes.Buffer{}, bytes.NewReader(enc.Bytes()[:first]), key); err == nil {
		t.Error("a backup cut short opened")
	}
	changed := bytes.Clone(enc.Bytes())
	changed[len(changed)-5] ^= 1
	if err := decryptBackup(&bytes.Buffer{}, bytes.NewReader(changed), key); err == nil {
		t.Error("a changed backup opened")
	}
}

func TestServerBackupHoldsTheStateAndGoesBesideTheMail(t *testing.T) {
	env, _ := mailTestEnv(t)
	env.Cfg.BackupDir = filepath.Join(t.TempDir(), "backups")
	env.Cfg.MailTransport = filepath.Join(t.TempDir(), "transport")
	if _, err := call(t, env, siteCreate, map[string]any{"domain": "shop.cz", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true}); err != nil {
		t.Fatal(err)
	}
	msg := filepath.Join(env.Cfg.VmailRoot, "firma.cz", "alice", "Maildir", "cur", "1.eml")
	if err := os.MkdirAll(filepath.Dir(msg), 0o700); err != nil {
		t.Fatal(err)
	}
	_ = os.WriteFile(msg, []byte("Subject: hi\n\nhello"), 0o600)
	// A game server that starts with the system.
	if err := saveGame(env, gameState{ID: 3, Name: "x", Game: "factorio", Version: "2.1.24", Port: 34197}); err != nil {
		t.Fatal(err)
	}
	wants := filepath.Join(env.Cfg.SystemdDir, "multi-user.target.wants")
	_ = os.MkdirAll(wants, 0o755)
	_ = os.Symlink("../wspanel-game-3.service", filepath.Join(wants, "wspanel-game-3.service"))

	res, err := call(t, env, serverBackup, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	out := res.(map[string]any)
	path := filepath.Join(serverBackupsDir(env), out["backup"].(string))
	if fi, err := os.Stat(path); err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("server backup: %v %v", fi, err)
	}
	key, _ := signingKey(env)
	man, err := readServerManifest(path, key)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(man.Sites, ",") != "shop.cz" || strings.Join(man.MailDomains, ",") != "firma.cz" || man.Files == 0 {
		t.Errorf("manifest: %+v", man)
	}
	if len(man.Games) != 1 || man.Games[0] != 3 || strings.Join(man.GameUnits, ",") != "wspanel-game-3.service" || slices.Contains(man.Enabled, "wspanel-game-3.service") {
		t.Errorf("game servers in the manifest: %v %v (enabled %v)", man.Games, man.GameUnits, man.Enabled)
	}
	var names []string
	_ = openServerArchive(path, key, func(_ *tar.Reader, h *tar.Header) (bool, error) {
		names = append(names, h.Name)
		return false, nil
	})
	all := strings.Join(names, "\n")
	for _, want := range []string{"fs" + siteStatePath(env.Cfg, "shop.cz"), "fs" + filepath.Join(env.Cfg.StateDir, "mail.json"), "fs" + gameStatePath(env, 3)} {
		if !strings.Contains(all, want) {
			t.Errorf("%s is not in the backup:\n%s", want, all)
		}
	}
	if !strings.Contains(all, "fs"+env.Cfg.ProxySitesDir) {
		t.Errorf("the edge blocks are not in it:\n%s", all)
	}
	if strings.Contains(all, "1.eml") {
		t.Error("mail belongs to the mail backup, not here")
	}
	mail := listLocal(mailTarget(env, "firma.cz"))
	if len(mail) != 1 {
		t.Fatalf("mail backups: %v", mail)
	}
	if _, err := readServerManifest(path, make([]byte, 32)); err == nil {
		t.Error("opened with a wrong key")
	}
}

func TestServerStateAndMailComeBackFromTheBackup(t *testing.T) {
	env, _ := mailTestEnv(t)
	env.Cfg.BackupDir = filepath.Join(t.TempDir(), "backups")
	env.Cfg.MailTransport = filepath.Join(t.TempDir(), "transport")
	env.Cfg.DovecotUsers = filepath.Join(t.TempDir(), "dovecot", "users")
	_ = os.MkdirAll(filepath.Dir(env.Cfg.DovecotUsers), 0o755)
	if _, err := call(t, env, siteCreate, map[string]any{"domain": "shop.cz", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, mailDomainSet, map[string]any{"domain": "firma.cz", "enabled": true}); err != nil {
		t.Fatal(err)
	}
	msg := filepath.Join(env.Cfg.VmailRoot, "firma.cz", "alice", "Maildir", "cur", "1.eml")
	_ = os.MkdirAll(filepath.Dir(msg), 0o700)
	_ = os.WriteFile(msg, []byte("Subject: hi\n\nhello"), 0o600)
	res, err := call(t, env, serverBackup, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(serverBackupsDir(env), res.(map[string]any)["backup"].(string))
	key, _ := signingKey(env)

	// A new server: none of it is there.
	state := siteStatePath(env.Cfg, "shop.cz")
	_ = os.Remove(state)
	_ = os.RemoveAll(env.Cfg.ProxySitesDir)
	_ = os.RemoveAll(env.Cfg.VmailRoot)

	man, rep, err := RestoreServerState(context.Background(), env, path, key)
	if err != nil {
		t.Fatalf("%v %+v", err, rep)
	}
	if b, err := os.ReadFile(state); err != nil || !strings.Contains(string(b), `"shop.cz"`) {
		t.Errorf("the site's state is not back: %v", err)
	}
	if edge, _ := filepath.Glob(filepath.Join(env.Cfg.ProxySitesDir, "*")); len(edge) == 0 {
		t.Error("the edge blocks are not back")
	}
	src, err := OpenBackupSource(context.Background(), env, env.Cfg.BackupDir)
	if err != nil {
		t.Fatal(err)
	}
	data := RestoreServerData(context.Background(), env, serverManifest{MailDomains: man.MailDomains}, src, func(string, ...any) {})
	if b, err := os.ReadFile(msg); err != nil || string(b) != "Subject: hi\n\nhello" {
		t.Errorf("the mail is not back: %v %+v", err, data)
	}
}

func TestARestoreWritesOnlyWhereWSPanelKeepsItsFiles(t *testing.T) {
	env, _ := mailTestEnv(t)
	env.Cfg.BackupDir = filepath.Join(t.TempDir(), "backups")
	key, _ := signingKey(env)
	outside := filepath.Join(t.TempDir(), "etc-passwd")
	_ = os.WriteFile(outside, []byte("root:x:0:0::/root:/bin/bash\n"), 0o644)
	plain := filepath.Join(t.TempDir(), "plain.tar.gz")
	man := serverManifest{Format: serverBackupFormat}
	if err := writeServerArchive(env, plain, &man, []string{outside}, nil, time.Now()); err != nil {
		t.Fatal(err)
	}
	enc := filepath.Join(t.TempDir(), "server-20260101T000000Z.wspbak")
	in, _ := os.Open(plain)
	out, _ := os.Create(enc)
	if err := encryptBackup(out, in, key); err != nil {
		t.Fatal(err)
	}
	in.Close()
	out.Close()
	_ = os.WriteFile(outside, []byte("changed\n"), 0o644)

	_, rep, err := RestoreServerState(context.Background(), env, enc, key)
	if err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(outside); string(b) != "changed\n" {
		t.Error("a path outside WSPanel's was written")
	}
	if !strings.Contains(strings.Join(rep.Problems, "\n"), "left out") {
		t.Errorf("not reported: %+v", rep)
	}
}
