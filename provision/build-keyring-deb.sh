#!/usr/bin/env bash
# The wspanel-archive-keyring package: the key the WSPanel apt repository is
# signed with and the repository itself, so that one package sets apt up:
#
#   curl -fLO https://<owner>.github.io/<repo>/apt/wspanel-archive-keyring.deb
#   sudo apt install ./wspanel-archive-keyring.deb
#
# and a new key (rotation) arrives through apt like any update. Needs dpkg-deb.
#
#   provision/build-keyring-deb.sh <version> <owner/repo> <key .asc> <out dir> [changes file]
#
# The repository's URI is GitHub Pages of owner/repo; WSPANEL_APT_URI at
# install time (a mirror, a test repository) takes its place.
set -euo pipefail
VERSION=${1:?version} REPO=${2:?owner/repo} KEY=${3:?key .asc} OUT=${4:?out dir} CHANGES=${5:-}
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "not a version: $VERSION" >&2; exit 1; }
[[ "$REPO" =~ ^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$ ]] || { echo "owner/repo: $REPO" >&2; exit 1; }
DEB_COMMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$DEB_COMMON_DIR/deb-common.sh"
DEBVER="$(deb_version "$VERSION")"
OWNER="${BASH_REMATCH[1],,}" NAME="${BASH_REMATCH[2]}"
URI="https://$OWNER.github.io/$NAME/apt"

ROOT="$(mktemp -d)"
chmod 0755 "$ROOT"
trap 'rm -rf "$ROOT"' EXIT
install -D -m 0644 "$KEY" "$ROOT/usr/share/keyrings/wspanel-archive-keyring.asc"
# Fixes of WSPanel with the daily unattended upgrades (when those are on).
install -d -m 0755 "$ROOT/etc/apt/apt.conf.d"
cat > "$ROOT/etc/apt/apt.conf.d/52wspanel-unattended" <<'CONF'
// From the wspanel-archive-keyring package: WSPanel's updates (fixes of
// the installed line) come with the daily unattended upgrades, when those
// are on (Zabezpečení → Aktualizace in the panel).
Unattended-Upgrade::Origins-Pattern { "origin=WSPanel"; };
CONF
deb_doc wspanel-archive-keyring "$ROOT" "$CHANGES" "$DEBVER"

install -d -m 0755 "$ROOT/DEBIAN"
echo /etc/apt/apt.conf.d/52wspanel-unattended > "$ROOT/DEBIAN/conffiles"
cat > "$ROOT/DEBIAN/control" <<CONTROL
Package: wspanel-archive-keyring
Version: $DEBVER
Architecture: all
Maintainer: $DEB_MAINTAINER
Section: admin
Priority: optional
Homepage: https://github.com/ONyklicek/wspanel
Installed-Size: $(du -sk "$ROOT" --exclude=DEBIAN | cut -f1)
Description: apt repository of the WSPanel hosting panel and its key
 Sets apt up for the WSPanel repository: the key its indexes are signed
 with (in /usr/share/keyrings) and the source in
 /etc/apt/sources.list.d/wspanel.sources (channel "stable" until the WSPanel
 installer sets the channel of the installed line), at
 $URI
 .
 A new key comes with an update of this package.
CONTROL

# The source is not a conffile: the installer and wspanel-update change its
# channel, which would make every upgrade of this package ask about it.
cat > "$ROOT/DEBIAN/postinst" <<SH
#!/bin/sh
set -e
SRC=/etc/apt/sources.list.d/wspanel.sources
if [ "\$1" = configure ]; then
  # Put back what a removal set aside (with the channel it had).
  if [ ! -e "\$SRC" ] && [ -e "\$SRC.disabled" ]; then
    mv "\$SRC.disabled" "\$SRC"
  fi
  if [ ! -e "\$SRC" ]; then
    cat > "\$SRC" <<SOURCE
# WSPanel (package wspanel-archive-keyring). The channel: stable (the newest
# release), pre (release candidates), or a line such as 1.2 (its fixes only;
# set by the WSPanel installer).
Types: deb
URIs: \${WSPANEL_APT_URI:-$URI}
Suites: \${WSPANEL_CHANNEL:-stable}
Components: main
Signed-By: /usr/share/keyrings/wspanel-archive-keyring.asc
SOURCE
    chmod 0644 "\$SRC"
  else
    # A source written by hand or by an older installer: this key from now on.
    sed -i 's|^Signed-By: .*|Signed-By: /usr/share/keyrings/wspanel-archive-keyring.asc|' "\$SRC"
    rm -f /etc/apt/keyrings/wspanel.asc
  fi
fi
SH
# An older WSPanel installer wrote the unattended file itself: it goes, so
# that the package's conffile takes its place without a question.
cat > "$ROOT/DEBIAN/preinst" <<'SH'
#!/bin/sh
set -e
F=/etc/apt/apt.conf.d/52wspanel-unattended
if [ "$1" = install ] && [ -e "$F" ] && ! dpkg-query -S "$F" >/dev/null 2>&1; then
  rm -f "$F"
fi
SH
# Removed, the key is gone: the source is set aside (apt reads only *.sources),
# or apt update would fail on it; purged, it goes.
cat > "$ROOT/DEBIAN/postrm" <<'SH'
#!/bin/sh
set -e
SRC=/etc/apt/sources.list.d/wspanel.sources
if [ "$1" = remove ] && [ -e "$SRC" ]; then
  mv "$SRC" "$SRC.disabled"
fi
if [ "$1" = purge ]; then
  rm -f "$SRC" "$SRC.disabled"
fi
SH
chmod 0755 "$ROOT/DEBIAN/preinst" "$ROOT/DEBIAN/postinst" "$ROOT/DEBIAN/postrm"

mkdir -p "$OUT"
deb_md5sums "$ROOT"
dpkg-deb --root-owner-group --build -Zxz "$ROOT" "$OUT/wspanel-archive-keyring_${DEBVER}_all.deb" >/dev/null
echo "$OUT/wspanel-archive-keyring_${DEBVER}_all.deb"
