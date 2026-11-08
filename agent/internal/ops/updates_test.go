package ops

import (
	"path/filepath"
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
)

func TestUpdatesListsWhatAnUpgradeWouldInstall(t *testing.T) {
	env, dr := testEnv(t)
	env.Cfg.AutoUpgrades = filepath.Join(t.TempDir(), "20auto-upgrades")
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		if c.Name == "apt-get" && len(c.Args) > 0 && c.Args[0] == "-s" {
			return executor.Result{Stdout: "Reading package lists...\n" +
				"Inst libssl3t64 [3.5.1-1] (3.5.1-1+deb13u1 Debian-Security:13/stable-security [amd64])\n" +
				"Inst curl [8.14.1-2] (8.14.1-2+deb13u2 Debian:13.2/stable [amd64])\n" +
				"Conf libssl3t64 (3.5.1-1+deb13u1 Debian-Security:13/stable-security [amd64])\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, sysUpdates, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	pkgs := out.(map[string]any)["packages"].([]upgradable)
	if len(pkgs) != 2 || pkgs[0] != (upgradable{"libssl3t64", "3.5.1-1", "3.5.1-1+deb13u1", true}) || pkgs[1].Security {
		t.Errorf("packages: %+v", pkgs)
	}
	if _, err := call(t, env, sysAutoUpdates, map[string]any{"enabled": true}); err != nil {
		t.Fatal(err)
	}
	if !autoSecurityOn(env) || !strings.Contains(read2(t, env.Cfg.AutoUpgrades), `APT::Periodic::Unattended-Upgrade "1";`) {
		t.Error("automatic security updates on")
	}
}

func TestFail2banListsBansAndUnbansOnlyAnAddress(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		switch c.String() {
		case "fail2ban-client status":
			return executor.Result{Stdout: "Status\n|- Number of jail:\t2\n`- Jail list:\tsshd, dovecot\n"}, nil
		case "fail2ban-client status sshd":
			return executor.Result{Stdout: "Status for the jail: sshd\n`- Actions\n   `- Banned IP list:\t203.0.113.5 198.51.100.7\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, fail2banStatus, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	jails := out.(map[string]any)["jails"].(map[string][]string)
	if len(jails["sshd"]) != 2 || jails["sshd"][0] != "203.0.113.5" || len(jails["dovecot"]) != 0 {
		t.Errorf("jails: %v", jails)
	}
	for _, bad := range []string{"--all", "1.2.3.4; reboot", "all"} {
		if _, err := call(t, env, fail2banUnban, map[string]string{"ip": bad}); code(err) != "invalid_params" {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
	if _, err := call(t, env, fail2banUnban, map[string]string{"ip": "203.0.113.5"}); err != nil {
		t.Fatal(err)
	}
}
