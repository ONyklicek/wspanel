package ops

import (
	"context"
	"encoding/json"
	"regexp"
	"strconv"
	"strings"

	"wspanel/agent/internal/protocol"
)

// unitPatternRe: a unit name or a glob of one (postgresql@*, wspanel-game-*).
var unitPatternRe = regexp.MustCompile(`^[a-zA-Z0-9@._*:-]{1,80}$`)

type unitMem struct {
	Unit  string `json:"unit"`
	Bytes int64  `json:"bytes"`
}

// sysUnitMemory (read-only): how much memory the running services matching
// each pattern use now (their cgroups' MemoryCurrent, page cache included),
// for Server → Moduly to say what a module costs.
func sysUnitMemory(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Patterns []string `json:"patterns"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if len(p.Patterns) == 0 || len(p.Patterns) > 40 {
		return nil, protocol.Invalid("patterns: 1 to 40 unit names or globs")
	}
	for _, pat := range p.Patterns {
		if !unitPatternRe.MatchString(pat) {
			return nil, protocol.Invalid("patterns: unit names or globs (" + pat + ")")
		}
	}
	out := map[string]any{}
	for _, pat := range p.Patterns {
		if !strings.Contains(pat, ".") {
			pat += ".service"
		}
		res, err := env.Exec.Run(ctx, "systemctl", "list-units", "--type=service", "--state=running", "--plain", "--no-legend", "--", pat)
		if err != nil {
			return nil, err
		}
		var units []string
		for _, line := range strings.Split(strings.TrimSpace(res.Stdout), "\n") {
			if f := strings.Fields(line); len(f) > 0 {
				units = append(units, f[0])
			}
		}
		total, list := int64(0), []unitMem{}
		if len(units) > 0 {
			show, err := env.Exec.Run(ctx, "systemctl", append([]string{"show", "--property=Id,MemoryCurrent", "--"}, units...)...)
			if err != nil {
				return nil, err
			}
			for _, b := range strings.Split(strings.TrimSpace(show.Stdout), "\n\n") {
				var m unitMem
				for _, line := range strings.Split(b, "\n") {
					k, v, _ := strings.Cut(line, "=")
					switch k {
					case "Id":
						m.Unit = v
					case "MemoryCurrent":
						m.Bytes, _ = strconv.ParseInt(v, 10, 64) // "[not set]" without memory accounting: 0
					}
				}
				if m.Unit != "" {
					total += m.Bytes
					list = append(list, m)
				}
			}
		}
		out[strings.TrimSuffix(pat, ".service")] = map[string]any{"bytes": total, "units": list}
	}
	return out, nil
}
