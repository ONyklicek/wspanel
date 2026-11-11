package ops

import (
	"strings"
	"testing"

	"wspanel/agent/internal/executor"
	"wspanel/agent/internal/protocol"
)

func TestUnitMemoryAddsUpTheRunningServicesOfAPattern(t *testing.T) {
	env, dr := testEnv(t)
	dr.Responder = func(c executor.Call) (executor.Result, error) {
		s := c.String()
		switch {
		case strings.Contains(s, "list-units") && strings.Contains(s, "postgresql@*.service"):
			return executor.Result{Stdout: "postgresql@17-main.service loaded active running PostgreSQL\npostgresql@17-wsd2.service loaded active running PostgreSQL\n"}, nil
		case strings.Contains(s, "list-units"):
			return executor.Result{}, nil
		case strings.Contains(s, "show"):
			return executor.Result{Stdout: "Id=postgresql@17-main.service\nMemoryCurrent=12000000\n\nId=postgresql@17-wsd2.service\nMemoryCurrent=[not set]\n"}, nil
		}
		return executor.Result{}, nil
	}
	out, err := call(t, env, sysUnitMemory, map[string]any{"patterns": []string{"postgresql@*", "pdns"}})
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	if pg := m["postgresql@*"].(map[string]any); pg["bytes"] != int64(12000000) || len(pg["units"].([]unitMem)) != 2 {
		t.Errorf("postgresql: %v", pg)
	}
	if m["pdns"].(map[string]any)["bytes"] != int64(0) {
		t.Errorf("a service that does not run uses nothing: %v", m["pdns"])
	}
	for _, bad := range []string{"x; rm -rf /", "../etc", ""} {
		if _, err := call(t, env, sysUnitMemory, map[string]any{"patterns": []string{bad}}); code(err) != protocol.CodeInvalidParams {
			t.Errorf("%q must be refused: %v", bad, err)
		}
	}
}
