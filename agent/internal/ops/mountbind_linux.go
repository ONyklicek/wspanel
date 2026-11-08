package ops

import (
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"syscall"

	"wspanel/agent/internal/config"
	"wspanel/agent/internal/validate"
)

// The disk is mounted at a root-owned staging folder; this binds it into
// the site's home. The home is the site user's, who could swap mounts/ or
// mounts/<name> for a symlink to anywhere (/etc): so the target is reached
// component by component with O_NOFOLLOW from the root-owned sites root,
// pinned as a file descriptor, and the bind goes onto that descriptor
// (/proc/self/fd/N), never onto a path the user can change in between.

// oPath is O_PATH (010000000 on amd64 and arm64; not in package syscall).
const oPath = 0x200000

// openMountTarget opens <sites root>/<domain>/mounts/<name> without
// following a symlink at any step below the sites root.
func openMountTarget(cfg config.Config, domain, name string) (int, error) {
	fd, err := syscall.Open(cfg.SitesRoot, oPath|syscall.O_DIRECTORY|syscall.O_CLOEXEC, 0)
	if err != nil {
		return -1, err
	}
	for _, part := range []string{domain, "mounts", name} {
		next, err := syscall.Openat(fd, part, oPath|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
		syscall.Close(fd)
		if err != nil {
			return -1, fmt.Errorf("%s: %w (a symlink or not a folder is refused)", part, err)
		}
		fd = next
	}
	return fd, nil
}

func checkMountArgs(domain, name string) error {
	if !mountNameRe.MatchString(name) {
		return fmt.Errorf("bad disk name %q", name)
	}
	return validate.Domain(domain)
}

// BindSiteMount binds the staged disk into the site (ExecStartPost of its unit).
func BindSiteMount(cfg config.Config, domain, name string) error {
	if err := checkMountArgs(domain, name); err != nil {
		return err
	}
	stage := mountStage(cfg, domain, name)
	var st syscall.Stat_t
	if err := syscall.Stat(stage, &st); err != nil {
		return err
	}
	var parent syscall.Stat_t
	if err := syscall.Stat(filepath.Dir(stage), &parent); err != nil || parent.Dev == st.Dev {
		return fmt.Errorf("%s is not mounted", stage)
	}
	fd, err := openMountTarget(cfg, domain, name)
	if err != nil {
		return err
	}
	defer syscall.Close(fd)
	return syscall.Mount(stage, "/proc/self/fd/"+strconv.Itoa(fd), "", syscall.MS_BIND, "")
}

// UnbindSiteMount removes the bind from the site (ExecStop), only when what
// sits there is this disk: never unmounts something a symlink points at.
func UnbindSiteMount(cfg config.Config, domain, name string) error {
	if err := checkMountArgs(domain, name); err != nil {
		return err
	}
	fd, err := openMountTarget(cfg, domain, name)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	defer syscall.Close(fd)
	var here, stage syscall.Stat_t
	if err := syscall.Fstat(fd, &here); err != nil {
		return err
	}
	if err := syscall.Stat(mountStage(cfg, domain, name), &stage); err != nil || here.Dev != stage.Dev || here.Ino != stage.Ino {
		return nil // not our disk (already unbound)
	}
	return syscall.Unmount("/proc/self/fd/"+strconv.Itoa(fd), syscall.MNT_DETACH)
}
