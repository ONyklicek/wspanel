//go:build !linux

package ops

import (
	"context"
	"encoding/json"
	"runtime"
)

// sysResources on non-Linux (dry-run development): CPU count only.
func sysResources(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	return map[string]any{"cpus": runtime.NumCPU(), "load": []float64{0, 0, 0}, "disks": []any{}, "memory": map[string]int64{}}, nil
}
