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

# mongod.conf: $1 = where it listens. While the panel's administrator does
# not exist yet MongoDB lets anyone who reaches it create the first user (the
# localhost exception): that first start listens only on a socket in a
# directory only mongodb (and root) may enter, never on 127.0.0.1, where any
# site's process could take the exception first.
write_conf() {
  cat >/etc/mongod.conf <<CONF
# Managed by WSPanel (mongodb.sh): localhost only, authentication required.
storage:
  dbPath: /var/lib/mongodb
systemLog:
  destination: file
  logAppend: true
  path: /var/log/mongodb/mongod.log
net:
  port: 27017
  bindIp: $1
  unixDomainSocket:
    enabled: true
    pathPrefix: $2
    filePermissions: 0700
processManagement:
  timeZoneInfo: /usr/share/zoneinfo
security:
  authorization: enabled
CONF
}
wait_mongod() { # $1: connection string
  for _ in $(seq 60); do
    mongosh --quiet --norc "$1" --eval 'db.runCommand({ping: 1}).ok' >/dev/null 2>&1 && return 0
    sleep 1
  done
  echo "mongod does not answer" >&2
  exit 1
}
systemctl enable mongod >/dev/null 2>&1

# Every run starts on the private socket first and only opens 127.0.0.1 once
# the administrator provably exists: the password file is only a hint (an
# interrupted run, a wiped data directory). On any error mongod stays stopped.
BOOT=/run/wspanel-mongodb-setup
SOCK_URI="mongodb://$(printf '%s' "$BOOT/mongod.sock" | sed 's|/|%2F|g')"
done_ok=0
cleanup() {
  if [[ $done_ok != 1 ]]; then
    systemctl stop mongod 2>/dev/null || true
    rm -f "$PW_FILE.new"
    echo "MongoDB left stopped: the setup did not finish" >&2
  fi
  rm -rf "$BOOT"
}
trap cleanup EXIT
install -d -o mongodb -g mongodb -m 0700 "$BOOT"
write_conf "$BOOT/mongod.sock" "$BOOT"
systemctl restart mongod
wait_mongod "$SOCK_URI/admin"

# JavaScript for mongosh in a root-only file; connection strings with a
# password are inside it (connect()), never on a command line.
run_js() {
  local script rc=0
  script=$(mktemp --suffix=.js)
  chmod 0600 "$script"
  printf '%s\n' "$1" > "$script"
  mongosh --quiet --norc --nodb "$script" >/dev/null 2>&1 || rc=$?
  rm -f "$script"
  return $rc
}
admin_works() {
  [[ -s $PW_FILE ]] || return 1
  run_js "const d = connect(\"$SOCK_URI/admin\"); if (!d.auth(\"wspanel\", \"$(cat "$PW_FILE")\")) { quit(1); }"
}
if ! admin_works; then
  # Through the localhost exception (over the socket): it allows only
  # createUser, and only while no user exists.
  ( umask 077; head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | head -c 32 > "$PW_FILE.new" )
  if ! run_js "connect(\"$SOCK_URI/admin\").createUser({user: \"wspanel\", pwd: \"$(cat "$PW_FILE.new")\", roles: [\"root\"]});"; then
    echo "creating the panel's MongoDB administrator failed: MongoDB already has users, and the panel's password ($PW_FILE) does not work" >&2
    exit 1
  fi
  mv -f "$PW_FILE.new" "$PW_FILE"
  admin_works || { echo "the panel's MongoDB administrator does not work" >&2; exit 1; }
fi
chmod 0600 "$PW_FILE"

# Only now on 127.0.0.1: the administrator exists, the exception is gone.
write_conf 127.0.0.1 /tmp
systemctl restart mongod
wait_mongod "mongodb://127.0.0.1:27017/admin"
done_ok=1
