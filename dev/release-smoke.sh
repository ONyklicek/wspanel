#!/usr/bin/env bash
# Installs a release bundle on a clean Debian 13 (a systemd container) the way
# a server would, then checks that it works and that an upgrade keeps it so.
# Used by the release workflow before anything is published; runs locally too:
#
#   dev/release-smoke.sh dist/wspanel-1.2.0-linux-amd64.tar.gz
#   dev/release-smoke.sh --apt <dir with wspanel_*.deb and wspanel-agent_*.deb>
#   dev/release-smoke.sh --from 1.2.0-rc5 dist/wspanel-1.2.0-linux-amd64.tar.gz
#
# --apt installs the way the README's "Instalace přes apt" does: from an apt
# repository built in the container out of those packages (signed with a
# throwaway key, so with a wspanel-archive-keyring package of that key),
# then upgrades with wspanel-update.
#
# --from installs that published release first (its get.sh from GitHub, the
# same modules), puts a site on it, and then upgrades it to the bundle: the
# way existing servers get the release. The site must still answer after.
#
# Needs Docker with privileged containers (systemd as PID 1, cgroup v2).
set -euo pipefail
MODE=bundle FROM=""
if [[ "${1:-}" == --apt ]]; then MODE=apt; shift; fi
if [[ "${1:-}" == --from ]]; then FROM=${2:?--from <published version>}; shift 2; fi
[[ -z "$FROM" || "$FROM" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "--from: not a version: $FROM" >&2; exit 1; }
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
trap '[[ -n "${SMOKE_KEEP:-}" ]] || docker rm -f "$NAME" "$NAME-new" >/dev/null 2>&1 || true' EXIT
docker run -d --name "$NAME" --hostname "$NAME" --privileged --cgroupns=host \
  -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock --tmpfs /tmp "$IMAGE" >/dev/null
for _ in $(seq 1 30); do on 'systemctl is-system-running 2>/dev/null | grep -qE "running|degraded"' && break; sleep 1; done
# A server, not an image build: Debian's Docker images forbid packages to
# start or restart services (policy-rc.d), which a real server allows.
on 'rm -f /usr/sbin/policy-rc.d'
FLAGS='--yes --panel-domain=panel.localhost --local-certs --db=mariadb,postgres --php=8.3,8.4 --mail=capture --dns=powerdns --clients=yes --mail-hosting=yes --xhprof=yes --allow-unenforced-socket-bind --admin-email=admin@smoke.test'
if [[ -n "$FROM" ]]; then
  log "The published release $FROM first (get.sh), with a site on it"
  on "curl -fsSL --retry 8 --retry-delay 10 --retry-all-errors https://github.com/${SMOKE_REPO:-ONyklicek/wspanel}/releases/download/v$FROM/get.sh \
      | env WSPANEL_VERSION=$FROM bash -s -- $FLAGS > /root/install-from.log 2>&1" \
    || { on 'tail -40 /root/install-from.log' >&2; fail "installing $FROM failed"; }
  out=$(tinker '$s = app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "old.localhost", "mode" => "classic", "framework" => "php", "php_version" => "8.4", "tls" => "internal"]); echo $s->status->value;')
  [[ $out == active ]] || fail "a site on $FROM: $out"
fi
if [[ $MODE == bundle ]]; then
  docker cp "$BUNDLE" "$NAME:/root/bundle.tar.gz"
  on 'cd /root && tar -xzf bundle.tar.gz' 2>&1 | grep -q . && fail "the bundle does not unpack cleanly (warnings above)"
  log "Install (every module), unattended"
  on "cd /root/wspanel && ./install $FLAGS --python=3.13 > /root/install.log 2>&1" || fail "install failed"
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
  on "WSPANEL_APT_URI=http://127.0.0.1:8099/apt wspanel-install $FLAGS --python=3.13 > /root/install.log 2>&1" || fail "wspanel-install failed"
  log "The server follows its line's channel"
  line=$(on "dpkg-query -W -f='\${Version}' wspanel" | sed -E 's/^([0-9]+\.[0-9]+).*/\1/')
  [[ $channel == pre ]] && line="$line-pre"
  on "grep -qx 'Suites: $line' /etc/apt/sources.list.d/wspanel.sources" || fail "apt does not follow the channel $line"
  on 'grep -q "^PANEL_INSTALL_METHOD=apt" /srv/panel/shared/.env' || fail "the panel does not know it was installed through apt"
fi
on "grep -q '^Password:' /root/$([[ -n $FROM ]] && echo install-from.log || echo install.log)" || fail "no admin account was created"

log "Services"
for unit in panel-agent panel-proxy panel-web panel-scheduler panel-queue php8.3-fpm php8.4-fpm mariadb postgresql postfix opendkim dovecot rspamd pdns fail2ban; do
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

  on 'test -f /usr/lib/systemd/system/panel-agent.service -a ! -e /etc/systemd/system/panel-agent.service' || fail "the agent unit is not the package's"
fi

on 'apt-config dump | grep -q "^Acquire::Retries \"5\";"' || fail "apt does not retry failed downloads"
log "FrankenPHP from its own packages"
on 'dpkg-query -W -f="\${Status}" frankenphp | grep -q "install ok installed"' || fail "frankenphp is not installed as a package"
on 'apt-cache policy frankenphp | grep -q pkg.henderkes.com' || fail "frankenphp does not come from FrankenPHP's repository"
[[ "$(on 'readlink /usr/local/bin/frankenphp')" == /usr/bin/frankenphp ]] || fail "/usr/local/bin/frankenphp does not lead to the package's binary"
[[ "$(on 'systemctl is-enabled frankenphp.service 2>/dev/null || true')" == masked ]] || fail "the package's own frankenphp.service is not masked"
# An update of FrankenPHP (apt) restarts what runs on it (wspanel-agent's trigger).
before=$(on 'systemctl show -p ExecMainPID --value panel-web')
on 'cd /tmp && apt-get download -q frankenphp >/dev/null 2>&1 && dpkg -i frankenphp_*.deb >/dev/null 2>&1' || fail "reinstalling frankenphp failed"
for _ in $(seq 20); do [[ "$(on 'systemctl show -p ExecMainPID --value panel-web')" != "$before" ]] && break; sleep 1; done
[[ "$(on 'systemctl show -p ExecMainPID --value panel-web')" != "$before" ]] || fail "an update of FrankenPHP did not restart the panel"
on 'systemctl is-active --quiet panel-web && systemctl is-active --quiet panel-proxy' || fail "the panel or the proxy did not come back after a FrankenPHP update"
for ext in pdo_mysql pdo_pgsql redis intl gd zip; do
  on "echo '<?php exit(extension_loaded(\"$ext\") ? 0 : 1);' > /tmp/e.php && frankenphp php-cli /tmp/e.php" || fail "FrankenPHP's PHP lacks $ext"
done

if [[ -n "$FROM" ]]; then
  log "The site from $FROM after the upgrade"
  code=$(on 'curl -sk -o /dev/null -w "%{http_code}" --resolve old.localhost:443:127.0.0.1 https://old.localhost/')
  [[ $code =~ ^(200|403|404)$ ]] || fail "the site from $FROM answered $code after the upgrade"
  on '! dpkg-query -W -f="\${Status}" wspanel-frankenphp 2>/dev/null | grep -q "ok installed"' || fail "the old wspanel-frankenphp package is still installed"
fi

log "Panel"
code=$(on 'curl -sk -o /dev/null -w "%{http_code}" --resolve panel.localhost:443:127.0.0.1 https://panel.localhost/login')
[[ $code == 200 ]] || fail "login page answered $code"

log "A Laravel site with its workers"
out=$(tinker '$s = app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "app.localhost", "mode" => "classic", "framework" => "laravel", "php_version" => "8.4", "tls" => "internal"]); foreach (["queue", "scheduler"] as $w) { app(App\Actions\Sites\ManageWorkers::class)->create($s, $w); } echo $s->status->value;')
[[ $out == active ]] || fail "site: $out"
# Its PHP-FPM pool may still be (re)loading for a moment.
for _ in $(seq 20); do
  code=$(on 'curl -sk -o /dev/null -w "%{http_code}" --resolve app.localhost:443:127.0.0.1 https://app.localhost/')
  [[ $code == 200 ]] && break
  sleep 1
done
[[ $code == 200 ]] || { on 'journalctl -u "php8.4-fpm" --no-pager -n 20' >&2 || true; fail "the site answered $code"; }

log "Python: a placeholder, then a Django site with Celery deployed twice (the second through the other slot)"
on 'test -x /usr/local/bin/uv && ls -d /opt/uv/python/cpython-3.13.*-linux-*-gnu >/dev/null' || fail "--python=3.13 did not install uv and Python 3.13"
out=$(tinker '$s = app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "py.localhost", "mode" => "python", "framework" => "python", "python_version" => "3.13", "tls" => "internal"]); echo $s->status->value;')
[[ $out == active ]] || fail "Python site: $out"
for _ in $(seq 30); do on 'curl -sk --resolve py.localhost:443:127.0.0.1 https://py.localhost/ | grep -q "Python 3.13"' && break; sleep 1; done
on 'curl -sk --resolve py.localhost:443:127.0.0.1 https://py.localhost/ | grep -q "Python 3.13"' || fail "the Python placeholder does not answer"
out=$(tinker '$s = app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "dj.localhost", "mode" => "python", "framework" => "django", "python_version" => "3.13", "tls" => "internal"]); echo $s->status->value;')
[[ $out == active ]] || fail "Django site: $out"
# A minimal Django project, as CI would upload it (gunicorn left out: the deploy adds it).
on 'rm -rf /root/dj && mkdir -p /root/dj/shop && cd /root/dj
    printf "Django>=5.2,<6\ncelery>=5.4,<6\n" > requirements.txt
    printf "import os, sys\nif __name__ == \"__main__\":\n    os.environ.setdefault(\"DJANGO_SETTINGS_MODULE\", \"shop.settings\")\n    from django.core.management import execute_from_command_line\n    execute_from_command_line(sys.argv)\n" > manage.py
    touch shop/__init__.py
    printf "import os\nfrom celery import Celery\nos.environ.setdefault(\"DJANGO_SETTINGS_MODULE\", \"shop.settings\")\napp = Celery(\"shop\", broker=\"memory://\")\napp.config_from_object(\"django.conf:settings\", namespace=\"CELERY\")\n" > shop/celery.py
    printf "from pathlib import Path\nBASE_DIR = Path(__file__).resolve().parent.parent\nSECRET_KEY = \"smoke\"\nDEBUG = False\nALLOWED_HOSTS = [\"*\"]\nINSTALLED_APPS = [\"django.contrib.contenttypes\", \"django.contrib.auth\", \"django.contrib.staticfiles\"]\nROOT_URLCONF = \"shop.urls\"\nDATABASES = {\"default\": {\"ENGINE\": \"django.db.backends.sqlite3\", \"NAME\": BASE_DIR / \"db.sqlite3\"}}\nSTATIC_URL = \"static/\"\nSTATIC_ROOT = BASE_DIR / \"staticfiles\"\n" > shop/settings.py
    printf "from django.http import HttpResponse\nfrom django.urls import path\nurlpatterns = [path(\"\", lambda r: HttpResponse(\"django ok\"))]\n" > shop/urls.py
    printf "import os\nfrom django.core.wsgi import get_wsgi_application\nos.environ.setdefault(\"DJANGO_SETTINGS_MODULE\", \"shop.settings\")\napplication = get_wsgi_application()\n" > shop/wsgi.py' || fail "the Django project"
slots=()
for round in 1 2; do
  on "cd /root/dj && tar -czf /var/lib/wspanel-artifacts/dj$round.tar.gz . && chown panel: /var/lib/wspanel-artifacts/dj$round.tar.gz" || fail "the artifact"
  out=$(tinker '$j = app(App\Services\Agent\AgentClient::class)->call("deploy.run", ["domain" => "dj.localhost", "artifact" => "dj'"$round"'.tar.gz"]); echo json_encode(app(App\Services\Agent\AgentJobs::class)->await($j["job_id"], 900));')
  [[ $out == *'"status":"success"'* ]] || { on 'tail -40 "$(ls -t /var/log/panel-agent/deploys/dj.localhost-*.log 2>/dev/null | head -1)"' >&2 || true; fail "Django deploy $round: $out"; }
  on 'curl -sk --resolve dj.localhost:443:127.0.0.1 https://dj.localhost/ | grep -qx "django ok"' || fail "the Django site does not answer after deploy $round"
  slots+=("$(on 'systemctl list-units --state=active --no-legend "wspanel-web_dj_localhost-app*.service" | awk "{print \$1}"')")
  if [[ $round == 1 ]]; then # Celery from the project package, after the first release has its .venv
    for w in celery celerybeat; do
      out=$(tinker '$o = app(App\Actions\Sites\ManageWorkers::class)->create(App\Models\Site::where("domain", "dj.localhost")->first(), "'"$w"'"); echo json_encode($o);')
      [[ $out == *'"started":true'* && $out == *'"app":"shop"'* ]] || fail "Celery worker $w: $out"
    done
  fi
done
# After the second deploy: the workers run from the new release, the old worker is gone.
rel=$(on 'basename "$(readlink /srv/sites/dj.localhost/current)"')
for _ in $(seq 1 30); do [[ "$(on 'systemctl list-units --state=active --no-legend "wspanel-web_dj_localhost-celery@*" | wc -l')" == 1 ]] && break; sleep 1; done
sleep 5
for w in celery celerybeat; do
  on "systemctl is-active --quiet wspanel-web_dj_localhost-$w@$rel.service && [ \"\$(systemctl show -P NRestarts wspanel-web_dj_localhost-$w@$rel.service)\" = 0 ]" \
    || { on "journalctl -u wspanel-web_dj_localhost-$w@$rel.service -n 20 --no-pager" >&2 || true; fail "Celery $w does not run from release $rel"; }
done
[[ "$(on 'systemctl list-units --state=active --no-legend "wspanel-web_dj_localhost-celery@*" | wc -l')" == 1 ]] || fail "the old Celery worker did not stop after the deploy"
on 'journalctl -u "wspanel-web_dj_localhost-celery@*" --no-pager | grep -q "ready"' || fail "the Celery worker never got ready"
# Python's patch update: just installed, so already the newest (the uv call itself is what is checked).
# Long operations run in the background (AgentTasks): the test waits for them.
out=$(tinker '$t = App\Support\AgentTasks::wait(app(App\Actions\Server\ManagePython::class)->upgrade("3.13"), 300); echo json_encode($t->output ?? ["status" => $t->status, "error" => $t->error]);')
[[ $out == *'"changed":false'* ]] || fail "python.upgrade: $out"
[[ ${slots[0]} != "${slots[1]}" && ${slots[1]} =~ ^wspanel-web_dj_localhost-appb?\.service$ ]] || fail "the deploys did not alternate the slots: ${slots[*]}"
on "grep -q 'shop.wsgi:application' /etc/systemd/system/${slots[1]}" || fail "the Django project was not found in manage.py"

log "Game server: Minecraft (Paper, the newest version, its Java from Debian)"
game_call() { tinker '$m = app(App\Actions\Games\ManageGameServers::class); $g = App\Models\GameServer::query()->first(); '"$1"; }
out=$(tinker '$m = app(App\Actions\Games\ManageGameServers::class); $v = $m->versions(App\Enums\Game::from("minecraft"), "paper")[0] ?? null; $s = $m->create(["name" => "Smoke", "game" => "minecraft", "flavor" => "paper", "version" => $v, "memory_mb" => 1024, "eula" => true]); App\Support\AgentTasks::wait(App\Models\AgentTask::where("subject_id", $s->id)->where("op", "game.create")->latest("id")->first(), 900); echo $s->fresh()->status, " ", $v;')
[[ $out == active* ]] || fail "game.create: $out"
echo "  Paper ${out#active }"
for _ in $(seq 90); do on 'journalctl -u wspanel-game-1 --no-pager -o cat | grep -q "Done ("' && break; sleep 2; done
on 'journalctl -u wspanel-game-1 --no-pager -o cat | grep -q "Done ("' \
  || { on 'journalctl -u wspanel-game-1 -n 30 --no-pager' >&2 || true; fail "the Minecraft server did not start"; }
out=$(game_call 'echo $m->command($g, "list");')
[[ $out == *"players online"* ]] || fail "the console (RCON) does not answer: $out"
out=$(game_call 'echo json_encode($m->status($g)["players"] ?? null);')
[[ $out == *'"online":0'* ]] || fail "game.status players: $out"
on 'ufw status | grep -q "25565/tcp.*ALLOW.*wspanel game 1"' || fail "the game port is not open in the firewall"
rcon=$(on 'grep -o "\"rcon_port\": *[0-9]*" /var/lib/panel-agent/games/1.json | grep -o "[0-9]*$"')
[[ $rcon =~ ^[0-9]+$ ]] || fail "no RCON port in the game state"
on "su -s /bin/bash -c 'timeout 3 bash -c \"echo > /dev/tcp/127.0.0.1/$rcon\"' nobody 2>/dev/null" \
  && fail "a user other than root reached the RCON port"
[[ "$(on 'stat -c %a /etc/systemd/system/wspanel-game-1.service')" == 600 ]] || fail "the game unit (RCON password) is readable by others"
out=$(game_call '$t = App\Support\AgentTasks::wait($m->backup($g), 600); echo json_encode($t->output ?? ["status" => $t->status, "error" => $t->error]);')
[[ $out == *'"file":"game-1-'* ]] || fail "game.backup: $out"
on 'test "$(tar -tzf /var/backups/wspanel-games/1/game-1-*.tar.gz | grep -c "^data/world/level.dat$")" = 1' || fail "the backup has no world"
on '! tar -tzf /var/backups/wspanel-games/1/game-1-*.tar.gz | grep -q "^data/libraries/"' || fail "the backup holds Paper's libraries"
# It stays for the server backup below (rebuilt on the new server), and is deleted at the end.

log "System: restart, name and time, swap, processes, disk usage, packages, network, disks"
sys_call() { tinker '$s = app(App\Actions\System\ManageSystem::class); '"$1"; }
pkg_call() { tinker '$p = app(App\Actions\System\ManagePackages::class); '"$1"; }
out=$(sys_call 'echo json_encode($s->reboot(600));')
[[ $out == *T*Z* ]] || fail "sys.reboot: $out"
out=$(tinker 'echo json_encode(app(App\Actions\System\ManageUpdates::class)->status());')
[[ $out == *'"reboot_scheduled":"2'* ]] || fail "the scheduled restart is not reported: $out"
sys_call '$s->cancelReboot(); echo "ok";' >/dev/null
[[ "$(tinker 'echo app(App\Actions\System\ManageUpdates::class)->status()["reboot_scheduled"] ?: "none";')" == none ]] || fail "the restart was not cancelled"
sys_call '$s->setAutoReboot(true, "03:30"); echo "ok";' >/dev/null
on 'grep -q "Automatic-Reboot-Time \"03:30\"" /etc/apt/apt.conf.d/52wspanel-auto-reboot' || fail "the automatic restart is not configured"
on 'apt-config dump | grep -q "Unattended-Upgrade::Automatic-Reboot \"true\""' || fail "apt does not read the automatic restart"
sys_call '$s->setTimezone("Europe/Prague"); echo "ok";' >/dev/null
[[ "$(on 'timedatectl show -p Timezone --value')" == Europe/Prague ]] || fail "the time zone did not change"
out=$(sys_call 'echo json_encode($s->settings(true));')
[[ $out == *'"timezone":"Europe\/Prague"'* && $out == *'"Pacific\/Auckland"'* ]] || fail "sys.settings: ${out:0:300}"
# The name and swap need what a container may not have (its own UTS name, swapon): reported, not required.
out=$(sys_call '$s->setHostname("smoke-1.example.test"); echo "ok";')
echo "  hostname in this container: $out ($(on hostname))"
out=$(sys_call '$s->setSwap(256, 10); echo json_encode($s->settings()["swaps"]);')
echo "  swap in this container: ${out:0:200}"
out=$(sys_call 'echo json_encode($s->network());')
[[ $out == *'"interfaces":[{'* && $out == *'"addresses":["'* ]] || fail "sys.network: $out"
out=$(sys_call 'echo json_encode($s->disks());')
[[ $out == *'"installed":false'* ]] || fail "sys.disks without smartmontools: $out"
on 'systemd-run --unit=smoke-sleep --quiet sleep 4242'
out=$(sys_call '$p = collect($s->processes())->firstWhere("cmd", "sleep 4242"); $s->kill($p["pid"], $p["start"]); echo collect($s->processes())->contains("pid", 1) ? "pid1" : "ok";')
sleep 1
on 'pgrep -x -f "sleep 4242" >/dev/null' && fail "sys.kill did not end the process ($out)"
out=$(sys_call 'try { $s->kill(1, 1); echo "killed"; } catch (Throwable $e) { echo "refused"; }')
[[ $out == refused ]] || fail "PID 1 must not be ended from the panel"
out=$(sys_call 'echo json_encode($s->diskUsage("/var"));')
[[ $out == *'"\/var\/lib"'* ]] || fail "sys.disk_usage: ${out:0:300}"
out=$(pkg_call 'echo json_encode($p->search("htop"));')
[[ $out == *'"name":"htop"'* ]] || fail "pkg.search: ${out:0:300}"
out=$(pkg_call 'echo json_encode($p->preview("htop", "install"));')
[[ $out == *'"install":["htop"'* ]] || fail "pkg.preview: $out"
[[ "$(pkg_call 'echo App\Support\AgentTasks::wait($p->install("htop"), 600)->status;')" == done ]] || fail "pkg.install"
on 'dpkg -s htop >/dev/null 2>&1' || fail "htop was not installed"
pkg_call '$p->hold("htop", true); echo "ok";' >/dev/null
on 'apt-mark showhold | grep -qx htop' || fail "htop is not held"
out=$(pkg_call 'echo json_encode(collect($p->installed())->firstWhere("name", "htop"));')
[[ $out == *'"manual":true'* && $out == *'"held":true'* ]] || fail "pkg.list: $out"
pkg_call '$p->hold("htop", false); echo "ok";' >/dev/null
out=$(pkg_call 'echo json_encode($p->preview("postfix", "remove"));')
[[ $out == *'"allowed":false'* ]] || fail "removing postfix must be refused: $out"
out=$(pkg_call 'try { $p->remove("libsasl2-modules"); echo "removed"; } catch (Throwable $e) { echo "refused"; }')
on 'dpkg -s postfix >/dev/null 2>&1' || fail "a removal took postfix along ($out)"
[[ "$(pkg_call 'echo App\Support\AgentTasks::wait($p->remove("htop"), 600)->status;')" == done ]] || fail "pkg.remove"
on '! dpkg -s htop 2>/dev/null | grep -q "Status: install ok installed"' || fail "htop was not removed"

log "Mail: a mailbox receives"
out=$(tinker '$h = app(App\Actions\Mail\ManageMailHosting::class); $d = $h->addDomain("app.localhost"); echo $h->saveMailbox($d, "info", "Smoke-heslo-123", 100, true)->address();')
[[ $out == info@app.localhost ]] || fail "mailbox: $out"
on 'doveadm auth test info@app.localhost Smoke-heslo-123 >/dev/null' || fail "mailbox login"
on 'printf "Subject: smoke\n\nok\n" | sendmail info@app.localhost'
for _ in $(seq 1 20); do on 'doveadm mailbox status -u info@app.localhost messages INBOX | grep -q "messages=1"' && break; sleep 1; done
on 'doveadm mailbox status -u info@app.localhost messages INBOX | grep -q "messages=1"' || fail "the message was not delivered"

log "Mail: a DMARC report reaches the panel"
on 'xml="<?xml version=\"1.0\"?><feedback><report_metadata><org_name>smoke.test</org_name><report_id>smoke-1</report_id><date_range><begin>1794009600</begin><end>1794096000</end></date_range></report_metadata><policy_published><domain>app.localhost</domain><p>none</p></policy_published><record><row><source_ip>203.0.113.9</source_ip><count>5</count><policy_evaluated><disposition>none</disposition><dkim>fail</dkim><spf>fail</spf></policy_evaluated></row><identifiers><header_from>app.localhost</header_from></identifiers></record></feedback>"
    { printf "From: dmarc@smoke.test\nTo: dmarc-reports@app.localhost\nSubject: Report\nMIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=b\n\n--b\nContent-Type: application/gzip; name=r.xml.gz\nContent-Transfer-Encoding: base64\n\n"
      printf "%s" "$xml" | gzip | base64; printf "\n--b--\n"; } | sendmail dmarc-reports@app.localhost'
for _ in $(seq 1 20); do on 'ls /srv/panel/shared/storage/app/dmarc-incoming/*.eml >/dev/null 2>&1' && break; sleep 1; done
on 'cd /srv/panel/current && runuser -u panel -- php artisan panel:dmarc >/dev/null' || fail "panel:dmarc failed"
[[ "$(tinker 'echo App\Models\DmarcReport::query()->sum("failed");')" == 5 ]] || fail "the DMARC report did not reach the panel (mail log: $(on 'journalctl -u postfix@- -n 5 --no-pager 2>/dev/null | tail -3'))"

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

# Back from an upgrade: the same bundle as a newer version, then
# wspanel-rollback. The version, the accounts and the sites as before.
if [[ $MODE == bundle ]]; then
  log "Upgrade to a newer version, then wspanel-rollback"
  v=$(on 'cat /root/wspanel/VERSION')
  # A version the installer accepts: 1.2.0-rc1 -> 1.2.0-rc1.next, 1.2.0 -> 1.2.1.
  if [[ $v == *-* ]]; then next="$v.next"; else next="${v%.*}.$((${v##*.} + 1))"; fi
  on "rm -rf /root/next && cp -a /root/wspanel /root/next && echo '$next' > /root/next/VERSION \
      && cd /root/next && ./install --yes > /root/upgrade-next.log 2>&1" || fail "the upgrade to $next failed"
  [[ "$(on 'sed -n "s/^PANEL_VERSION=//p" /srv/panel/shared/.env')" == "$next" ]] || fail "the panel is not on $next"
  on 'wspanel-rollback --yes > /root/rollback.log 2>&1' || fail "wspanel-rollback failed (see /root/rollback.log)"
  [[ "$(on 'sed -n "s/^PANEL_VERSION=//p" /srv/panel/shared/.env')" == "$v" ]] || fail "after the rollback the panel is not on $v"
  [[ "$(tinker 'echo App\Models\User::count();')" == 1 ]] || fail "the rollback changed the accounts"
  [[ "$(on 'systemctl --failed --no-legend | wc -l')" == 0 ]] || fail "failed units after the rollback"
  code=$(on 'curl -sk -o /dev/null -w "%{http_code}" --resolve app.localhost:443:127.0.0.1 https://app.localhost/')
  [[ $code == 200 ]] || fail "the site answered $code after the rollback"
fi

# Disaster recovery: a database with a row and a backup of the site, the
# server backup, then a new server restored from them (wspanel-restore).
if [[ $MODE == bundle ]]; then
  log "Server backup, then a new server restored from it"
  on 'mariadb -e "CREATE TABLE IF NOT EXISTS x(v INT); INSERT INTO x VALUES (42)" smokedb' 2>/dev/null \
    || { tinker '$s = App\Models\Site::query()->where("domain", "app.localhost")->first(); app(App\Actions\Databases\ManageDatabases::class)->create("mariadb", "smokedb", $s); echo "ok";' >/dev/null
         on 'mariadb -e "CREATE TABLE x(v INT); INSERT INTO x VALUES (42)" smokedb' || fail "a table in the site's database"; }
  # Every site backed up (a site without a backup would come back empty).
  [[ "$(tinker '$ok = App\Models\Site::query()->get()->every(fn ($s) => App\Support\AgentTasks::wait(app(App\Actions\Sites\ManageSiteBackups::class)->backupNow($s), 600)->status === "done"); echo $ok ? "done" : "running";')" == "done" ]] \
    || fail "the site backups did not finish"
  key=$(tinker 'echo app(App\Actions\Server\ManageOffsite::class)->signingKey();')
  [[ $key =~ ^[0-9a-f]{64}$ ]] || fail "no recovery key: $key"
  on 'cd /srv/panel/current && runuser -u panel -- php artisan panel:server-backup >/dev/null' || fail "the server backup did not start"
  for _ in $(seq 1 60); do on 'ls /var/backups/wspanel/server/*.wspbak >/dev/null 2>&1 && ls /var/backups/wspanel/mail/app.localhost/*.tar.gz >/dev/null 2>&1' && break; sleep 2; done
  sbk=$(on 'basename "$(ls -1 /var/backups/wspanel/server/*.wspbak | tail -1)"') || fail "no server backup"
  move=$(mktemp -d)
  docker cp "$NAME:/var/backups/wspanel" "$move/backups" && docker cp "$NAME:/root/wspanel" "$move/wspanel" \
    && docker cp "$NAME:/var/backups/wspanel-games" "$move/wspanel-games"

  docker run -d --name "$NAME-new" --hostname "$NAME" --privileged --cgroupns=host \
    -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock --tmpfs /tmp "$IMAGE" >/dev/null
  new() { docker exec "$NAME-new" bash -c "$1"; }
  for _ in $(seq 1 30); do new 'systemctl is-system-running 2>/dev/null | grep -qE "running|degraded"' && break; sleep 1; done
  new 'rm -f /usr/sbin/policy-rc.d'
  docker cp "$move/backups" "$NAME-new:/root/wspanel-backups" && docker cp "$move/wspanel" "$NAME-new:/root/wspanel" \
    && docker cp "$move/wspanel-games" "$NAME-new:/root/wspanel-games"
  rm -rf "$move"
  new "cd /root/wspanel && WSPANEL_RECOVERY_KEY=$key ./install --yes --restore=/root/wspanel-backups/server/$sbk --backups=/root/wspanel-backups > /root/restore.log 2>&1" \
    || { new 'tail -50 /root/restore.log' >&2; fail "the restore failed"; }
  new 'grep -q "^PROBLEM" /var/log/wspanel-restore.log' && { new 'grep "^PROBLEM" /var/log/wspanel-restore.log' >&2; fail "the restore reported problems"; }
  code=$(new 'curl -sk -o /dev/null -w "%{http_code}" --resolve app.localhost:443:127.0.0.1 https://app.localhost/')
  [[ $code == 200 ]] || fail "the restored site answered $code"
  for _ in $(seq 1 15); do new 'curl -sk --resolve dj.localhost:443:127.0.0.1 https://dj.localhost/ | grep -qx "django ok"' && break; sleep 1; done
  new 'curl -sk --resolve dj.localhost:443:127.0.0.1 https://dj.localhost/ | grep -qx "django ok"' || fail "the restored Django site does not answer (its .venv after the move)"
  rel=$(new 'basename "$(readlink /srv/sites/dj.localhost/current)"')
  new "systemctl is-active --quiet wspanel-web_dj_localhost-celery@$rel.service" || fail "Celery does not run from the restored release $rel"
  [[ "$(new 'ls /etc/systemd/system/*.wants/ | grep -c "wspanel-web_dj_localhost-celery@"')" == 1 ]] || fail "boot links of the old server's releases stay: $(new 'ls /etc/systemd/system/*.wants/ | grep celery')"
  [[ "$(new 'mariadb -N -e "SELECT v FROM x" smokedb')" == 42 ]] || fail "the site's database did not come back"
  new 'doveadm auth test info@app.localhost Smoke-heslo-123 >/dev/null' || fail "the mailbox login did not come back"
  new 'doveadm mailbox status -u info@app.localhost messages INBOX | grep -q "messages=1"' || fail "the mailbox's mail did not come back"
  [[ "$(new "cd /srv/panel/current && runuser -u panel -- php artisan tinker --execute 'echo App\Models\User::count();' 2>&1 | tail -1")" == 1 ]] \
    || fail "the panel's accounts did not come back"
  code=$(new 'curl -sk -o /dev/null -w "%{http_code}" --resolve panel.localhost:443:127.0.0.1 https://panel.localhost/login')
  [[ $code == 200 ]] || fail "the restored panel answered $code"
  # The game server, built again: its user, its world from its backup, running.
  new 'id wsg_1 >/dev/null && test -f /srv/games/1/data/world/level.dat' || fail "the game server's world did not come back"
  for _ in $(seq 90); do new 'journalctl -u wspanel-game-1 --no-pager -o cat | grep -q "Done ("' && break; sleep 2; done
  new 'systemctl is-active --quiet wspanel-game-1 && journalctl -u wspanel-game-1 --no-pager -o cat | grep -q "Done ("' \
    || { new 'journalctl -u wspanel-game-1 -n 30 --no-pager' >&2 || true; fail "the restored game server does not run"; }
  new 'ufw status | grep -q "25565/tcp.*ALLOW.*wspanel game 1"' || fail "the restored game server's port is not open"
  [[ "$(new 'systemctl --failed --no-legend | wc -l')" == 0 ]] || { new 'systemctl --failed --no-legend' >&2; fail "failed units on the restored server"; }
fi

log "Game server: deleted"
game_call '$m->delete($g); echo "ok";' >/dev/null
on '! id wsg_1 >/dev/null 2>&1 && ! test -e /srv/games/1 && ! ufw status | grep -q "wspanel game 1"' || fail "deleting the game server left its user, files or firewall rule"

log "OK: $(basename "$BUNDLE")"
