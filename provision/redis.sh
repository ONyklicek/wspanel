#!/usr/bin/env bash
# Redis 8 as an optional per-site key-value server beside Valkey (Server →
# Moduly), from Redis' own repository (packages.redis.io), never a
# repackaged copy. Installed for the binaries only: every site that picks
# Redis runs its own redis-server under its own user (like Valkey); the
# packaged shared server stays off.
#
#   redis.sh install
#   redis.sh --remove     (the agent first checks that no site uses Redis)
#
# Idempotent.
set -euo pipefail
# Run by the agent (umask 077): the repository and the binaries are read by others.
umask 022
export DEBIAN_FRONTEND=noninteractive

KEY=/etc/apt/keyrings/redis-archive-keyring.asc
SOURCE=/etc/apt/sources.list.d/redis.sources
PIN=/etc/apt/preferences.d/wspanel-redis

if [[ "${1:-}" == --remove ]]; then
  mapfile -t pkgs < <(dpkg-query -W -f='${Package} ${Status}\n' redis redis-server redis-tools 2>/dev/null | awk '/ok installed/ {print $1}')
  ((${#pkgs[@]} == 0)) || apt-get purge -y -q -o DPkg::Lock::Timeout=900 "${pkgs[@]}" >/dev/null
  rm -f "$SOURCE" "$PIN" "$KEY"
  apt-get update -q >/dev/null || true
  echo "redis removed"
  exit 0
fi
[[ "${1:-}" == install ]] || { echo "usage: redis.sh install | --remove" >&2; exit 2; }

. /etc/os-release
SUITE=${VERSION_CODENAME:-trixie}
if [[ ! -s $KEY ]]; then
  install -d -m 0755 /etc/apt/keyrings
  curl -fsSL --retry 8 --retry-delay 10 --retry-all-errors -o "$KEY.tmp" https://packages.redis.io/gpg
  grep -q "BEGIN PGP PUBLIC KEY BLOCK" "$KEY.tmp" || { echo "the Redis key is not a PGP key" >&2; exit 1; }
  mv "$KEY.tmp" "$KEY"
fi
write_source() {
  cat >"$SOURCE" <<EOF
# Managed by WSPanel (redis.sh).
Types: deb
URIs: https://packages.redis.io/deb
Suites: $1
Components: main
Signed-By: $KEY
EOF
}
# Redis' packages win over Debian's own redis (an older 8.0 in Debian 13).
cat >"$PIN" <<'EOF'
# Managed by WSPanel (redis.sh): Redis from packages.redis.io.
Package: redis redis-server redis-tools
Pin: origin packages.redis.io
Pin-Priority: 1001
EOF
# Redis publishes per Debian release; for one it has nothing for yet, its
# Debian 12 (bookworm) build (the same libraries through the t64 successors).
write_source "$SUITE"
apt-get update -q >/dev/null || true
if ! apt-cache policy redis-server 2>/dev/null | grep -q packages.redis.io && [[ $SUITE != bookworm ]]; then
  echo "Redis has no packages for $SUITE yet: its Debian 12 (bookworm) build" >&2
  write_source bookworm
fi
for attempt in 1 2 3; do
  apt-get update -q >/dev/null && apt-get install -y -q --no-install-recommends -o DPkg::Lock::Timeout=900 redis-server redis-tools >/dev/null && break
  [[ $attempt == 3 ]] && { echo "installing redis-server failed" >&2; exit 1; }
  sleep 20
done
# Binaries only: each site runs its own server.
systemctl disable --now redis-server >/dev/null 2>&1 || true
systemctl mask redis-server >/dev/null 2>&1 || true
v=$(/usr/bin/redis-server --version | sed -n 's/.* v=\([0-9.]*\).*/\1/p')
case "$v" in 8.*|9.*) ;; *) echo "redis-server $v is not Redis 8 or newer" >&2; exit 1 ;; esac
echo "redis $v installed"
