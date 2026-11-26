package fileop

import (
	"os"
	"syscall"
)

// Filesystems of a network disk (SMB, NFS, rclone's FUSE for FTP/SFTP).
var netfsMagic = map[int64]bool{
	0xFF534D42: true, // CIFS
	0xFE534D42: true, // SMB2/3
	0x517B:     true, // SMB (old)
	0x6969:     true, // NFS
	0x65735546: true, // FUSE (rclone)
}

// onNetworkDisk: whether f lives on a network disk.
func onNetworkDisk(f *os.File) bool {
	var st syscall.Statfs_t
	if err := syscall.Fstatfs(int(f.Fd()), &st); err != nil {
		return false
	}
	return netfsMagic[int64(st.Type)]
}
