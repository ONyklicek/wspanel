#!/usr/bin/env bash
# Installs a release bundle on a clean Debian 13 (a systemd container) the way
# a server would, then checks that it works and that an upgrade keeps it so.
# Used by the release workflow before anything is published; runs locally too:
#
#   dev/release-smoke.sh dist/wspanel-1.2.0-linux-amd64.tar.gz
#   dev/release-smoke.sh --apt <dir with wspanel_*.deb and wspanel-agent_*.deb>
#
# --apt installs the way the README's "Instalace přes apt" does: from an apt
# repository built in the container out of those packages (signed with a
# throwaway key, so with a wspanel-archive-keyring package of that key),
# then upgrades with wspanel-update.
#
# Needs Docker with privileged containers (systemd as PID 1, cgroup v2).
set -euo pipefail
MODE=bundle
if [[ "${1:-}" == --apt ]]; then MODE=apt; shift; fi
BUNDLE=${1:?bundle .tar.gz or (with --apt) a directory of .deb files}
NAME=${SMOKE_CONTAINER:-wspanel-smoke}
IMAGE=wspanel-smoke-base
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mFAIL:\033[0m %s\n' "$*" >&2; docker exec "$NAME" bash -c 'tail -60 /root/install.log 2>/dev/null; systemctl --failed --no-legend' >&2 || true; exit 1; }
on() { docker exec "$NAME" bash -c "$1"; }
tinker() { on "cd /srv/panel/current && runuser -u panel -- php artisan tinker --execute '$1' 2>&1 | tail -1"; }

log "Clean Debian 13"
docker build -q -t "$IMAGE" -f "$HERE/Dockerfile" "$HERE" >/dev/null
docker rm -f "$NAME" >/dev/null 2>&1 || true
trap '[[ -n "${SMOKE_KEEP:-}" ]] || docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT
docker run -d --name "$NAME" --hostname "$NAME" --privileged --cgroupns=host \
  -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock --tmpfs /tmp "$IMAGE" >/dev/null
for _ in $(seq 1 30); do on 'systemctl is-system-running 2>/dev/null | grep -qE "running|degraded"' && break; sleep 1; done
FLAGS='--yes --panel-domain=panel.localhost --local-certs --db=mariadb,postgres --php=8.3,8.4 --mail=capture --dns=powerdns --clients=yes --mail-hosting=yes --xhprof=yes --allow-unenforced-socket-bind --admin-email=admin@smoke.test'
if [[ $MODE == bundle ]]; then
  docker cp "$BUNDLE" "$NAME:/root/bundle.tar.gz"
  on 'cd /root && tar -xzf bundle.tar.gz' 2>&1 | grep -q . && fail "the bundle does not unpack cleanly (warnings above)"
  log "Install (every module), unattended"
  on "cd /root/wspanel && ./install $FLAGS > /root/install.log 2>&1" || fail "install failed"
else
  log "An apt repository of these packages (throwaway key) on 127.0.0.1:8099"
  docker exec "$NAME" mkdir -p /root/debs /root/tools/provision
  docker cp "$BUNDLE/." "$NAME:/root/debs/"
  for f in apt-repo.sh build-keyring-deb.sh deb-common.sh; do docker cp "$HERE/../provision/$f" "$NAME:/root/tools/provision/$f"; done
  docker cp "$HERE/../LICENSE" "$NAME:/root/tools/LICENSE"
  on 'apt-get update -q >/dev/null && apt-get install -y -q --no-install-recommends gnupg apt-utils dpkg-dev python3 >/dev/null' || fail "tools"
  # The release's keyring package carries the real key: this one the test key.
  on 'export GNUPGHOME=/root/gnupg; mkdir -m 700 -p $GNUPGHOME
      gpg --batch --quiet --passphrase "" --quick-generate-key "smoke <smoke@test>" ed25519 sign never
      gpg --armor --export > /root/test-key.asc
      rm -f /root/debs/wspanel-archive-keyring_*.deb
      v=$(dpkg-deb -f /root/debs/wspanel_*_all.deb Version)
      /root/tools/provision/build-keyring-deb.sh "${v/\~/-}" ONyklicek/wspanel /root/test-key.asc /root/debs >/dev/null
      /root/tools/provision/apt-repo.sh /srv/apt-test/apt /root/test-key.asc /root/debs/*.deb >/dev/null' || fail "building the repository"
  on 'cd /srv/apt-test && setsid nohup python3 -m http.server 8099 --bind 127.0.0.1 >/dev/null 2>&1 < /dev/null &'
  sleep 1
  channel=$(ls "$BUNDLE"/wspanel_*.deb | head -1 | grep -q "~" && echo pre || echo stable)
  log "Install through apt (channel $channel), unattended"
  # Word for word the README, but this repository's address.
  on "cd /root && curl -fsSLO http://127.0.0.1:8099/apt/wspanel-archive-keyring.deb
      WSPANEL_APT_URI=http://127.0.0.1:8099/apt WSPANEL_CHANNEL=$channel apt-get install -y -q ./wspanel-archive-keyring.deb > /root/apt.log 2>&1
      apt-get update -q >> /root/apt.log 2>&1 && apt-get install -y -q wspanel >> /root/apt.log 2>&1" || fail "apt install wspanel (see /root/apt.log)"
  on "WSPANEL_APT_URI=http://127.0.0.1:8099/apt wspanel-install $FLAGS > /root/install.log 2>&1" || fail "wspanel-install failed"
  log "The server follows its line's channel"
  line=$(on "dpkg-query -W -f='\${Version}' wspanel" | sed -E 's/^([0-9]+\.[0-9]+).*/\1/')
  [[ $channel == pre ]] && line="$line-pre"
  on "grep -qx 'Suites: $line' /etc/apt/sources.list.d/wspanel.sources" || fail "apt does not follow the channel $line"
  on 'grep -q "^PANEL_INSTALL_METHOD=apt" /srv/panel/shared/.env' || fail "the panel does not know it was installed through apt"
fi
on 'grep -q "^Password:" /root/install.log' || fail "no admin account was created"

log "Services"
for unit in panel-agent panel-proxy panel-web panel-scheduler php8.3-fpm php8.4-fpm mariadb postgresql postfix opendkim dovecot rspamd pdns fail2ban; do
  on "systemctl is-active --quiet $unit" || fail "$unit is not running"
done
[[ "$(on 'systemctl --failed --no-legend | wc -l')" == 0 ]] || fail "failed units"

if [[ $MODE == apt ]] || tar -tzf "$BUNDLE" | grep -q '/bin/wspanel-agent_.*\.deb$'; then
  log "The agent as a package, apt set up"
  on 'dpkg-query -W -f="\${Status}" wspanel-agent | grep -q "install ok installed"' || fail "wspanel-agent is not installed as a package"
  [[ "$(on 'readlink /usr/local/sbin/panel-agent')" == /usr/sbin/panel-agent ]] || fail "the old agent path does not lead to the package's"
  on 'dpkg-query -W -f="\${Status}" wspanel-archive-keyring | grep -q "install ok installed"' || fail "wspanel-archive-keyring is not installed"
  on 'grep -qx "Signed-By: /usr/share/keyrings/wspanel-archive-keyring.asc" /etc/apt/sources.list.d/wspanel.sources' || fail "no WSPanel apt source with the packaged key"
  on 'test -s /etc/apt/apt.conf.d/52wspanel-unattended' || fail "no unattended upgrades of WSPanel"
  on 'dpkg-query -W -f="\${Status}" wspanel-frankenphp | grep -q "install ok installed"' || fail "wspanel-frankenphp is not installed"
  [[ "$(on 'readlink /usr/local/bin/frankenphp')" == /usr/lib/wspanel/frankenphp ]] || fail "frankenphp is not the package's"
  on 'test -f /usr/lib/systemd/system/panel-agent.service -a ! -e /etc/systemd/system/panel-agent.service' || fail "the agent unit is not the package's"
fi

log "Panel"
code=$(on 'curl -sk -o /dev/null -w "%{http_code}" --resolve panel.localhost:443:127.0.0.1 https://panel.localhost/login')
[[ $code == 200 ]] || fail "login page answered $code"

log "A Laravel site with its workers"
out=$(tinker '$s = app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "app.localhost", "mode" => "classic", "framework" => "laravel", "php_version" => "8.4", "tls" => "internal"]); foreach (["queue", "scheduler"] as $w) { app(App\Actions\Sites\ManageWorkers::class)->create($s, $w); } echo $s->status->value;')
[[ $out == active ]] || fail "site: $out"
code=$(on 'curl -sk -o /dev/null -w "%{http_code}" --resolve app.localhost:443:127.0.0.1 https://app.localhost/')
[[ $code == 200 ]] || fail "the site answered $code"

log "Mail: a mailbox receives"
out=$(tinker '$h = app(App\Actions\Mail\ManageMailHosting::class); $d = $h->addDomain("app.localhost"); echo $h->saveMailbox($d, "info", "Smoke-heslo-123", 100, true)->address();')
[[ $out == info@app.localhost ]] || fail "mailbox: $out"
on 'doveadm auth test info@app.localhost Smoke-heslo-123 >/dev/null' || fail "mailbox login"
on 'printf "Subject: smoke\n\nok\n" | sendmail info@app.localhost'
for _ in $(seq 1 20); do on 'doveadm mailbox status -u info@app.localhost messages INBOX | grep -q "messages=1"' && break; sleep 1; done
on 'doveadm mailbox status -u info@app.localhost messages INBOX | grep -q "messages=1"' || fail "the message was not delivered"

# The bundle's own apt channel is not published yet (that comes after this
# test): the upgrade also shows that an unreachable repository stops nothing.
log "Upgrade with the saved choices"
if [[ $MODE == bundle ]]; then
  on 'cd /root/wspanel && ./install --yes > /root/upgrade.log 2>&1' || fail "upgrade failed"
else
  on "WSPANEL_APT_URI=http://127.0.0.1:8099/apt wspanel-update --yes > /root/upgrade.log 2>&1" || fail "wspanel-update failed"
fi
[[ "$(tinker 'echo App\Models\User::count();')" == 1 ]] || fail "the upgrade changed the accounts"
[[ "$(on 'systemctl --failed --no-legend | wc -l')" == 0 ]] || fail "failed units after the upgrade"
code=$(on 'curl -sk -o /dev/null -w "%{http_code}" --resolve app.localhost:443:127.0.0.1 https://app.localhost/')
[[ $code == 200 ]] || fail "the site answered $code after the upgrade"

log "OK: $(basename "$BUNDLE")"
