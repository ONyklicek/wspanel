//go:build !linux

package ops

import (
	"context"
	"encoding/json"
	"errors"
	"runtime"
)

// sysResources on non-Linux (dry-run development): CPU count only.
func sysResources(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	return map[string]any{"cpus": runtime.NumCPU(), "load": []float64{0, 0, 0}, "disks": []any{}, "memory": map[string]int64{}}, nil
}

// sysMetrics on non-Linux (dry-run development): plausible zeros.
func sysMetrics(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct{}{}); err != nil {
		return nil, err
	}
	return map[string]any{
		"cpus": runtime.NumCPU(), "cpu": map[string]float64{"percent": 0, "iowait": 0, "steal": 0},
		"memory": map[string]int64{}, "net": map[string]int64{}, "io": map[string]int64{},
		"filesystems": []any{}, "processes": 0, "counters": map[string]uint64{}, "load": []float64{0, 0, 0},
		"sites": []any{}, "top": []any{},
	}, nil
}

func sysSiteDisk(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	return map[string]any{"sizes": map[string]int64{}}, nil
}

type fsInfo struct {
	Mount string `json:"mount"`
	Free  int64  `json:"free"`
}

func filesystems() []fsInfo { return nil }

func readProcIdentity(pid int) (procIdent, error) {
	return procIdent{}, errors.New("processes: Linux only")
}

func sysProcesses(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	if err := decode(raw, &struct {
		Kernel bool `json:"kernel,omitempty"`
	}{}); err != nil {
		return nil, err
	}
	return map[string]any{"processes": []any{}, "total": 0, "kernel_threads": 0}, nil
}
