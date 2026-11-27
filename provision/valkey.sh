#!/usr/bin/env bash
# Valkey (Debian's package) as a per-site key-value server (Server → Moduly,
# install.sh --kv=valkey|both). Installed for the binaries only: every site
# that picks Valkey runs its own valkey-server under its own user; the
# packaged shared server stays off.
#
#   valkey.sh install
#   valkey.sh --remove    (the agent first checks that no site and not the
#                          mail filter use Valkey, and that Redis is there)
#
# Idempotent.
set -euo pipefail
umask 022
export DEBIAN_FRONTEND=noninteractive

if [[ "${1:-}" == --remove ]]; then
  mapfile -t pkgs < <(dpkg-query -W -f='${Package} ${Status}\n' valkey-server valkey-tools 2>/dev/null | awk '/ok installed/ {print $1}')
  ((${#pkgs[@]} == 0)) || apt-get purge -y -q -o DPkg::Lock::Timeout=900 "${pkgs[@]}" >/dev/null
  systemctl unmask valkey-server >/dev/null 2>&1 || true
  echo "valkey removed"
  exit 0
fi
[[ "${1:-}" == install ]] || { echo "usage: valkey.sh install | --remove" >&2; exit 2; }

for attempt in 1 2 3; do
  apt-get install -y -q --no-install-recommends -o DPkg::Lock::Timeout=900 valkey-server valkey-tools >/dev/null && break
  [[ $attempt == 3 ]] && { echo "installing valkey-server failed" >&2; exit 1; }
  apt-get update -q >/dev/null || true
  sleep 20
done
# Binaries only: each site runs its own server.
systemctl disable --now valkey-server >/dev/null 2>&1 || true
systemctl mask valkey-server >/dev/null 2>&1 || true
echo "valkey $(/usr/bin/valkey-server --version | sed -n 's/.* v=\([0-9.]*\).*/\1/p') installed"
