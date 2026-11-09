# Shared by the package builders (build-deb.sh, build-installer-deb.sh,
# build-keyring-deb.sh): what Debian expects in every package.
# shellcheck shell=bash

DEB_MAINTAINER="Ondřej Nyklíček <60318239+ONyklicek@users.noreply.github.com>"

# deb_version <release version>: 1.2.0-rc1 -> 1.2.0~rc1 (sorts before 1.2.0).
deb_version() { echo "${1/-/\~}"; }

# deb_doc <package> <root> <changes file or ""> <debian version> [extra copyright paragraphs]:
# /usr/share/doc/<package>/copyright (machine-readable, MIT) and changelog.gz.
deb_doc() {
  local pkg=$1 root=$2 changes=$3 ver=$4 extra=${5:-}
  local doc="$root/usr/share/doc/$pkg"
  install -d -m 0755 "$doc"
  {
    printf 'Format: https://www.debian.org/doc/packaging-manuals/copyright-format/1.0/\n'
    printf 'Upstream-Name: WSPanel\nSource: https://github.com/ONyklicek/wspanel\n\n'
    printf 'Files: *\nCopyright: 2026 Ondřej Nyklíček\nLicense: MIT\n'
    [[ -n "$extra" ]] && printf '\n%s\n' "$extra"
    printf '\nLicense: MIT\n'
    sed -e '1,/^$/d' -e 's/^$/./' -e 's/^/ /' "$DEB_COMMON_DIR/../LICENSE" 2>/dev/null \
      || sed -e '1,/^$/d' -e 's/^$/./' -e 's/^/ /' "$DEB_COMMON_DIR/LICENSE"
  } > "$doc/copyright"
  # A native changelog: this release's changes (the release workflow's git
  # log since the previous tag), or one line when there are none.
  {
    printf '%s (%s) unstable; urgency=medium\n\n' "$pkg" "$ver"
    if [[ -n "$changes" && -s "$changes" ]]; then
      sed -e 's/^- //' -e 's/^/  * /' "$changes" | head -200
    else
      printf '  * Release %s.\n' "$ver"
    fi
    printf '\n -- %s  %s\n' "$DEB_MAINTAINER" "$(LC_ALL=C date -u -R -d "@${SOURCE_DATE_EPOCH:-$(date +%s)}" 2>/dev/null || LC_ALL=C date -u -R)"
  } | gzip -9n > "$doc/changelog.gz"
}

# deb_man <root> <section> <page file>...: gzipped manual pages.
deb_man() {
  local root=$1 sec=$2 f
  shift 2
  install -d -m 0755 "$root/usr/share/man/man$sec"
  for f in "$@"; do gzip -9n < "$f" > "$root/usr/share/man/man$sec/$(basename "$f").gz"; done
}

# deb_md5sums <root>: DEBIAN/md5sums of the package's files (conffiles,
# under etc/, have their own checks).
deb_md5sums() {
  (cd "$1" && find . -type f ! -path './DEBIAN/*' ! -path './etc/*' -printf '%P\0' | LC_ALL=C sort -z \
    | xargs -0r md5sum) > "$1/DEBIAN/md5sums"
  chmod 0644 "$1/DEBIAN/md5sums"
}

# deb_overrides <package> <root> <line>...: lintian overrides (known, intended).
deb_overrides() {
  local pkg=$1 root=$2
  shift 2
  install -d -m 0755 "$root/usr/share/lintian/overrides"
  printf '%s\n' "$@" > "$root/usr/share/lintian/overrides/$pkg"
}
