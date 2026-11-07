package ops

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/executor"
)

func callLines(dr *executor.DryRun) []string {
	var out []string
	for _, c := range dr.Calls() {
		out = append(out, c.String())
	}
	return out
}

func hasCall(dr *executor.DryRun, prefix string) bool {
	for _, c := range callLines(dr) {
		if strings.HasPrefix(c, prefix) {
			return true
		}
	}
	return false
}

func TestClientSiteUsesTheClientGroupAndFPMLimit(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]any{"domain": "a.example.com", "framework": "laravel", "php_version": "8.3", "group": "wsc_7", "fpm_children": 4}); err != nil {
		t.Fatal(err)
	}
	if !hasCall(dr, "setfacl -m g::--- "+filepath.Join(env.Cfg.SitesRoot, "a.example.com")) {
		t.Error("sites sharing the client's group must not read each other's home")
	}
	if !hasCall(dr, "useradd --system --gid wsc_7 --no-create-home") || hasCall(dr, "useradd --system --user-group") {
		t.Fatalf("site user must get the client's group as primary:\n%s", strings.Join(callLines(dr), "\n"))
	}
	pool, _ := os.ReadFile(filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, "8.3"), "web_a_example_com.conf"))
	if !strings.Contains(string(pool), "pm.max_children = 4") || strings.Contains(string(pool), "\ngroup =") {
		t.Errorf("pool must follow the user's primary group and the package limit:\n%s", pool)
	}
	st, _, _ := loadSite(env, "a.example.com")
	if st.Group != "wsc_7" || st.FPMChildren != 4 {
		t.Errorf("state: %+v", st)
	}
}

func TestSiteWithoutClientKeepsItsOwnGroupAndDefaultLimit(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]any{"domain": "b.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	if !hasCall(dr, "useradd --system --user-group") {
		t.Fatal("an admin site keeps its own group")
	}
	pool, _ := os.ReadFile(filepath.Join(config.ForVersion(env.Cfg.FPMPoolDir, "8.3"), "web_b_example_com.conf"))
	if !strings.Contains(string(pool), "pm.max_children = 10") {
		t.Errorf("default limit:\n%s", pool)
	}
}

func TestClientGroupNamesAreChecked(t *testing.T) {
	env, _ := testEnv(t)
	for _, g := range []string{"root", "wsc_", "wsc_0", "wsc_1;rm", "web_x", "wsc_12345678901"} {
		if _, err := call(t, env, clientCreate, map[string]string{"group": g}); code(err) != "invalid_params" {
			t.Errorf("group %q: %v", g, err)
		}
		if _, err := call(t, env, siteCreate, map[string]any{"domain": "c.example.com", "framework": "laravel", "php_version": "8.3", "group": g}); code(err) != "invalid_params" {
			t.Errorf("site with group %q: %v", g, err)
		}
	}
	if _, err := call(t, env, siteCreate, map[string]any{"domain": "c.example.com", "framework": "laravel", "php_version": "8.3", "fpm_children": 500}); code(err) != "invalid_params" {
		t.Errorf("fpm_children 500: %v", err)
	}
}

func TestClientDeleteRefusedWhileItOwnsASite(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]any{"domain": "d.example.com", "framework": "laravel", "php_version": "8.3", "group": "wsc_3"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, clientDelete, map[string]string{"group": "wsc_3"}); code(err) != "conflict" {
		t.Fatalf("delete with a site: %v", err)
	}
}

func TestClientQuotaIsSetOnlyWhereEnforced(t *testing.T) {
	env, dr := testEnv(t)
	out, err := call(t, env, clientQuota, map[string]any{"group": "wsc_5", "mb": 2048})
	if err != nil {
		t.Fatal(err)
	}
	if out.(map[string]any)["enforced"] != false || hasCall(dr, "setquota") {
		t.Fatalf("no quota on the filesystem: only report it, got %v", out)
	}

	env, dr = testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch c.Name {
		case "findmnt":
			return executor.Result{Stdout: "/srv\n"}, nil
		case "quotaon":
			return executor.Result{Stdout: "group quota on /srv (/dev/vdb1) is on\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err = call(t, env, clientQuota, map[string]any{"group": "wsc_5", "mb": 2048})
	if err != nil {
		t.Fatal(err)
	}
	if out.(map[string]any)["enforced"] != true || !hasCall(dr, "setquota -g wsc_5 2097152 2097152 0 0 /srv") {
		t.Fatalf("quota: %v\n%s", out, strings.Join(callLines(dr), "\n"))
	}
}

func TestSiteMovesToAClientGroup(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]any{"domain": "e.example.com", "framework": "laravel", "php_version": "8.3"}); err != nil {
		t.Fatal(err)
	}
	home := filepath.Join(env.Cfg.SitesRoot, "e.example.com")
	if _, err := call(t, env, siteSetGroup, map[string]any{"domain": "e.example.com", "group": "wsc_9"}); err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"usermod --gid wsc_9 web_e_example_com",
		"chown -R -h --from=:web_e_example_com :wsc_9 " + home,
		"systemctl reload php8.3-fpm",
		"setfacl -m g::--- " + home,
	} {
		if !hasCall(dr, want) {
			t.Errorf("missing %q in:\n%s", want, strings.Join(callLines(dr), "\n"))
		}
	}
	if st, _, _ := loadSite(env, "e.example.com"); st.Group != "wsc_9" {
		t.Errorf("state group %q", st.Group)
	}
}
