package ops

import (
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
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
	return openBeneath(cfg.SitesRoot, []string{domain, "mounts", name})
}

// openBeneath opens root/parts... folder by folder, refusing a symlink (or
// anything not a folder) at every step.
func openBeneath(root string, parts []string) (int, error) {
	fd, err := syscall.Open(root, oPath|syscall.O_DIRECTORY|syscall.O_CLOEXEC, 0)
	if err != nil {
		return -1, err
	}
	for _, part := range parts {
		if part == "" || part == "." || part == ".." {
			syscall.Close(fd)
			return -1, fmt.Errorf("bad path part %q", part)
		}
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

// UnbindSiteMount removes every bind of this disk from the site (ExecStop),
// wherever in the home it is now: the user may have renamed mounts/<name>
// since, and the bind moves with the folder. Each mount point under the home
// that /proc/self/mountinfo lists is opened without following a symlink and
// detached only when it is this disk (the stage's device), so a symlink or
// another mount is never touched.
func UnbindSiteMount(cfg config.Config, domain, name string) error {
	if err := checkMountArgs(domain, name); err != nil {
		return err
	}
	stagePath := mountStage(cfg, domain, name)
	var stage, parent syscall.Stat_t
	if err := syscall.Stat(stagePath, &stage); err != nil {
		return nil
	}
	if err := syscall.Stat(filepath.Dir(stagePath), &parent); err != nil || parent.Dev == stage.Dev {
		return nil // the disk is not mounted: nothing of it can be bound
	}
	home := filepath.Join(cfg.SitesRoot, domain)
	var failed []string
	// Nested binds (a bind inside a bind) show once the outer one is gone.
	for round := 0; round < 8; round++ {
		points, err := mountPointsUnder(home)
		if err != nil {
			return err
		}
		detached := 0
		for _, p := range points {
			rel, err := filepath.Rel(home, p)
			if err != nil || strings.HasPrefix(rel, "..") {
				continue
			}
			parts := append([]string{domain}, strings.Split(rel, "/")...)
			fd, err := openBeneath(cfg.SitesRoot, parts)
			if err != nil {
				continue // a symlink or gone: not followed
			}
			pfd, err := openBeneath(cfg.SitesRoot, parts[:len(parts)-1])
			if err != nil {
				syscall.Close(fd)
				continue
			}
			// This disk, and the root of a mount of it: a folder on the disk
			// swapped in at that path in between has its parent on the disk too.
			var here, up syscall.Stat_t
			if syscall.Fstat(fd, &here) == nil && syscall.Fstat(pfd, &up) == nil && here.Dev == stage.Dev && up.Dev != here.Dev {
				err := syscall.Unmount("/proc/self/fd/"+strconv.Itoa(fd), syscall.MNT_DETACH)
				switch {
				case err == nil:
					detached++
				case err == syscall.EINVAL || err == syscall.ENOENT:
					// not a mount (any more): nothing to detach
				default:
					failed = append(failed, p+": "+err.Error())
				}
			}
			syscall.Close(pfd)
			syscall.Close(fd)
		}
		if detached == 0 {
			break
		}
	}
	if len(failed) > 0 {
		return fmt.Errorf("could not detach: %s", strings.Join(failed, "; "))
	}
	return nil
}

// mountPointsUnder lists the mount points below dir (from mountinfo, its
// octal escapes undone), deepest first.
func mountPointsUnder(dir string) ([]string, error) {
	b, err := os.ReadFile("/proc/self/mountinfo")
	if err != nil {
		return nil, err
	}
	var out []string
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) < 5 {
			continue
		}
		p := unescapeMountinfo(f[4])
		if strings.HasPrefix(p, dir+"/") {
			out = append(out, p)
		}
	}
	sort.Slice(out, func(i, j int) bool { return len(out[i]) > len(out[j]) })
	return out, nil
}

func unescapeMountinfo(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+3 < len(s) {
			if v, err := strconv.ParseUint(s[i+1:i+4], 8, 8); err == nil {
				b.WriteByte(byte(v))
				i += 3
				continue
			}
		}
		b.WriteByte(s[i])
	}
	return b.String()
}
