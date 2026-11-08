#!/usr/bin/env bash
# Installs a release bundle on a clean Debian 13 (a systemd container) the way
# a server would, then checks that it works and that an upgrade keeps it so.
# Used by the release workflow before anything is published; runs locally too:
#
#   dev/release-smoke.sh dist/wspanel-1.2.0-linux-amd64.tar.gz
#
# Needs Docker with privileged containers (systemd as PID 1, cgroup v2).
set -euo pipefail
BUNDLE=${1:?bundle .tar.gz}
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
docker cp "$BUNDLE" "$NAME:/root/bundle.tar.gz"
on 'cd /root && tar -xzf bundle.tar.gz' 2>&1 | grep -q . && fail "the bundle does not unpack cleanly (warnings above)"

log "Install (every module), unattended"
on 'cd /root/wspanel && ./install --yes --panel-domain=panel.localhost --local-certs \
  --db=mariadb,postgres --php=8.3,8.4 --mail=capture --dns=powerdns --clients=yes --mail-hosting=yes --xhprof=yes \
  --allow-unenforced-socket-bind --admin-email=admin@smoke.test > /root/install.log 2>&1' || fail "install failed"
on 'grep -q "^Password:" /root/install.log' || fail "no admin account was created"

log "Services"
for unit in panel-agent panel-proxy panel-web panel-scheduler php8.3-fpm php8.4-fpm mariadb postgresql postfix opendkim dovecot rspamd pdns fail2ban; do
  on "systemctl is-active --quiet $unit" || fail "$unit is not running"
done
[[ "$(on 'systemctl --failed --no-legend | wc -l')" == 0 ]] || fail "failed units"

if tar -tzf "$BUNDLE" | grep -q '/bin/wspanel-agent_.*\.deb$'; then
  log "The agent as a package, apt set up"
  on 'dpkg-query -W -f="\${Status}" wspanel-agent | grep -q "install ok installed"' || fail "wspanel-agent is not installed as a package"
  [[ "$(on 'readlink /usr/local/sbin/panel-agent')" == /usr/sbin/panel-agent ]] || fail "the old agent path does not lead to the package's"
  on 'test -s /etc/apt/sources.list.d/wspanel.sources -a -s /etc/apt/keyrings/wspanel.asc' || fail "no WSPanel apt source"
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
on 'cd /root/wspanel && ./install --yes > /root/upgrade.log 2>&1' || fail "upgrade failed"
[[ "$(tinker 'echo App\Models\User::count();')" == 1 ]] || fail "the upgrade changed the accounts"
[[ "$(on 'systemctl --failed --no-legend | wc -l')" == 0 ]] || fail "failed units after the upgrade"
code=$(on 'curl -sk -o /dev/null -w "%{http_code}" --resolve app.localhost:443:127.0.0.1 https://app.localhost/')
[[ $code == 200 ]] || fail "the site answered $code after the upgrade"

log "OK: $(basename "$BUNDLE")"
