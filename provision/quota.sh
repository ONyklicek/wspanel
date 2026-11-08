# shellcheck shell=bash
# Group disk quotas for hosting clients (sourced by install.sh and dev/e2e.sh).
#
# Each client is a Linux group (wsc_<id>) and the primary group of its site
# users; the agent sets that group's hard block quota (client.quota). This
# turns group quotas on for the filesystem holding TARGET:
#   ext4  journaled quota (grpjquota) via an online remount + fstab entry
#   xfs   needs "grpquota" at mount time: printed, not done (a reboot)
#   other (overlay in Docker, btrfs, zfs…) left off: the panel only measures.
# Prints one line: "on <mount>", "off <reason>".
enable_group_quota() {
  local target=$1 mnt fstype opts
  read -r mnt fstype opts < <(findmnt --noheadings --output TARGET,FSTYPE,OPTIONS --target "$target") || { echo "off cannot read the mount of $target"; return 0; }
  if [[ "$(quotaon --print-state --group "$mnt" 2>/dev/null || true)" == *'is on'* ]]; then
    echo "on $mnt"; return 0
  fi
  case $fstype in
    ext4)
      modprobe quota_v2 2>/dev/null || true
      local dev opt
      dev=$(findmnt --noheadings --output SOURCE "$mnt")
      if tune2fs -l "$dev" 2>/dev/null | grep -Eq '^Filesystem features:.*\<quota\>'; then
        opt=grpquota # native ext4 quota (hidden quota inodes): enforcement is a mount option
      else
        opt="grpjquota=aquota.group,jqfmt=vfsv1" # journaled quota files
      fi
      if [[ ",$opts," != *",${opt%%=*}"* ]]; then
        mount -o "remount,$opt" "$mnt" 2>/dev/null || { echo "off remount of $mnt with group quota failed"; return 0; }
        _quota_fstab_add "$mnt" "$opt"
      fi
      if [[ $opt != grpquota ]]; then
        quotacheck --create-files --group --no-remount --force "$mnt" >/dev/null 2>&1 || true
      fi
      quotaon --group "$mnt" >/dev/null 2>&1 || { echo "off the kernel refused group quota on $mnt (no quota_v2?)"; return 0; }
      echo "on $mnt"
      ;;
    xfs)
      if [[ ",$opts," == *",grpquota,"* || ",$opts," == *",gquota,"* ]]; then echo "on $mnt"; return 0; fi
      echo "off XFS $mnt: add grpquota to its mount options in /etc/fstab and reboot"
      ;;
    *)
      echo "off $fstype on $mnt has no group quotas"
      ;;
  esac
}

# _quota_fstab_add MOUNT OPTIONS: append OPTIONS to MOUNT's fstab line (backup kept).
_quota_fstab_add() {
  local mnt=$1 opt=$2
  awk -v m="$mnt" '$1 !~ /^#/ && $2 == m { found = 1 } END { exit !found }' /etc/fstab 2>/dev/null || return 0
  cp -a /etc/fstab "/etc/fstab.wspanel-$(date +%s)"
  awk -v m="$mnt" -v o="$opt" 'BEGIN { OFS = "\t" } $1 !~ /^#/ && $2 == m && index($4, o) == 0 { $4 = $4 "," o } { print }' /etc/fstab >/etc/fstab.new && mv /etc/fstab.new /etc/fstab
}
