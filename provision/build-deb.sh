#!/usr/bin/env bash
# The wspanel-agent package: the agent and the wspanel CLI, so that fixes of
# the agent reach servers through apt (the panel and the configuration stay
# the installer's). Needs dpkg-deb (Debian, Ubuntu; the release workflow).
#
#   provision/build-deb.sh <version> <arch> <panel-agent binary> <wspanel binary> <out dir>
#
# 1.2.0-rc1 becomes the Debian version 1.2.0~rc1 (sorts before 1.2.0).
set -euo pipefail
VERSION=${1:?version} ARCH=${2:?arch} AGENT=${3:?panel-agent binary} CLI=${4:?wspanel binary} OUT=${5:?out dir}
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "not a version: $VERSION" >&2; exit 1; }
[[ $ARCH == amd64 || $ARCH == arm64 ]] || { echo "arch: amd64 or arm64" >&2; exit 1; }
DEBVER="${VERSION/-/\~}"

ROOT="$(mktemp -d)"
chmod 0755 "$ROOT" # the package root is /; mktemp makes it 0700
trap 'rm -rf "$ROOT"' EXIT
install -D -m 0755 "$AGENT" "$ROOT/usr/sbin/panel-agent"
install -D -m 0755 "$CLI" "$ROOT/usr/bin/wspanel"
install -d "$ROOT/usr/share/doc/wspanel-agent" "$ROOT/DEBIAN"
cat > "$ROOT/usr/share/doc/wspanel-agent/copyright" <<'TXT'
WSPanel agent and command line tool.
The panel, its installer and the configuration they write are installed by
the WSPanel installer (./install, get.sh), not by this package.
TXT

cat > "$ROOT/DEBIAN/control" <<CONTROL
Package: wspanel-agent
Version: $DEBVER
Architecture: $ARCH
Maintainer: WSPanel <noreply@github.com>
Section: admin
Priority: optional
Installed-Size: $(du -sk "$ROOT/usr" | cut -f1)
Description: WSPanel agent and command line tool
 The privileged agent of the WSPanel hosting panel (panel-agent) and its
 command line client (wspanel). Installed and configured by the WSPanel
 installer; updates of this package bring fixes of the agent.
CONTROL

# An upgrade restarts a running agent (a fresh install leaves that to the
# installer, which writes its configuration and unit first).
cat > "$ROOT/DEBIAN/postinst" <<'SH'
#!/bin/sh
set -e
if [ "$1" = configure ] && [ -d /run/systemd/system ]; then
  systemctl try-restart panel-agent.service >/dev/null 2>&1 || true
fi
SH
chmod 0755 "$ROOT/DEBIAN/postinst"

mkdir -p "$OUT"
dpkg-deb --root-owner-group --build -Zxz "$ROOT" "$OUT/wspanel-agent_${DEBVER}_${ARCH}.deb" >/dev/null
echo "$OUT/wspanel-agent_${DEBVER}_${ARCH}.deb"
