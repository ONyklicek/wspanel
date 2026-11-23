#!/usr/bin/env bash
# The mirror end to end, on this machine with Docker: two Debian 13 servers
# (a primary and its standby, as provision/install.sh installs them) and a
# stand-in for the Windows DNS server (sshd + the same JSON protocol as
# wspanel-dns.ps1). Covers pairing, the copy of every layer, an outage of
# the primary with automatic failover, the old primary fencing itself on
# return, its rejoin as the standby, and a planned switchover back.
#
#   make agent-build && dev/mirror-e2e.sh        (MIRROR_KEEP=1 keeps the containers)
#
# Takes about half an hour (two installations run in parallel).
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD
NET=wspanel-mirror-e2e
P=wsm-primary
S=wsm-standby
D=wsm-windns
IMAGE=wspanel-dev-base
ARCH=${ARCH:-amd64}
PASS=0
FAIL=0
FAILED=()
green() { printf '\033[32m%s\033[0m' "$*"; }
red() { printf '\033[31m%s\033[0m' "$*"; }
section() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
ok() { PASS=$((PASS + 1)); printf '  %s %s\n' "$(green PASS)" "$1"; }
bad() { FAIL=$((FAIL + 1)); FAILED+=("$1"); printf '  %s %s %s\n' "$(red FAIL)" "$1" "${2:+— $2}"; }
expect() { if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1" "got '$2', want '$3'"; fi; }
contains() { if [[ "$2" == *"$3"* ]]; then ok "$1"; else bad "$1" "'$3' not in '${2:0:300}'"; fi; }
die() { printf '%s\n' "$(red "$*")" >&2; exit 1; }

on() { local c=$1; shift; docker exec "$c" "$@"; }
agent() { # agent CONTAINER OP [JSON]
	docker exec "$1" php /opt/wspanel/dev/agent-call.php "$2" "${3:-{\}}"
}
jqr() { docker exec -i "$1" jq -r "$2"; }
mstatus() { agent "$1" mirror.status; }
role() { mstatus "$1" | jqr "$1" .role; }
layer_ok() { mstatus "$1" | jqr "$1" ".status.layers[\"$2\"].ok // false"; }
wait_for() { # wait_for SECONDS DESCRIPTION COMMAND...
	local secs=$1 what=$2; shift 2
	for _ in $(seq 1 $((secs / 3))); do
		if "$@" >/dev/null 2>&1; then return 0; fi
		sleep 3
	done
	echo "  (timed out waiting for $what)" >&2
	return 1
}
cleanup() {
	if [[ -z "${MIRROR_KEEP:-}" ]]; then
		docker rm -f "$P" "$S" "$D" >/dev/null 2>&1 || true
		docker network rm "$NET" >/dev/null 2>&1 || true
	fi
}
trap cleanup EXIT

[[ -x build/panel-agent-linux-$ARCH ]] || die "build the agent first: make agent-build"

section "Containers"
docker build -q -t "$IMAGE" -f dev/Dockerfile dev >/dev/null || die "image"
docker rm -f "$P" "$S" "$D" >/dev/null 2>&1 || true
docker network rm "$NET" >/dev/null 2>&1 || true
docker network create --subnet 172.30.42.0/24 "$NET" >/dev/null
PIP=172.30.42.10
SIP=172.30.42.20
DIP=172.30.42.5
for c in "$P:$PIP" "$S:$SIP"; do
	name=${c%%:*} ip=${c#*:}
	docker run -d --name "$name" --hostname "$name" --network "$NET" --ip "$ip" --privileged --cgroupns=host \
		--tmpfs /run --tmpfs /run/lock --tmpfs /tmp -v /sys/fs/cgroup:/sys/fs/cgroup:rw -v "$ROOT:/opt/wspanel:ro" \
		"$IMAGE" >/dev/null || die "container $name"
done
docker run -d --name "$D" --hostname dc1 --network "$NET" --ip "$DIP" debian:trixie sleep infinity >/dev/null || die "windns container"
ok "primary $PIP, standby $SIP, Windows DNS stand-in $DIP"

section "Installing both servers (parallel)"
install() {
	docker exec "$1" /opt/wspanel/provision/install.sh --db=mariadb,postgres --php=8.4 --panel-domain=panel.firma.local \
		--agent-bin=/opt/wspanel/build/panel-agent-linux-"$ARCH" --panel-source=/opt/wspanel/panel --local-certs \
		--mail=capture --allow-unenforced-socket-bind >"/tmp/$1-install.log" 2>&1
}
install "$P" & ip=$!
install "$S" & is=$!
(docker exec "$D" bash -c 'apt-get update -q >/dev/null && apt-get install -y -q openssh-server jq >/dev/null') & id=$!
wait $ip && ok "primary installed" || { tail -30 "/tmp/$P-install.log"; die "primary install failed"; }
wait $is && ok "standby installed" || { tail -30 "/tmp/$S-install.log"; die "standby install failed"; }
wait $id && ok "Windows DNS stand-in ready" || die "windns"
for c in "$P" "$S"; do docker exec "$c" bash -c 'command -v jq >/dev/null || apt-get install -y -q jq >/dev/null'; done

section "Pairing"
code=$(agent "$P" mirror.init "{\"address\":\"$PIP\",\"peer_address\":\"$SIP\"}" | jqr "$P" .code)
contains "the primary shows a pairing code" "$code" "WSPM1-"
out=$(agent "$S" mirror.join "{\"primary\":\"$PIP\",\"code\":\"$code\",\"address\":\"$SIP\"}")
contains "the standby pairs" "$out" '"role":"standby"'
expect "the code is used up" "$(agent "$S" mirror.join "{\"primary\":\"$PIP\",\"code\":\"$code\",\"address\":\"$SIP\"}" 2>&1 | grep -c 'already in a mirror')" 1
wait_for 60 "the link" bash -c "[[ \$(docker exec $P php /opt/wspanel/dev/agent-call.php mirror.status | docker exec -i $P jq -r .status.peer_up) == true ]]"
expect "the primary sees the standby" "$(mstatus "$P" | jqr "$P" .status.peer.role)" standby
expect "the mirror port is open only for the standby" "$(on "$P" ufw status | grep -c "7443/tcp.*ALLOW.*$SIP")" 1

section "Data on the primary"
agent "$P" site.create '{"domain":"shop.firma.local","framework":"laravel","php_version":"8.4"}' >/dev/null && ok "site created"
agent "$P" db.create '{"engine":"mariadb","name":"shop","domain":"shop.firma.local"}' >/dev/null && ok "MariaDB database"
agent "$P" db.create '{"engine":"postgresql","name":"shoppg"}' >/dev/null && ok "PostgreSQL database"
agent "$P" valkey.set '{"domain":"shop.firma.local","enabled":true,"maxmemory_mb":32,"persist":true}' >/dev/null && ok "Valkey"
on "$P" mariadb shop -e "CREATE TABLE t (id INT PRIMARY KEY, v VARCHAR(20)); INSERT INTO t VALUES (1, 'before');"
on "$P" runuser -u postgres -- psql -q -d shoppg -c "CREATE TABLE t (id int primary key, v text); INSERT INTO t VALUES (1, 'before');"
VK_PW=$(on "$P" sed -n 's/^requirepass //p' /etc/panel-sites/valkey-auth/web_shop_firma_local.conf)
on "$P" bash -c "echo '$VK_PW' | runuser -u web_shop_firma_local -- valkey-cli --askpass --no-auth-warning -s /run/wspanel-valkey/web_shop_firma_local/valkey.sock SET greeting before" >/dev/null
on "$P" bash -c "echo hello-from-primary | runuser -u web_shop_firma_local -- tee /srv/sites/shop.firma.local/current/public/index.html" >/dev/null
agent "$S" mirror.sync_now >/dev/null

section "The standby's copy"
for l in state accounts sites mariadb postgresql kv; do
	wait_for 600 "layer $l" bash -c "[[ \$(docker exec $S php /opt/wspanel/dev/agent-call.php mirror.status | docker exec -i $S jq -r '.status.layers[\"$l\"].ok // false') == true ]]" \
		&& ok "layer $l in sync" || bad "layer $l in sync" "$(mstatus "$S" | jqr "$S" ".status.layers[\"$l\"]")"
done
expect "site user with the primary's uid" "$(on "$S" id -u web_shop_firma_local)" "$(on "$P" id -u web_shop_firma_local)"
expect "site files copied" "$(on "$S" cat /srv/sites/shop.firma.local/current/public/index.html 2>/dev/null)" hello-from-primary
on "$P" mariadb shop -e "INSERT INTO t VALUES (2, 'replicated');"
on "$P" runuser -u postgres -- psql -q -d shoppg -c "INSERT INTO t VALUES (2, 'replicated');"
sleep 5
expect "MariaDB replicates" "$(on "$S" mariadb -N shop -e 'SELECT v FROM t WHERE id = 2')" replicated
expect "PostgreSQL replicates" "$(on "$S" runuser -u postgres -- psql -tAq -d shoppg -c 'SELECT v FROM t WHERE id = 2')" replicated
expect "the MariaDB replica is read-only" "$(on "$S" mariadb -N -e 'SELECT @@read_only')" 1
expect "the standby serves no sites" "$(on "$S" bash -c 'ls /etc/panel-proxy/sites/*.caddy 2>/dev/null | wc -l')" 0
kvs=$(on "$S" bash -c "echo '$VK_PW' | runuser -u web_shop_firma_local -- valkey-cli --askpass --no-auth-warning -s /run/wspanel-mirror-kv/web_shop_firma_local/kv.sock GET greeting")
expect "Valkey replicates" "$kvs" before
expect "a tunnel refuses other users" "$(on "$S" runuser -u nobody -- bash -c 'exec 3<>/dev/tcp/127.0.0.1/17306' 2>&1 | grep -c -i 'refused\|reset')" 1

section "Windows DNS"
for c in "$P" "$S"; do
	host=$DIP
	pub=$(agent "$c" mirror.windns_set "{\"host\":\"$host\",\"user\":\"wspanel-dns\",\"zones\":[\"firma.local\"],\"extra\":[\"panel.firma.local\"]}" | jqr "$c" .public_key)
	docker exec -i "$D" bash -c "cat >> /etc/wspanel-dns.keys" <<<"$pub"
done
docker cp dev/fake-windns/fake-dns.sh "$D:/usr/local/bin/fake-dns"
docker exec "$D" bash -c 'useradd -m -s /bin/bash wspanel-dns 2>/dev/null; install -d -o wspanel-dns /var/lib/fake-dns
  cat > /etc/ssh/sshd_config.d/wspanel.conf <<EOF
Match User wspanel-dns
    AuthorizedKeysFile /etc/wspanel-dns.keys
    ForceCommand /usr/local/bin/fake-dns
    AllowTcpForwarding no
    PermitTTY no
EOF
  mkdir -p /run/sshd && ssh-keygen -A >/dev/null && /usr/sbin/sshd'
for c in "$P" "$S"; do
	contains "$c trusts the Windows host key" "$(agent "$c" mirror.windns_trust)" "ssh-ed25519"
done
contains "the connection works" "$(agent "$P" mirror.windns_test)" '"zone":"firma.local"'
wait_for 120 "the record" bash -c "docker exec $D jq -r '.\"shop/A\".values[0]' /var/lib/fake-dns/firma.local.json | grep -qx $PIP" \
	&& ok "shop.firma.local → the primary" || bad "shop.firma.local → the primary" "$(on "$D" cat /var/lib/fake-dns/firma.local.json)"

section "Failover"
for c in "$P" "$S"; do
	agent "$c" mirror.settings "{\"witness\":[\"$DIP:22\"],\"failover_after\":30,\"sync_every\":30}" >/dev/null
	agent "$c" mirror.settings '{"auto_failover":true}' >/dev/null
done
ok "automatic failover after 30 s, the Windows DNS server as witness"
on "$P" mariadb shop -e "INSERT INTO t VALUES (3, 'last before outage');"
sleep 5
docker stop "$P" >/dev/null && ok "the primary is down"
wait_for 240 "promotion" bash -c "[[ \$(docker exec $S php /opt/wspanel/dev/agent-call.php mirror.status | docker exec -i $S jq -r .role) == primary ]]" \
	&& ok "the standby promoted itself" || bad "the standby promoted itself" "$(mstatus "$S" | jqr "$S" .status.events)"
wait_for 120 "sites up" bash -c "docker exec $S curl -sk --resolve shop.firma.local:443:127.0.0.1 https://shop.firma.local/ | grep -q hello-from-primary" \
	&& ok "the site is served by the new primary" || bad "the site is served by the new primary"
expect "MariaDB takes writes" "$(on "$S" mariadb -N -e 'SELECT @@read_only')" 0
expect "nothing replicated is lost" "$(on "$S" mariadb -N shop -e 'SELECT v FROM t WHERE id = 3')" "last before outage"
on "$S" mariadb shop -e "INSERT INTO t VALUES (4, 'written on the new primary');" && ok "a write on the new primary"
expect "PostgreSQL promoted" "$(on "$S" runuser -u postgres -- psql -tAq -c 'SELECT pg_is_in_recovery()')" f
expect "Valkey data kept" "$(on "$S" bash -c "echo '$VK_PW' | runuser -u web_shop_firma_local -- valkey-cli --askpass --no-auth-warning -s /run/wspanel-valkey/web_shop_firma_local/valkey.sock GET greeting")" before
wait_for 120 "DNS" bash -c "docker exec $D jq -r '.\"shop/A\".values[0]' /var/lib/fake-dns/firma.local.json | grep -qx $SIP" \
	&& ok "Windows DNS points at the new primary" || bad "Windows DNS points at the new primary"
contains "the panel's users came along" "$(on "$S" sqlite3 /srv/panel/shared/database.sqlite 'SELECT count(*) FROM users')" ""

section "The old primary returns"
docker start "$P" >/dev/null
wait_for 240 "fenced" bash -c "[[ \$(docker exec $P php /opt/wspanel/dev/agent-call.php mirror.status | docker exec -i $P jq -r .role) == fenced ]]" \
	&& ok "it steps down (a newer epoch is primary)" || bad "it steps down" "$(mstatus "$P")"
expect "it serves no sites" "$(on "$P" bash -c 'ls /etc/panel-proxy/sites/*.caddy 2>/dev/null | wc -l')" 0
expect "its MariaDB is read-only" "$(on "$P" mariadb -N -e 'SELECT @@read_only')" 1

section "Rejoin and switch back"
contains "it rejoins as the standby" "$(agent "$P" mirror.rejoin)" '"role":"standby"'
agent "$P" mirror.sync_now >/dev/null
for l in state sites mariadb postgresql; do
	wait_for 600 "layer $l" bash -c "[[ \$(docker exec $P php /opt/wspanel/dev/agent-call.php mirror.status | docker exec -i $P jq -r '.status.layers[\"$l\"].ok // false') == true ]]" \
		&& ok "rejoined: layer $l" || bad "rejoined: layer $l" "$(mstatus "$P" | jqr "$P" ".status.layers[\"$l\"]")"
done
expect "the new primary's write reached it" "$(on "$P" mariadb -N shop -e 'SELECT v FROM t WHERE id = 4')" "written on the new primary"
contains "a planned switchover from the new primary" "$(agent "$S" mirror.switchover)" '"switched":true'
expect "the old primary is primary again" "$(role "$P")" primary
expect "and the other its standby" "$(role "$S")" standby
wait_for 120 "sites back" bash -c "docker exec $P curl -sk --resolve shop.firma.local:443:127.0.0.1 https://shop.firma.local/ | grep -q hello-from-primary" \
	&& ok "the site is served by the primary again" || bad "the site is served by the primary again"
expect "no write was lost in the switchover" "$(on "$P" mariadb -N shop -e 'SELECT count(*) FROM t')" 4
wait_for 120 "DNS back" bash -c "docker exec $D jq -r '.\"shop/A\".values[0]' /var/lib/fake-dns/firma.local.json | grep -qx $PIP" \
	&& ok "Windows DNS points back" || bad "Windows DNS points back"

printf '\n%s passed, %s failed\n' "$PASS" "$FAIL"
if ((FAIL > 0)); then
	printf '  - %s\n' "${FAILED[@]}"
	exit 1
fi
