#!/usr/bin/env bash
# WSPanel in one command, on a fresh Debian 13:
#
#   curl -fsSL https://github.com/@REPO@/releases/latest/download/get.sh | sudo bash
#
# Downloads the newest release for this server's architecture, checks it
# against the release's SHA256SUMS, unpacks it and starts the installer's
# guide. On a server that has WSPanel it upgrades (the guide offers to keep
# the settings). Arguments go to the installer:
#
#   ... | sudo bash -s -- --yes --panel-domain=panel.example.com --acme-email=me@example.com \
#                         --db=mariadb --mail-hosting=yes --admin-email=me@example.com
#
# Through sudo, settings go with env (sudo itself may drop them):
#
#   ... | sudo env WSPANEL_VERSION=1.2.0 bash   a given version instead of the newest
#
# Releases in a private repository: get this script with gh and give it a
# token that may read the repository:
#
#   gh release download --repo @REPO@ --pattern get.sh
#   sudo env WSPANEL_TOKEN="$(gh auth token)" bash get.sh
set -euo pipefail

# Everything in a function called on the last line: piped into bash, the
# script is read whole before anything runs (a cut-off download runs nothing).
main() {
  local repo="${WSPANEL_REPO:-@REPO@}"
  local base="${WSPANEL_BASE_URL:-https://github.com}" # tests point it elsewhere
  local api="${WSPANEL_API_URL:-https://api.github.com}"
  local version="${WSPANEL_VERSION:-}" token="${WSPANEL_TOKEN:-}"

  say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
  die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

  [[ $EUID -eq 0 ]] || die "run as root: curl -fsSL … | sudo bash"
  [[ "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || die "WSPANEL_REPO must be owner/name"
  . /etc/os-release
  [[ "${ID:-}" == debian && "${VERSION_CODENAME:-}" == trixie ]] || die "Debian 13 (trixie) required, found ${PRETTY_NAME:-unknown}"
  local arch
  arch="$(dpkg --print-architecture)"
  [[ $arch == amd64 || $arch == arm64 ]] || die "unsupported architecture: $arch (amd64 or arm64)"
  command -v curl >/dev/null || die "curl is needed"
  command -v sha256sum >/dev/null || die "sha256sum is needed (coreutils)"

  local auth=()
  [[ -n "$token" ]] && auth=(-H "Authorization: Bearer $token")

  # The newest release: GitHub's /releases/latest redirects to its tag.
  if [[ -z "$version" ]]; then
    if [[ -n "$token" ]]; then
      version="$( (curl -fsSL "${auth[@]}" "$api/repos/$repo/releases/latest" || true) | json_lines | sed -nE 's/^ *"tag_name": *"v?([^"]+)".*/\1/p' | head -1)"
    else
      version="$( (curl -fsSIL -o /dev/null -w '%{url_effective}' "$base/$repo/releases/latest" || true) | sed -nE 's#.*/tag/v?([^/]+)$#\1#p')"
    fi
    [[ -n "$version" ]] || die "no release found in $repo$([[ -n $token ]] && echo ' (is the token allowed to read it?)' || echo ' (a private repository needs WSPANEL_TOKEN)')"
  fi
  version="${version#v}"
  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || die "not a version: $version"

  local file="wspanel-$version-linux-$arch.tar.gz"
  local dir="/var/lib/wspanel-installer/$version"
  local tmp
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' EXIT

  say "WSPanel $version ($arch) from $repo"
  fetch "$file" "$tmp/$file"
  fetch SHA256SUMS "$tmp/SHA256SUMS"
  (cd "$tmp" && grep -E "  $file\$" SHA256SUMS | sha256sum -c --status) || die "$file does not match the release's SHA256SUMS"
  say "Checksum OK"

  rm -rf "$dir"
  install -d -m 0700 "$dir"
  tar -xzf "$tmp/$file" -C "$dir"
  [[ -x "$dir/wspanel/install" ]] || die "the release has no installer"
  say "Unpacked to $dir/wspanel"

  # The guide needs the terminal; piped in, stdin is this script.
  if [[ ! -t 0 && -e /dev/tty ]] && { : </dev/tty; } 2>/dev/null; then
    exec "$dir/wspanel/install" "$@" </dev/tty
  fi
  exec "$dir/wspanel/install" "$@"
}

# json_lines: one "key": value per line, however GitHub formats its JSON
# (no jq on a fresh Debian). Release names and asset names hold no commas.
json_lines() { tr ',{}[]' '\n\n\n\n\n'; }

# fetch <asset name> <destination>: from the release of $version.
fetch() {
  local name=$1 out=$2
  if [[ -n "$token" ]]; then
    # A private repository: the asset through the API, by its id.
    local id
    id="$( (curl -fsSL "${auth[@]}" "$api/repos/$repo/releases/tags/v$version" || true) | json_lines \
      | awk -v want="$name" '
          /"url": *".*\/releases\/assets\/[0-9]+"/ { match($0, /assets\/[0-9]+/); url = substr($0, RSTART + 7, RLENGTH - 7) }
          /"name":/ { n = $0; sub(/^ *"name": *"/, "", n); sub(/".*/, "", n); if (n == want && url != "") { print url; exit } }')"
    [[ -n "$id" ]] || die "$name is not in release v$version"
    curl -fSL --retry 3 "${auth[@]}" -H "Accept: application/octet-stream" -o "$out" "$api/repos/$repo/releases/assets/$id" \
      || die "download of $name failed"
  else
    curl -fSL --retry 3 --progress-bar -o "$out" "$base/$repo/releases/download/v$version/$name" \
      || die "download of $name failed ($base/$repo/releases/download/v$version/$name)"
  fi
}

main "$@"
