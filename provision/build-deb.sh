#!/usr/bin/env bash
# The wspanel-agent package: the agent, its systemd unit and the wspanel CLI,
# so that fixes of the agent reach servers through apt (the panel and the
# configuration stay the installer's). Needs dpkg-deb.
#
#   provision/build-deb.sh <version> <arch> <panel-agent binary> <wspanel binary> <out dir> [changes file]
#
# 1.2.0-rc1 becomes the Debian version 1.2.0~rc1 (sorts before 1.2.0).
set -euo pipefail
VERSION=${1:?version} ARCH=${2:?arch} AGENT=${3:?panel-agent binary} CLI=${4:?wspanel binary} OUT=${5:?out dir} CHANGES=${6:-}
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "not a version: $VERSION" >&2; exit 1; }
[[ $ARCH == amd64 || $ARCH == arm64 ]] || { echo "arch: amd64 or arm64" >&2; exit 1; }
DEB_COMMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$DEB_COMMON_DIR/deb-common.sh"
DEBVER="$(deb_version "$VERSION")"
FILES="$DEB_COMMON_DIR/files"

ROOT="$(mktemp -d)"
chmod 0755 "$ROOT" # the package root is /; mktemp makes it 0700
trap 'rm -rf "$ROOT"' EXIT
install -D -m 0755 "$AGENT" "$ROOT/usr/sbin/panel-agent"
install -D -m 0755 "$CLI" "$ROOT/usr/bin/wspanel"
# The unit in the package (systemctl edit panel-agent for local changes). It
# starts only once the installer has written the configuration.
install -d -m 0755 "$ROOT/usr/lib/systemd/system"
sed -e '/^Description=/a Documentation=man:panel-agent(8)' -e '/^\[Unit\]/a ConditionPathExists=/etc/panel-agent/agent.json' "$FILES/panel-agent.service" > "$ROOT/usr/lib/systemd/system/panel-agent.service"
chmod 0644 "$ROOT/usr/lib/systemd/system/panel-agent.service"
deb_man "$ROOT" 8 "$FILES/man/panel-agent.8"
deb_man "$ROOT" 1 "$FILES/man/wspanel.1"
deb_doc wspanel-agent "$ROOT" "$CHANGES" "$DEBVER"
deb_overrides wspanel-agent "$ROOT" \
  "# Go: one static binary each, no shared libraries by design." \
  "wspanel-agent: statically-linked-binary [usr/bin/wspanel]" \
  "wspanel-agent: statically-linked-binary [usr/sbin/panel-agent]"

install -d -m 0755 "$ROOT/DEBIAN"
cat > "$ROOT/DEBIAN/control" <<CONTROL
Package: wspanel-agent
Version: $DEBVER
Architecture: $ARCH
Maintainer: $DEB_MAINTAINER
Depends: init-system-helpers (>= 1.54~)
Recommends: wspanel-archive-keyring
Section: admin
Priority: optional
Homepage: https://github.com/ONyklicek/wspanel
Installed-Size: $(du -sk "$ROOT/usr" | cut -f1)
Description: privileged agent and command line tool of the WSPanel hosting panel
 The agent (panel-agent) is the only part of the WSPanel hosting panel that
 runs as root: it performs a fixed list of operations the panel asks for
 over a Unix socket, each one audited. The package also brings the wspanel
 command line client of the panel's REST API.
 .
 The WSPanel installer (wspanel-install) configures the agent; updates of
 this package bring fixes of the agent.
CONTROL

# Enabled with the package (the unit waits for the configuration), restarted
# by an upgrade, stopped by a removal (with a warning: the panel needs it).
cat > "$ROOT/DEBIAN/postinst" <<'SH'
#!/bin/sh
set -e
if [ "$1" = configure ] || [ "$1" = abort-upgrade ] || [ "$1" = abort-deconfigure ] || [ "$1" = abort-remove ]; then
  # The installer of releases before the package carried the unit put its
  # own copy in /etc, which would hide this one.
  if [ -f /etc/systemd/system/panel-agent.service ] && grep -q '^Description=WSPanel privileged agent' /etc/systemd/system/panel-agent.service; then
    rm -f /etc/systemd/system/panel-agent.service
  fi
  deb-systemd-helper unmask panel-agent.service >/dev/null || true
  if deb-systemd-helper --quiet was-enabled panel-agent.service; then
    deb-systemd-helper enable panel-agent.service >/dev/null || true
  else
    deb-systemd-helper update-state panel-agent.service >/dev/null || true
    deb-systemd-helper enable panel-agent.service >/dev/null || true
  fi
  if [ -d /run/systemd/system ]; then
    systemctl --system daemon-reload >/dev/null || true
    if [ -n "$2" ]; then
      deb-systemd-invoke try-restart panel-agent.service >/dev/null || true
    fi
  fi
fi
SH
cat > "$ROOT/DEBIAN/prerm" <<'SH'
#!/bin/sh
set -e
if [ "$1" = remove ] && [ -d /run/systemd/system ]; then
  echo "wspanel-agent: stopping the agent; the WSPanel panel does not work without it." >&2
  deb-systemd-invoke stop panel-agent.service >/dev/null || true
fi
SH
cat > "$ROOT/DEBIAN/postrm" <<'SH'
#!/bin/sh
set -e
if [ -d /run/systemd/system ]; then
  systemctl --system daemon-reload >/dev/null || true
fi
if [ "$1" = remove ]; then
  deb-systemd-helper mask panel-agent.service >/dev/null || true
fi
if [ "$1" = purge ]; then
  deb-systemd-helper purge panel-agent.service >/dev/null || true
  deb-systemd-helper unmask panel-agent.service >/dev/null || true
fi
SH
chmod 0755 "$ROOT/DEBIAN/postinst" "$ROOT/DEBIAN/prerm" "$ROOT/DEBIAN/postrm"

mkdir -p "$OUT"
deb_md5sums "$ROOT"
dpkg-deb --root-owner-group --build -Zxz "$ROOT" "$OUT/wspanel-agent_${DEBVER}_${ARCH}.deb" >/dev/null
echo "$OUT/wspanel-agent_${DEBVER}_${ARCH}.deb"
