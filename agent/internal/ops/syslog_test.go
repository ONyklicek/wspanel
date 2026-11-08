package ops

import (
	"strings"
	"testing"

	"wspanel/agent/internal/protocol"
)

func TestServerLogsComeFromAFixedSetOfSources(t *testing.T) {
	env, dr := testEnv(t)
	if _, err := call(t, env, sysLog, map[string]any{"source": "mail", "since": "24h", "priority": "warning"}); err != nil {
		t.Fatal(err)
	}
	c := dr.Calls()[len(dr.Calls())-1]
	args := strings.Join(c.Args, " ")
	if c.Name != "journalctl" || !strings.Contains(args, "--unit postfix@-.service") || !strings.Contains(args, "--unit dovecot.service") ||
		!strings.Contains(args, "--since -1day") || !strings.Contains(args, "--priority warning") {
		t.Errorf("journalctl %s", args)
	}
	for _, bad := range []map[string]any{
		{"source": "/etc/shadow"},
		{"source": "mail", "since": "1y"},
		{"source": "mail", "priority": "debug"},
		{"source": "mail", "filter": "a\nb"},
	} {
		if _, err := call(t, env, sysLog, bad); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%v: %v", bad, err)
		}
	}
}
