#!/usr/bin/env bash
# WSPanel local DNS: PowerDNS, authoritative only (no recursion), zones in
# SQLite, managed by the agent over its API on the loopback. Run by
# install.sh --dns=powerdns, or later by the agent (dns.install, Server →
# Moduly). Idempotent.
#
#   dns.sh install <mail host name>   installs or updates it; prints nothing secret
#   dns.sh remove                     takes it away (the agent first checks
#                                     that no zone is left)
#
# The API key lives in /etc/powerdns/pdns.d/wspanel.conf (root and pdns only);
# the agent reads it from there.
set -euo pipefail
umask 022 # the agent runs this with umask 077
export DEBIAN_FRONTEND=noninteractive
CONF=/etc/powerdns/pdns.d/wspanel.conf
DB=/var/lib/powerdns/pdns.sqlite3

case "${1:-}" in
  install)
    MAIL_NAME=${2:?usage: dns.sh install <mail host name>}
    [[ "$MAIL_NAME" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || { echo "invalid host name: $MAIL_NAME" >&2; exit 2; }
    apt-get install -y -q -o DPkg::Lock::Timeout=900 --no-install-recommends pdns-server pdns-backend-sqlite3 sqlite3 >/dev/null
    rm -f /etc/powerdns/pdns.d/bind.conf
    install -d -m 0750 -o pdns -g pdns /var/lib/powerdns
    if [[ ! -s $DB ]]; then
      sqlite3 "$DB" < /usr/share/pdns-backend-sqlite3/schema/schema.sqlite3.sql
      chown pdns:pdns "$DB" && chmod 0640 "$DB"
    fi
    key=""
    [[ -f $CONF ]] && key="$(sed -n 's/^api-key=//p' "$CONF")"
    [[ -n "$key" ]] || key="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
    ( umask 027
      cat > "$CONF" <<CONF
# Managed by WSPanel (dns.sh).
launch=gsqlite3
gsqlite3-database=$DB
local-address=0.0.0.0, ::
# version.bind answers nothing: the exact version only helps who looks for
# a known hole in it.
version-string=anonymous
api=yes
api-key=$key
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.1/32
default-soa-content=ns1.$MAIL_NAME. hostmaster.$MAIL_NAME. 0 10800 3600 604800 3600
default-ttl=3600
CONF
    )
    chgrp pdns "$CONF" && chmod 0640 "$CONF"
    systemctl enable pdns >/dev/null 2>&1
    systemctl restart pdns
    if command -v ufw >/dev/null; then
      ufw allow 53/udp comment dns >/dev/null
      ufw allow 53/tcp comment dns >/dev/null
    fi
    echo "dns ready"
    ;;
  remove)
    systemctl disable --now pdns >/dev/null 2>&1 || true
    if command -v ufw >/dev/null; then
      ufw delete allow 53/udp >/dev/null 2>&1 || true
      ufw delete allow 53/tcp >/dev/null 2>&1 || true
    fi
    rm -f "$CONF"
    apt-get purge -y -q -o DPkg::Lock::Timeout=900 pdns-server pdns-backend-sqlite3 >/dev/null
    rm -rf /var/lib/powerdns /etc/powerdns
    echo "dns removed"
    ;;
  *) echo "usage: dns.sh install <mail host name> | remove" >&2; exit 2 ;;
esac
