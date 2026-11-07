//go:build !linux

package peercred

import (
	"errors"
	"net"
)

// Supported is false outside Linux; the agent then only runs in dry-run mode.
const Supported = false

func UID(*net.UnixConn) (uint32, error) {
	return 0, errors.New("peer credentials not supported on this platform")
}
