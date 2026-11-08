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
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARCH="$(dpkg --print-architecture 2>/dev/null || echo amd64)"
AGENT="$HERE/bin/panel-agent-linux-$ARCH"
TITLE="WSPanel $(cat "$HERE/VERSION" 2>/dev/null || echo dev)"

die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "run as root (sudo ./install)"
[[ -x "$AGENT" ]] || die "this bundle has no agent for $ARCH ($AGENT)"
. /etc/os-release
[[ "${ID:-}" == debian && "${VERSION_CODENAME:-}" == trixie ]] || die "Debian 13 (trixie) required, found ${PRETTY_NAME:-unknown}"

YES=0
ADMIN_EMAIL=""
ADMIN_NAME="Administrator"
PASS=()
for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --admin-email=*) ADMIN_EMAIL="${arg#*=}" ;;
    --admin-name=*) ADMIN_NAME="${arg#*=}" ;;
    -h|--help) sed -n '2,14p' "$0"; exit 0 ;;
    *) PASS+=("$arg") ;;
  esac
done

run_install() {
  "$HERE/provision/install.sh" --agent-bin="$AGENT" --panel-source="$HERE/panel" "$@"
}

create_admin() { # email name -> prints the generated password
  local email=$1 name=$2 pw
  pw="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)"
  (cd /srv/panel/current && runuser -u panel -- php artisan wire:user --no-interaction --super-admin \
    --name="$name" --email="$email" --password="$pw" >/dev/null)
  echo "$pw"
}

if ((YES)); then
  run_install "${PASS[@]}"
  if [[ -n "$ADMIN_EMAIL" ]]; then
    pw="$(create_admin "$ADMIN_EMAIL" "$ADMIN_NAME")"
    printf '\nPanel account: %s\nPassword:      %s   (change it in the profile)\n' "$ADMIN_EMAIL" "$pw"
  fi
  exit 0
fi

command -v whiptail >/dev/null || { apt-get install -y -q whiptail >/dev/null; }
W() { whiptail --title "$TITLE" "$@" 3>&1 1>&2 2>&3; }
ask() { W --inputbox "$1" 12 72 "${2:-}"; }
yesno() { whiptail --title "$TITLE" --yesno "$1" 12 72; }

W --msgbox "Hostingový panel pro tento server (Debian 13).\n\nPrůvodce se zeptá na adresu panelu a na to, co nainstalovat. Na konci ukáže souhrn a teprve potom začne instalovat (trvá 5–15 minut).\n\nVše jde doinstalovat i později (Server → Moduly)." 15 72

flags=()
# Address of the panel.
mode=$(W --menu "Kde bude panel?" 15 72 3 \
  domain "Na doméně (např. panel.example.com), certifikát Let's Encrypt" \
  port   "Na IP adrese serveru a portu (bez domény)" \
  both   "Na doméně a vlastním portu (např. example.com:8443)")
if [[ $mode == domain || $mode == both ]]; then
  domain=$(ask "Doména panelu (DNS záznam A musí ukazovat na tento server):" "panel.$(hostname -d 2>/dev/null || echo example.com)")
  flags+=("--panel-domain=$domain")
  email=$(ask "E-mail pro Let's Encrypt (upozornění na certifikáty):" "admin@${domain#*.}")
  flags+=("--acme-email=$email")
fi
if [[ $mode == port || $mode == both ]]; then
  port=$(ask "Port panelu:" "8443")
  flags+=("--panel-port=$port")
fi

# Databases and runtimes.
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

# Modules.
mods=$(W --separate-output --checklist "Moduly (mezerníkem vyberete):" 18 76 5 \
  clients "Klienti a balíčky (hosting pro víc klientů, limity, kvóty)" OFF \
  mail    "Pošta: schránky, webmail, antispam (porty 25, 465, 587, 993…)" OFF \
  dns     "DNS server (PowerDNS) pro zóny z panelu" OFF \
  xhprof  "Profilování PHP (XHProf + XHGui)" OFF)
grep -qx clients <<<"$mods" && flags+=("--clients=yes")
grep -qx mail <<<"$mods" && flags+=("--mail-hosting=yes")
grep -qx dns <<<"$mods" && flags+=("--dns=powerdns")
grep -qx xhprof <<<"$mods" && flags+=("--xhprof=yes")

mail=$(W --menu "Pošta z webů ve výchozím stavu:" 12 72 2 \
  send    "Odesílat (Postfix, DKIM)" \
  capture "Zachytávat v Mailpitu (vývojový server)")
flags+=("--mail=$mail")

if yesno "Omezit přístup k panelu jen na vybrané adresy (VPN, kancelář)?\n\nWeby zůstanou veřejné."; then
  allow=$(ask "Adresy nebo rozsahy oddělené čárkou (např. 10.8.0.0/24,203.0.113.7):" "")
  [[ -n "$allow" ]] && flags+=("--panel-allow=$allow")
fi

ADMIN_EMAIL=$(ask "E-mail prvního správce panelu (heslo se vygeneruje):" "${email:-admin@example.com}")

summary="Nainstaluje se:\n\n$(printf '  %s\n' "${flags[@]}" "${PASS[@]}")\n\nSprávce: $ADMIN_EMAIL\n\nPokračovat?"
yesno "$summary" || { echo "Nic se neinstalovalo."; exit 1; }

clear
run_install "${flags[@]}" "${PASS[@]}"
pw="$(create_admin "$ADMIN_EMAIL" "$ADMIN_NAME")"
url="$(grep '^APP_URL=' /srv/panel/shared/.env | cut -d= -f2-)"
W --msgbox "Hotovo.\n\nPanel:   $url\nÚčet:    $ADMIN_EMAIL\nHeslo:   $pw\n\nHeslo si poznamenejte, zobrazí se jen teď (změníte ho v profilu)." 15 72
printf '\nPanel: %s\nAccount: %s\nPassword: %s\n' "$url" "$ADMIN_EMAIL" "$pw"
