#!/usr/bin/env bash
# Builds the release bundle: everything a server needs in one archive.
#
#   provision/bundle.sh <version> <arch>   ->  dist/wspanel-<version>-linux-<arch>.tar.gz
#
#   wspanel/install          the installer (questions, or --yes with flags)
#   wspanel/provision/       install.sh, mail-hosting.sh, xhprof.sh, files/
#   wspanel/panel/           the panel's source (built on the server)
#   wspanel/docs/            documentation (shown in the panel too)
#   wspanel/bin/             panel-agent and the wspanel CLI for <arch>
set -euo pipefail
VERSION=${1:?version}
ARCH=${2:-amd64}
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
B="$STAGE/wspanel"
mkdir -p "$B/bin" "$OUT"

(cd "$ROOT/agent" && CGO_ENABLED=0 GOOS=linux GOARCH="$ARCH" go build -trimpath \
  -ldflags "-s -w -X wspanel/agent/internal/ops.Version=$VERSION" -o "$B/bin/panel-agent-linux-$ARCH" ./cmd/panel-agent)
(cd "$ROOT/agent" && CGO_ENABLED=0 GOOS=linux GOARCH="$ARCH" go build -trimpath -ldflags '-s -w' -o "$B/bin/wspanel-linux-$ARCH" ./cmd/wspanel)

rsync -a --exclude='.DS_Store' "$ROOT/provision/" "$B/provision/"
rm -f "$B/provision/bundle.sh"
install -m 0755 "$ROOT/provision/wspanel-install.sh" "$B/install"
rm -f "$B/provision/wspanel-install.sh"
rsync -a "$ROOT/docs/" "$B/docs/"
# The panel's source: what composer/npm build on the server, without local state.
# Patterns anchored to the panel's root: an unanchored "vendor" also dropped
# resources/views/vendor (the published, adjusted package views).
rsync -a --delete \
  --exclude=/vendor --exclude=/node_modules --exclude=/.env --exclude='/.env.*.local' \
  --exclude='/storage/logs/*' --exclude='/storage/framework/*/*' --exclude='/database/*.sqlite' \
  --exclude='/public/build' --exclude='/public/hot' --exclude=/.phpunit.cache --exclude=.idea --exclude=.DS_Store \
  "$ROOT/panel/" "$B/panel/"
echo "$VERSION" > "$B/VERSION"

# Built on a Mac: no extended attributes or AppleDouble files, or GNU tar on
# the server warns about each file ("unknown extended header keyword").
if tar --version 2>/dev/null | grep -q bsdtar; then
  COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata -C "$STAGE" -czf "$OUT/wspanel-$VERSION-linux-$ARCH.tar.gz" wspanel
else
  tar -C "$STAGE" -czf "$OUT/wspanel-$VERSION-linux-$ARCH.tar.gz" wspanel
fi
(cd "$OUT" && sha256sum "wspanel-$VERSION-linux-$ARCH.tar.gz" > "wspanel-$VERSION-linux-$ARCH.tar.gz.sha256" 2>/dev/null \
  || shasum -a 256 "wspanel-$VERSION-linux-$ARCH.tar.gz" > "wspanel-$VERSION-linux-$ARCH.tar.gz.sha256")
echo "$OUT/wspanel-$VERSION-linux-$ARCH.tar.gz"
