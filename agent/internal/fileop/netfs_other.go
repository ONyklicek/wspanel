//go:build !linux

package fileop

import "os"

func onNetworkDisk(*os.File) bool { return false }
