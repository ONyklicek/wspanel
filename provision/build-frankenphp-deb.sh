#!/usr/bin/env bash
# The wspanel-frankenphp package: the FrankenPHP build WSPanel was tested
# with (panel, proxy, sites in FrankenPHP mode), in /usr/lib/wspanel. The
# installer links it to /usr/local/bin/frankenphp, where the units look.
#
# FrankenPHP rebuilds the binaries of its newest release every night under
# the same name, so a checksum pinned in the installer stops matching. The
# release workflow therefore downloads it once, checks that FrankenPHP's own
# workflow built it (GitHub attestation) and ships that exact file in this
# package: servers get it from the release or the apt repository, checked
# by apt like every package.
#
#   provision/build-frankenphp-deb.sh <wspanel version> <arch> <out dir> [changes file]
#
# The FrankenPHP version is the installer's (FRANKENPHP_VERSION). Verification
# needs the GitHub CLI (gh) with a token; WSPANEL_FRANKENPHP_UNVERIFIED=1 skips
# it (local test builds only). Needs dpkg-deb.
set -euo pipefail
VERSION=${1:?wspanel version} ARCH=${2:?arch} OUT=${3:?out dir} CHANGES=${4:-}
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "not a version: $VERSION" >&2; exit 1; }
DEB_COMMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$DEB_COMMON_DIR/deb-common.sh"
DEBVER="$(deb_version "$VERSION")"
FP="$(sed -n 's/^FRANKENPHP_VERSION="\(v[0-9.]*\)"$/\1/p' "$DEB_COMMON_DIR/install.sh")"
[[ -n "$FP" ]] || { echo "FRANKENPHP_VERSION not found in install.sh" >&2; exit 1; }
case "$ARCH" in
  amd64) ASSET=frankenphp-linux-x86_64 ;;
  arm64) ASSET=frankenphp-linux-aarch64 ;;
  *) echo "unsupported architecture: $ARCH" >&2; exit 1 ;;
esac

ROOT="$(mktemp -d)"
chmod 0755 "$ROOT"
trap 'rm -rf "$ROOT"' EXIT
BIN="$ROOT/usr/lib/wspanel/frankenphp"
install -d -m 0755 "$ROOT/usr/lib/wspanel"
# Through the API with gh where it is (github.com's download links answer
# 504 at times), else the download link.
if command -v gh >/dev/null && gh auth status >/dev/null 2>&1; then
  gh release download "$FP" --repo php/frankenphp --pattern "$ASSET" --output "$BIN"
else
  curl -fsSL --retry 10 --retry-delay 15 --retry-all-errors -o "$BIN" \
    "https://github.com/php/frankenphp/releases/download/$FP/$ASSET"
fi
chmod 0755 "$BIN"
if [[ "${WSPANEL_FRANKENPHP_UNVERIFIED:-}" == 1 ]]; then
  echo "warning: FrankenPHP $FP ($ARCH) not verified (WSPANEL_FRANKENPHP_UNVERIFIED=1)" >&2
else
  # Built by FrankenPHP's release workflow in php/frankenphp, nothing else.
  gh attestation verify "$BIN" --repo php/frankenphp \
    --signer-workflow php/frankenphp/.github/workflows/static.yaml >/dev/null \
    || { echo "FrankenPHP $FP ($ARCH): no valid attestation from php/frankenphp" >&2; exit 1; }
fi
grep -aqF "FrankenPHP $FP" "$BIN" || grep -aqF "${FP#v}" "$BIN" \
  || { echo "the downloaded binary is not FrankenPHP $FP" >&2; exit 1; }
SHA="$(sha256sum "$BIN" | cut -d' ' -f1)"

deb_doc wspanel-frankenphp "$ROOT" "$CHANGES" "$DEBVER" "Files: usr/lib/wspanel/frankenphp
Copyright: Kévin Dunglas and the FrankenPHP contributors
License: MIT and PHP-3.01 and Apache-2.0
Comment: FrankenPHP $FP, the static build of its release
 (https://github.com/php/frankenphp/releases/tag/$FP, $ASSET,
 sha256 $SHA). FrankenPHP itself is MIT licensed (the text below); it
 embeds PHP (PHP License 3.01) and Caddy (Apache License 2.0) with their
 own dependencies, listed with their licenses at
 https://github.com/php/frankenphp.

License: PHP-3.01
 The full text: https://www.php.net/license/3_01.txt

License: Apache-2.0
 On Debian systems the full text is in /usr/share/common-licenses/Apache-2.0."
deb_overrides wspanel-frankenphp "$ROOT" \
  "# FrankenPHP's official static build (static PIE): PHP, Caddy and their libraries in one file." \
  "wspanel-frankenphp: shared-library-lacks-prerequisites [usr/lib/wspanel/frankenphp]" \
  "wspanel-frankenphp: embedded-library * [usr/lib/wspanel/frankenphp]"

install -d -m 0755 "$ROOT/DEBIAN"
cat > "$ROOT/DEBIAN/control" <<CONTROL
Package: wspanel-frankenphp
Version: $DEBVER
Architecture: $ARCH
Maintainer: $DEB_MAINTAINER
Depends: init-system-helpers (>= 1.54~)
Section: httpd
Priority: optional
Homepage: https://github.com/ONyklicek/wspanel
Installed-Size: $(du -sk "$ROOT/usr" | cut -f1)
Description: FrankenPHP application server as used by the WSPanel hosting panel
 FrankenPHP ${FP#v} (PHP with the Caddy web server in one binary), the build
 this WSPanel release was tested with: it runs the panel, its reverse proxy
 and the sites in FrankenPHP mode. Installed in /usr/lib/wspanel; the
 WSPanel installer links it to /usr/local/bin/frankenphp.
CONTROL

# A new build: the panel and the proxy run it from now on (a few seconds);
# sites in FrankenPHP mode with their next restart or the installer.
cat > "$ROOT/DEBIAN/postinst" <<'SH'
#!/bin/sh
set -e
if [ "$1" = configure ] && [ -n "$2" ]; then
  for unit in panel-web.service panel-proxy.service; do
    deb-systemd-invoke try-restart "$unit" >/dev/null 2>&1 || true
  done
fi
SH
chmod 0755 "$ROOT/DEBIAN/postinst"

deb_md5sums "$ROOT"
mkdir -p "$OUT"
dpkg-deb --root-owner-group --build -Zxz "$ROOT" "$OUT/wspanel-frankenphp_${DEBVER}_${ARCH}.deb" >/dev/null
echo "$OUT/wspanel-frankenphp_${DEBVER}_${ARCH}.deb"
