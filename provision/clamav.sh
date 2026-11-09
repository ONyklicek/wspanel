#!/usr/bin/env bash
# WSPanel: ClamAV for incoming mail (Rspamd asks clamd over its socket).
# Run by the agent when the antivirus is switched on or off (Schránky →
# Ochrana pošty). Idempotent.
#
#   clamav.sh enable|disable
#
# clamd keeps its signatures in memory (about 1 GB); disable stops it and
# its updater but keeps the packages, so switching it back on is quick.
set -euo pipefail
# Run by the agent (umask 077): what it installs is read by other users.
umask 022
export DEBIAN_FRONTEND=noninteractive
case "${1:-}" in
  enable)
    apt-get install -y -q --no-install-recommends clamav-daemon clamav-freshclam >/dev/null
    systemctl enable --now clamav-freshclam >/dev/null 2>&1
    # The first start needs signatures: fetch them now if the updater has none yet.
    if ! ls /var/lib/clamav/*.c[lv]d >/dev/null 2>&1; then
      systemctl stop clamav-freshclam
      freshclam --quiet || true
      systemctl start clamav-freshclam
    fi
    systemctl enable --now clamav-daemon >/dev/null 2>&1
    for _ in $(seq 1 120); do
      [[ -S /run/clamav/clamd.ctl ]] && break
      sleep 1
    done
    [[ -S /run/clamav/clamd.ctl ]] || { echo "clamd did not start (not enough memory?)" >&2; exit 1; }
    # Rspamd's worker connects to the socket.
    usermod -a -G clamav _rspamd 2>/dev/null || true
    echo "clamav enabled"
    ;;
  disable)
    systemctl disable --now clamav-daemon clamav-freshclam >/dev/null 2>&1 || true
    echo "clamav disabled"
    ;;
  *)
    echo "usage: clamav.sh enable|disable" >&2
    exit 2
    ;;
esac
