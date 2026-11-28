//go:build !linux

package ops

import (
	"errors"
	"net"
)

var errNoRawARP = errors.New("raw ARP needs Linux")

func arpAnnounceRaw(string, net.IP) error { return errNoRawARP }

func arpProbeRaw(string, net.IP) (string, error) { return "", errNoRawARP }
