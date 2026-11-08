#!/usr/bin/env bash
# The wspanel package: WSPanel itself for apt (the installer, provisioning,
# the panel's source and the docs of a release bundle), for
#
#   apt install wspanel && wspanel-install
#
# The agent is its own package (wspanel-agent, same version, a dependency).
# Installing the package installs nothing on the system yet: wspanel-install
# does (questions, or --yes with flags), as ./install of a bundle does.
#
#   provision/build-installer-deb.sh <release bundle .tar.gz> <out dir>
set -euo pipefail
BUNDLE=${1:?release bundle} OUT=${2:?out dir}

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
tar -xzf "$BUNDLE" -C "$WORK"
B="$WORK/wspanel"
VERSION="$(cat "$B/VERSION")"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "the bundle has no release version" >&2; exit 1; }
DEBVER="${VERSION/-/\~}"

ROOT="$WORK/root"
install -d -m 0755 "$ROOT" "$ROOT/usr/share" "$ROOT/usr/sbin" "$ROOT/DEBIAN" "$ROOT/usr/share/doc/wspanel"
# The bundle without its binaries (the agent comes with wspanel-agent).
rm -rf "$B/bin"
mv "$B" "$ROOT/usr/share/wspanel"
install -m 0755 "$ROOT/usr/share/wspanel/provision/files/wspanel-update" "$ROOT/usr/sbin/wspanel-update"
cat > "$ROOT/usr/sbin/wspanel-install" <<'SH'
#!/bin/sh
# Installs or upgrades WSPanel from the installed wspanel package (the guide,
# or --yes with the installer's flags). The agent is wspanel-agent's.
exec env WSPANEL_AGENT_BIN=/usr/sbin/panel-agent WSPANEL_VIA_APT=1 /usr/share/wspanel/install "$@"
SH
chmod 0755 "$ROOT/usr/sbin/wspanel-install"
cat > "$ROOT/usr/share/doc/wspanel/copyright" <<'TXT'
WSPanel: the installer, provisioning and the panel's source of a release.
Bundled third-party files keep their own licences (the panel's font:
SIL Open Font License 1.1, panel/resources/fonts/instrument-sans/OFL.txt).
TXT

cat > "$ROOT/DEBIAN/control" <<CONTROL
Package: wspanel
Version: $DEBVER
Architecture: all
Maintainer: WSPanel <noreply@github.com>
Depends: wspanel-agent (= $DEBVER), ca-certificates, curl
Section: admin
Priority: optional
Installed-Size: $(du -sk "$ROOT/usr" | cut -f1)
Description: WSPanel hosting panel (installer)
 Hosting panel for one Debian 13 server: sites (PHP, Laravel, Symfony,
 Node), databases, mail, DNS, backups. This package brings the installer
 and the panel; run "wspanel-install" to set the server up (or upgrade
 it), and "wspanel-update" later for a newer version.
CONTROL

# Nothing is set up by the package itself: the installer asks first.
cat > "$ROOT/DEBIAN/postinst" <<'SH'
#!/bin/sh
set -e
if [ "$1" = configure ]; then
  if [ -z "$2" ]; then
    echo "WSPanel is unpacked. Set the server up with:  sudo wspanel-install"
  else
    echo "WSPanel $2 -> new version unpacked. Apply it with:  sudo wspanel-install  (keeps the settings)"
  fi
fi
SH
chmod 0755 "$ROOT/DEBIAN/postinst"

mkdir -p "$OUT"
dpkg-deb --root-owner-group --build -Zxz "$ROOT" "$OUT/wspanel_${DEBVER}_all.deb" >/dev/null
echo "$OUT/wspanel_${DEBVER}_all.deb"
