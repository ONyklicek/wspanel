#!/usr/bin/env bash
# WSPanel provisioning for Debian 13 (trixie). Runs natively on the host; no Docker.
#
#   install.sh --db=mariadb,postgres --php=8.3,8.4 --panel-domain=panel.example.com \
#              --acme-email=admin@example.com --agent-bin=./panel-agent-linux-amd64 \
#              [--node=22,24] [--panel-source=./panel] [--ssh-port=22] [--no-root-ssh] [--local-certs]
#
# --db accepts: none | mariadb | postgres | mariadb,postgres
# Safe to re-run: every step is idempotent.
set -euo pipefail

FRANKENPHP_VERSION="v1.13.5"
declare -A FRANKENPHP_SHA256=(
  [x86_64]="78e9b0ed1999ba658545c68bd74baece5697192f75f56e02aa778119f1164812"
  [aarch64]="4d0311de6986d4fe9dc9bc3467d9549d26b89ab7ac6804cbe68fbb5352f8b153"
)
FNM_VERSION="v1.39.0"
declare -A FNM_ASSET=([x86_64]="fnm-linux.zip" [aarch64]="fnm-arm64.zip")
declare -A FNM_SHA256=(
  [x86_64]="7807664f39d39fc518da1c35ba0181e4b3267603c4b1dedeb4b5fc6ae440a224"
  [aarch64]="4eaff58b2c5bf30d0934027572dd0b5bbb60d2a1af309230b53662d4b1d45599"
)
PMA_VERSION="5.2.3"
PMA_SHA256="57881348297c4412f86c410547cf76b4d8a236574dd2c6b7d6a2beebe7fc44e3"
ADMINER_VERSION="6.2.0"
ADMINER_SHA256="9047c00dfc8258c6046c6720d7d201eaa0ca016fa0fc554bf0c4c137306e1c16"
PANEL_PHP="8.4" # the panel itself always runs on this version

DB="none"
PHP_VERSIONS="8.4"
NODE_VERSIONS="22,24"
PANEL_DOMAIN=""
ACME_EMAIL=""
AGENT_BIN=""
PANEL_SOURCE=""
LOCAL_CERTS=0
SSH_PORT=22
ROOT_LOGIN="prohibit-password"
UNENFORCED_BIND=false
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

for arg in "$@"; do
  case "$arg" in
    --db=*) DB="${arg#*=}" ;;
    --php=*) PHP_VERSIONS="${arg#*=}" ;;
    --node=*) NODE_VERSIONS="${arg#*=}" ;; # Node majors for SSR sites, "none" to skip
    --panel-domain=*) PANEL_DOMAIN="${arg#*=}" ;;
    --acme-email=*) ACME_EMAIL="${arg#*=}" ;;
    --agent-bin=*) AGENT_BIN="${arg#*=}" ;;
    --panel-source=*) PANEL_SOURCE="${arg#*=}" ;;
    --ssh-port=*) SSH_PORT="${arg#*=}" ;;
    --allow-unenforced-socket-bind) UNENFORCED_BIND=true ;; # dev containers only (no cgroup BPF)
    --no-root-ssh) ROOT_LOGIN="no" ;; # make sure another admin account has a key first
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

NODE_LIST=()
if [[ "$NODE_VERSIONS" != "none" ]]; then
  IFS=',' read -r -a NODE_LIST <<< "$NODE_VERSIONS"
  for v in "${NODE_LIST[@]}"; do [[ "$v" =~ ^[0-9]{2}$ ]] || die "invalid Node major: $v"; done
fi

ARCH="$(uname -m)"
[[ -n "${FRANKENPHP_SHA256[$ARCH]:-}" ]] || die "unsupported architecture: $ARCH"

export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------- base packages
log "Base packages"
apt-get update -q
apt-get install -y -q --no-install-recommends \
  ca-certificates curl gnupg acl sqlite3 unzip git rsync composer valkey-server ufw \
  openssh-server fail2ban mc nano xz-utils

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

# ---------------------------------------------------------------- Node (fnm)
# System-wide installs in /opt/fnm, readable by every site user. The agent
# discovers versions from /opt/fnm/node-versions; sites pick a major.
if [[ ${#NODE_LIST[@]} -gt 0 ]]; then
  if ! /usr/local/bin/fnm --version 2>/dev/null | grep -q "${FNM_VERSION#v}"; then
    log "fnm $FNM_VERSION"
    tmp="$(mktemp -d)"
    curl -fsSLo "$tmp/fnm.zip" "https://github.com/Schniz/fnm/releases/download/${FNM_VERSION}/${FNM_ASSET[$ARCH]}"
    echo "${FNM_SHA256[$ARCH]}  $tmp/fnm.zip" | sha256sum -c - || die "fnm checksum mismatch"
    unzip -q -o "$tmp/fnm.zip" -d "$tmp"
    install -m 0755 "$tmp/fnm" /usr/local/bin/fnm
    rm -rf "$tmp"
  fi
  install -d -m 0755 /opt/fnm
  for v in "${NODE_LIST[@]}"; do
    log "Node $v"
    FNM_DIR=/opt/fnm /usr/local/bin/fnm install "$v"
  done
  chmod -R a+rX /opt/fnm
fi

# ---------------------------------------------------------------- kernel
# App ports (Node SSR, Reverb) are allocated below 1024 so that only the unit
# granted CAP_NET_BIND_SERVICE for exactly that port can bind it. Containers
# often lower this to 0; the agent refuses to allocate ports if it is.
log "Kernel settings"
echo "net.ipv4.ip_unprivileged_port_start = 1024" > /etc/sysctl.d/90-wspanel.conf
sysctl -q -p /etc/sysctl.d/90-wspanel.conf

# ---------------------------------------------------------------- users
log "System users"
id panel-proxy &>/dev/null || useradd --system --user-group --no-create-home --home-dir /var/lib/panel-proxy --shell /usr/sbin/nologin panel-proxy
id panel-web &>/dev/null || useradd --system --user-group --no-create-home --home-dir /var/lib/panel-web --shell /usr/sbin/nologin panel-web
id dbtools &>/dev/null || useradd --system --user-group --no-create-home --home-dir /var/lib/wspanel-dbtools --shell /usr/sbin/nologin dbtools
getent group wspanel-ssh >/dev/null || groupadd --system wspanel-ssh
getent group wspanel-sftp >/dev/null || groupadd --system wspanel-sftp
id panel &>/dev/null || useradd --system --user-group --no-create-home --home-dir /srv/panel --shell /usr/sbin/nologin panel

# ---------------------------------------------------------------- directories
install -d -m 0751 -o root -g root /srv/sites
install -d -m 0700 -o root -g root /srv/sites/.trash
install -d -m 0750 -o panel -g panel /srv/panel /srv/panel/releases /srv/panel/shared /srv/panel/tmp
# The web tier may traverse to the panel's public/ only (ACLs set per release below).
setfacl -m u:panel-web:x /srv/panel /srv/panel/releases
install -d -m 0755 /etc/panel-proxy /etc/panel-proxy/sites /etc/panel-web /etc/panel-web/sites /etc/panel-sites
# Socket dirs of worker-mode sites live under here (one per site, via tmpfiles.d).
echo "d /run/wspanel-sites 0755 root root -" > /etc/tmpfiles.d/wspanel.conf
systemd-tmpfiles --create /etc/tmpfiles.d/wspanel.conf
install -d -m 0700 /etc/panel-agent
# Database dumps: root-written, readable by the panel (downloads).
install -d -m 0750 -o root -g panel /var/backups/wspanel

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
  "panel_domain": "$PANEL_DOMAIN",
  "protected_ports": [$SSH_PORT, 80, 443],
  "allow_unenforced_socket_bind": $UNENFORCED_BIND
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

# ---------------------------------------------------------------- phpMyAdmin + Adminer
# Served at /dbtools/ on the panel domain by their own FPM pool (user dbtools).
# Sign-in only through one-time tokens from the agent; config outside the web root.
log "phpMyAdmin $PMA_VERSION, Adminer $ADMINER_VERSION"
TOOLS=/usr/share/wspanel-dbtools/dbtools
install -d -m 0755 /usr/share/wspanel-dbtools "$TOOLS"
install -d -m 0750 -o root -g dbtools /etc/wspanel-dbtools
install -d -m 0700 -o dbtools -g dbtools /var/lib/wspanel-dbtools /var/lib/wspanel-dbtools/tmp /var/lib/wspanel-dbtools/sessions /var/lib/wspanel-dbtools/pma-tmp
echo "d /run/wspanel-dbtools 0700 dbtools dbtools -" > /etc/tmpfiles.d/wspanel-dbtools.conf
systemd-tmpfiles --create /etc/tmpfiles.d/wspanel-dbtools.conf
if [[ "$(cat "$TOOLS/pma/RELEASE-DATE-$PMA_VERSION" 2>/dev/null)" == "" ]]; then
  tmp="$(mktemp -d)"
  curl -fsSLo "$tmp/pma.tar.xz" "https://files.phpmyadmin.net/phpMyAdmin/$PMA_VERSION/phpMyAdmin-$PMA_VERSION-all-languages.tar.xz"
  echo "$PMA_SHA256  $tmp/pma.tar.xz" | sha256sum -c - || die "phpMyAdmin checksum mismatch"
  tar -xJf "$tmp/pma.tar.xz" -C "$tmp"
  rm -rf "$TOOLS/pma" && mv "$tmp/phpMyAdmin-$PMA_VERSION-all-languages" "$TOOLS/pma"
  rm -rf "$TOOLS/pma/setup" "$TOOLS/pma/examples" "$TOOLS/pma/test" "$TOOLS/pma/config.sample.inc.php"
  sed -i "s|'configFile' => ROOT_PATH . 'config.inc.php'|'configFile' => '/etc/wspanel-dbtools/pma.config.inc.php'|" "$TOOLS/pma/libraries/vendor_config.php"
  grep -q "/etc/wspanel-dbtools/pma.config.inc.php" "$TOOLS/pma/libraries/vendor_config.php" || die "could not relocate phpMyAdmin config"
  rm -rf "$tmp"
fi
install -m 0644 "$HERE/files/dbtools/pma-signon.php" "$TOOLS/pma/wspanel-signon.php"
if ! sha256sum "$TOOLS/adminer/adminer.php" 2>/dev/null | grep -q "$ADMINER_SHA256"; then
  install -d -m 0755 "$TOOLS/adminer"
  curl -fsSLo "$TOOLS/adminer/adminer.php.new" "https://github.com/vrana/adminer/releases/download/v$ADMINER_VERSION/adminer-$ADMINER_VERSION.php"
  echo "$ADMINER_SHA256  $TOOLS/adminer/adminer.php.new" | sha256sum -c - || die "Adminer checksum mismatch"
  mv "$TOOLS/adminer/adminer.php.new" "$TOOLS/adminer/adminer.php"
fi
install -m 0644 "$HERE/files/dbtools/adminer-index.php" "$TOOLS/adminer/index.php"
install -m 0644 "$HERE/files/dbtools/adminer-signon.php" "$TOOLS/adminer/wspanel-signon.php"
chown -R root:root /usr/share/wspanel-dbtools && chmod -R a+rX,go-w /usr/share/wspanel-dbtools
install -m 0644 "$HERE/files/dbtools/token.php" /etc/wspanel-dbtools/token.php
if [[ ! -f /etc/wspanel-dbtools/pma.config.inc.php ]]; then
  sed "s|__BLOWFISH_HEX__|$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')|" "$HERE/files/dbtools/pma-config.inc.php.tmpl" > /etc/wspanel-dbtools/pma.config.inc.php
fi
chown root:dbtools /etc/wspanel-dbtools/pma.config.inc.php && chmod 0640 /etc/wspanel-dbtools/pma.config.inc.php
install -m 0644 "$HERE/files/dbtools/dbtools-pool.conf" "/etc/php/$PANEL_PHP/fpm/pool.d/dbtools.conf"

# ---------------------------------------------------------------- SSH / SFTP
log "SSH (keys only) and fail2ban"
install -d -m 0755 /etc/ssh/authorized_keys /etc/panel-sites/bin
sed -e "s|__ROOT_LOGIN__|$ROOT_LOGIN|" "$HERE/files/sshd-wspanel.conf.tmpl" > /etc/ssh/sshd_config.d/50-wspanel.conf
if [[ "$SSH_PORT" != 22 ]]; then
  echo "Port $SSH_PORT" > /etc/ssh/sshd_config.d/40-wspanel-port.conf
fi
install -m 0644 "$HERE/files/profile-wspanel.sh" /etc/profile.d/wspanel.sh
# Same PATH for non-login SSH commands, via pam_env (idempotent block).
sed -i '/^# >>> WSPanel/,/^# <<< WSPanel/d' /etc/security/pam_env.conf
cat "$HERE/files/pam_env-wspanel.conf" >> /etc/security/pam_env.conf
sed -e "s|__SSH_PORT__|$SSH_PORT|" "$HERE/files/fail2ban-wspanel.conf.tmpl" > /etc/fail2ban/jail.d/wspanel.conf
sshd -t || die "sshd config invalid"
systemctl reload ssh 2>/dev/null || systemctl restart ssh
systemctl enable --now fail2ban
systemctl restart fail2ban

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
