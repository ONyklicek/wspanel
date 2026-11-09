#!/usr/bin/env bash
# WSPanel provisioning for Debian 13 (trixie). Runs natively on the host; no Docker.
#
#   install.sh --db=mariadb,postgres --php=8.3,8.4 --panel-domain=panel.example.com \
#              --acme-email=admin@example.com --agent-bin=./panel-agent-linux-amd64 \
#              [--node=22,24] [--panel-source=./panel] [--ssh-port=22] [--no-root-ssh] [--local-certs]
#              [--panel-port=8443] [--clients=yes] [--mail-hosting=yes]
#
# Panel address:
#   --panel-domain=panel.example.com             own hostname, port 443, Let's Encrypt
#   --panel-domain=example.com --panel-port=8443 a port of a domain the sites use too
#   --panel-port=8443 (no --panel-domain)        the server's IP address and that port,
#                                                with the panel's own CA certificate
#
# --db accepts: none | mariadb | postgres | mariadb,postgres
# Safe to re-run: every step is idempotent.
set -euo pipefail

FRANKENPHP_VERSION="v1.13.5"
declare -A FRANKENPHP_SHA256=(
  [x86_64]="512e61cf72699f7680beb9a1c51da994bed0ee9a0e451b74a74c85eb286b20d9"
  [aarch64]="dec255cf2c69f127fc47f8f312be0bb600f58738e0e34574f77d58f91c408de0"
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
MAILPIT_VERSION="v1.33.0"
declare -A MAILPIT_ASSET=([x86_64]="mailpit-linux-amd64.tar.gz" [aarch64]="mailpit-linux-arm64.tar.gz")
declare -A MAILPIT_SHA256=(
  [x86_64]="d0ec50080cbf3e7c1fa2cda2ee68125d7894175811084b1f8f47c6cfc6f487c8"
  [aarch64]="1d27c819e1a5dd2761ed9fd07022443e35a47793b2e92de5b49cf3656a649ef9"
)
PANEL_PHP="8.4" # the panel itself always runs on this version

DB="none"
PHP_VERSIONS="8.4"
NODE_VERSIONS="22,24"
PANEL_DOMAIN=""
PANEL_PORT=443
CLIENTS=no
MAIL_HOSTING=no
XHPROF=no
ACME_EMAIL=""
AGENT_BIN=""
PANEL_SOURCE=""
LOCAL_CERTS=0
SSH_PORT=22
PUBLIC_SSH_PORT=""   # as clients see it (port forwarding / NAT); defaults to --ssh-port
PUBLIC_HTTPS_PORT=443
PUBLIC_IPV4=""       # server's public addresses for suggested DNS records; detected when empty
PUBLIC_IPV6=""
ROOT_LOGIN="prohibit-password"
UNENFORCED_BIND=false
PANEL_ALLOW=""      # comma-separated IPs/CIDRs allowed to open the panel; empty = keep current (public on first install)
DNS_SERVER="none"   # powerdns: this server is authoritative DNS for zones managed in the panel
MAIL_DEFAULT="send" # new sites: send (Postfix) | capture (Mailpit, nothing leaves the server)
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

for arg in "$@"; do
  case "$arg" in
    --db=*) DB="${arg#*=}" ;;
    --php=*) PHP_VERSIONS="${arg#*=}" ;;
    --node=*) NODE_VERSIONS="${arg#*=}" ;; # Node majors for SSR sites, "none" to skip
    --panel-domain=*) PANEL_DOMAIN="${arg#*=}" ;;
    --panel-port=*) PANEL_PORT="${arg#*=}" ;;
    --clients=*) CLIENTS="${arg#*=}" ;; # yes: hosting clients with packages (Server → Moduly later)
    --xhprof=*) XHPROF="${arg#*=}" ;; # yes: XHProf + XHGui, switchable per site (Server → Moduly later)
    --mail-hosting=*) MAIL_HOSTING="${arg#*=}" ;; # yes: mailboxes, IMAP/POP3, antispam (Server → Moduly later)
    --acme-email=*) ACME_EMAIL="${arg#*=}" ;;
    --agent-bin=*) AGENT_BIN="${arg#*=}" ;;
    --panel-source=*) PANEL_SOURCE="${arg#*=}" ;;
    --ssh-port=*) SSH_PORT="${arg#*=}" ;;
    --public-ssh-port=*) PUBLIC_SSH_PORT="${arg#*=}" ;;     # dev container: 2222
    --public-https-port=*) PUBLIC_HTTPS_PORT="${arg#*=}" ;; # dev container: 8443
    --public-ipv4=*) PUBLIC_IPV4="${arg#*=}" ;; # behind NAT: the public address
    --public-ipv6=*) PUBLIC_IPV6="${arg#*=}" ;;
    --allow-unenforced-socket-bind) UNENFORCED_BIND=true ;; # dev containers only (no cgroup BPF)
    --no-root-ssh) ROOT_LOGIN="no" ;; # make sure another admin account has a key first
    --mail=*) MAIL_DEFAULT="${arg#*=}" ;;
    --dns=*) DNS_SERVER="${arg#*=}" ;;
    --panel-allow=*) PANEL_ALLOW="${arg#*=}" ;; # e.g. 10.8.0.0/24,203.0.113.7 ; "any" = public again
    --local-certs) LOCAL_CERTS=1 ;; # dev only: Caddy internal CA instead of Let's Encrypt
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) die "unknown argument: $arg" ;;
  esac
done

# ---------------------------------------------------------------- checks
[[ $EUID -eq 0 ]] || die "run as root"
. /etc/os-release
[[ "${ID:-}" == "debian" && "${VERSION_CODENAME:-}" == "trixie" ]] || die "Debian 13 (trixie) required, found ${PRETTY_NAME:-unknown}"
[[ "$CLIENTS" == yes || "$CLIENTS" == no ]] || die "--clients must be yes or no"
[[ "$MAIL_HOSTING" == yes || "$MAIL_HOSTING" == no ]] || die "--mail-hosting must be yes or no"
[[ "$XHPROF" == yes || "$XHPROF" == no ]] || die "--xhprof must be yes or no"
[[ "$PANEL_PORT" =~ ^[0-9]+$ && $PANEL_PORT -ge 1 && $PANEL_PORT -le 65535 ]] || die "invalid --panel-port"
# ip(8) finds the server's addresses; a minimal system may not have it yet.
command -v ip >/dev/null || { apt-get update -q >/dev/null && apt-get install -y -q --no-install-recommends iproute2 >/dev/null; } || true
if [[ -z "$PANEL_DOMAIN" ]]; then
  [[ $PANEL_PORT -ne 443 ]] || die "--panel-domain is required (or give --panel-port to reach the panel at the server's IP address)"
  PANEL_DOMAIN="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' || true)"
  [[ -n "$PANEL_DOMAIN" ]] || die "cannot find the server's IPv4 address; give --panel-domain"
fi
[[ "$PANEL_DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || die "invalid --panel-domain"
[[ $PANEL_PORT -ne 80 && $PANEL_PORT -ne $SSH_PORT ]] || die "--panel-port must not be 80 or the SSH port"
# An IP address has no public certificate: the panel uses its own CA (SSL → Kořenový certifikát).
PANEL_IS_IP=0
[[ "$PANEL_DOMAIN" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && PANEL_IS_IP=1
# Hostname for mail and DNS (an IP address is not one).
MAIL_NAME="$PANEL_DOMAIN"
[[ $PANEL_IS_IP -eq 1 ]] && MAIL_NAME="$(hostname -f 2>/dev/null || hostname)"
[[ -n "$AGENT_BIN" && -x "$AGENT_BIN" ]] || die "--agent-bin must point to the built panel-agent binary"
[[ "$SSH_PORT" =~ ^[0-9]+$ && $SSH_PORT -ge 1 && $SSH_PORT -le 65535 ]] || die "invalid --ssh-port"
[[ -z "$ACME_EMAIL" ]] && ACME_EMAIL="admin@${MAIL_NAME}"
[[ "$MAIL_DEFAULT" == send || "$MAIL_DEFAULT" == capture ]] || die "--mail must be send or capture"
[[ "$DNS_SERVER" == none || "$DNS_SERVER" == powerdns ]] || die "--dns must be none or powerdns"
[[ -z "$PUBLIC_SSH_PORT" ]] && PUBLIC_SSH_PORT="$SSH_PORT"
[[ "$PUBLIC_SSH_PORT" =~ ^[0-9]+$ && "$PUBLIC_HTTPS_PORT" =~ ^[0-9]+$ ]] || die "invalid --public-*-port"
# Where browsers reach the panel: its own port, or the sites' (published) HTTPS port.
PANEL_PUBLIC_PORT="$PUBLIC_HTTPS_PORT"
[[ $PANEL_PORT -ne 443 ]] && PANEL_PUBLIC_PORT="$PANEL_PORT"
PANEL_URL="https://$PANEL_DOMAIN"
[[ "$PANEL_PUBLIC_PORT" != 443 ]] && PANEL_URL="$PANEL_URL:$PANEL_PUBLIC_PORT"
PANEL_ADDRESS="$PANEL_DOMAIN"
[[ $PANEL_PORT -ne 443 ]] && PANEL_ADDRESS="$PANEL_DOMAIN:$PANEL_PORT"
PANEL_TLS=""
[[ $PANEL_IS_IP -eq 1 ]] && PANEL_TLS="tls internal"

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

# apt-get update that a WSPanel repository out of reach (GitHub Pages down)
# does not stop: it is retried without that one source.
apt_update() {
  apt-get update -q && return 0
  local src=/etc/apt/sources.list.d/wspanel.sources rc=0
  [[ -f $src ]] || return 1
  log "The WSPanel apt repository did not answer; continuing without it"
  mv "$src" "$src.off"
  apt-get update -q || rc=$?
  mv "$src.off" "$src"
  return $rc
}

# ---------------------------------------------------------------- base packages
log "Base packages"
apt_update
apt-get install -y -q --no-install-recommends \
  ca-certificates curl gnupg openssl acl sqlite3 unzip git rsync composer valkey-server ufw nftables \
  openssh-server fail2ban mc nano xz-utils iproute2 quota

# ---------------------------------------------------------------- PHP (sury)
log "PHP ${PHP_LIST[*]} (packages.sury.org)"
if [[ ! -f /etc/apt/sources.list.d/php.list ]]; then
  curl -fsSL --retry 4 --retry-delay 3 --retry-all-errors -o /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
  dpkg -i /tmp/debsuryorg-archive-keyring.deb
  echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ trixie main" \
    > /etc/apt/sources.list.d/php.list
  apt_update
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
# Output captured first: with pipefail, `cmd | grep -q` fails when grep exits early
# (SIGPIPE to cmd), which re-downloaded installed tools on every run.
if [[ "$(/usr/local/bin/frankenphp version 2>/dev/null || true)" != *"${FRANKENPHP_VERSION#v}"* ]]; then
  log "FrankenPHP $FRANKENPHP_VERSION"
  tmp="$(mktemp)"
  curl -fsSL --retry 4 --retry-delay 3 --retry-all-errors -o "$tmp" "https://github.com/php/frankenphp/releases/download/${FRANKENPHP_VERSION}/frankenphp-linux-${ARCH}"
  echo "${FRANKENPHP_SHA256[$ARCH]}  $tmp" | sha256sum -c - || die "FrankenPHP checksum mismatch"
  install -m 0755 "$tmp" /usr/local/bin/frankenphp
  rm -f "$tmp"
fi

# ---------------------------------------------------------------- Node (fnm)
# System-wide installs in /opt/fnm, readable by every site user. The agent
# discovers versions from /opt/fnm/node-versions; sites pick a major.
# The panel's own assets are built with Node too: with --node=none one LTS
# major is still installed for that.
[[ ${#NODE_LIST[@]} -gt 0 ]] || NODE_LIST=(24)
if [[ ${#NODE_LIST[@]} -gt 0 ]]; then
  if [[ "$(/usr/local/bin/fnm --version 2>/dev/null || true)" != *"${FNM_VERSION#v}"* ]]; then
    log "fnm $FNM_VERSION"
    tmp="$(mktemp -d)"
    curl -fsSL --retry 4 --retry-delay 3 --retry-all-errors -o "$tmp/fnm.zip" "https://github.com/Schniz/fnm/releases/download/${FNM_VERSION}/${FNM_ASSET[$ARCH]}"
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
# CI build artifacts: the panel writes, the agent reads (and checks the owner).
install -d -m 0730 -o root -g panel /var/lib/wspanel-artifacts
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

# ---------------------------------------------------------------- disk quotas
# Clients' disk limits are group quotas on the filesystem of the sites.
# shellcheck source=quota.sh
source "$HERE/quota.sh"
QUOTA_STATE=$(enable_group_quota /srv/sites)
log "Client disk quotas: $QUOTA_STATE"

# ---------------------------------------------------------------- mail
# Outgoing only: Postfix on loopback relays the sites' mail (directly or via a
# smarthost set in the panel), OpenDKIM signs it. Nothing is received from
# outside. Mailpit catches mail of sites in "capture" mode (tests, staging).
log "Mail (Postfix, OpenDKIM, Mailpit)"
echo "postfix postfix/main_mailer_type select Internet Site" | debconf-set-selections
echo "postfix postfix/mailname string $MAIL_NAME" | debconf-set-selections
DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends postfix opendkim libsasl2-modules sasl2-bin
# Every site has its own SMTP login (created by the agent) and may send only as
# its own domain: over SMTP via reject_sender_login_mismatch, with sendmail via
# local_login_sender_maps. OpenDKIM signs by that verified envelope sender.
install -d -m 0755 /etc/postfix/sasl
printf 'pwcheck_method: auxprop\nauxprop_plugin: sasldb\nmech_list: PLAIN LOGIN\nsasldb_path: /etc/sasldb2\n' > /etc/postfix/sasl/smtpd.conf
[[ -f /etc/sasldb2 ]] || install -m 0640 -o root -g sasl /dev/null /etc/sasldb2
adduser --quiet postfix sasl
for m in wspanel_sender_logins wspanel_login_senders; do
  [[ -f /etc/postfix/$m ]] || printf '# Managed by WSPanel agent.\n' > "/etc/postfix/$m"
  postmap "/etc/postfix/$m"
done
postconf -e "myhostname=$MAIL_NAME" "myorigin=\$myhostname" "inet_interfaces=loopback-only" \
  "mydestination=" "local_transport=error:local delivery is disabled" "mynetworks=127.0.0.0/8 [::1]/128" \
  "smtpd_sasl_auth_enable=yes" "smtpd_sasl_type=cyrus" "smtpd_sasl_path=smtpd" "smtpd_sasl_security_options=noanonymous" \
  "smtpd_tls_security_level=none" \
  "smtpd_relay_restrictions=permit_sasl_authenticated, reject" \
  "smtpd_recipient_restrictions=permit_sasl_authenticated, reject" \
  "smtpd_sender_login_maps=hash:/etc/postfix/wspanel_sender_logins" \
  "smtpd_sender_restrictions=reject_sender_login_mismatch" \
  "local_login_sender_maps=hash:/etc/postfix/wspanel_login_senders, static:*" \
  "smtpd_milters=inet:127.0.0.1:8891" "non_smtpd_milters=inet:127.0.0.1:8891" "milter_default_action=accept" \
  "smtp_tls_security_level=may" "smtp_tls_CApath=/etc/ssl/certs" "message_size_limit=26214400"
postconf -F 'smtp/inet/chroot = n' # smtpd must reach /etc/sasldb2
install -d -m 0755 /etc/opendkim
install -d -m 0750 -o opendkim -g opendkim /etc/opendkim/keys
[[ -f /etc/opendkim/KeyTable ]] || printf '# Managed by WSPanel agent.\n' > /etc/opendkim/KeyTable
[[ -f /etc/opendkim/SigningTable ]] || printf '# Managed by WSPanel agent.\n' > /etc/opendkim/SigningTable
cat > /etc/opendkim.conf <<'CONF'
# Managed by WSPanel install.sh. Signing only; keys are created by the panel agent.
Syslog                  yes
UMask                   007
Mode                    s
Canonicalization        relaxed/simple
OversignHeaders         From
Socket                  inet:8891@127.0.0.1
PidFile                 /run/opendkim/opendkim.pid
UserID                  opendkim
KeyTable                refile:/etc/opendkim/KeyTable
SigningTable            refile:/etc/opendkim/SigningTable
# Sign by the envelope sender (checked by Postfix against the site's login),
# not by the From header a site could set to anything.
SenderMacro             mail_addr
InternalHosts           127.0.0.1, ::1
# Mailbox users sending from their programs (submission/smtps, mail hosting)
# connect from anywhere: those listeners mark the mail as originating.
MacroList               daemon_name|ORIGINATING
TrustAnchorFile         /usr/share/dns/root.key
CONF
systemctl enable opendkim postfix >/dev/null
systemctl restart opendkim postfix

# `mailpit version` also checks GitHub for updates and its exit code follows that.
if [[ "$(/usr/local/bin/mailpit version 2>/dev/null || true)" != *"${MAILPIT_VERSION}"* ]]; then
  tmp="$(mktemp -d)"
  curl -fsSL --retry 4 --retry-delay 3 --retry-all-errors -o "$tmp/mailpit.tgz" "https://github.com/axllent/mailpit/releases/download/${MAILPIT_VERSION}/${MAILPIT_ASSET[$ARCH]}"
  echo "${MAILPIT_SHA256[$ARCH]}  $tmp/mailpit.tgz" | sha256sum -c - >/dev/null || die "mailpit checksum mismatch"
  tar -xzf "$tmp/mailpit.tgz" -C "$tmp" mailpit
  install -m 0755 "$tmp/mailpit" /usr/local/bin/mailpit
  rm -rf "$tmp"
fi
id mailpit &>/dev/null || useradd --system --user-group --no-create-home --home-dir /var/lib/mailpit --shell /usr/sbin/nologin mailpit
# The web UI sits behind the panel login (edge forward_auth) and, since site
# processes can reach loopback too, additionally behind basic auth whose
# credentials only the edge proxy knows.
install -d -m 0750 -o root -g mailpit /etc/mailpit
if [[ ! -f /etc/mailpit/ui-auth ]]; then
  MAILPIT_UI_PASS="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
  printf 'panel:%s\n' "$(openssl passwd -apr1 "$MAILPIT_UI_PASS")" > /etc/mailpit/ui-auth
  printf '%s' "$(printf 'panel:%s' "$MAILPIT_UI_PASS" | base64 -w0)" > /etc/mailpit/ui-basic
  chmod 0640 /etc/mailpit/ui-auth; chgrp mailpit /etc/mailpit/ui-auth; chmod 0600 /etc/mailpit/ui-basic
fi
install -m 0644 "$HERE/files/mailpit.service" /etc/systemd/system/mailpit.service
systemctl daemon-reload
systemctl enable mailpit >/dev/null
systemctl restart mailpit

# Mail hosting (optional): the script the agent also runs when it is added later.
install -d -m 0755 /usr/lib/wspanel
install -m 0755 "$HERE/mail-hosting.sh" /usr/lib/wspanel/mail-hosting.sh
install -d -m 0755 /usr/lib/wspanel/files/webmail
install -m 0644 "$HERE"/files/webmail/* /usr/lib/wspanel/files/webmail/
# ClamAV for incoming mail (optional, switched on in the panel).
install -m 0755 "$HERE/clamav.sh" /usr/lib/wspanel/clamav.sh
# XHProf + XHGui (optional): the script the agent also runs when added later.
install -m 0755 "$HERE/xhprof.sh" /usr/lib/wspanel/xhprof.sh
install -d -m 0755 /usr/lib/wspanel/files/xhgui /usr/lib/wspanel/files/xhgui/xhgui /usr/lib/wspanel/files/xhgui/collector
install -m 0644 "$HERE"/files/xhgui/*.php /usr/lib/wspanel/files/xhgui/
install -m 0644 "$HERE"/files/xhgui/xhgui/composer.* /usr/lib/wspanel/files/xhgui/xhgui/
install -m 0644 "$HERE"/files/xhgui/collector/composer.* /usr/lib/wspanel/files/xhgui/collector/
rm -rf /etc/panel-web/xhgui # viewers moved onto the sites' own domains
if [[ $MAIL_HOSTING == yes || -f /etc/wspanel/mail/hosting ]]; then
  log "Mail hosting (Dovecot, Rspamd)"
  PANEL_DOMAIN="$PANEL_DOMAIN" PANEL_PORT="$PANEL_PORT" /usr/lib/wspanel/mail-hosting.sh "$MAIL_NAME" "$PANEL_PHP"
fi
if [[ $XHPROF == yes || -f /usr/share/wspanel-xhgui/prepend.php ]]; then
  log "XHProf + XHGui"
  /usr/lib/wspanel/xhprof.sh "${PHP_LIST[@]}"
fi

# ---------------------------------------------------------------- DNS (optional)
# Authoritative only (no recursion), zones in SQLite, managed by the agent over
# the API on loopback. Zones can also live at Cloudflare (panel, API token).
PDNS_API_KEY=""
if [[ "$DNS_SERVER" == powerdns ]]; then
  log "DNS server (PowerDNS)"
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends pdns-server pdns-backend-sqlite3
  rm -f /etc/powerdns/pdns.d/bind.conf
  install -d -m 0750 -o pdns -g pdns /var/lib/powerdns
  if [[ ! -s /var/lib/powerdns/pdns.sqlite3 ]]; then
    sqlite3 /var/lib/powerdns/pdns.sqlite3 < /usr/share/pdns-backend-sqlite3/schema/schema.sqlite3.sql
    chown pdns:pdns /var/lib/powerdns/pdns.sqlite3 && chmod 0640 /var/lib/powerdns/pdns.sqlite3
  fi
  if [[ -f /etc/powerdns/pdns.d/wspanel.conf ]]; then
    PDNS_API_KEY="$(sed -n 's/^api-key=//p' /etc/powerdns/pdns.d/wspanel.conf)"
  else
    PDNS_API_KEY="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
  fi
  cat > /etc/powerdns/pdns.d/wspanel.conf <<CONF
# Managed by WSPanel install.sh.
launch=gsqlite3
gsqlite3-database=/var/lib/powerdns/pdns.sqlite3
local-address=0.0.0.0, ::
api=yes
api-key=$PDNS_API_KEY
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.1/32
default-soa-content=ns1.$MAIL_NAME. hostmaster.$MAIL_NAME. 0 10800 3600 604800 3600
default-ttl=3600
CONF
  chmod 0640 /etc/powerdns/pdns.d/wspanel.conf; chgrp pdns /etc/powerdns/pdns.d/wspanel.conf
  systemctl enable pdns >/dev/null
  systemctl restart pdns
fi

# ---------------------------------------------------------------- agent
log "Agent"
DEB_ARCH="$(dpkg --print-architecture)"
AGENT_DEB="$(ls "$(dirname "$AGENT_BIN")"/wspanel-agent_*_"$DEB_ARCH".deb 2>/dev/null | head -1 || true)"
agent_packaged() { dpkg-query -W -f='${Status}' wspanel-agent 2>/dev/null | grep -q 'ok installed'; }
if [[ -n "$AGENT_DEB" ]]; then
  # A release bundle: the agent as the wspanel-agent package, so that apt
  # brings its fixes. Installed from the bundle itself, nothing downloaded.
  dpkg -i "$AGENT_DEB" >/dev/null
elif [[ "$(readlink -f "$AGENT_BIN")" == /usr/sbin/panel-agent ]] && agent_packaged; then
  : # installed through apt (wspanel-install): the package is in place already
else
  # From source (dev): the binaries as they are, in the same places.
  install -m 0755 "$AGENT_BIN" /usr/sbin/panel-agent
  CLI_BIN="$(dirname "$AGENT_BIN")/$(basename "$AGENT_BIN" | sed 's/^panel-agent/wspanel/')"
  [[ -x "$CLI_BIN" ]] && install -m 0755 "$CLI_BIN" /usr/bin/wspanel
fi
# The old places, which timers and units written before still name.
ln -sfn /usr/sbin/panel-agent /usr/local/sbin/panel-agent
[[ -x /usr/bin/wspanel ]] && ln -sfn /usr/bin/wspanel /usr/local/bin/wspanel

# The apt repository of the release this bundle is (GitHub Pages of its
# repository): one channel per minor version (1.2, or 1.2-pre for release
# candidates), so apt brings fixes of this line only; the next line comes
# with the installer, together with its panel. Picked up by unattended
# upgrades as well. The wspanel-archive-keyring package sets apt up (key,
# source, unattended upgrades) and brings a new key when there is one.
# WSPANEL_APT_URI: a mirror (or a test repository).
KEYRING_DEB="$(ls "$(dirname "$AGENT_BIN")"/wspanel-archive-keyring_*_all.deb 2>/dev/null | head -1 || true)"
keyring_packaged() { dpkg-query -W -f='${Status}' wspanel-archive-keyring 2>/dev/null | grep -q 'ok installed'; }
bundle_version="$(cat "$HERE/../VERSION" 2>/dev/null || true)"
if agent_packaged && [[ "$bundle_version" =~ ^([0-9]+)\.([0-9]+)\.[0-9]+(-.+)?$ ]]; then
  suite="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"; [[ -n "${BASH_REMATCH[3]}" ]] && suite+="-pre"
  if [[ -n "$KEYRING_DEB" ]]; then
    WSPANEL_CHANNEL="$suite" dpkg -i "$KEYRING_DEB" >/dev/null
  fi
  SRC=/etc/apt/sources.list.d/wspanel.sources
  if keyring_packaged && [[ -f $SRC ]]; then
    sed -i -E "s/^Suites: .*/Suites: $suite/" "$SRC"
    [[ -n "${WSPANEL_APT_URI:-}" ]] && sed -i -E "s|^URIs: .*|URIs: $WSPANEL_APT_URI|" "$SRC"
  fi
fi
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
  "panel_port": $PANEL_PORT,
  "protected_ports": [$SSH_PORT, 80, 443$([[ $PANEL_PORT -ne 443 ]] && echo ", $PANEL_PORT")],
  "mail_default": "$MAIL_DEFAULT",
  "mail_host": "$MAIL_NAME",
  "powerdns_api": "$([[ -n "$PDNS_API_KEY" ]] && echo http://127.0.0.1:8081)",
  "powerdns_key": "$PDNS_API_KEY",
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
# The unit: wspanel-agent's (/usr/lib/systemd/system; changes with
# systemctl edit panel-agent), or for an agent from source one in /etc.
if agent_packaged; then
  if grep -qs '^Description=WSPanel privileged agent' /etc/systemd/system/panel-agent.service; then
    rm -f /etc/systemd/system/panel-agent.service # an older installer's copy
  fi
else
  install -m 0644 "$HERE/files/panel-agent.service" /etc/systemd/system/panel-agent.service
fi

# ---------------------------------------------------------------- proxy
log "Reverse proxy"
sed -e "s|__PANEL_ADDRESS__|$PANEL_ADDRESS|" -e "s|__PANEL_TLS__|$PANEL_TLS|" -e "s|__ACME_EMAIL__|$ACME_EMAIL|" \
  -e "s|__MAILPIT_BASIC__|$(cat /etc/mailpit/ui-basic)|" \
  "$HERE/files/Caddyfile.tmpl" > /etc/panel-proxy/Caddyfile
chmod 0640 /etc/panel-proxy/Caddyfile; chgrp panel-proxy /etc/panel-proxy/Caddyfile
# Panel access list (also editable in the panel). Deploy webhooks and the CI
# API stay public; loopback is always allowed (SSH tunnel).
if [[ -n "$PANEL_ALLOW" ]]; then
  if [[ "$PANEL_ALLOW" == any ]]; then
    printf '# Managed by WSPanel agent (panel.access). Public.\n' > /etc/panel-proxy/panel-access.caddy
  else
    [[ "$PANEL_ALLOW" =~ ^[0-9a-fA-F:.,/]+$ ]] || die "invalid --panel-allow"
    {
      printf '# Managed by WSPanel agent (panel.access). Who may open the panel.\n'
      printf '@panel_outsider {\n\tnot remote_ip 127.0.0.1/8 ::1 %s\n\tnot path /hooks/deploy/* /api/deploy/*\n}\n' "${PANEL_ALLOW//,/ }"
      printf 'respond @panel_outsider 404 {\n\tclose\n}\n'
    } > /etc/panel-proxy/panel-access.caddy
  fi
fi
[[ -f /etc/panel-proxy/panel-access.caddy ]] || printf '# Managed by WSPanel agent (panel.access). Public.\n' > /etc/panel-proxy/panel-access.caddy
chmod 0640 /etc/panel-proxy/panel-access.caddy; chgrp panel-proxy /etc/panel-proxy/panel-access.caddy
if [[ $LOCAL_CERTS -eq 1 ]]; then
  sed -i 's|^\temail .*|\tlocal_certs|' /etc/panel-proxy/Caddyfile
fi
cp "$HERE/files/web-Caddyfile.tmpl" /etc/panel-web/Caddyfile
install -m 0644 "$HERE/files/panel-proxy.service" /etc/systemd/system/panel-proxy.service
install -m 0644 "$HERE/files/panel-web.service" /etc/systemd/system/panel-web.service
install -m 0644 "$HERE/files/panel-pool.conf" "/etc/php/$PANEL_PHP/fpm/pool.d/panel.conf"

# ---------------------------------------------------------------- panel app
if [[ -n "$PANEL_SOURCE" ]]; then
  log "Panel application"
  [[ -f "$PANEL_SOURCE/artisan" ]] || die "--panel-source does not look like the Laravel panel"
  release="/srv/panel/releases/$(date -u +%Y%m%d%H%M%S)"
  install -d -m 0750 -o panel -g panel "$release"
  # Anchored (/vendor, not vendor): an unanchored name also dropped
  # resources/views/vendor, the panel's published and adjusted package views.
  rsync -a --delete --exclude=/.git --exclude=/vendor --exclude=/node_modules --exclude=/.env \
    --exclude=/storage --exclude='/database/*.sqlite' "$PANEL_SOURCE/" "$release/"
  chown -R panel:panel "$release"
  # Persistent state lives in shared/.
  install -d -m 0750 -o panel -g panel /srv/panel/shared/storage
  for d in app app/public app/private framework framework/cache framework/cache/data framework/sessions framework/views logs; do
    install -d -m 0750 -o panel -g panel "/srv/panel/shared/storage/$d"
  done
  if [[ ! -f /srv/panel/shared/.env ]]; then
    install -m 0600 -o panel -g panel "$PANEL_SOURCE/.env.example" /srv/panel/shared/.env
    sed -i -e "s|^APP_ENV=.*|APP_ENV=production|" -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
      -e "s|^APP_URL=.*|APP_URL=$PANEL_URL|" \
      -e "s|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|" -e "s|^# *DB_DATABASE=.*|DB_DATABASE=/srv/panel/shared/database.sqlite|" \
      -e "s|^AGENT_SOCKET=.*|AGENT_SOCKET=/run/panel-agent.sock|" /srv/panel/shared/.env
    grep -q '^DB_DATABASE=' /srv/panel/shared/.env || echo "DB_DATABASE=/srv/panel/shared/database.sqlite" >> /srv/panel/shared/.env
    grep -q '^AGENT_SOCKET=' /srv/panel/shared/.env || echo "AGENT_SOCKET=/run/panel-agent.sock" >> /srv/panel/shared/.env
  fi
  # Public URL and SSH port (shown with links and SFTP logins), kept in sync.
  set_panel_env() {
    if grep -q "^$1=" /srv/panel/shared/.env; then
      sed -i "s|^$1=.*|$1=$2|" /srv/panel/shared/.env
    else
      echo "$1=$2" >> /srv/panel/shared/.env
    fi
  }
  set_panel_env APP_URL "$PANEL_URL"
  set_panel_env PANEL_PORT "$PANEL_PORT"
  set_panel_env PANEL_CLIENTS "$([[ $CLIENTS == yes ]] && echo true || echo false)"
  set_panel_env PANEL_MAIL_HOST "$MAIL_NAME"
  set_panel_env PANEL_SITES_HTTPS_PORT "$PUBLIC_HTTPS_PORT"
  set_panel_env PANEL_SSH_PORT "$PUBLIC_SSH_PORT"
  [[ -n "$PUBLIC_IPV4" ]] || PUBLIC_IPV4="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' || true)"
  [[ -n "$PUBLIC_IPV6" ]] || PUBLIC_IPV6="$(ip -6 route get 2606:4700::1111 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' || true)"
  # The release this is and where newer ones come from (a bundle from the
  # release workflow carries both; a run from source leaves them as they are).
  bundle_version="$(cat "$HERE/../VERSION" 2>/dev/null || true)"
  [[ "$bundle_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] && set_panel_env PANEL_VERSION "$bundle_version"
  bundle_repo="$(cat "$HERE/../REPO" 2>/dev/null || true)"
  [[ "$bundle_repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] && set_panel_env PANEL_RELEASE_REPO "$bundle_repo"
  # How the next version comes: apt (wspanel-update) or a release bundle (get.sh).
  [[ -n "${WSPANEL_VIA_APT:-}" ]] && set_panel_env PANEL_INSTALL_METHOD apt
  [[ -z "${WSPANEL_VIA_APT:-}" && "$bundle_version" =~ ^[0-9] ]] && set_panel_env PANEL_INSTALL_METHOD bundle
  set_panel_env PANEL_PUBLIC_IPV4 "$PUBLIC_IPV4"
  set_panel_env PANEL_PUBLIC_IPV6 "$PUBLIC_IPV6"
  grep -q '^APP_LOCALE=cs' /srv/panel/shared/.env || set_panel_env APP_LOCALE cs
  # Notifications by e-mail go through the local Postfix (sendmail as the panel user).
  set_panel_env MAIL_MAILER sendmail
  set_panel_env MAIL_FROM_ADDRESS "\"wspanel@$MAIL_NAME\""
  set_panel_env MAIL_FROM_NAME "\"WSPanel\""
  [[ -f /srv/panel/shared/database.sqlite ]] || install -m 0600 -o panel -g panel /dev/null /srv/panel/shared/database.sqlite
  # Documentation shown in the panel (Nápověda).
  if [[ -d "$HERE/../docs" ]]; then rsync -a --delete "$HERE/../docs/" "$release/resources/docs/"; fi
  ln -sfn /srv/panel/shared/.env "$release/.env"
  rm -rf "$release/storage" && ln -sfn /srv/panel/shared/storage "$release/storage"
  chown -h panel:panel "$release/.env" "$release/storage"

  as_panel() { runuser -u panel -- env HOME=/srv/panel/tmp COMPOSER_HOME=/srv/panel/tmp/composer "$@"; }
  (cd "$release" && as_panel composer install --no-dev --optimize-autoloader --no-interaction --no-progress)
  # Panel theme (Tailwind via Vite), built with the newest installed Node.
  node_bin="$(ls -d /opt/fnm/node-versions/v*/installation/bin | sort -V | tail -1)"
  (cd "$release" && as_panel env PATH="$node_bin:/usr/bin:/bin" npm ci --no-audit --no-fund --loglevel=error \
    && as_panel env PATH="$node_bin:/usr/bin:/bin" npm run build >/dev/null && rm -rf node_modules)
  grep -q '^APP_KEY=base64:' /srv/panel/shared/.env || (cd "$release" && as_panel php artisan key:generate --force)
  (cd "$release" && as_panel php artisan migrate --force && as_panel php artisan optimize)
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
  curl -fsSL --retry 4 --retry-delay 3 --retry-all-errors -o "$tmp/pma.tar.xz" "https://files.phpmyadmin.net/phpMyAdmin/$PMA_VERSION/phpMyAdmin-$PMA_VERSION-all-languages.tar.xz"
  echo "$PMA_SHA256  $tmp/pma.tar.xz" | sha256sum -c - || die "phpMyAdmin checksum mismatch"
  tar -xJf "$tmp/pma.tar.xz" -C "$tmp"
  rm -rf "$TOOLS/pma" && mv "$tmp/phpMyAdmin-$PMA_VERSION-all-languages" "$TOOLS/pma"
  rm -rf "$TOOLS/pma/setup" "$TOOLS/pma/examples" "$TOOLS/pma/test" "$TOOLS/pma/config.sample.inc.php"
  sed -i "s|'configFile' => ROOT_PATH . 'config.inc.php'|'configFile' => '/etc/wspanel-dbtools/pma.config.inc.php'|" "$TOOLS/pma/libraries/vendor_config.php"
  grep -q "/etc/wspanel-dbtools/pma.config.inc.php" "$TOOLS/pma/libraries/vendor_config.php" || die "could not relocate phpMyAdmin config"
  rm -rf "$tmp"
fi
install -m 0644 "$HERE/files/dbtools/pma-signon.php" "$TOOLS/pma/wspanel-signon.php"
if [[ "$(sha256sum "$TOOLS/adminer/adminer.php" 2>/dev/null || true)" != "$ADMINER_SHA256 "* ]]; then
  install -d -m 0755 "$TOOLS/adminer"
  curl -fsSL --retry 4 --retry-delay 3 --retry-all-errors -o "$TOOLS/adminer/adminer.php.new" "https://github.com/vrana/adminer/releases/download/v$ADMINER_VERSION/adminer-$ADMINER_VERSION.php"
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
if [[ $PANEL_PORT -ne 443 ]]; then
  ufw allow "$PANEL_PORT/tcp" comment panel >/dev/null
  ufw allow "$PANEL_PORT/udp" comment panel-http3 >/dev/null
fi
if [[ "$DNS_SERVER" == powerdns ]]; then
  ufw allow 53/udp comment dns >/dev/null
  ufw allow 53/tcp comment dns >/dev/null
fi
ufw --force enable >/dev/null

# ---------------------------------------------------------------- start
log "Starting services"
systemctl daemon-reload
for v in "${PHP_LIST[@]}"; do
  "php-fpm$v" -t
  systemctl enable "php$v-fpm"
  systemctl restart "php$v-fpm"
done
# No shared Valkey: every site gets its own (Další → Valkey), with its own
# port, password and memory. The package stays for the binaries only.
systemctl disable --now valkey-server >/dev/null 2>&1 || true
systemctl mask valkey-server >/dev/null 2>&1 || true
frankenphp validate --config /etc/panel-web/Caddyfile --adapter caddyfile
frankenphp validate --config /etc/panel-proxy/Caddyfile --adapter caddyfile
systemctl enable panel-web panel-proxy panel-agent
systemctl restart panel-web panel-proxy panel-agent
if [[ -f /etc/systemd/system/panel-scheduler.service ]]; then
  systemctl enable panel-scheduler
  systemctl restart panel-scheduler
fi
# The webmail calls the panel over the edge; with its own CA (local certs,
# an IP address) it trusts that root. The CA exists once the proxy ran,
# which on a first install is after mail-hosting.sh.
if [[ -d /etc/roundcube ]]; then
  for _ in $(seq 1 20); do
    [[ -s /var/lib/panel-proxy/caddy/pki/authorities/local/root.crt ]] && break
    sleep 0.5
  done
  if [[ -s /var/lib/panel-proxy/caddy/pki/authorities/local/root.crt ]]; then
    install -m 0644 /var/lib/panel-proxy/caddy/pki/authorities/local/root.crt /etc/roundcube/wspanel-edge-root-ca.crt
  fi
fi

# The choices of this run, for the next upgrade ("./install" offers to keep
# them). Left out: paths of this bundle (the next one has its own) and what
# the panel manages from then on (who may open it: replaying the list from
# the install would undo a change made in Firewall → Přístup k panelu).
install -d -m 0755 /etc/wspanel
saved=()
for arg in "$@"; do
  case "$arg" in --agent-bin=*|--panel-source=*|--panel-allow=*) ;; *) saved+=("$arg") ;; esac
done
printf '%s\n' "${saved[@]}" > /etc/wspanel/install.flags
chmod 0600 /etc/wspanel/install.flags

log "Done. Panel: $PANEL_URL"
if [[ -n "$PANEL_SOURCE" && -z "${WSPANEL_ADMIN_BY_INSTALLER:-}" ]] && [[ "$(runuser -u panel -- php /srv/panel/current/artisan tinker --execute 'echo App\Models\User::count();' 2>/dev/null | tail -1)" == 0 ]]; then
  log "Create the admin account: cd /srv/panel/current && runuser -u panel -- php artisan wire:user"
fi
exit 0
