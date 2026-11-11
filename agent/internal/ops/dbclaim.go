package ops

import (
	"sync"

	"wspanel/agent/internal/protocol"
)

// A restore or a copy into a database takes as long as the database is big
// (an hour is no exception). It used to hold the agent's lock for mutating
// operations all that time, so one client's restore froze everybody's
// panel: their settings, their databases, the panel's own sign-ins to
// phpMyAdmin. It now claims only the database it writes; an operation that
// replaces or drops that same database is refused meanwhile.
var claimedDBs = struct {
	sync.Mutex
	m map[string]bool
}{m: map[string]bool{}}

// claimDatabase gives an operation exclusive use of engine/name; the
// returned function gives it back.
func claimDatabase(engine, name string) (func(), error) {
	key := engine + "/" + name
	claimedDBs.Lock()
	defer claimedDBs.Unlock()
	if claimedDBs.m[key] {
		return nil, protocol.Conflict("a restore or a copy into " + name + " is running")
	}
	claimedDBs.m[key] = true
	return func() {
		claimedDBs.Lock()
		delete(claimedDBs.m, key)
		claimedDBs.Unlock()
	}, nil
}

// engineName is the engine's name as operations take it.
func engineName(e dbEngine) string {
	switch e.(type) {
	case mariadb, *mariadb:
		return "mariadb"
	case postgres, *postgres:
		return "postgresql"
	default:
		return "mongodb"
	}
}
