#!/usr/bin/env bash
# The one-container image (docker/) started the way docs/docker.md starts it:
# the first start installs WSPanel, the panel answers, a restart keeps it.
#
#   dev/docker-smoke.sh <image>      e.g. wspanel:1.10.0-test
#
# Needs Docker with privileged containers (systemd as PID 1, cgroup v2).
set -euo pipefail
IMAGE=${1:?image}
NAME=wspanel-docker-smoke
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mFAIL:\033[0m %s\n' "$*" >&2; docker exec "$NAME" bash -c 'tail -60 /var/log/wspanel-docker-setup.log 2>/dev/null; systemctl --failed --no-legend' >&2 || true; exit 1; }
on() { docker exec "$NAME" bash -c "$1"; }

docker rm -f "$NAME" >/dev/null 2>&1 || true
trap '[[ -n "${SMOKE_KEEP:-}" ]] || docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT
log "Start $IMAGE (first start installs)"
docker run -d --name "$NAME" --hostname "$NAME" --privileged --cgroupns=host -t \
  -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock --tmpfs /tmp \
  -e WSPANEL_ADMIN_EMAIL=admin@smoke.test -e WSPANEL_PANEL_DOMAIN=panel.localhost -e WSPANEL_LOCAL_CERTS=1 \
  -e WSPANEL_PUBLIC_SSH_PORT=2222 -e WSPANEL_ARGS='--mail=capture' \
  "$IMAGE" >/dev/null

for _ in $(seq 1 240); do
  state="$(on 'systemctl show -p ActiveState --value wspanel-docker-setup' 2>/dev/null || true)"
  [[ $state == active || $state == failed ]] && break
  sleep 5
done
[[ $state == active ]] || fail "the first-start installation did not finish (state: ${state:-?})"
on 'test -s /var/lib/wspanel-docker/installed' || fail "no installation mark"
# Not piped into grep -q: it stops reading, docker logs dies of SIGPIPE (pipefail).
grep -qa 'Password:' <(docker logs "$NAME" 2>&1) || fail "the panel account is not in docker logs"
on 'systemctl is-active --quiet panel-agent panel-web panel-proxy' || fail "the panel's services are not running"
on 'curl -fsk -o /dev/null --resolve panel.localhost:443:127.0.0.1 https://panel.localhost/' || fail "the panel does not answer"

log "Restart: the installation stays, nothing runs again"
docker restart "$NAME" >/dev/null
for _ in $(seq 1 60); do on 'systemctl is-system-running 2>/dev/null | grep -qE "running|degraded"' && break; sleep 2; done
on '[[ "$(systemctl show -p ExecMainStartTimestampMonotonic --value wspanel-docker-setup)" == 0 ]]' || fail "the installation ran again after a restart"
for _ in $(seq 1 30); do on 'curl -fsk -o /dev/null --resolve panel.localhost:443:127.0.0.1 https://panel.localhost/' && break; sleep 2; done \
  || fail "the panel does not answer after a restart"
log "OK"
