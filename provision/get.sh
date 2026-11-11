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
#   ... | sudo env WSPANEL_VERSION=pre bash     the newest release candidate too
#
# Releases in a private repository: get this script with gh and give it a
# token that may read the repository:
#
#   gh release download --repo @REPO@ --pattern get.sh
#   sudo env WSPANEL_TOKEN=github_pat_… bash get.sh
#
# Best a fine-grained token for that one repository with read-only
# "Contents" access: the server keeps nothing of it, but it passes through.
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

  local tmp
  tmp="$(mktemp -d)" # 0700: only root reads what is in it
  trap 'rm -rf "$tmp"' EXIT
  # The token reaches curl in a config file, never on its command line
  # (any user on the server sees command lines in ps).
  local auth=()
  if [[ -n "$token" ]]; then
    [[ "$token" =~ ^[A-Za-z0-9_]+$ ]] || die "WSPANEL_TOKEN does not look like a GitHub token"
    (umask 077 && printf 'header = "Authorization: Bearer %s"\n' "$token" > "$tmp/auth.curlrc")
    auth=(-K "$tmp/auth.curlrc")
  fi

  # The newest release candidate or release: the newest of the list (the API
  # lists them newest first; drafts only with a token that may see them).
  if [[ "$version" == pre ]]; then
    version="$( (curl -fsSL --retry 8 --retry-delay 10 --retry-all-errors "${auth[@]}" "$api/repos/$repo/releases?per_page=10" || true) \
      | json_lines | sed -nE 's/^ *"tag_name": *"v?([^"]+)".*/\1/p' | head -1)"
    [[ -n "$version" ]] || die "no release found in $repo"
  fi
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

  say "WSPanel $version ($arch) from $repo"
  fetch "$file" "$tmp/$file"
  fetch SHA256SUMS "$tmp/SHA256SUMS"
  verify_signature
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

# The release's checksums are signed with the key of WSPanel's apt
# repository (from 1.7.1 on), carried in this script (binary, base64): a
# bundle and its SHA256SUMS changed together in a release do not pass.
KEYRING='@KEYRING@'
FIRST_SIGNED=1.7.1
verify_signature() {
  if [[ "$(printf '%s\n%s\n' "$FIRST_SIGNED" "${version%%-*}" | sort -V | head -1)" != "$FIRST_SIGNED" ]]; then
    # Never on its own: whoever could change the releases could otherwise
    # point the newest one at an old, unsigned number.
    [[ "${WSPANEL_VERSION#v}" == "$version" && "${WSPANEL_ALLOW_UNSIGNED:-}" == 1 ]] \
      || die "v$version predates signed releases; to install it anyway: WSPANEL_VERSION=$version WSPANEL_ALLOW_UNSIGNED=1"
    say "v$version predates signed checksums: checksum only (WSPANEL_ALLOW_UNSIGNED)"
    return 0
  fi
  [[ $KEYRING != @*@ ]] || die "this get.sh carries no signing key: use the one from the release"
  command -v gpgv >/dev/null || die "gpgv is needed (Debian's gpgv package)"
  fetch SHA256SUMS.asc "$tmp/SHA256SUMS.asc"
  printf '%s' "$KEYRING" | base64 -d > "$tmp/wspanel.gpg" || die "the signing key in this get.sh is damaged"
  gpgv --keyring "$tmp/wspanel.gpg" "$tmp/SHA256SUMS.asc" "$tmp/SHA256SUMS" >/dev/null 2>&1 \
    || die "SHA256SUMS of v$version is not signed by WSPanel's key"
  say "Signature OK"
}

# json_lines: one "key": value per line, however GitHub formats its JSON
# (no jq on a fresh Debian). Release names and asset names hold no commas.
json_lines() { tr ',{}[]' '\n\n\n\n\n'; }

# fetch <asset name> <destination>: from the release of $version. A public
# repository: the download link first; github.com answers it with 504 at
# times, and the same file then still comes through the API. A private one:
# through the API only (with the token).
fetch() {
  local name=$1 out=$2
  if [[ -z "$token" ]]; then
    curl -fSL --retry 3 --retry-delay 5 --retry-all-errors --progress-bar -o "$out" "$base/$repo/releases/download/v$version/$name" \
      && return 0
    say "github.com did not serve $name, trying the API"
  fi
  local id
  id="$( (curl -fsSL --retry 8 --retry-delay 10 --retry-all-errors "${auth[@]}" "$api/repos/$repo/releases/tags/v$version" || true) | json_lines \
    | awk -v want="$name" '
        /"url": *".*\/releases\/assets\/[0-9]+"/ { match($0, /assets\/[0-9]+/); url = substr($0, RSTART + 7, RLENGTH - 7) }
        /"name":/ { n = $0; sub(/^ *"name": *"/, "", n); sub(/".*/, "", n); if (n == want && url != "") { print url; exit } }')"
  [[ -n "$id" ]] || die "$name is not in release v$version"
  curl -fSL --retry 8 --retry-delay 10 --retry-all-errors "${auth[@]}" -H "Accept: application/octet-stream" -o "$out" "$api/repos/$repo/releases/assets/$id" \
    || die "download of $name failed"
}

main "$@"
