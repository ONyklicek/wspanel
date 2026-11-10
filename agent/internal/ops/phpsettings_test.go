package ops

import (
	"errors"
	"os"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

func TestPHPSettingsGoIntoThePool(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	out, err := call(t, env, sitePHP, map[string]any{"domain": "app.example.com", "settings": map[string]string{
		"memory_limit": "512m", "upload_max_filesize": "64M", "date.timezone": "Europe/Prague", "display_errors": "on",
		"error_reporting": "production", "allow_url_fopen": "off", "max_input_vars": "",
	}})
	if err != nil {
		t.Fatal(err)
	}
	if got := out.(map[string]any)["settings"].(map[string]string); got["memory_limit"] != "512M" || len(got) != 6 {
		t.Fatalf("normalized settings: %v", got)
	}
	l, _ := layoutFor(t, env, "app.example.com")
	pool, _ := os.ReadFile(l.PoolFile)
	for _, want := range []string{
		"php_value[memory_limit] = 512M", "php_value[upload_max_filesize] = 64M", "php_value[date.timezone] = Europe/Prague",
		"php_value[error_reporting] = 22527", "php_flag[allow_url_fopen] = off", "php_admin_flag[display_errors] = on",
	} {
		if !strings.Contains(string(pool), want) {
			t.Errorf("pool missing %q:\n%s", want, pool)
		}
	}
	if strings.Count(string(pool), "display_errors") != 1 {
		t.Fatal("display_errors set twice")
	}
	if !ran(dr, "systemctl reload php8.4-fpm.service") {
		t.Fatal("FPM not reloaded")
	}

	// Back to the defaults: the pool loses the lines, errors are hidden again.
	if _, err := call(t, env, sitePHP, map[string]any{"domain": "app.example.com", "settings": map[string]string{}}); err != nil {
		t.Fatal(err)
	}
	pool, _ = os.ReadFile(l.PoolFile)
	if strings.Contains(string(pool), "memory_limit") || !strings.Contains(string(pool), "php_admin_flag[display_errors] = off") {
		t.Fatalf("defaults not restored:\n%s", pool)
	}
}

func TestPHPSettingsAreValidated(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []map[string]string{
		{"disable_functions": ""},                      // not settable
		{"open_basedir": "/"},                          // not settable
		{"memory_limit": "512M\nphp_admin_value[x]=1"}, // injection
		{"memory_limit": "99G"},
		{"upload_max_filesize": "lots"},
		{"max_execution_time": "-5"},
		{"date.timezone": "Mars/Base"},
		{"date.timezone": "../../etc"},
		{"display_errors": "maybe"},
		{"error_reporting": "E_ALL"},
		{"worker_threads": "4"}, // classic site
	} {
		if _, err := call(t, env, sitePHP, map[string]any{"domain": "app.example.com", "settings": bad}); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v accepted: %v", bad, err)
		}
	}
}

func TestBrokenPoolIsRolledBack(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "app.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	l, _ := layoutFor(t, env, "app.example.com")
	before, _ := os.ReadFile(l.PoolFile)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "php-fpm8.4" {
			return executor.Result{ExitCode: 78}, errors.New("exit status 78")
		}
		return executor.Result{}, nil
	}
	if _, err := call(t, env, sitePHP, map[string]any{"domain": "app.example.com", "settings": map[string]string{"memory_limit": "256M"}}); err == nil {
		t.Fatal("a pool FPM rejects was kept")
	}
	if after, _ := os.ReadFile(l.PoolFile); string(after) != string(before) {
		t.Fatal("pool not restored")
	}
	if st, _, _ := loadSite(env, "app.example.com"); st.PHPSettings != nil {
		t.Fatal("state saved despite the failure")
	}
}

func TestWorkerSettingsGoIntoFrankenPHP(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "w.example.com", "framework": "laravel", "mode": "worker"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, sitePHP, map[string]any{"domain": "w.example.com", "settings": map[string]string{
		"memory_limit": "1G", "worker_threads": "6", "max_requests": "2000",
	}}); err != nil {
		t.Fatal(err)
	}
	l, _ := layoutFor(t, env, "w.example.com")
	cfg, _ := os.ReadFile(l.InstanceConfig)
	for _, want := range []string{"php_ini memory_limit 1G", "php_ini display_errors Off", "num 6", "env MAX_REQUESTS 2000"} {
		if !strings.Contains(string(cfg), want) {
			t.Errorf("instance config missing %q:\n%s", want, cfg)
		}
	}
	if !ran(dr, "systemctl restart "+l.AppUnit) {
		t.Fatal("app not restarted")
	}

	// Switching to classic keeps the php.ini values, drops the worker knobs.
	if got := carryPHPSettings(map[string]string{"memory_limit": "1G", "worker_threads": "6"}, "classic"); len(got) != 1 || got["memory_limit"] != "1G" {
		t.Fatal(got)
	}
	if carryPHPSettings(map[string]string{"memory_limit": "1G"}, "node") != nil {
		t.Fatal("a Node site has no PHP")
	}
}

func layoutFor(t *testing.T, env *Env, domain string) (siteLayout, siteState) {
	t.Helper()
	st, l, err := loadSite(env, domain)
	if err != nil {
		t.Fatal(err)
	}
	return l, st
}

// One FPM master runs every site's pool: saving the same settings again must
// not reload it (a reload empties OPcache and APCu for all sites).
func TestUnchangedPHPSettingsDoNotReloadFPM(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "same.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	set := map[string]any{"domain": "same.example.com", "settings": map[string]string{"memory_limit": "512M"}}
	if _, err := call(t, env, sitePHP, set); err != nil {
		t.Fatal(err)
	}
	n := len(dr.Calls())
	if _, err := call(t, env, sitePHP, set); err != nil {
		t.Fatal(err)
	}
	for _, c := range dr.Calls()[n:] {
		if strings.Contains(c.String(), "reload php8.4-fpm") {
			t.Fatalf("FPM reloaded for an unchanged pool: %v", c)
		}
	}
}
