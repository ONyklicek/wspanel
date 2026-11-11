package ops

import (
	"errors"
	"testing"

	"wspanel/agent/internal/protocol"
)

// A restore or a copy into a database holds only that database: the agent's
// lock for mutating operations would freeze every other client's panel for
// as long as the database takes.
func TestARestoreHoldsItsDatabaseNotTheWholeAgent(t *testing.T) {
	for _, op := range Registry() {
		if (op.Name == "db.restore" || op.Name == "db.copy") && op.Mutating {
			t.Errorf("%s holds the agent's lock for mutating operations all the time it runs", op.Name)
		}
	}
	env, _ := dbEnv(t)
	out, err := call(t, env, dbBackup, map[string]string{"engine": "mariadb", "name": "shop"})
	if err != nil {
		t.Fatal(err)
	}
	release, err := claimDatabase("mariadb", "shop")
	if err != nil {
		t.Fatal(err)
	}
	defer release()
	for name, c := range map[string]struct {
		h      Handler
		params map[string]string
	}{
		"a restore": {dbRestore, map[string]string{"engine": "mariadb", "name": "shop", "backup": out.(backupInfo).File, "confirm": "shop"}},
		"a drop":    {dbDrop, map[string]string{"engine": "mariadb", "name": "shop", "confirm": "shop"}},
		"a copy":    {dbCopy, map[string]string{"engine": "mariadb", "from": "other", "to": "shop"}},
	} {
		_, err := call(t, env, c.h, c.params)
		var oe *protocol.OpError
		if !errors.As(err, &oe) || oe.Code != protocol.CodeConflict {
			t.Errorf("%s into a database being restored was not refused: %v", name, err)
		}
	}
	if _, err := claimDatabase("mariadb", "other"); err != nil {
		t.Error("another database is held too")
	}
}
