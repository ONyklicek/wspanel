package ops

import (
	"strings"
	"testing"
)

func TestXhprofLoadsOnlyInTheSitesPoolAndAddsItsViewer(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "prof.example.com", "framework": "laravel", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	_, l, _ := loadSite(env, "prof.example.com")

	if _, err := call(t, env, siteXhprof, map[string]any{"domain": "prof.example.com", "enabled": true, "ratio": 25}); err != nil {
		t.Fatal(err)
	}
	pool := read2(t, l.PoolFile)
	for _, want := range []string{
		"php_admin_value[extension] = xhprof.so",
		"php_admin_value[auto_prepend_file] = " + env.Cfg.XhguiDir + "/prepend.php",
		"env[WSP_XHGUI_SPOOL] = " + l.Home + "/xhgui/spool.jsonl",
		"env[WSP_XHGUI_RATIO] = 25",
		"php_admin_value[open_basedir] = " + l.Home + "/:" + env.Cfg.XhguiDir + "/",
	} {
		if !strings.Contains(pool, want) {
			t.Errorf("pool lacks %q:\n%s", want, pool)
		}
	}
	web := read2(t, l.WebFile)
	if !strings.Contains(web, "handle_path /_wsp_xhgui/*") || !strings.Contains(web, "php_fastcgi unix/"+l.Socket) {
		t.Errorf("the viewer runs in the site's own pool, on its own domain:\n%s", web)
	}
	if !strings.Contains(web, "env XHGUI_PDO_DSN sqlite:"+l.Home+"/xhgui/xhgui.sqlite3") {
		t.Errorf("and reads only the site's profiles:\n%s", web)
	}
	edge := read2(t, l.EdgeFile)
	if !strings.Contains(edge, "handle /_wsp_xhgui/*") || !strings.Contains(edge, "uri /auth/xhgui/prof.example.com") {
		t.Errorf("the edge asks the panel about this very domain:\n%s", edge)
	}
	if _, err := call(t, env, siteXhprof, map[string]any{"domain": "prof.example.com", "enabled": false}); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(read2(t, l.PoolFile), "xhprof") {
		t.Error("switched off: the pool no longer loads it")
	}
	if strings.Contains(read2(t, l.WebFile), "_wsp_xhgui") || strings.Contains(read2(t, l.EdgeFile), "_wsp_xhgui") {
		t.Error("switched off: the viewer is gone")
	}
}

func TestXhprofIsForClassicSitesOnly(t *testing.T) {
	env, _ := testEnv(t)
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "fast.example.com", "framework": "laravel", "mode": "worker"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteXhprof, map[string]any{"domain": "fast.example.com", "enabled": true}); code(err) != "invalid_params" {
		t.Errorf("worker mode keeps the app in memory, a prepend file profiles nothing: %v", err)
	}
	if _, err := call(t, env, siteCreate, map[string]string{"domain": "c.example.com", "framework": "php", "php_version": "8.4"}); err != nil {
		t.Fatal(err)
	}
	if _, err := call(t, env, siteXhprof, map[string]any{"domain": "c.example.com", "enabled": true, "ratio": 101}); code(err) != "invalid_params" {
		t.Errorf("ratio is a percentage: %v", err)
	}
}
