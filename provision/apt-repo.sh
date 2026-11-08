#!/usr/bin/env bash
# The WSPanel apt repository (served by GitHub Pages from the gh-pages branch,
# under apt/): adds wspanel-agent packages, keeps the newest few of each
# channel, rebuilds the indexes and signs them. Run by the release workflow.
#
#   provision/apt-repo.sh <repository dir> <public key .asc> <package.deb>...
#
# Channels (suites) are minor versions: 1.2 gets 1.2.x, 1.2-pre gets the
# release candidates of 1.2. The signing key must be in the gpg keyring
# (APT_SIGNING_KEY_ID selects it when there are several).
# Needs apt-ftparchive (apt-utils), dpkg-deb, gpg.
set -euo pipefail
REPO=${1:?repository dir} PUBKEY=${2:?public key}
shift 2
KEEP=${APT_KEEP:-5}
POOL="pool/main/w/wspanel-agent"

mkdir -p "$REPO/$POOL"
for deb in "$@"; do
  [[ "$(dpkg-deb -f "$deb" Package)" == wspanel-agent ]] || { echo "not wspanel-agent: $deb" >&2; exit 1; }
  cp "$deb" "$REPO/$POOL/"
done
cd "$REPO"

suite_of() { # Debian version -> channel
  local v=$1 mm
  mm="$(sed -E 's/^([0-9]+\.[0-9]+)\..*/\1/' <<<"$v")"
  [[ $v == *"~"* ]] && echo "$mm-pre" || echo "$mm"
}

# Keep the newest $KEEP versions of each channel (per architecture).
declare -A seen=()
while read -r v a f; do
  key="$(suite_of "$v")/$a"
  seen[$key]=$(( ${seen[$key]:-0} + 1 ))
  (( seen[$key] > KEEP )) && rm -f "$f"
done < <(for f in "$POOL"/*.deb; do printf '%s %s %s\n' "$(dpkg-deb -f "$f" Version)" "$(dpkg-deb -f "$f" Architecture)" "$f"; done \
  | sort -k1,1Vr)

rm -rf dists
all="$(mktemp)"
trap 'rm -f "$all" "$all.release"' EXIT
apt-ftparchive packages "$POOL" > "$all"
mapfile -t suites < <(for f in "$POOL"/*.deb; do suite_of "$(dpkg-deb -f "$f" Version)"; done | sort -u)
for suite in "${suites[@]}"; do
  for arch in amd64 arm64; do
    dir="dists/$suite/main/binary-$arch"
    mkdir -p "$dir"
    # The records of this channel and architecture.
    awk -v RS= -v ORS='\n\n' -v arch="$arch" -v suite="$suite" '
      { v = ""; a = ""
        n = split($0, l, "\n")
        for (i = 1; i <= n; i++) {
          if (l[i] ~ /^Version: /) v = substr(l[i], 10)
          if (l[i] ~ /^Architecture: /) a = substr(l[i], 15)
        }
        match(v, /^[0-9]+\.[0-9]+/); mm = substr(v, RSTART, RLENGTH)
        s = (index(v, "~") > 0) ? mm "-pre" : mm
        if (a == arch && s == suite) print }' "$all" > "$dir/Packages"
    gzip -9kf "$dir/Packages"
  done
  apt-ftparchive \
    -o APT::FTPArchive::Release::Origin=WSPanel -o APT::FTPArchive::Release::Label=WSPanel \
    -o APT::FTPArchive::Release::Suite="$suite" -o APT::FTPArchive::Release::Codename="$suite" \
    -o APT::FTPArchive::Release::Architectures="amd64 arm64" -o APT::FTPArchive::Release::Components=main \
    -o APT::FTPArchive::Release::Description="WSPanel agent, channel $suite" \
    release "dists/$suite" > "$all.release" # outside dists/: it would list itself
  mv "$all.release" "dists/$suite/Release"
  key=(); [[ -n "${APT_SIGNING_KEY_ID:-}" ]] && key=(--local-user "$APT_SIGNING_KEY_ID")
  gpg --batch --yes "${key[@]}" --clearsign -o "dists/$suite/InRelease" "dists/$suite/Release"
  gpg --batch --yes "${key[@]}" -abs -o "dists/$suite/Release.gpg" "dists/$suite/Release"
done

cp "$PUBKEY" wspanel-archive-keyring.asc
cat > index.html <<HTML
<!doctype html><meta charset="utf-8"><title>WSPanel apt</title>
<p>Apt repository of the WSPanel agent (wspanel-agent). The WSPanel installer sets it up;
channels: ${suites[*]}. Key: <a href="wspanel-archive-keyring.asc">wspanel-archive-keyring.asc</a>.
HTML
echo "channels: ${suites[*]}"
