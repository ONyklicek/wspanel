package ops

import (
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestPackageSearchPutsTheExactNameFirst(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch c.Name {
		case "apt-cache":
			return executor.Result{Stdout: "libhtop-dev - dev files\nhtop - interactive processes viewer\nbtop - modern htop\nhtop-extra - more\n"}, nil
		case "dpkg-query":
			return executor.Result{Stdout: "htop\t3.4.1-5\tii \t400\toptional\tno\tinteractive processes viewer\n", ExitCode: 1}, nil
		}
		return executor.Result{}, nil
	}
	for _, bad := range []string{"", "a", "-o=x", "foo bar", "Foo$"} {
		if _, err := call(t, env, pkgSearch, map[string]string{"query": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	out, err := call(t, env, pkgSearch, map[string]string{"query": "htop"})
	if err != nil {
		t.Fatal(err)
	}
	p := out.(map[string]any)["packages"].([]pkgInfo)
	names := []string{}
	for _, x := range p {
		names = append(names, x.Name)
	}
	if strings.Join(names, ",") != "htop,htop-extra,btop,libhtop-dev" || p[0].Version != "3.4.1-5" || p[1].Version != "" {
		t.Errorf("search: %+v", p)
	}
	if c := dr.Calls()[len(dr.Calls())-2]; c.String() != "apt-cache search --names-only htop" {
		t.Errorf("search call: %v", c)
	}
}

func TestPackageRemovalNeverTakesWhatThePanelNeeds(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		s := c.String()
		switch {
		case strings.Contains(s, "-s ") && strings.HasSuffix(s, "remove libfoo"):
			return executor.Result{Stdout: "Remv libfoo [1]\nRemv postfix [3.10]\n"}, nil
		case strings.Contains(s, "-s ") && strings.HasSuffix(s, "remove htop"):
			return executor.Result{Stdout: "Remv htop [3.4]\n"}, nil
		case strings.Contains(s, "-s ") && strings.HasSuffix(s, "remove mawk"):
			return executor.Result{Stdout: "Remv mawk [1]\n"}, nil
		case c.Name == "dpkg-query" && strings.HasSuffix(s, "mawk"):
			return executor.Result{Stdout: "mawk\t1\tii \t200\trequired\tno\tawk\n"}, nil
		}
		return executor.Result{}, nil
	}
	for _, name := range []string{"postfix", "php8.4-fpm", "wspanel", "libfoo", "mawk", "--purge"} {
		if _, err := call(t, env, pkgRemove, map[string]string{"name": name}); code(err) != "invalid_params" {
			t.Errorf("%s must be refused: %v", name, err)
		}
	}
	for _, c := range dr.Calls() {
		if c.Name == "apt-get" && c.Args[0] == "-y" {
			t.Fatalf("nothing may be removed: %v", c)
		}
	}
	out, err := call(t, env, pkgPreview, map[string]string{"name": "libfoo", "action": "remove"})
	if err != nil || out.(map[string]any)["allowed"] != false {
		t.Errorf("preview of a removal that takes postfix along: %v %v", out, err)
	}
	if _, err := call(t, env, pkgRemove, map[string]string{"name": "htop"}); err != nil {
		t.Fatal(err)
	}
	last := dr.Calls()[len(dr.Calls())-1]
	if last.Name != "apt-get" || !strings.HasSuffix(last.String(), "--force-confold remove htop") || !strings.Contains(last.String(), "DPkg::Lock::Timeout=300") {
		t.Errorf("remove call: %v", last)
	}
}

func TestPackageListMarksManualHeldAndProtected(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch c.String() {
		case "apt-mark showmanual":
			return executor.Result{Stdout: "htop\nnginx\n"}, nil
		case "apt-mark showhold":
			return executor.Result{Stdout: "nginx\n"}, nil
		}
		if c.Name == "dpkg-query" {
			return executor.Result{Stdout: "htop\t3.4\tii \t400\toptional\tno\tviewer\n" +
				"nginx:amd64\t1.26\thi \t1000\toptional\tno\tweb server\n" +
				"bash\t5.2\tii \t7000\trequired\tyes\tshell\n" +
				"gone\t1\trc \t0\toptional\tno\tremoved, config left\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, pkgList, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	p := out.(map[string]any)["packages"].([]pkgInfo)
	if len(p) != 3 || p[0].Name != "bash" || !p[0].Protected || !p[1].Manual || p[1].Held || !p[2].Held || p[2].Size != 1000*1024 {
		t.Errorf("list: %+v", p)
	}
	if _, err := call(t, env, pkgHold, map[string]any{"name": "-x", "hold": true}); code(err) != "invalid_params" {
		t.Errorf("a bad name: %v", err)
	}
}
