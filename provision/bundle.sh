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

# What changed since the previous release (the packages' changelog): the
# subjects of the commits since the previous tag, where git history is.
if prev="$(git -C "$ROOT" describe --tags --abbrev=0 --match 'v*' HEAD^ 2>/dev/null)"; then
  git -C "$ROOT" log --no-merges --format='- %s' "$prev..HEAD" > "$B/CHANGES" 2>/dev/null || : > "$B/CHANGES"
else
  : > "$B/CHANGES"
fi

# The agent and the apt repository's key as Debian packages too (where
# dpkg-deb is: the release workflow); the installer puts the agent under apt,
# which then brings its fixes.
if command -v dpkg-deb >/dev/null; then
  "$ROOT/provision/build-deb.sh" "$VERSION" "$ARCH" "$B/bin/panel-agent-linux-$ARCH" "$B/bin/wspanel-linux-$ARCH" "$B/bin" "$B/CHANGES" >/dev/null
  if [[ -n "${WSPANEL_REPO:-}" ]]; then
    "$ROOT/provision/build-keyring-deb.sh" "$VERSION" "$WSPANEL_REPO" "$ROOT/provision/files/wspanel-archive-keyring.asc" "$B/bin" "$B/CHANGES" >/dev/null
  fi
fi

rsync -a --exclude='.DS_Store' "$ROOT/provision/" "$B/provision/"
rm -f "$B/provision/bundle.sh" "$B/provision/build-deb.sh" "$B/provision/build-installer-deb.sh" \
  "$B/provision/build-keyring-deb.sh" "$B/provision/deb-common.sh" "$B/provision/apt-repo.sh"
install -m 0755 "$ROOT/provision/wspanel-install.sh" "$B/install"
rm -f "$B/provision/wspanel-install.sh"
rsync -a "$ROOT/docs/" "$B/docs/"
install -m 0644 "$ROOT/LICENSE" "$B/LICENSE"
# The panel's source: what composer/npm build on the server, without local state.
# Patterns anchored to the panel's root: an unanchored "vendor" also dropped
# resources/views/vendor (the published, adjusted package views).
rsync -a --delete \
  --exclude=/vendor --exclude=/node_modules --exclude=/.env --exclude='/.env.*.local' \
  --exclude='/storage/logs/*' --exclude='/storage/framework/*/*' --exclude='/database/*.sqlite' \
  --exclude='/public/build' --exclude='/public/hot' --exclude='/bootstrap/cache/*.php' --exclude=/.phpunit.cache --exclude=.idea --exclude=.DS_Store \
  --exclude=/.claude --exclude=/.mcp.json --exclude=/AGENTS.md --exclude=/CLAUDE.md --exclude=/boost.json \
  "$ROOT/panel/" "$B/panel/"
echo "$VERSION" > "$B/VERSION"
# Where releases are published (owner/name): the panel looks there for newer
# ones. Set by the release workflow; a local bundle has none.
if [[ -n "${WSPANEL_REPO:-}" ]]; then
  [[ "$WSPANEL_REPO" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || { echo "WSPANEL_REPO must be owner/name" >&2; exit 1; }
  echo "$WSPANEL_REPO" > "$B/REPO"
fi

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
