package ops

import (
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
)

// Another user of the server must not reach a site's own server around the
// edge; the edge, the agent and the site itself still do.
func TestASitesOwnPortsAreForTheEdgeTheAgentAndTheSiteOnly(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.com", "mode": "node", "framework": "nuxt", "node_version": "22"}); err != nil {
		t.Fatal(err)
	}
	_, st := layoutFor(t, env, "app.example.com")
	if st.Port == 0 {
		t.Fatal("the Node site got no port")
	}
	rules, err := os.ReadFile(filepath.Join(env.Cfg.StateDir, "site-guard.nft"))
	if err != nil {
		t.Fatal("no guard was written when the site took its port")
	}
	port := strconv.Itoa(st.Port)
	for _, want := range []string{
		"elements = { " + port, // guarded
		port + " . 0",          // the agent
		`oifname "lo" tcp dport . meta skuid @allowed accept`,
		`oifname "lo" tcp dport @guarded reject`, // loopback only: the same port elsewhere is untouched
	} {
		if !strings.Contains(string(rules), want) {
			t.Errorf("guard lacks %q:\n%s", want, rules)
		}
	}
	var loaded bool
	for _, c := range dr.Calls() {
		if c.Name == "nft" && strings.HasSuffix(strings.Join(c.Args, " "), "site-guard.nft") {
			loaded = true
		}
	}
	if !loaded {
		t.Error("the guard was not loaded")
	}
	if unit := read(t, filepath.Join(env.Cfg.SystemdDir, siteGuardUnit)); !strings.Contains(unit, "Before=network-pre.target") {
		t.Error("the guard is not loaded at boot before the sites' servers start")
	}

	if _, err := call(t, env, siteDelete, map[string]string{"domain": "app.example.com", "confirm": "app.example.com"}); err != nil {
		t.Fatal(err)
	}
	rules, _ = os.ReadFile(filepath.Join(env.Cfg.StateDir, "site-guard.nft"))
	if strings.Contains(string(rules), port) {
		t.Errorf("the deleted site's port stays guarded for its old user:\n%s", rules)
	}
}
