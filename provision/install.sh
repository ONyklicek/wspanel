#!/usr/bin/env bash
# WSPanel provisioning for Debian 13 (trixie). Runs natively on the host; no Docker.
#
#   install.sh --db=mariadb,postgres --php=8.3,8.4 --panel-domain=panel.example.com \
#              --acme-email=admin@example.com --agent-bin=./panel-agent-linux-amd64 \
#              [--panel-source=./panel] [--ssh-port=22] [--local-certs]
#
# --db accepts: none | mariadb | postgres | mariadb,postgres
# Safe to re-run: every step is idempotent.
set -euo pipefail

FRANKENPHP_VERSION="v1.13.5"
declare -A FRANKENPHP_SHA256=(
  [x86_64]="78e9b0ed1999ba658545c68bd74baece5697192f75f56e02aa778119f1164812"
  [aarch64]="4d0311de6986d4fe9dc9bc3467d9549d26b89ab7ac6804cbe68fbb5352f8b153"
)
PANEL_PHP="8.4" # the panel itself always runs on this version

DB="none"
PHP_VERSIONS="8.4"
PANEL_DOMAIN=""
ACME_EMAIL=""
AGENT_BIN=""
PANEL_SOURCE=""
LOCAL_CERTS=0
SSH_PORT=22
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

for arg in "$@"; do
  case "$arg" in
    --db=*) DB="${arg#*=}" ;;
    --php=*) PHP_VERSIONS="${arg#*=}" ;;
    --panel-domain=*) PANEL_DOMAIN="${arg#*=}" ;;
    --acme-email=*) ACME_EMAIL="${arg#*=}" ;;
    --agent-bin=*) AGENT_BIN="${arg#*=}" ;;
    --panel-source=*) PANEL_SOURCE="${arg#*=}" ;;
    --ssh-port=*) SSH_PORT="${arg#*=}" ;;
    --local-certs) LOCAL_CERTS=1 ;; # dev only: Caddy internal CA instead of Let's Encrypt
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) die "unknown argument: $arg" ;;
  esac
done

# ---------------------------------------------------------------- checks
[[ $EUID -eq 0 ]] || die "run as root"
. /etc/os-release
[[ "${ID:-}" == "debian" && "${VERSION_CODENAME:-}" == "trixie" ]] || die "Debian 13 (trixie) required, found ${PRETTY_NAME:-unknown}"
[[ -n "$PANEL_DOMAIN" ]] || die "--panel-domain is required"
[[ "$PANEL_DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || die "invalid --panel-domain"
[[ -n "$AGENT_BIN" && -x "$AGENT_BIN" ]] || die "--agent-bin must point to the built panel-agent binary"
[[ "$SSH_PORT" =~ ^[0-9]+$ && $SSH_PORT -ge 1 && $SSH_PORT -le 65535 ]] || die "invalid --ssh-port"
[[ -z "$ACME_EMAIL" ]] && ACME_EMAIL="admin@${PANEL_DOMAIN}"

DB_ENGINES=()
case "$DB" in
  none) ;;
  mariadb) DB_ENGINES=(mariadb) ;;
  postgres|postgresql) DB_ENGINES=(postgresql) ;;
  mariadb,postgres|postgres,mariadb|mariadb,postgresql|postgresql,mariadb) DB_ENGINES=(mariadb postgresql) ;;
  *) die "--db must be none, mariadb, postgres or mariadb,postgres" ;;
esac

IFS=',' read -r -a PHP_LIST <<< "$PHP_VERSIONS"
for v in "${PHP_LIST[@]}"; do
  [[ "$v" =~ ^8\.[2-5]$ ]] || die "unsupported PHP version: $v"
done
[[ " ${PHP_LIST[*]} " == *" $PANEL_PHP "* ]] || PHP_LIST+=("$PANEL_PHP")

ARCH="$(uname -m)"
[[ -n "${FRANKENPHP_SHA256[$ARCH]:-}" ]] || die "unsupported architecture: $ARCH"

export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------- base packages
log "Base packages"
apt-get update -q
apt-get install -y -q --no-install-recommends \
  ca-certificates curl gnupg acl sqlite3 unzip git rsync composer valkey-server ufw

# ---------------------------------------------------------------- PHP (sury)
log "PHP ${PHP_LIST[*]} (packages.sury.org)"
if [[ ! -f /etc/apt/sources.list.d/php.list ]]; then
  curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
  dpkg -i /tmp/debsuryorg-archive-keyring.deb
  echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ trixie main" \
    > /etc/apt/sources.list.d/php.list
  apt-get update -q
fi
PHP_PKGS=()
for v in "${PHP_LIST[@]}"; do
  # Extensions Laravel and Symfony need or recommend.
  for ext in fpm cli common opcache intl bcmath mbstring xml curl zip gd sqlite3 redis apcu; do
    PHP_PKGS+=("php${v}-${ext}")
  done
  [[ " ${DB_ENGINES[*]} " == *" mariadb "* ]] && PHP_PKGS+=("php${v}-mysql")
  [[ " ${DB_ENGINES[*]} " == *" postgresql "* ]] && PHP_PKGS+=("php${v}-pgsql")
done
apt-get install -y -q --no-install-recommends "${PHP_PKGS[@]}"
for v in "${PHP_LIST[@]}"; do
  install -m 0644 "$HERE/files/90-wspanel.ini" "/etc/php/$v/fpm/conf.d/90-wspanel.ini"
  install -m 0644 "$HERE/files/90-wspanel.ini" "/etc/php/$v/cli/conf.d/90-wspanel.ini"
  # Debian's default www pool is replaced by an idle pool (FPM needs >= 1 pool).
  rm -f "/etc/php/$v/fpm/pool.d/www.conf"
  sed "s|\$pool|$v|" "$HERE/files/00-wspanel-idle.conf" > "/etc/php/$v/fpm/pool.d/00-wspanel-idle.conf"
done

# ---------------------------------------------------------------- databases
for engine in "${DB_ENGINES[@]}"; do
  log "Database engine: $engine"
  case "$engine" in
    mariadb) apt-get install -y -q mariadb-server ;;
    postgresql) apt-get install -y -q postgresql ;;
  esac
  systemctl enable --now "$engine"
done

# ---------------------------------------------------------------- FrankenPHP
if ! /usr/local/bin/frankenphp version 2>/dev/null | grep -q "${FRANKENPHP_VERSION#v}"; then
  log "FrankenPHP $FRANKENPHP_VERSION"
  tmp="$(mktemp)"
  curl -fsSLo "$tmp" "https://github.com/php/frankenphp/releases/download/${FRANKENPHP_VERSION}/frankenphp-linux-${ARCH}"
  echo "${FRANKENPHP_SHA256[$ARCH]}  $tmp" | sha256sum -c - || die "FrankenPHP checksum mismatch"
  install -m 0755 "$tmp" /usr/local/bin/frankenphp
  rm -f "$tmp"
fi

# ---------------------------------------------------------------- users
log "System users"
id panel-proxy &>/dev/null || useradd --system --user-group --no-create-home --home-dir /var/lib/panel-proxy --shell /usr/sbin/nologin panel-proxy
id panel-web &>/dev/null || useradd --system --user-group --no-create-home --home-dir /var/lib/panel-web --shell /usr/sbin/nologin panel-web
id panel &>/dev/null || useradd --system --user-group --no-create-home --home-dir /srv/panel --shell /usr/sbin/nologin panel

# ---------------------------------------------------------------- directories
install -d -m 0751 -o root -g root /srv/sites
install -d -m 0700 -o root -g root /srv/sites/.trash
install -d -m 0750 -o panel -g panel /srv/panel /srv/panel/releases /srv/panel/shared /srv/panel/tmp
# The web tier may traverse to the panel's public/ only (ACLs set per release below).
setfacl -m u:panel-web:x /srv/panel /srv/panel/releases
install -d -m 0755 /etc/panel-proxy /etc/panel-proxy/sites /etc/panel-web /etc/panel-web/sites
install -d -m 0700 /etc/panel-agent

# ---------------------------------------------------------------- agent
log "Agent"
install -m 0755 "$AGENT_BIN" /usr/local/sbin/panel-agent
json_list() { local out="" x; for x in "$@"; do out+="${out:+,}\"$x\""; done; printf '[%s]' "$out"; }
cat > /etc/panel-agent/agent.json <<JSON
{
  "socket": "/run/panel-agent.sock",
  "socket_group": "panel",
  "allowed_users": ["panel"],
  "sites_root": "/srv/sites",
  "trash_dir": "/srv/sites/.trash",
  "php_versions": $(json_list "${PHP_LIST[@]}"),
  "default_php_version": "$PANEL_PHP",
  "db_engines": $(json_list "${DB_ENGINES[@]}"),
  "protected_ports": [$SSH_PORT, 80, 443]
}
JSON
chmod 0600 /etc/panel-agent/agent.json
# DB admin access: on Debian both engines authenticate root/postgres over the
# local socket (unix_socket / peer), so no admin password is stored at all.
cat > /etc/panel-agent/db.conf <<CONF
# Only readable by root. Used by the agent's db.* operations (phase 4).
mariadb_socket=/run/mysqld/mysqld.sock
postgres_os_user=postgres
CONF
chmod 0600 /etc/panel-agent/db.conf
install -m 0644 "$HERE/files/panel-agent.service" /etc/systemd/system/panel-agent.service

# ---------------------------------------------------------------- proxy
log "Reverse proxy"
sed -e "s|__PANEL_DOMAIN__|$PANEL_DOMAIN|" -e "s|__ACME_EMAIL__|$ACME_EMAIL|" \
  "$HERE/files/Caddyfile.tmpl" > /etc/panel-proxy/Caddyfile
if [[ $LOCAL_CERTS -eq 1 ]]; then
  sed -i 's|^\temail .*|\tlocal_certs|' /etc/panel-proxy/Caddyfile
fi
sed -e "s|__PANEL_DOMAIN__|$PANEL_DOMAIN|" "$HERE/files/web-Caddyfile.tmpl" > /etc/panel-web/Caddyfile
install -m 0644 "$HERE/files/panel-proxy.service" /etc/systemd/system/panel-proxy.service
install -m 0644 "$HERE/files/panel-web.service" /etc/systemd/system/panel-web.service
install -m 0644 "$HERE/files/panel-pool.conf" "/etc/php/$PANEL_PHP/fpm/pool.d/panel.conf"

# ---------------------------------------------------------------- panel app
if [[ -n "$PANEL_SOURCE" ]]; then
  log "Panel application"
  [[ -f "$PANEL_SOURCE/artisan" ]] || die "--panel-source does not look like the Laravel panel"
  release="/srv/panel/releases/$(date -u +%Y%m%d%H%M%S)"
  install -d -m 0750 -o panel -g panel "$release"
  rsync -a --delete --exclude=.git --exclude=vendor --exclude=node_modules --exclude=.env \
    --exclude=storage --exclude='database/*.sqlite' "$PANEL_SOURCE/" "$release/"
  chown -R panel:panel "$release"
  # Persistent state lives in shared/.
  install -d -m 0750 -o panel -g panel /srv/panel/shared/storage
  for d in app app/public app/private framework framework/cache framework/cache/data framework/sessions framework/views logs; do
    install -d -m 0750 -o panel -g panel "/srv/panel/shared/storage/$d"
  done
  if [[ ! -f /srv/panel/shared/.env ]]; then
    install -m 0600 -o panel -g panel "$PANEL_SOURCE/.env.example" /srv/panel/shared/.env
    sed -i -e "s|^APP_ENV=.*|APP_ENV=production|" -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
      -e "s|^APP_URL=.*|APP_URL=https://$PANEL_DOMAIN|" \
      -e "s|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|" -e "s|^# *DB_DATABASE=.*|DB_DATABASE=/srv/panel/shared/database.sqlite|" \
      -e "s|^AGENT_SOCKET=.*|AGENT_SOCKET=/run/panel-agent.sock|" /srv/panel/shared/.env
    grep -q '^DB_DATABASE=' /srv/panel/shared/.env || echo "DB_DATABASE=/srv/panel/shared/database.sqlite" >> /srv/panel/shared/.env
    grep -q '^AGENT_SOCKET=' /srv/panel/shared/.env || echo "AGENT_SOCKET=/run/panel-agent.sock" >> /srv/panel/shared/.env
  fi
  [[ -f /srv/panel/shared/database.sqlite ]] || install -m 0600 -o panel -g panel /dev/null /srv/panel/shared/database.sqlite
  ln -sfn /srv/panel/shared/.env "$release/.env"
  rm -rf "$release/storage" && ln -sfn /srv/panel/shared/storage "$release/storage"
  chown -h panel:panel "$release/.env" "$release/storage"

  as_panel() { runuser -u panel -- env HOME=/srv/panel/tmp COMPOSER_HOME=/srv/panel/tmp/composer "$@"; }
  (cd "$release" && as_panel composer install --no-dev --optimize-autoloader --no-interaction --no-progress)
  grep -q '^APP_KEY=base64:' /srv/panel/shared/.env || (cd "$release" && as_panel php artisan key:generate --force)
  (cd "$release" && as_panel php artisan migrate --force && as_panel php artisan optimize && as_panel php artisan filament:optimize)
  setfacl -m u:panel-web:x "$release"
  setfacl -R -m u:panel-web:rX,d:u:panel-web:rX "$release/public"
  ln -sfn "$release" /srv/panel/current.tmp && mv -Tf /srv/panel/current.tmp /srv/panel/current
  # Keep the last 5 releases.
  ls -1dt /srv/panel/releases/*/ | tail -n +6 | xargs -r rm -rf
  install -m 0644 "$HERE/files/panel-scheduler.service" /etc/systemd/system/panel-scheduler.service
fi

# ---------------------------------------------------------------- firewall
log "Firewall (UFW)"
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow "$SSH_PORT/tcp" comment ssh >/dev/null
ufw allow 80/tcp comment http >/dev/null
ufw allow 443/tcp comment https >/dev/null
ufw allow 443/udp comment http3 >/dev/null
ufw --force enable >/dev/null

# ---------------------------------------------------------------- start
log "Starting services"
systemctl daemon-reload
for v in "${PHP_LIST[@]}"; do
  "php-fpm$v" -t
  systemctl enable "php$v-fpm"
  systemctl restart "php$v-fpm"
done
systemctl enable --now valkey-server
frankenphp validate --config /etc/panel-web/Caddyfile --adapter caddyfile
frankenphp validate --config /etc/panel-proxy/Caddyfile --adapter caddyfile
systemctl enable panel-web panel-proxy panel-agent
systemctl restart panel-web panel-proxy panel-agent
if [[ -f /etc/systemd/system/panel-scheduler.service ]]; then
  systemctl enable panel-scheduler
  systemctl restart panel-scheduler
fi

log "Done. Panel: https://$PANEL_DOMAIN"
[[ -n "$PANEL_SOURCE" ]] && log "Create the admin account: cd /srv/panel/current && runuser -u panel -- php artisan make:filament-user"
exit 0
