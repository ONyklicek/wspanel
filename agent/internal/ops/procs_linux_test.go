//go:build linux

package ops

import (
	"os"
	"testing"
)

func TestProcessListHasThisProcessWithItsIdentity(t *testing.T) {
	env, _ := testEnv(t)
	out, err := call(t, env, sysProcesses, map[string]any{})
	if err != nil {
		t.Fatal(err)
	}
	id, err := readProcIdentity(os.Getpid())
	if err != nil || id.Cmd == "" || id.Start == 0 {
		t.Fatalf("identity: %+v %v", id, err)
	}
	for _, p := range out.(map[string]any)["processes"].([]procRow) {
		if p.PID == os.Getpid() {
			if p.Start != id.Start || p.Cmd != id.Cmd || p.Started == "" || p.RSS <= 0 {
				t.Errorf("row: %+v", p)
			}
			return
		}
	}
	t.Error("this test's process is not listed")
}
