#!/usr/bin/env bash
# MongoDB as an optional database engine (Databáze → Servery → Doinstalovat
# engine), from MongoDB's own repository, never a repackaged copy.
#
#   mongodb.sh <admin password file> <php version>...
#
# The server listens on 127.0.0.1 only and requires authentication. The
# panel's administrator (wspanel, role root) is created once through the
# localhost exception; its password is generated here into the given file
# (root only) and never leaves the agent.
set -euo pipefail
# Run by the agent (umask 077): the repository and the config are read by others.
umask 022

PW_FILE=${1:?usage: mongodb.sh <admin password file> <php version>...}
shift
VER=8.0
KEY=/usr/share/keyrings/mongodb-server-$VER.asc
. /etc/os-release
SUITE=${VERSION_CODENAME:-trixie}

if [[ ! -s $KEY ]]; then
  curl -fsSL --retry 8 --retry-delay 10 --retry-all-errors -o "$KEY.tmp" "https://pgp.mongodb.com/server-$VER.asc"
  grep -q "BEGIN PGP PUBLIC KEY BLOCK" "$KEY.tmp" || { echo "the MongoDB key is not a PGP key" >&2; exit 1; }
  mv "$KEY.tmp" "$KEY"
fi
write_source() {
  cat >/etc/apt/sources.list.d/mongodb-org-$VER.sources <<EOF
# Managed by WSPanel (mongodb.sh).
Types: deb
URIs: https://repo.mongodb.org/apt/debian
Suites: $1/mongodb-org/$VER
Components: main
Signed-By: $KEY
EOF
}
# MongoDB publishes its server per Debian release; for a release it has only
# the tools for so far (Debian 13 in 2026), its build for Debian 12 (the
# same libraries: libssl3, libcurl4 are provided by their t64 successors).
# The newer suite is taken as soon as it carries the server.
write_source "$SUITE"
apt-get update -q >/dev/null || true
if ! apt-cache show mongodb-org-server >/dev/null 2>&1 && [[ $SUITE != bookworm ]]; then
  echo "MongoDB $VER has no server for $SUITE yet: its Debian 12 (bookworm) build" >&2
  write_source bookworm
fi

pkgs=(mongodb-org)
for v in "$@"; do
  [[ "$v" =~ ^8\.[2-5]$ ]] || { echo "invalid PHP version: $v" >&2; exit 1; }
  pkgs+=("php$v-mongodb")
done
for attempt in 1 2 3; do
  apt-get update -q >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends "${pkgs[@]}" >/dev/null && break
  [[ $attempt == 3 ]] && { echo "installing ${pkgs[*]} failed" >&2; exit 1; }
  sleep 20
done

cat >/etc/mongod.conf <<'CONF'
# Managed by WSPanel (mongodb.sh): localhost only, authentication required.
storage:
  dbPath: /var/lib/mongodb
systemLog:
  destination: file
  logAppend: true
  path: /var/log/mongodb/mongod.log
net:
  port: 27017
  bindIp: 127.0.0.1
processManagement:
  timeZoneInfo: /usr/share/zoneinfo
security:
  authorization: enabled
CONF
systemctl enable mongod >/dev/null 2>&1
systemctl restart mongod
for _ in $(seq 60); do
  mongosh --quiet --norc --eval 'db.runCommand({ping: 1}).ok' >/dev/null 2>&1 && break
  sleep 1
done
mongosh --quiet --norc --eval 'db.runCommand({ping: 1}).ok' >/dev/null || { echo "mongod does not answer" >&2; exit 1; }

# The panel's administrator, through the localhost exception (only while no
# user exists). The password goes in a script file, never on a command line.
if [[ ! -s $PW_FILE ]]; then
  ( umask 077; head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | head -c 32 > "$PW_FILE" )
  script=$(mktemp --suffix=.js)
  chmod 0600 "$script"
  printf 'db.getSiblingDB("admin").createUser({user: "wspanel", pwd: "%s", roles: ["root"]});\n' "$(cat "$PW_FILE")" > "$script"
  if ! mongosh --quiet --norc "mongodb://127.0.0.1:27017/admin" "$script" >/dev/null; then
    rm -f "$script" "$PW_FILE"
    echo "creating the panel's MongoDB administrator failed (does MongoDB already have users?)" >&2
    exit 1
  fi
  rm -f "$script"
fi
chmod 0600 "$PW_FILE"
