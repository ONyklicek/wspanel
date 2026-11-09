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
DEB_COMMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$DEB_COMMON_DIR/deb-common.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
tar -xzf "$BUNDLE" -C "$WORK"
B="$WORK/wspanel"
VERSION="$(cat "$B/VERSION")"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "the bundle has no release version" >&2; exit 1; }
DEBVER="$(deb_version "$VERSION")"

ROOT="$WORK/root"
install -d -m 0755 "$ROOT" "$ROOT/usr/share" "$ROOT/usr/sbin" "$ROOT/DEBIAN"
# The bundle without its binaries (the agent comes with wspanel-agent) and
# without what only a git checkout needs.
rm -rf "${B:?}/bin"
find "$B" \( -name .gitignore -o -name .gitattributes -o -name .gitkeep \) -delete
chmod 0755 "$B/provision/get.sh"
rm -f "$B/LICENSE" # in /usr/share/doc/wspanel/copyright
mv "$B" "$ROOT/usr/share/wspanel"
install -m 0755 "$ROOT/usr/share/wspanel/provision/files/wspanel-update" "$ROOT/usr/sbin/wspanel-update"
cat > "$ROOT/usr/sbin/wspanel-install" <<'SH'
#!/bin/sh
# Installs or upgrades WSPanel from the installed wspanel package (the guide,
# or --yes with the installer's flags). The agent is wspanel-agent's.
exec env WSPANEL_AGENT_BIN=/usr/sbin/panel-agent WSPANEL_VIA_APT=1 /usr/share/wspanel/install "$@"
SH
chmod 0755 "$ROOT/usr/sbin/wspanel-install"
MAN="$ROOT/usr/share/wspanel/provision/files/man"
deb_man "$ROOT" 8 "$MAN/wspanel-install.8" "$MAN/wspanel-update.8"
deb_doc wspanel "$ROOT" "$ROOT/usr/share/wspanel/CHANGES" "$DEBVER" "Files: usr/share/wspanel/panel/resources/fonts/instrument-sans/*
Copyright: 2022 The Instrument Sans Project Authors
License: OFL-1.1
 The full text is in usr/share/wspanel/panel/resources/fonts/instrument-sans/OFL.txt."
deb_overrides wspanel "$ROOT" \
  "# PHP comes with the installer (wspanel-install), from its own repository." \
  "wspanel: php-script-but-no-php-cli-dep php * [usr/share/wspanel/panel/artisan]"

cat > "$ROOT/DEBIAN/control" <<CONTROL
Package: wspanel
Version: $DEBVER
Architecture: all
Maintainer: $DEB_MAINTAINER
Depends: wspanel-agent (= $DEBVER), wspanel-archive-keyring, ca-certificates, curl
Section: admin
Priority: optional
Homepage: https://github.com/ONyklicek/wspanel
Installed-Size: $(du -sk "$ROOT/usr" | cut -f1)
Description: hosting panel for one Debian server (installer and panel)
 WSPanel manages sites (PHP, Laravel, Symfony, Node), databases, mail, DNS,
 TLS certificates and backups of one Debian 13 server, with clients and
 their packages.
 .
 This package brings the installer and the panel; run "wspanel-install" to
 set the server up (or to apply an upgrade), and "wspanel-update" for a
 newer release.
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
deb_md5sums "$ROOT"
dpkg-deb --root-owner-group --build -Zxz "$ROOT" "$OUT/wspanel_${DEBVER}_all.deb" >/dev/null
echo "$OUT/wspanel_${DEBVER}_all.deb"
