//go:build !linux

package ops

import (
	"errors"

	"wspanel/agent/internal/config"
)

func BindSiteMount(config.Config, string, string) error {
	return errors.New("network disks need Linux")
}

func UnbindSiteMount(config.Config, string, string) error {
	return errors.New("network disks need Linux")
}
