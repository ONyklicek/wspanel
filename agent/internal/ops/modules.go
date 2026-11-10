package ops

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Optional parts added or taken away after installation (Server → Moduly):
// the local DNS server, mail hosting, XHProf and the Python versions. A
// module goes only while nothing uses it (a zone, a mail domain, a site with
// profiling or on that Python): taking it away never deletes anyone's data.
// What goes leaves the installer's saved choices too, or the next
// "wspanel-install" with the saved settings would put it back.

// moduleTimeout bounds a module's setup script: apt installs, which outlast
// the executor's default two minutes.
const moduleTimeout = 30 * time.Minute

func runModuleScript(ctx context.Context, env *Env, name string, args ...string) (executor.Result, error) {
	ctx, cancel := context.WithTimeout(ctx, moduleTimeout)
	defer cancel()
	return env.Exec.RunIO(ctx, nil, nil, name, args...)
}

// ---------------------------------------------------------------- local DNS

// dnsInstall adds the local DNS server (PowerDNS) and starts using its API.
func dnsInstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	if pdnsEnabled(env) == nil {
		return map[string]any{"installed": true, "changed": false}, nil
	}
	if env.Cfg.DryRun {
		return map[string]any{"installed": true, "changed": true, "dry_run": true}, nil
	}
	res, err := runModuleScript(ctx, env, "dns-setup", "install", mailHostName(env))
	if err != nil {
		return nil, err
	}
	key, err := powerDNSSetupKey(env)
	if err != nil {
		return nil, err
	}
	env.Cfg.PowerDNSAPI, env.Cfg.PowerDNSKey = "http://127.0.0.1:8081", key
	if err := persistConfig(env, map[string]any{"powerdns_api": env.Cfg.PowerDNSAPI, "powerdns_key": key}); err != nil {
		return nil, fmt.Errorf("DNS installed, but saving agent.json failed: %w", err)
	}
	return map[string]any{"installed": true, "changed": true, "log": tail(res.Stdout, 2000)}, nil
}

// powerDNSSetupKey reads the API key dns.sh wrote.
func powerDNSSetupKey(env *Env) (string, error) {
	f, err := os.Open(env.Cfg.PowerDNSSetup)
	if err != nil {
		return "", err
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		if k, ok := strings.CutPrefix(sc.Text(), "api-key="); ok && k != "" {
			return k, nil
		}
	}
	return "", fmt.Errorf("%s has no api-key", env.Cfg.PowerDNSSetup)
}

// dnsUninstall takes the local DNS server away, once it holds no zone.
func dnsUninstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	if pdnsEnabled(env) != nil {
		return map[string]any{"installed": false, "changed": false}, nil
	}
	// The zones the server answers for, not only the panel's: a zone added
	// by hand is somebody's too.
	if body, _, err := pdns(ctx, env, "GET", "/zones", nil); err == nil {
		var zones []struct {
			Name string `json:"name"`
		}
		if json.Unmarshal(body, &zones) == nil && len(zones) > 0 {
			names := make([]string, 0, len(zones))
			for _, z := range zones {
				names = append(names, strings.TrimSuffix(z.Name, "."))
			}
			return nil, protocol.Conflict("the local DNS server still has zones (" + strings.Join(names, ", ") + "): delete them first (Síť → DNS)")
		}
	} else if !env.Cfg.DryRun {
		// PowerDNS not answering: no way to tell whether a zone is left.
		return nil, protocol.Conflict("PowerDNS does not answer, so its zones cannot be checked: start it first (" + err.Error() + ")")
	}
	var log string
	if !env.Cfg.DryRun {
		res, err := runModuleScript(ctx, env, "dns-setup", "remove")
		if err != nil {
			return nil, err
		}
		log = res.Stdout
		_ = os.Remove(dnsStatePath(env)) // DNSSEC and secondaries of zones that are gone
	}
	env.Cfg.PowerDNSAPI, env.Cfg.PowerDNSKey = "", ""
	if err := persistConfig(env, map[string]any{"powerdns_api": "", "powerdns_key": ""}); err != nil {
		return nil, fmt.Errorf("DNS removed, but saving agent.json failed: %w", err)
	}
	if err := dropInstallFlag(env, "--dns=", ""); err != nil {
		return nil, err
	}
	return map[string]any{"installed": false, "changed": true, "log": tail(log, 2000)}, nil
}

// ---------------------------------------------------------------- mail hosting

// mailUninstall takes mail hosting away, once no mail domain is left.
// Postfix keeps sending the sites' mail.
func mailUninstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	if !mailHostingOn(env) {
		return map[string]any{"installed": false, "changed": false}, nil
	}
	st, err := loadMail(env)
	if err != nil {
		return nil, err
	}
	if len(st.Domains) > 0 {
		return nil, protocol.Conflict("mail domains are left (" + strings.Join(sortedKeys(st.Domains), ", ") + "): delete them first (Pošta → Domény)")
	}
	res, err := runModuleScript(ctx, env, "mail-hosting", "--remove", mailHostName(env))
	if err != nil {
		return nil, err
	}
	if err := dropInstallFlag(env, "--mail-hosting=", ""); err != nil {
		return nil, err
	}
	return map[string]any{"installed": false, "changed": true, "log": tail(res.Stdout, 2000)}, nil
}

// ---------------------------------------------------------------- XHProf

// xhprofUninstall takes XHProf and XHGui away, once no site profiles.
func xhprofUninstall(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	var on []string
	for _, st := range allSiteStates(env.Cfg) {
		if st.Xhprof != nil {
			on = append(on, st.Domain)
		}
	}
	if len(on) > 0 {
		slices.Sort(on)
		return nil, protocol.Conflict("profiling is on for " + strings.Join(on, ", ") + ": switch it off first (the site's Nastavení → Výkon a ladění)")
	}
	if !env.Cfg.DryRun && !fileIn(env.Cfg.XhguiDir, "prepend.php") {
		return map[string]any{"installed": false, "changed": false}, nil
	}
	res, err := runModuleScript(ctx, env, "xhprof", "--remove")
	if err != nil {
		return nil, err
	}
	if err := dropInstallFlag(env, "--xhprof=", ""); err != nil {
		return nil, err
	}
	return map[string]any{"installed": false, "changed": true, "log": tail(res.Stdout, 2000)}, nil
}

// ---------------------------------------------------------------- Python

// pythonRemove takes an installed Python version away, once no site runs on
// it; uv goes with the last one.
func pythonRemove(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Version string `json:"version"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("version", p.Version, pythonMinors(env.Cfg)); err != nil {
		return nil, err
	}
	var on []string
	for _, st := range allSiteStates(env.Cfg) {
		if st.Mode == "python" && st.PythonVersion == p.Version {
			on = append(on, st.Domain)
		}
	}
	if len(on) > 0 {
		slices.Sort(on)
		return nil, protocol.Conflict("Python " + p.Version + " runs " + strings.Join(on, ", ") + ": move them to another version or delete them first")
	}
	out := map[string]any{"version": p.Version, "changed": true}
	if env.Cfg.DryRun {
		out["dry_run"] = true
		return out, nil
	}
	// The minor's link and every patch build behind it.
	dirs, _ := filepath.Glob(filepath.Join(env.Cfg.PythonDir, "cpython-"+p.Version+"*"))
	for _, d := range dirs {
		name := filepath.Base(d)
		m := pythonDirRe.FindStringSubmatch(name)
		l := pythonLinkRe.FindStringSubmatch(name)
		if (m == nil || "3."+m[1] != p.Version) && (l == nil || "3."+l[1] != p.Version) {
			continue // 3.1 must not take 3.13 with it
		}
		if err := os.RemoveAll(d); err != nil {
			return nil, err
		}
	}
	if err := dropInstallFlag(env, "--python=", p.Version); err != nil {
		return nil, err
	}
	left := pythonMinors(env.Cfg)
	if len(left) == 0 {
		for _, tool := range []string{"uv", "uvx"} {
			_ = os.Remove(filepath.Join(filepath.Dir(env.Cfg.UVBin), tool))
		}
		_ = os.RemoveAll(env.Cfg.PythonDir)
	}
	out["installed"] = left
	return out, nil
}

// ---------------------------------------------------------------- the installer's choices

// dropInstallFlag removes a module from the installer's saved choices:
// the flag ("--dns=") altogether, or one value of a list flag ("--python=",
// "3.13"; the flag goes with its last value).
func dropInstallFlag(env *Env, flag, value string) error {
	if env.Cfg.InstallFlags == "" {
		return nil
	}
	b, err := os.ReadFile(env.Cfg.InstallFlags)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	var out []string
	changed := false
	for _, line := range strings.Split(strings.TrimRight(string(b), "\n"), "\n") {
		v, ok := strings.CutPrefix(line, flag)
		if !ok {
			out = append(out, line)
			continue
		}
		if value != "" {
			vs := slices.DeleteFunc(strings.Split(v, ","), func(s string) bool { return s == value })
			if len(vs) > 0 {
				if next := flag + strings.Join(vs, ","); next != line {
					changed = true
					line = next
				}
				out = append(out, line)
				continue
			}
		}
		changed = true
	}
	if !changed {
		return nil
	}
	data := strings.Join(out, "\n")
	if data != "" {
		data += "\n"
	}
	return env.FS.WriteFile(env.Cfg.InstallFlags, []byte(data), 0o600, fsx.Root)
}
