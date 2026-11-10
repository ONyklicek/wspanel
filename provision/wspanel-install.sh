#!/usr/bin/env bash
# WSPanel installer: asks what to install (whiptail dialogs) and runs
# provision/install.sh with it. Part of the release bundle:
#
#   tar -xzf wspanel-<version>-linux-<arch>.tar.gz && cd wspanel && ./install
#
# Unattended (the same choices as flags, no questions):
#
#   ./install --yes --panel-domain=panel.example.com --acme-email=me@example.com \
#             --db=mariadb --php=8.3,8.4 --mail-hosting=yes --admin-email=me@example.com
#
# Every install.sh flag is passed through. Re-running it upgrades the server
# in place (the same as running install.sh again).
#
# Back to the version before the last upgrade (its panel database and
# settings as they were before it):
#
#   ./install --rollback          (installed: sudo wspanel-rollback)
#
# A lost server on a new one (a clean Debian 13), from its server backup and
# the recovery key (Zálohy mimo server → Klíč pro obnovu); the sites,
# databases and mail come from the off-site storage, or from a copy of the
# old server's /var/backups/wspanel:
#
#   ./install --restore=/root/server-20261109T021800Z.wspbak [--backups=/root/wspanel-backups]
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCH="$(dpkg --print-architecture 2>/dev/null || echo amd64)"
# The agent of this bundle; installed through apt (wspanel-install), the
# wspanel-agent package's.
AGENT="${WSPANEL_AGENT_BIN:-$HERE/bin/panel-agent-linux-$ARCH}"
TITLE="WSPanel $(cat "$HERE/VERSION" 2>/dev/null || echo dev)"

die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "run as root (sudo ./install)"
# The server's own name must resolve (Debian: "127.0.1.1 <name>" in
# /etc/hosts). Some VPS images lack the line: sudo then warns on every call
# ("unable to resolve host") and mail stumbles over it.
own="$(hostname 2>/dev/null || true)"
if [[ -n $own ]] && ! getent hosts "$own" >/dev/null 2>&1; then
  short="${own%%.*}"
  if [[ $short != "$own" ]]; then printf '127.0.1.1\t%s %s\n' "$own" "$short"; else printf '127.0.1.1\t%s\n' "$own"; fi >> /etc/hosts
  echo "Added the server's name $own to /etc/hosts (127.0.1.1)." >&2
fi
[[ -x "$AGENT" ]] || die "this bundle has no agent for $ARCH ($AGENT)"
. /etc/os-release
[[ "${ID:-}" == debian && "${VERSION_CODENAME:-}" == trixie ]] || die "Debian 13 (trixie) required, found ${PRETTY_NAME:-unknown}"

YES=0 ROLLBACK=0 RESTORE="" BACKUPS=""
ADMIN_EMAIL=""
ADMIN_NAME="Administrator"
PASS=()
for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --rollback) ROLLBACK=1 ;;
    --restore=*) RESTORE="${arg#*=}" ;;
    --backups=*) BACKUPS="${arg#*=}" ;;
    --admin-email=*) ADMIN_EMAIL="${arg#*=}" ;;
    --admin-name=*) ADMIN_NAME="${arg#*=}" ;;
    -h|--help) sed -n '2,/^set -euo/{/^#/p;}' "$0"; exit 0 ;;
    *) PASS+=("$arg") ;;
  esac
done

# ---------------------------------------------------------------- rollback
# Before an upgrade: a snapshot of the panel's state (database, settings,
# saved choices) and the version it belongs to. After a successful run: this
# version's installer kept as a "kit", which a rollback runs again. The
# newest 3 snapshots and 2 kits stay.
STATE=/var/lib/wspanel
THIS_VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo dev)"
installed_version() { sed -n 's/^PANEL_VERSION=//p' /srv/panel/shared/.env 2>/dev/null | tail -1; }
installed_method() { sed -n 's/^PANEL_INSTALL_METHOD=//p' /srv/panel/shared/.env 2>/dev/null | tail -1; }

snapshot() {
  [[ -f /srv/panel/shared/database.sqlite && -f /srv/panel/shared/.env ]] || return 0
  local from dir
  from="$(installed_version)"
  [[ -n "$from" ]] || return 0
  dir="$STATE/rollback/$(date -u +%Y%m%d%H%M%S)"
  install -d -m 0700 "$dir"
  sqlite3 /srv/panel/shared/database.sqlite ".backup '$dir/database.sqlite'"
  cp -a /srv/panel/shared/.env "$dir/env"
  [[ -s /etc/wspanel/install.flags ]] && cp -a /etc/wspanel/install.flags "$dir/install.flags"
  printf '%s\n' "$from" > "$dir/VERSION"
  printf '%s\n' "$(installed_method)" > "$dir/METHOD"
  ls -1d "$STATE"/rollback/*/ 2>/dev/null | sort | head -n -3 | xargs -r rm -rf
}

keep_kit() {
  [[ "$THIS_VERSION" != dev ]] || return 0
  local kit="$STATE/kit/$THIS_VERSION"
  install -d -m 0700 "$STATE/kit"
  if [[ "$(cd "$HERE" && pwd -P)" != "$(cd "$kit" 2>/dev/null && pwd -P)" ]]; then
    rm -rf "$kit.new" && install -d -m 0700 "$kit.new"
    # The panel's source without what the install builds into it.
    rsync -a --exclude='/panel/vendor' --exclude='/panel/node_modules' "$HERE/" "$kit.new/"
    printf '%s\n' "${WSPANEL_VIA_APT:+apt}" > "$kit.new/KIT_METHOD"
    rm -rf "$kit" && mv "$kit.new" "$kit"
  fi
  ls -1dt "$STATE"/kit/*/ 2>/dev/null | tail -n +3 | xargs -r rm -rf
  # One command for it, whichever way WSPanel was installed.
  cat > /usr/local/sbin/wspanel-rollback <<SH
#!/bin/sh
# Back to the WSPanel version before the last upgrade (written by the installer).
exec "$kit/install" --rollback "\$@"
SH
  chmod 0755 /usr/local/sbin/wspanel-rollback
}

rollback() {
  local snap="" from="" kit method debver d now
  now="$(installed_version)"
  # The newest snapshot of another version (a re-run of the same version
  # leaves one of that version too).
  while read -r d; do
    [[ -n "$d" && "$(cat "$d/VERSION" 2>/dev/null)" != "$now" ]] || continue
    snap="$d" from="$(cat "$d/VERSION")"
    break
  done < <(ls -1d "$STATE"/rollback/*/ 2>/dev/null | sort -r)
  [[ -n "$snap" ]] || die "no snapshot of a version other than $now in $STATE/rollback"
  kit="$STATE/kit/$from"
  [[ -x "$kit/install" ]] || die "the installer of $from is not kept in $kit; install $from again (get.sh with WSPANEL_VERSION=$from)"
  method="$(cat "$kit/KIT_METHOD" 2>/dev/null || true)"
  printf '\033[1;34m==>\033[0m Back to WSPanel %s (the panel database and settings from %s)\n' "$from" "${snap%/}"
  if ((! YES)); then
    read -r -p "Continue? [y/N] " a </dev/tty || a=""
    [[ $a == [yYaA]* ]] || { echo "Zrušeno."; exit 1; }
  fi
  systemctl stop panel-scheduler panel-queue panel-web 2>/dev/null || true
  install -m 0600 -o panel -g panel "$snap/database.sqlite" /srv/panel/shared/database.sqlite
  rm -f /srv/panel/shared/database.sqlite-wal /srv/panel/shared/database.sqlite-shm
  install -m 0600 -o panel -g panel "$snap/env" /srv/panel/shared/.env
  [[ -f "$snap/install.flags" ]] && install -m 0600 "$snap/install.flags" /etc/wspanel/install.flags
  if [[ $method == apt ]]; then
    debver="${from/-/\~}"
    apt-get update -q || true
    apt-get install -y -q -o DPkg::Lock::Timeout=900 --allow-downgrades "wspanel=$debver" "wspanel-agent=$debver" \
      || die "apt cannot install $debver any more; install $from again (get.sh with WSPANEL_VERSION=$from)"
    WSPANEL_ROLLBACK=1 exec /usr/sbin/wspanel-install --yes
  fi
  WSPANEL_ROLLBACK=1 exec "$kit/install" --yes
}

run_install() {
  [[ -n "${WSPANEL_ROLLBACK:-}" ]] || snapshot
  # This script creates the administrator itself (or keeps the existing ones).
  if ! WSPANEL_ADMIN_BY_INSTALLER=1 "$HERE/provision/install.sh" --agent-bin="$AGENT" --panel-source="$HERE/panel" "$@"; then
    if [[ -z "${WSPANEL_ROLLBACK:-}" && -x /usr/local/sbin/wspanel-rollback ]]; then
      printf '\n\033[1;31mThe installation did not finish.\033[0m Back to the previous version: sudo wspanel-rollback\n' >&2
    fi
    exit 1
  fi
  keep_kit
}

create_admin() { # email name [password] -> prints the password (generated when none)
  local email=$1 name=$2 pw=${3:-}
  [[ -n "$pw" ]] || pw="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)"
  (cd /srv/panel/current && runuser -u panel -- php artisan wire:user --no-interaction --super-admin \
    --name="$name" --email="$email" --password="$pw" >/dev/null)
  echo "$pw"
}

# Accounts already in the panel (an upgrade): none is created again.
user_count() {
  [[ -f /srv/panel/current/artisan ]] || { echo 0; return; }
  (cd /srv/panel/current && runuser -u panel -- php artisan tinker --execute 'echo App\Models\User::count();' 2>/dev/null | tail -1) || echo 0
}

((ROLLBACK)) && rollback

# ---------------------------------------------------------------- restore
restore_server() {
  local file=$1 info version flags=() newenv key
  [[ -f "$file" ]] || die "$file does not exist"
  [[ -z "$BACKUPS" || -d "$BACKUPS" ]] || die "$BACKUPS is not a folder"
  if compgen -G "/var/lib/panel-agent/sites/*.json" >/dev/null; then
    die "this server has sites already: a server backup is restored onto a new, empty server"
  fi
  key="${WSPANEL_RECOVERY_KEY:-}"
  if [[ -z "$key" ]]; then
    read -r -s -p "Klíč pro obnovu (Zálohy mimo server → Klíč pro obnovu): " key </dev/tty || true
    echo
  fi
  [[ "$key" =~ ^[0-9a-fA-F]{64}$ ]] || die "the recovery key is 64 hexadecimal digits"
  export WSPANEL_RECOVERY_KEY="$key"
  info="$(WSPANEL_MANIFEST_FORMAT=shell "$AGENT" -server-backup-manifest "$file")" || die "the backup does not open with this key"
  version="$(sed -n 's/^VERSION=//p' <<<"$info")"
  mapfile -t flags < <(sed -n 's/^FLAG=//p' <<<"$info")
  printf '\033[1;34m==>\033[0m Server backup of %s (%s), WSPanel %s: %s sites, %s mail domains, %s databases\n' \
    "$(sed -n 's/^HOST=//p' <<<"$info")" "$(sed -n 's/^CREATED=//p' <<<"$info")" "${version:-?}" \
    "$(sed -n 's/^SITES=//p' <<<"$info")" "$(sed -n 's/^MAIL=//p' <<<"$info")" "$(sed -n 's/^DATABASES=//p' <<<"$info")"
  if [[ -n "$version" && "$THIS_VERSION" != dev && "$(printf '%s\n%s\n' "$version" "$THIS_VERSION" | sort -V | tail -1)" != "$THIS_VERSION" ]]; then
    die "the backup is of WSPanel $version, newer than this installer ($THIS_VERSION): restore with $version or newer"
  fi
  ((${#flags[@]})) || die "the backup holds no installation choices"
  if ((! YES)); then
    read -r -p "Install WSPanel with the backup's choices and restore it here? [y/N] " a </dev/tty || a=""
    [[ $a == [yYaA]* ]] || { echo "Zrušeno."; exit 1; }
  fi

  printf '\033[1;34m==>\033[0m 1/3 Installing with the choices of the old server\n'
  WSPANEL_ROLLBACK=1 run_install "${flags[@]}"
  # What this installation knows better than the backup's .env.
  newenv="$(grep -E '^(PANEL_VERSION|PANEL_INSTALL_METHOD|PANEL_RELEASE_REPO)=' /srv/panel/shared/.env || true)"

  printf '\033[1;34m==>\033[0m 2/3 The server backup: accounts, configuration, the panel, DNS\n'
  systemctl stop panel-scheduler panel-queue panel-web panel-agent
  /usr/sbin/panel-agent -server-restore "$file" || die "restoring the server backup failed (see above)"
  while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    k="${line%%=*}"
    if grep -q "^$k=" /srv/panel/shared/.env; then sed -i "s|^$k=.*|$line|" /srv/panel/shared/.env; else echo "$line" >> /srv/panel/shared/.env; fi
  done <<<"$newenv"
  (cd /srv/panel/current && runuser -u panel -- php artisan migrate --force && runuser -u panel -- php artisan optimize) >/dev/null

  printf '\033[1;34m==>\033[0m 3/3 Sites, databases and mail from %s\n' "${BACKUPS:-the off-site storage}"
  local data=(-server-restore-data "$file")
  [[ -n "$BACKUPS" ]] && data+=(-backups "$BACKUPS")
  /usr/sbin/panel-agent "${data[@]}" | tee /var/log/wspanel-restore.log
  for s in postfix dovecot rspamd opendkim pdns; do
    systemctl is-enabled --quiet "$s" 2>/dev/null && systemctl restart "$s" || true
  done
  systemctl list-unit-files 'wspanel-*' --state=enabled --no-legend | awk '{print $1}' | xargs -r systemctl start || true
  systemctl restart panel-proxy
  systemctl start panel-agent panel-web panel-scheduler panel-queue
  (cd /srv/panel/current && runuser -u panel -- php artisan panel:sync) >/dev/null 2>&1 || true
  url="$(grep '^APP_URL=' /srv/panel/shared/.env | cut -d= -f2-)"
  printf '\nRestored. Panel: %s (the accounts of the old server)\n' "$url"
  grep -q '^PROBLEM' /var/log/wspanel-restore.log && printf 'Some parts did not come back: see /var/log/wspanel-restore.log\n'
  exit 0
}
[[ -n "$RESTORE" ]] && restore_server "$RESTORE"

if ((YES)); then
  if ((${#PASS[@]} == 0)) && [[ -s /etc/wspanel/install.flags ]]; then
    mapfile -t PASS < /etc/wspanel/install.flags # "./install --yes" upgrades as installed
  fi
  run_install "${PASS[@]}"
  if [[ -n "$ADMIN_EMAIL" && "$(user_count)" == 0 ]]; then
    pw="$(create_admin "$ADMIN_EMAIL" "$ADMIN_NAME")"
    printf '\nPanel account: %s\nPassword:      %s   (change it in the profile)\n' "$ADMIN_EMAIL" "$pw"
  fi
  exit 0
fi

command -v whiptail >/dev/null || { apt-get install -y -q whiptail >/dev/null; }

# An installed server: upgrade with the choices it was installed with, or
# go through the questions again.
if [[ -s /etc/wspanel/install.flags && "$(user_count)" != 0 ]]; then
  mapfile -t saved < /etc/wspanel/install.flags
  choice=$(whiptail --title "$TITLE" --menu "WSPanel je na tomto serveru už nainstalovaný.\n\nUčty, weby a data zůstanou v obou případech." 15 76 2 \
    keep   "Aktualizovat se stávajícím nastavením" \
    change "Projít průvodce a nastavení změnit" 3>&1 1>&2 2>&3) || { echo "Zrušeno."; exit 1; }
  if [[ $choice == keep ]]; then
    clear 2>/dev/null || true
    run_install "${saved[@]}" "${PASS[@]}"
    url="$(grep '^APP_URL=' /srv/panel/shared/.env | cut -d= -f2-)"
    printf '\nPanel: %s (updated)\n' "$url"
    exit 0
  fi
fi
W() { whiptail --title "$TITLE" "$@" 3>&1 1>&2 2>&3; }
ask() { W --inputbox "$1" 12 72 "${2:-}"; }
# Asks again until the answer matches (a regex), or the dialog is cancelled.
ask_valid() { # prompt default regex error
  local v
  while true; do
    v=$(ask "$1" "$2") || { echo "Zrušeno." >&2; exit 1; }
    [[ "$v" =~ $3 ]] && { echo "$v"; return; }
    W --msgbox "$4" 8 60 || true
  done
}
DOMAIN_RE='^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'
EMAIL_RE='^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
yesno() { whiptail --title "$TITLE" --yesno "$1" 12 72; }

W --msgbox "Hostingový panel pro tento server (Debian 13).\n\nPrůvodce se zeptá na adresu panelu, síť, SSH, co nainstalovat a na prvního správce. Na konci ukáže souhrn a teprve potom začne instalovat (trvá 5–15 minut).\n\nModuly jde doinstalovat i později (Server → Moduly)." 15 72

flags=()
said=()   # the summary, in words
say() { said+=("$1"); }
INTERNAL_RE='\.(lan|local|internal|intranet|home\.arpa|corp|test|localhost)$'

# ---------------------------------------------------------------- address
mode=$(W --menu "Kde bude panel?" 16 76 4 \
  domain "Na doméně (např. panel.example.com), certifikát Let's Encrypt" \
  port   "Na IP adrese serveru a portu (bez domény)" \
  both   "Na doméně a vlastním portu (např. example.com:8443)" \
  lan    "Ve vnitřní síti bez internetu (např. panel.firma.lan)") || { echo "Zrušeno."; exit 1; }
if [[ $mode == domain || $mode == both || $mode == lan ]]; then
  base="$(hostname -d 2>/dev/null)"; [[ -n "$base" ]] || base="example.com"
  [[ $mode == lan ]] && base="firma.lan"
  domain=$(ask_valid "Doména panelu (DNS záznam A musí ukazovat na tento server):" "panel.$base" "$DOMAIN_RE" "Zadejte doménu, např. panel.example.com.")
  flags+=("--panel-domain=$domain")
  if [[ $mode == lan || $domain =~ $INTERNAL_RE ]]; then
    W --msgbox "Doména $domain je jen ve vnitřní síti: Let's Encrypt pro ni certifikát nevydá.\n\nPanel i weby dostanou certifikáty od vlastní certifikační autority serveru. Počítače, které panel a weby otevírají, si jednou nainstalují její kořenový certifikát (v panelu SSL → Kořenový certifikát)." 14 72 || true
    flags+=("--local-certs")
    say "Panel: https://$domain (vlastní certifikační autorita)"
  else
    email=$(ask_valid "E-mail pro Let's Encrypt (upozornění na certifikáty):" "admin@${domain#*.}" "$EMAIL_RE" "Zadejte platný e-mail.")
    flags+=("--acme-email=$email")
    say "Panel: https://$domain (Let's Encrypt, $email)"
  fi
fi
if [[ $mode == port || $mode == both ]]; then
  port=$(ask_valid "Port panelu:" "8443" '^[0-9]{2,5}$' "Port je číslo 10–65535.")
  flags+=("--panel-port=$port")
  if [[ $mode == port ]]; then
    say "Panel: https://<IP adresa serveru>:$port (vlastní certifikát)"
  else
    said[-1]="${said[-1]/https:\/\/$domain/https://$domain:$port}"
  fi
fi

# ---------------------------------------------------------------- network
# Not every minimal system has ip(8) or sshd yet: a guess, never a failure.
ip4="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' || true)"
nat_default=--defaultno
[[ "$ip4" =~ ^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.) ]] && nat_default=""
if [[ $mode != lan ]] && whiptail --title "$TITLE" $nat_default --yesno "Je server za NATem (veřejná adresa je jiná než ${ip4:-adresa serveru} a porty se na server přesměrovávají)?\n\nPanel podle toho doporučí DNS záznamy a ukáže správné adresy pro připojení." 13 72; then
  pub4=$(ask_valid "Veřejná IPv4 adresa (na ni budou mířit DNS záznamy):" "" '^([0-9]{1,3}\.){3}[0-9]{1,3}$' "Zadejte IPv4 adresu, např. 203.0.113.7.")
  flags+=("--public-ipv4=$pub4")
  pub_https=$(ask_valid "Veřejný port HTTPS (přesměrovaný na 443 serveru):" "443" '^[0-9]{2,5}$' "Port je číslo 10–65535.")
  [[ $pub_https != 443 ]] && flags+=("--public-https-port=$pub_https")
  pub_ssh=$(ask_valid "Veřejný port SSH (přesměrovaný na SSH serveru):" "22" '^[0-9]{2,5}$' "Port je číslo 10–65535.")
  say "Za NATem: veřejná adresa $pub4, HTTPS $pub_https, SSH $pub_ssh"
fi

# The panel's domain must point here before Let's Encrypt issues its
# certificate: what to set, said now, not after the installation.
if [[ ${domain:-} && $mode != lan && ! $domain =~ $INTERNAL_RE ]]; then
  want4="${pub4:-$ip4}"
  ip6="$(ip -6 route get 2606:4700::1111 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' || true)"
  [[ $ip6 =~ ^(fe80|fc|fd) ]] && ip6=""
  have4="$(getent ahostsv4 "$domain" 2>/dev/null | awk '{print $1}' | sort -u | paste -sd ' ' -)"
  have6="$(getent ahostsv6 "$domain" 2>/dev/null | awk '$1 !~ /^::ffff:/ {print $1}' | sort -u | paste -sd ' ' -)"
  records="  A     $domain  →  $want4   $([[ " $have4 " == *" $want4 "* ]] && echo '✓ nastaveno' || echo "✗ teď: ${have4:-žádný}")"
  [[ -n $ip6 ]] && records+="\n  AAAA  $domain  →  $ip6   $([[ " $have6 " == *" $ip6 "* ]] && echo '✓ nastaveno' || echo "✗ teď: ${have6:-žádný}")"
  if [[ -n $want4 && " $have4 " != *" $want4 "* ]]; then
    W --msgbox "Doména $domain zatím neukazuje na tento server.\n\nU registrátora nebo poskytovatele DNS nastavte:\n\n$records\n\nInstalace proběhne i tak; certifikát Let's Encrypt se vydá, jakmile záznam začne platit (zkouší se znovu). Záznamy se vypíšou i na konci instalace." 18 76 || true
  fi
  say "DNS: $(printf '%b' "$records" | sed 's/^ *//' | paste -sd ';' - | sed 's/;/; /g')"
fi

# ---------------------------------------------------------------- SSH
cur_ssh="$(sshd -T 2>/dev/null | awk '$1 == "port" {print $2; exit}' || true)"; [[ -n "$cur_ssh" ]] || cur_ssh=22
ssh_port=$(ask_valid "Port SSH serveru (správa serveru i SFTP webů):" "$cur_ssh" '^[0-9]{2,5}$' "Port je číslo 10–65535.")
[[ $ssh_port != 22 ]] && flags+=("--ssh-port=$ssh_port")
[[ -n "${pub_ssh:-}" ]] && flags+=("--public-ssh-port=$pub_ssh") || { [[ $ssh_port != 22 ]] && flags+=("--public-ssh-port=$ssh_port"); }
# Keys only for administrators, never a lock-out: who can get in with a key.
SSH_KEY_RE='^(ssh-(ed25519|rsa)|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com) [A-Za-z0-9+/]+=*( [^[:cntrl:]]*)?$'
key_of() { local h; h="$(getent passwd "$1" | cut -d: -f6)"; [[ -n "$h" && -f "$h/.ssh/authorized_keys" ]] && grep -Eq "$SSH_KEY_RE" "$h/.ssh/authorized_keys"; }
sudo_key=""
for u in $(getent group sudo | cut -d: -f4 | tr ',' ' '); do key_of "$u" && sudo_key="$u" && break; done
root_ssh=""
have_key=0; { key_of root || [[ -n "$sudo_key" ]]; } && have_key=1
auth=$(W --default-item heslo --menu "Jak se správci (root) přihlašují přes SSH?" 13 76 2 \
  heslo "Heslem i klíčem (heslo hlídá fail2ban)" \
  klic  "Jen SSH klíčem (bezpečnější)") || auth=heslo
if [[ $auth == heslo ]]; then
  flags+=("--ssh-password=yes"); root_ssh="správci heslem i klíčem"
fi
if ((! have_key)); then
  # Keys only needs a key; with the password, one is welcome but optional.
  while :; do
    if [[ $auth == klic ]]; then
      msg="Jen klíčem: root zatím žádný klíč nemá, bez něj by se na server nedalo přihlásit.\n\nVložte veřejný klíč pro root (ssh-ed25519 AAAA… z ~/.ssh/id_ed25519.pub, z Termiusu apod.)."
    else
      msg="Volitelně veřejný SSH klíč pro root (ssh-ed25519 AAAA…), přihlášení heslem zůstane.\n\nPrázdné = jen heslo."
    fi
    key=$(W --inputbox "$msg" 14 76 "") || key=""
    key="$(printf '%s' "$key" | tr -d '\r' | sed 's/^ *//; s/ *$//')"
    if [[ -z "$key" && $auth == heslo ]]; then
      break
    elif [[ -n "$key" && "$key" =~ $SSH_KEY_RE ]]; then
      flags+=("--ssh-key=$key"); root_ssh+=" (klíč ${key%% *} vložený)"
      break
    elif [[ -z "$key" ]]; then
      W --msgbox "Bez klíče nejde zvolit přihlášení jen klíčem: vložte klíč, nebo se vraťte a zvolte heslo i klíč." 9 72 || true
      auth=$(W --default-item heslo --menu "Jak se správci (root) přihlašují přes SSH?" 13 76 2 \
        heslo "Heslem i klíčem (heslo hlídá fail2ban)" \
        klic  "Jen SSH klíčem (bezpečnější)") || auth=heslo
      if [[ $auth == heslo ]]; then flags+=("--ssh-password=yes"); root_ssh="správci heslem i klíčem"; fi
    else
      W --msgbox "To není veřejný SSH klíč. Začíná ssh-ed25519, ssh-rsa nebo ecdsa-sha2-… a má jeden řádek." 9 72 || true
    fi
  done
fi
[[ $auth == klic ]] && root_ssh="správci jen klíčem${root_ssh#správci heslem i klíčem}"
if [[ -n "$sudo_key" ]] && whiptail --title "$TITLE" --defaultno --yesno "Zakázat přihlášení roota přes SSH úplně?\n\nSprávce $sudo_key má SSH klíč a sudo: přihlášení zůstane přes něj." 11 72; then
  flags+=("--no-root-ssh"); root_ssh+=", root přes SSH zakázaný (správa přes $sudo_key)"
fi
say "SSH: port $ssh_port, $root_ssh"

# ---------------------------------------------------------------- software
db=$(W --separate-output --checklist "Databázové servery (další jdou doinstalovat z panelu):" 12 72 2 \
  mariadb "MariaDB (MySQL)" ON \
  postgres "PostgreSQL" OFF | paste -sd, -)
flags+=("--db=${db:-none}")
php=$(W --separate-output --checklist "Verze PHP pro weby:" 14 72 4 \
  8.2 "PHP 8.2" OFF  8.3 "PHP 8.3" ON  8.4 "PHP 8.4" ON  8.5 "PHP 8.5" OFF | paste -sd, -)
flags+=("--php=${php:-8.4}")
node=$(W --separate-output --checklist "Node.js pro SSR weby (Nuxt, Next):" 12 72 3 \
  22 "Node 22" ON  24 "Node 24" ON | paste -sd, -)
flags+=("--node=${node:-none}")
say "Databáze: ${db:-žádná}; PHP ${php:-8.4}; Node ${node:-žádný}"

mods=$(W --separate-output --checklist "Moduly (mezerníkem vyberete):" 19 76 6 \
  clients "Klienti a balíčky (hosting pro víc klientů, limity, kvóty)" OFF \
  games   "Herní servery (Minecraft, Factorio)" OFF \
  mail    "Pošta: schránky, webmail, antispam (porty 25, 465, 587, 993…)" OFF \
  dns     "DNS server (PowerDNS) pro zóny z panelu" OFF \
  xhprof  "Profilování PHP (XHProf + XHGui)" OFF)
names=()
grep -qx clients <<<"$mods" && { flags+=("--clients=yes"); names+=("klienti"); }
grep -qx games <<<"$mods" && { flags+=("--games=yes"); names+=("herní servery"); }
grep -qx mail <<<"$mods" && { flags+=("--mail-hosting=yes"); names+=("pošta"); }
grep -qx dns <<<"$mods" && { flags+=("--dns=powerdns"); names+=("DNS server"); }
grep -qx xhprof <<<"$mods" && { flags+=("--xhprof=yes"); names+=("profilování"); }
say "Moduly: $( ((${#names[@]})) && (IFS=,; echo "${names[*]}" | sed 's/,/, /g') || echo žádné)"

mail=$(W --menu "Pošta z webů ve výchozím stavu:" 12 72 2 \
  send    "Odesílat (Postfix, DKIM)" \
  capture "Zachytávat v Mailpitu (vývojový server)") || mail=send
flags+=("--mail=$mail")
[[ $mail == send ]] && say "Pošta z webů: odesílá se" || say "Pošta z webů: zachytává se v Mailpitu"

if yesno "Omezit přístup k panelu jen na vybrané adresy (VPN, kancelář)?\n\nWeby zůstanou veřejné."; then
  allow=$(ask_valid "Adresy nebo rozsahy oddělené čárkou (např. 10.8.0.0/24,203.0.113.7):" "" '^[0-9a-fA-F:.,/]+$' "Jen IP adresy a rozsahy oddělené čárkou.")
  flags+=("--panel-allow=$allow")
  say "Panel jen z: $allow"
fi

# ---------------------------------------------------------------- admin
EXISTING_USERS="$(user_count)"
if [[ "$EXISTING_USERS" != 0 ]]; then
  say "Správci: zůstávají stávající účty ($EXISTING_USERS), tohle je aktualizace"
else
ADMIN_NAME=$(ask_valid "Jméno prvního správce panelu:" "$ADMIN_NAME" '^.{1,100}$' "Zadejte jméno.")
ADMIN_EMAIL=$(ask_valid "E-mail prvního správce (přihlašuje se jím):" "${email:-admin@example.com}" "$EMAIL_RE" "Zadejte platný e-mail.")
ADMIN_PASSWORD=""
while true; do
  p1=$(W --passwordbox "Heslo správce (aspoň 12 znaků).\n\nPrázdné = vygeneruje se a ukáže na konci." 12 72 "") || { echo "Zrušeno."; exit 1; }
  [[ -z "$p1" ]] && break
  if (( ${#p1} < 12 )); then W --msgbox "Heslo má mít aspoň 12 znaků." 8 60 || true; continue; fi
  p2=$(W --passwordbox "Heslo znovu pro kontrolu:" 10 72 "") || { echo "Zrušeno."; exit 1; }
  [[ "$p1" == "$p2" ]] && { ADMIN_PASSWORD="$p1"; break; }
  W --msgbox "Hesla se neshodují." 8 60 || true
done
say "Správce: $ADMIN_NAME <$ADMIN_EMAIL>, heslo $([[ -n $ADMIN_PASSWORD ]] && echo zadané || echo "se vygeneruje")"
fi

summary="Nainstaluje se:\n\n$(printf '  • %s\n' "${said[@]}")\n\nPokračovat?"
yesno "$summary" || { echo "Nic se neinstalovalo."; exit 1; }

clear 2>/dev/null || true
run_install "${flags[@]}" "${PASS[@]}"
url="$(grep '^APP_URL=' /srv/panel/shared/.env | cut -d= -f2-)"
if [[ "$EXISTING_USERS" != 0 ]]; then
  W --msgbox "Hotovo, server je aktualizovaný.\n\nPanel: $url\n\nÚčty, weby a nastavení zůstaly." 12 72 || true
  printf '\nPanel: %s (updated)\n' "$url"
  exit 0
fi
pw="$(create_admin "$ADMIN_EMAIL" "$ADMIN_NAME" "$ADMIN_PASSWORD")"
if [[ -n "$ADMIN_PASSWORD" ]]; then
  W --msgbox "Hotovo.\n\nPanel:   $url\nÚčet:    $ADMIN_EMAIL\nHeslo:   zadané v průvodci\n\nPo přihlášení zapněte dvoufázové přihlášení (Profil)." 14 72 || true
  printf '\nPanel: %s\nAccount: %s\n' "$url" "$ADMIN_EMAIL"
else
  W --msgbox "Hotovo.\n\nPanel:   $url\nÚčet:    $ADMIN_EMAIL\nHeslo:   $pw\n\nHeslo si poznamenejte, zobrazí se jen teď (změníte ho v profilu)." 15 72 || true
  printf '\nPanel: %s\nAccount: %s\nPassword: %s\n' "$url" "$ADMIN_EMAIL" "$pw"
fi
