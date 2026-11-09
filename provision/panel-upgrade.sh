#!/usr/bin/env bash
# WSPanel's upgrade started from the panel (Zabezpečení → Aktualizace).
#
#   panel-upgrade.sh <version>
#
# The agent starts it as its own systemd unit (wspanel-upgrade): the upgrade
# restarts the panel and the agent, and a unit of its own outlives both. It
# does what the documented command does, with the choices the server was
# installed with: through apt (wspanel-update) when WSPanel came that way,
# otherwise get.sh of that release, checked against the release's
# SHA256SUMS first. The rest (the bundle against SHA256SUMS, the snapshot for
# wspanel-rollback) is the installer's, as from the command line.
set -euo pipefail
version=${1:?version}
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "not a version: $version" >&2; exit 2; }

env_get() { sed -nE "s/^$1=\"?([^\"]*)\"?\$/\1/p" /srv/panel/shared/.env 2>/dev/null | tail -1; }
method="$(env_get PANEL_INSTALL_METHOD)"
repo="$(env_get PANEL_RELEASE_REPO)"
token="$(env_get PANEL_RELEASE_TOKEN)"
export DEBIAN_FRONTEND=noninteractive

echo "WSPANEL-UPGRADE start $version (${method:-bundle})"
if [[ $method == apt ]] && command -v wspanel-update >/dev/null; then
  pre=()
  [[ $version == *-* ]] && pre=(--pre)
  wspanel-update "${pre[@]}" --yes </dev/null
else
  [[ "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || { echo "no release repository (PANEL_RELEASE_REPO)" >&2; exit 2; }
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' EXIT
  auth=()
  if [[ -n "$token" ]]; then
    [[ "$token" =~ ^[A-Za-z0-9_]+$ ]] || { echo "PANEL_RELEASE_TOKEN does not look like a GitHub token" >&2; exit 2; }
    (umask 077 && printf 'header = "Authorization: Bearer %s"\nheader = "Accept: application/octet-stream"\n' "$token" > "$tmp/auth.curlrc")
    auth=(-K "$tmp/auth.curlrc")
  fi
  # The release's get.sh, verified like the bundle it then fetches.
  for f in get.sh SHA256SUMS; do
    if [[ -z "$token" ]]; then
      curl -fsSL --retry 8 --retry-delay 10 --retry-all-errors -o "$tmp/$f" "https://github.com/$repo/releases/download/v$version/$f"
    else
      id="$(curl -fsSL --retry 8 --retry-delay 10 --retry-all-errors -K <(printf 'header = "Authorization: Bearer %s"\n' "$token") \
        "https://api.github.com/repos/$repo/releases/tags/v$version" | tr ',{}[]' '\n\n\n\n\n' \
        | awk -v want="$f" '/"id":/ { gsub(/[^0-9]/, "", $0); id = $0 } /"name":/ { if (index($0, "\"" want "\"")) { print id; exit } }')"
      [[ "$id" =~ ^[0-9]+$ ]] || { echo "$f is not in release v$version" >&2; exit 3; }
      curl -fsSL --retry 8 --retry-delay 10 --retry-all-errors "${auth[@]}" -o "$tmp/$f" "https://api.github.com/repos/$repo/releases/assets/$id"
    fi
  done
  (cd "$tmp" && grep -E '  get\.sh$' SHA256SUMS | sha256sum -c --status) || { echo "get.sh does not match the release's SHA256SUMS" >&2; exit 3; }
  echo "get.sh of $version: checksum OK"
  WSPANEL_VERSION="$version" WSPANEL_REPO="$repo" WSPANEL_TOKEN="$token" bash "$tmp/get.sh" --yes </dev/null
fi
echo "WSPANEL-UPGRADE done $version"
