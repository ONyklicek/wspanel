#!/usr/bin/env bash
# The WSPanel apt repository (served by GitHub Pages from the gh-pages branch,
# under apt/): adds packages (wspanel, wspanel-agent, wspanel-archive-keyring),
# keeps the newest few,
# rebuilds the indexes and signs them. Run by the release workflow.
#
#   provision/apt-repo.sh <repository dir> <public key .asc> <package.deb>...
#
# Channels (suites):
#   1.2, 1.2-pre   a line: 1.2.x, or the release candidates of 1.2 (newest
#                  $APT_KEEP versions). An installed server follows its line,
#                  so apt brings fixes of that line only.
#   stable, pre    the newest release (candidate) of any line: where an apt
#                  installation starts, and where wspanel-update looks; the
#                  installer then sets the server to its line's channel.
#
# The signing key must be in the gpg keyring (APT_SIGNING_KEY_ID selects it
# when there are several). Needs apt-ftparchive (apt-utils), dpkg-deb, gpg.
set -euo pipefail
REPO=${1:?repository dir} PUBKEY=${2:?public key}
shift 2
PUBKEY="$(cd "$(dirname "$PUBKEY")" && pwd)/$(basename "$PUBKEY")" # used after cd "$REPO"
KEEP=${APT_KEEP:-5}
PACKAGES=(wspanel wspanel-agent wspanel-archive-keyring)
# Packages no longer published: their files leave the pool (and the indexes).
RETIRED=(wspanel-frankenphp)

for deb in "$@"; do
  pkg="$(dpkg-deb -f "$deb" Package)"
  [[ " ${PACKAGES[*]} " == *" $pkg "* ]] || { echo "not a WSPanel package: $deb" >&2; exit 1; }
  mkdir -p "$REPO/pool/main/w/$pkg"
  # Named from its control file: GitHub renames ~ in release asset names.
  cp "$deb" "$REPO/pool/main/w/$pkg/${pkg}_$(dpkg-deb -f "$deb" Version)_$(dpkg-deb -f "$deb" Architecture).deb"
done
cd "$REPO"
for pkg in "${RETIRED[@]}"; do rm -rf "pool/main/w/${pkg:?}"; done

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# Every package in the pool: package version architecture line rolling file,
# newest first.
for f in pool/main/w/*/*.deb; do
  v="$(dpkg-deb -f "$f" Version)"
  mm="$(sed -E 's/^([0-9]+\.[0-9]+)\..*/\1/' <<<"$v")"
  if [[ $v == *"~"* ]]; then line="$mm-pre" rolling=pre; else line="$mm" rolling=stable; fi
  printf '%s %s %s %s %s %s\n' "$(dpkg-deb -f "$f" Package)" "$v" "$(dpkg-deb -f "$f" Architecture)" "$line" "$rolling" "$f"
done | sort -k1,1 -k3,3 -k2,2Vr > "$tmp/manifest"

# What each channel holds (one file list per suite); what none holds goes.
declare -A count=()
: > "$tmp/keep"
while read -r pkg v arch line rolling f; do
  n=$(( ${count[$line/$pkg/$arch]:-0} + 1 )); count[$line/$pkg/$arch]=$n
  if (( n <= KEEP )); then echo "$f" >> "$tmp/suite-$line"; echo "$f" >> "$tmp/keep"; fi
  if [[ -z "${count[$rolling/$pkg/$arch]:-}" ]]; then
    count[$rolling/$pkg/$arch]=1
    echo "$f" >> "$tmp/suite-$rolling"; echo "$f" >> "$tmp/keep"
  fi
done < "$tmp/manifest"
for f in pool/main/w/*/*.deb; do grep -qxF "$f" "$tmp/keep" || rm -f "$f"; done

rm -rf dists
apt-ftparchive packages pool > "$tmp/all"
suites=()
for list in "$tmp"/suite-*; do
  suite="${list##*/suite-}"; suites+=("$suite")
  for arch in amd64 arm64; do
    dir="dists/$suite/main/binary-$arch"
    mkdir -p "$dir"
    # The records of this suite's files, for this architecture (and "all").
    awk -v ORS='\n\n' -v arch="$arch" -v list="$list" '
      # The list line by line, then the index paragraph by paragraph.
      BEGIN { while ((getline f < list) > 0) want[f] = 1; RS = "" }
      { fn = ""; a = ""
        n = split($0, l, "\n")
        for (i = 1; i <= n; i++) {
          if (l[i] ~ /^Filename: /) fn = substr(l[i], 11)
          if (l[i] ~ /^Architecture: /) a = substr(l[i], 15)
        }
        if ((fn in want) && (a == arch || a == "all")) print }' "$tmp/all" > "$dir/Packages"
    gzip -9kf "$dir/Packages"
  done
  apt-ftparchive \
    -o APT::FTPArchive::Release::Origin=WSPanel -o APT::FTPArchive::Release::Label=WSPanel \
    -o APT::FTPArchive::Release::Suite="$suite" -o APT::FTPArchive::Release::Codename="$suite" \
    -o APT::FTPArchive::Release::Architectures="amd64 arm64" -o APT::FTPArchive::Release::Components=main \
    -o APT::FTPArchive::Release::Description="WSPanel, channel $suite" \
    release "dists/$suite" > "$tmp/Release" # outside dists/: it would list itself
  mv "$tmp/Release" "dists/$suite/Release"
  key=(); [[ -n "${APT_SIGNING_KEY_ID:-}" ]] && key=(--local-user "$APT_SIGNING_KEY_ID")
  gpg --batch --yes "${key[@]}" --clearsign -o "dists/$suite/InRelease" "dists/$suite/Release"
  gpg --batch --yes "${key[@]}" -abs -o "dists/$suite/Release.gpg" "dists/$suite/Release"
done

cp "$PUBKEY" wspanel-archive-keyring.asc
# The newest keyring package at a fixed address: what sets apt up on a server
# (curl -fLO …/wspanel-archive-keyring.deb && apt install ./wspanel-archive-keyring.deb).
newest="$(awk '$1 == "wspanel-archive-keyring" { print $6; exit }' "$tmp/manifest")"
if [[ -n "$newest" ]]; then cp "$newest" wspanel-archive-keyring.deb; fi
cat > index.html <<HTML
<!doctype html><meta charset="utf-8"><title>WSPanel apt</title>
<p>Apt repository of WSPanel (packages wspanel, wspanel-agent, wspanel-archive-keyring);
how to use it: <a href="https://github.com/ONyklicek/wspanel#instalace-přes-apt">README</a>.
Channels: ${suites[*]}. Setup: <a href="wspanel-archive-keyring.deb">wspanel-archive-keyring.deb</a>;
key: <a href="wspanel-archive-keyring.asc">wspanel-archive-keyring.asc</a>.
HTML
echo "channels: ${suites[*]}"
