package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// A site's workers stop before its Valkey and the database servers: a
// Horizon whose Valkey went first held a shutdown for its hour-long stop
// timeout (seen on a VPS rebooting after an upgrade).
func TestSiteProcessesStopBeforeTheirValkeyAndDatabases(t *testing.T) {
	env, dr := dbEnv(t)
	dr.Responder = frankenVersion
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "shop.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	_, l, err := loadSite(env, "shop.example.com")
	if err != nil {
		t.Fatal(err)
	}
	want := "After=network.target wspanel-" + l.User + "-valkey.service mariadb.service postgresql.service mongod.service"
	unit, err := render("unit.service.tmpl", workerUnitDataFor(env, l, "horizon", workerSpecs["laravel"]["horizon"]))
	if err != nil || !strings.Contains(string(unit), "\n"+want+"\n") {
		t.Fatalf("new worker unit:\n%s %v", unit, err)
	}
	// An existing one from before: only its After= line is brought along.
	old := strings.Replace(string(unit), want, "After=network.target", 1)
	path := filepath.Join(env.Cfg.SystemdDir, "wspanel-"+l.User+"-horizon@.service")
	if err := os.WriteFile(path, []byte(old), 0o644); err != nil {
		t.Fatal(err)
	}
	if n := renderSiteOrder(env); n != 1 {
		t.Fatalf("changed %d", n)
	}
	if got, _ := os.ReadFile(path); string(got) != string(unit) {
		t.Errorf("rewritten:\n%s", got)
	}
	if n := renderSiteOrder(env); n != 0 {
		t.Errorf("a second pass changed %d", n)
	}
}
