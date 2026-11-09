package ops

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// Scheduled tasks of a site: systemd timers running a whitelisted command
// (artisan / bin/console / a PHP script of the site; manage.py / a Python
// script in the release's virtualenv) as the site user, with
// the same sandbox as workers. Cron syntax is converted to OnCalendar.

var cronKinds = []string{"artisan", "console", "php", "manage", "python"}

var (
	cronArgRe    = regexp.MustCompile(`^[A-Za-z0-9:_./=@,+-]{1,200}$`) // no shell or systemd specifier characters ($ % " ' ; | & ...)
	cronScriptRe = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_./-]{0,200}\.php$`)
	cronPyRe     = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_./-]{0,200}\.py$`)
)

func cronUnitBase(user string, id int) string { return "wspanel-" + user + "-cron-" + strconv.Itoa(id) }

// cronUnitRe matches exactly one site's cron units: after "cron-" only digits,
// so web_a_com never matches units of web_a_com-cron-1_com.
func cronUnitRe(user string) *regexp.Regexp {
	return regexp.MustCompile(`^wspanel-` + regexp.QuoteMeta(user) + `-cron-(\d{1,6})\.timer$`)
}

func siteCronIDs(cfg config.Config, user string) []int {
	entries, _ := os.ReadDir(cfg.SystemdDir)
	re := cronUnitRe(user)
	var ids []int
	for _, e := range entries {
		if m := re.FindStringSubmatch(e.Name()); m != nil {
			n, _ := strconv.Atoi(m[1])
			ids = append(ids, n)
		}
	}
	slices.Sort(ids)
	return ids
}

// cronToCalendar converts a 5-field cron expression to systemd OnCalendar.
// Day-of-month and day-of-week together are rejected: cron ORs them,
// systemd ANDs them.
func cronToCalendar(expr string) (string, error) {
	f := strings.Fields(expr)
	if len(f) != 5 {
		return "", protocol.Invalid("schedule: expected 5 fields (minute hour day month weekday)")
	}
	type field struct {
		min, max int
	}
	limits := []field{{0, 59}, {0, 23}, {1, 31}, {1, 12}, {0, 7}}
	conv := make([]string, 5)
	for i, s := range f {
		v, err := cronField(s, limits[i].min, limits[i].max, i == 4)
		if err != nil {
			return "", protocol.Invalid(fmt.Sprintf("schedule: field %d (%q): %v", i+1, s, err))
		}
		conv[i] = v
	}
	if f[2] != "*" && f[4] != "*" {
		return "", protocol.Invalid("schedule: use either day of month or day of week, not both")
	}
	cal := fmt.Sprintf("*-%s-%s %s:%s:00", conv[3], conv[2], conv[1], conv[0])
	if conv[4] != "*" {
		cal = conv[4] + " " + cal
	}
	return cal, nil
}

var dowNames = []string{"Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"}

func cronField(s string, min, max int, dow bool) (string, error) {
	if s == "*" {
		return "*", nil
	}
	var parts []string
	for _, item := range strings.Split(s, ",") {
		rng, step := item, 1
		if r, st, ok := strings.Cut(item, "/"); ok {
			n, err := strconv.Atoi(st)
			if err != nil || n < 1 || n > max {
				return "", fmt.Errorf("bad step")
			}
			rng, step = r, n
		}
		lo, hi := min, max
		if dow && rng == "*" {
			hi = 6
		}
		if rng != "*" {
			a, b, isRange := strings.Cut(rng, "-")
			x, err := strconv.Atoi(a)
			if err != nil {
				return "", fmt.Errorf("not a number")
			}
			lo, hi = x, x
			if isRange {
				y, err := strconv.Atoi(b)
				if err != nil {
					return "", fmt.Errorf("not a number")
				}
				hi = y
			} else if step > 1 {
				hi = max
			}
		}
		if lo < min || hi > max || lo > hi {
			return "", fmt.Errorf("out of range %d-%d", min, max)
		}
		for v := lo; v <= hi; v += step {
			if dow {
				parts = append(parts, dowNames[v])
			} else {
				parts = append(parts, fmt.Sprintf("%02d", v))
			}
		}
	}
	slices.Sort(parts)
	parts = slices.Compact(parts)
	return strings.Join(parts, ","), nil
}

type cronParams struct {
	Domain   string   `json:"domain"`
	ID       int      `json:"id,omitempty"` // cron.edit / cron.remove
	Kind     string   `json:"kind"`         // artisan | console | php | manage | python
	Args     []string `json:"args"`         // artisan/console/manage: command + args; php/python: [script path]
	Schedule string   `json:"schedule"`     // 5-field cron expression
}

type cronMeta struct {
	ID       int      `json:"id"`
	Kind     string   `json:"kind"`
	Args     []string `json:"args"`
	Schedule string   `json:"schedule"`
	Calendar string   `json:"calendar"`
}

func (p *cronParams) validate() (string, error) {
	if err := validate.Domain(p.Domain); err != nil {
		return "", err
	}
	if err := validate.OneOf("kind", p.Kind, cronKinds); err != nil {
		return "", err
	}
	if len(p.Args) == 0 || len(p.Args) > 20 {
		return "", protocol.Invalid("args: 1-20 arguments")
	}
	if p.Kind == "php" {
		if len(p.Args) != 1 || !cronScriptRe.MatchString(p.Args[0]) || strings.Contains(p.Args[0], "..") {
			return "", protocol.Invalid("args: php needs one script path relative to the release (e.g. bin/task.php)")
		}
	}
	if p.Kind == "python" {
		if len(p.Args) != 1 || !cronPyRe.MatchString(p.Args[0]) || strings.Contains(p.Args[0], "..") {
			return "", protocol.Invalid("args: python needs one script path relative to the release (e.g. tasks/cleanup.py)")
		}
	}
	for _, a := range p.Args {
		if !cronArgRe.MatchString(a) {
			return "", protocol.Invalid(fmt.Sprintf("args: %q contains characters that are not allowed", a))
		}
	}
	return cronToCalendar(p.Schedule)
}

func cronExecStart(env *Env, l siteLayout, p cronParams) (string, string, error) {
	php := config.ForVersion(env.Cfg.PHPCLIBinary, l.PHPVersion)
	if l.Mode == "worker" {
		php = env.Cfg.FrankenPHPBin + " php-cli"
		if cli := frankenCLI(env); cli != "" {
			php = cli
		}
	}
	if l.Mode == "python" {
		py := filepath.Join(l.Home, "current", ".venv", "bin", "python")
		switch {
		case p.Kind == "manage" && l.Framework == "django":
			return py + " manage.py " + strings.Join(p.Args, " "), "manage.py", nil
		case p.Kind == "python":
			return py + " " + p.Args[0], p.Args[0], nil
		case p.Kind == "manage":
			return "", "", protocol.Invalid("kind: manage needs a Django site")
		}
		return "", "", protocol.Invalid("kind: a Python site runs manage.py commands or Python scripts")
	}
	switch {
	case l.Mode == "node":
		return "", "", protocol.Invalid("kind: Node sites have no PHP tasks")
	case p.Kind == "manage" || p.Kind == "python":
		return "", "", protocol.Invalid("kind: " + p.Kind + " needs a Python site")
	case p.Kind == "artisan" && l.Framework != "laravel":
		return "", "", protocol.Invalid("kind: artisan needs a Laravel site")
	case p.Kind == "console" && l.Framework != "symfony":
		return "", "", protocol.Invalid("kind: console needs a Symfony site")
	}
	entry := map[string]string{"artisan": "artisan", "console": "bin/console", "php": p.Args[0]}[p.Kind]
	args := p.Args
	if p.Kind == "php" {
		args = nil
	}
	return php + " " + strings.TrimSpace(entry+" "+strings.Join(args, " ")), entry, nil
}

func writeCron(ctx context.Context, env *Env, l siteLayout, p cronParams, calendar string) error {
	exec, _, err := cronExecStart(env, l, p)
	if err != nil {
		return err
	}
	base := filepath.Join(env.Cfg.SystemdDir, cronUnitBase(l.User, p.ID))
	meta, _ := json.Marshal(cronMeta{ID: p.ID, Kind: p.Kind, Args: p.Args, Schedule: p.Schedule, Calendar: calendar})
	unit, err := render("unit.service.tmpl", unitData{
		Description: fmt.Sprintf("WSPanel task %d of %s", p.ID, l.Domain),
		User:        l.User, Home: l.Home, ExecStart: exec, StopTimeout: 30, Extra: noBindExtra,
	})
	if err != nil {
		return err
	}
	// A task is a oneshot run, not a restarting service.
	unitS := strings.Replace(string(unit), "Type=simple", "Type=oneshot", 1)
	unitS = strings.Replace(unitS, "Restart=always\nRestartSec=5s\n", "TimeoutStartSec=1h\n", 1)
	unitS = strings.Replace(unitS, "[Install]\nWantedBy=multi-user.target\n", "", 1)
	timer := fmt.Sprintf("# Managed by WSPanel agent. Do not edit.\n# wspanel-cron: %s\n[Unit]\nDescription=WSPanel task %d of %s\n\n[Timer]\nOnCalendar=%s\nAccuracySec=1s\n\n[Install]\nWantedBy=timers.target\n",
		meta, p.ID, l.Domain, calendar)
	if err := env.FS.WriteFile(base+".service", []byte(unitS), 0o644, fsx.Root); err != nil {
		return err
	}
	if err := env.FS.WriteFile(base+".timer", []byte(timer), 0o644, fsx.Root); err != nil {
		return err
	}
	for _, f := range []string{base + ".service", base + ".timer"} {
		if _, err := env.Exec.Run(ctx, "systemd-analyze", "verify", f); err != nil {
			_ = os.Remove(base + ".service")
			_ = os.Remove(base + ".timer")
			return fmt.Errorf("task unit invalid: %w", err)
		}
	}
	if _, err := env.Exec.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return err
	}
	_, err = env.Exec.Run(ctx, "systemctl", "enable", "--now", cronUnitBase(l.User, p.ID)+".timer")
	return err
}

func cronAdd(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p cronParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	calendar, err := p.validate()
	if err != nil {
		return nil, err
	}
	if _, err := env.Exec.Run(ctx, "systemd-analyze", "calendar", calendar); err != nil {
		return nil, protocol.Invalid("schedule: systemd rejects " + calendar)
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	ids := siteCronIDs(env.Cfg, l.User)
	if len(ids) >= 50 {
		return nil, protocol.Invalid("at most 50 tasks per site")
	}
	p.ID = 1
	if len(ids) > 0 {
		p.ID = ids[len(ids)-1] + 1
	}
	if err := writeCron(ctx, env, l, p, calendar); err != nil {
		return nil, err
	}
	return map[string]any{"id": p.ID, "calendar": calendar}, nil
}

func cronEdit(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p cronParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	calendar, err := p.validate()
	if err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	if !slices.Contains(siteCronIDs(env.Cfg, l.User), p.ID) {
		return nil, protocol.NotFound(fmt.Sprintf("task %d does not exist", p.ID))
	}
	if err := writeCron(ctx, env, l, p, calendar); err != nil {
		return nil, err
	}
	_, err = env.Exec.Run(ctx, "systemctl", "restart", cronUnitBase(l.User, p.ID)+".timer")
	return map[string]any{"id": p.ID, "calendar": calendar}, err
}

func cronRemove(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
		ID     int    `json:"id"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	user := validate.SiteUser(p.Domain)
	if !slices.Contains(siteCronIDs(env.Cfg, user), p.ID) {
		return nil, protocol.NotFound(fmt.Sprintf("task %d does not exist", p.ID))
	}
	return map[string]any{"id": p.ID, "removed": true}, removeCron(ctx, env, user, p.ID)
}

func removeCron(ctx context.Context, env *Env, user string, id int) error {
	base := cronUnitBase(user, id)
	if _, err := env.Exec.Run(ctx, "systemctl", "disable", "--now", base+".timer"); err != nil {
		return err
	}
	_, _ = env.Exec.Run(ctx, "systemctl", "stop", base+".service")
	_ = os.Remove(filepath.Join(env.Cfg.SystemdDir, base+".timer"))
	_ = os.Remove(filepath.Join(env.Cfg.SystemdDir, base+".service"))
	_, err := env.Exec.Run(ctx, "systemctl", "daemon-reload")
	return err
}

var cronMetaRe = regexp.MustCompile(`(?m)^# wspanel-cron: (\{.*\})$`)

type cronEntry struct {
	cronMeta
	LastRun    string `json:"last_run,omitempty"`
	LastResult string `json:"last_result,omitempty"`
	NextRun    string `json:"next_run,omitempty"`
}

func cronList(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.Domain(p.Domain); err != nil {
		return nil, err
	}
	user := validate.SiteUser(p.Domain)
	out := []cronEntry{}
	for _, id := range siteCronIDs(env.Cfg, user) {
		base := cronUnitBase(user, id)
		b, err := os.ReadFile(filepath.Join(env.Cfg.SystemdDir, base+".timer"))
		if err != nil {
			continue
		}
		var e cronEntry
		if m := cronMetaRe.FindSubmatch(b); m != nil {
			_ = json.Unmarshal(m[1], &e.cronMeta)
		}
		e.ID = id
		if res, err := env.Exec.Run(ctx, "systemctl", "show", "--property=ExecMainStartTimestamp,Result", "--", base+".service"); err == nil {
			for _, line := range strings.Split(res.Stdout, "\n") {
				if v, ok := strings.CutPrefix(line, "ExecMainStartTimestamp="); ok {
					e.LastRun = v
				}
				if v, ok := strings.CutPrefix(line, "Result="); ok {
					e.LastResult = v
				}
			}
		}
		if res, err := env.Exec.Run(ctx, "systemctl", "show", "--property=NextElapseUSecRealtime", "--", base+".timer"); err == nil {
			e.NextRun = strings.TrimPrefix(strings.TrimSpace(res.Stdout), "NextElapseUSecRealtime=")
		}
		out = append(out, e)
	}
	return out, nil
}

// refreshCrons re-renders a site's tasks after a mode or version change
// (they embed the PHP binary); tasks the new mode cannot run are removed.
func refreshCrons(ctx context.Context, env *Env, l siteLayout) error {
	for _, id := range siteCronIDs(env.Cfg, l.User) {
		b, err := os.ReadFile(filepath.Join(env.Cfg.SystemdDir, cronUnitBase(l.User, id)+".timer"))
		if err != nil {
			continue
		}
		var meta cronMeta
		if m := cronMetaRe.FindSubmatch(b); m == nil || json.Unmarshal(m[1], &meta) != nil {
			continue
		}
		p := cronParams{Domain: l.Domain, ID: id, Kind: meta.Kind, Args: meta.Args, Schedule: meta.Schedule}
		if _, _, err := cronExecStart(env, l, p); err != nil {
			if err := removeCron(ctx, env, l.User, id); err != nil {
				return err
			}
			continue
		}
		if err := writeCron(ctx, env, l, p, meta.Calendar); err != nil {
			return err
		}
	}
	return nil
}
