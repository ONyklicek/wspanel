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

# ---------------------------------------------------------------- SSH
cur_ssh="$(sshd -T 2>/dev/null | awk '$1 == "port" {print $2; exit}' || true)"; [[ -n "$cur_ssh" ]] || cur_ssh=22
ssh_port=$(ask_valid "Port SSH serveru (správa serveru i SFTP webů):" "$cur_ssh" '^[0-9]{2,5}$' "Port je číslo 10–65535.")
[[ $ssh_port != 22 ]] && flags+=("--ssh-port=$ssh_port")
[[ -n "${pub_ssh:-}" ]] && flags+=("--public-ssh-port=$pub_ssh") || { [[ $ssh_port != 22 ]] && flags+=("--public-ssh-port=$ssh_port"); }
root_ssh="root přes SSH jen klíčem"
if whiptail --title "$TITLE" --defaultno --yesno "Zakázat přihlášení roota přes SSH úplně?\n\nZapněte, jen když se na server přihlašujete jiným účtem s SSH klíčem a sudo. Jinak root zůstane, ale jen s klíčem (heslem ne)." 12 72; then
  flags+=("--no-root-ssh"); root_ssh="root přes SSH zakázaný"
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

mods=$(W --separate-output --checklist "Moduly (mezerníkem vyberete):" 18 76 5 \
  clients "Klienti a balíčky (hosting pro víc klientů, limity, kvóty)" OFF \
  mail    "Pošta: schránky, webmail, antispam (porty 25, 465, 587, 993…)" OFF \
  dns     "DNS server (PowerDNS) pro zóny z panelu" OFF \
  xhprof  "Profilování PHP (XHProf + XHGui)" OFF)
names=()
grep -qx clients <<<"$mods" && { flags+=("--clients=yes"); names+=("klienti"); }
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
