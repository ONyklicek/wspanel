# shellcheck shell=bash
# The cluster the scenarios run on: two Debian 13 servers installed by
# provision/install.sh (each with a panel domain of its own) and a third
# machine that stands in for the Windows DNS server, the witness and a
# client in the LAN. Sourced by run.sh.

# The installation is the slow part (two installers, ~15 minutes): with
# --reuse the installed containers are kept as images keyed by what went
# into them (the agent binary, provision/ and panel/), and later runs start
# from those.
image_key() {
	{
		shasum -a 256 "$ROOT/build/panel-agent-linux-$ARCH" | cut -d' ' -f1
		git -C "$ROOT" ls-files -s provision panel dev/Dockerfile
		git -C "$ROOT" diff HEAD -- provision panel dev/Dockerfile
		git -C "$ROOT" ls-files --others --exclude-standard provision panel | while read -r f; do shasum "$ROOT/$f"; done
	} | shasum -a 256 | cut -c1-16
}

remove_cluster() {
	docker rm -f "$P" "$S" "$D" >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
}

run_node() { # run_node NAME IP IMAGE
	docker run -d --name "$1" --hostname "$1" --network "$NET" --ip "$2" --privileged --cgroupns=host \
		--tmpfs /run --tmpfs /run/lock --tmpfs /tmp -v /sys/fs/cgroup:/sys/fs/cgroup:rw -v "$ROOT:/opt/wspanel:ro" \
		"$3" >/dev/null
}

install_node() { # install_node NAME: each node its own panel domain
	docker exec "$1" /opt/wspanel/provision/install.sh --db=mariadb,postgres --php=8.4 --panel-domain="$1.firma.local" \
		--agent-bin=/opt/wspanel/build/panel-agent-linux-"$ARCH" --panel-source=/opt/wspanel/panel --local-certs \
		--mail=capture --allow-unenforced-socket-bind >"$ARTIFACTS/$1-install.log" 2>&1
}

prepare_windns() {
	docker exec "$D" bash -c 'apt-get update -q >/dev/null && apt-get install -y -q openssh-server jq curl iproute2 iputils-ping >/dev/null'
}

setup_cluster() {
	begin_scenario setup "containers, installation, Windows DNS stand-in"
	docker build -q -t "$IMAGE" -f "$ROOT/dev/Dockerfile" "$ROOT/dev" >/dev/null || die "image"
	remove_cluster
	docker network create --subnet "$SUBNET" "$NET" >/dev/null || die "network"
	local key="" pimg="$IMAGE" simg="$IMAGE" dimg="debian:trixie" fresh=1
	if ((REUSE)); then
		key=$(image_key)
		if docker image inspect "wsm-installed:$key-primary" >/dev/null 2>&1 && docker image inspect "wsm-installed:$key-standby" >/dev/null 2>&1 &&
			docker image inspect "wsm-installed:$key-windns" >/dev/null 2>&1; then
			pimg="wsm-installed:$key-primary" simg="wsm-installed:$key-standby" dimg="wsm-installed:$key-windns" fresh=0
			log "reusing the installed servers ($key)"
		fi
	fi
	run_node "$P" "$PIP" "$pimg" || die "container $P"
	run_node "$S" "$SIP" "$simg" || die "container $S"
	docker run -d --name "$D" --hostname dc1 --network "$NET" --ip "$DIP" --cap-add NET_ADMIN "$dimg" sleep infinity >/dev/null || die "windns container"
	ok "primary $PIP, standby $SIP, Windows DNS stand-in $DIP, floating IP $VIP"
	if ((fresh)); then
		install_node "$P" &
		local ip=$!
		install_node "$S" &
		local is=$!
		prepare_windns &
		local id=$!
		wait $ip && ok "primary installed" || { tail -30 "$ARTIFACTS/$P-install.log"; die "primary install failed"; }
		wait $is && ok "standby installed" || { tail -30 "$ARTIFACTS/$S-install.log"; die "standby install failed"; }
		wait $id && ok "Windows DNS stand-in ready" || die "windns"
		for c in "$P" "$S"; do docker exec "$c" bash -c 'command -v jq >/dev/null || apt-get install -y -q jq >/dev/null'; done
		if ((REUSE)); then
			log "keeping the installed servers as images ($key)"
			# One set only: older ones (another agent, provision/, panel/) go.
			docker images --format '{{.Repository}}:{{.Tag}}' wsm-installed | grep -v ":$key-" | xargs -r docker rmi >/dev/null 2>&1 || true
			for c in "$P:primary" "$S:standby" "$D:windns"; do
				docker stop "${c%%:*}" >/dev/null
				docker commit "${c%%:*}" "wsm-installed:$key-${c#*:}" >/dev/null
				docker start "${c%%:*}" >/dev/null
			done
			wait_until 180 docker exec "$P" php /opt/wspanel/dev/agent-call.php agent.ping || die "primary agent after restart"
			wait_until 180 docker exec "$S" php /opt/wspanel/dev/agent-call.php agent.ping || die "standby agent after restart"
		fi
	else
		wait_until 240 docker exec "$P" php /opt/wspanel/dev/agent-call.php agent.ping && ok "primary up" || die "primary agent"
		wait_until 240 docker exec "$S" php /opt/wspanel/dev/agent-call.php agent.ping && ok "standby up" || die "standby agent"
	fi
	start_windns
	end_scenario
}

# The stand-in speaks wspanel-dns.ps1's protocol behind sshd's ForceCommand.
start_windns() {
	docker cp "$ROOT/dev/fake-windns/fake-dns.sh" "$D:/usr/local/bin/fake-dns"
	docker exec "$D" bash -c 'useradd -m -s /bin/bash wspanel-dns 2>/dev/null; install -d -o wspanel-dns /var/lib/fake-dns
  touch /etc/wspanel-dns.keys
  cat > /etc/ssh/sshd_config.d/wspanel.conf <<EOF
Match User wspanel-dns
    AuthorizedKeysFile /etc/wspanel-dns.keys
    ForceCommand /usr/local/bin/fake-dns
    AllowTcpForwarding no
    PermitTTY no
EOF
  mkdir -p /run/sshd && ssh-keygen -A >/dev/null && (pgrep -x sshd >/dev/null || /usr/sbin/sshd)'
}

# The sites' data the scenarios watch: files, MariaDB, PostgreSQL, Valkey.
seed_data() {
	agent "$P" site.create '{"domain":"shop.firma.local","framework":"laravel","php_version":"8.4"}' >/dev/null && ok "site shop.firma.local" || bad "site shop.firma.local"
	agent "$P" site.create '{"domain":"old.firma.local","framework":"laravel","php_version":"8.4"}' >/dev/null && ok "site old.firma.local (deleted later)" || bad "site old.firma.local"
	agent "$P" db.create '{"engine":"mariadb","name":"shop","domain":"shop.firma.local"}' >/dev/null && ok "MariaDB database" || bad "MariaDB database"
	agent "$P" db.create '{"engine":"postgresql","name":"shoppg"}' >/dev/null && ok "PostgreSQL database" || bad "PostgreSQL database"
	agent "$P" valkey.set '{"domain":"shop.firma.local","enabled":true,"maxmemory_mb":32,"persist":true}' >/dev/null && ok "Valkey" || bad "Valkey"
	sql "$P" "CREATE TABLE t (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(40)); INSERT INTO t (v) VALUES ('seed');"
	psql_shop "$P" "CREATE TABLE t (id serial primary key, v text); INSERT INTO t (v) VALUES ('seed');" >/dev/null
	VK_PW=$(docker exec "$P" sed -n 's/^requirepass //p' /etc/panel-sites/valkey-auth/web_shop_firma_local.conf)
	valkey "$P" valkey SET greeting before >/dev/null
	docker exec "$P" bash -c "echo hello-from-primary | runuser -u web_shop_firma_local -- tee /srv/sites/shop.firma.local/current/public/index.html" >/dev/null
	docker exec "$P" bash -c "echo old-site | runuser -u web_old_firma_local -- tee /srv/sites/old.firma.local/current/public/index.html" >/dev/null
}

# valkey NODE SOCKETKIND ARGS...: the shop site's Valkey (kind: valkey = the
# primary's server, kv = the standby's replica).
valkey() {
	local node=$1 kind=$2 sock
	shift 2
	if [[ $kind == kv ]]; then sock=/run/wspanel-mirror-kv/web_shop_firma_local/kv.sock; else sock=/run/wspanel-valkey/web_shop_firma_local/valkey.sock; fi
	docker exec "$node" bash -c "echo '$VK_PW' | runuser -u web_shop_firma_local -- valkey-cli --askpass --no-auth-warning -s $sock $*"
}

valkey_is() { [[ $(valkey "$1" "$2" GET "$3") == "$4" ]]; } # valkey_is NODE KIND KEY VALUE
pg_has_row() { [[ $(psql_shop "$1" "SELECT count(*) FROM t WHERE v = '$2'") -ge 1 ]]; }
lacks_row() { [[ $(sql "$1" "SELECT count(*) FROM t WHERE v = '$2'") == 0 ]]; }

# A write on whichever node serves; the scenarios count rows, not ids.
write_row() { # write_row NODE TEXT
	sql "$1" "INSERT INTO t (v) VALUES ('$2');"
	psql_shop "$1" "INSERT INTO t (v) VALUES ('$2');" >/dev/null
}
rows() { sql "$1" "SELECT count(*) FROM t"; }
# app_write NODE TEXT: one row as the site's own database account (as its
# application writes; root would pass a read-only server, this does not).
app_write() {
	docker exec "$1" bash -c "envv() { sed -n \"s/^\$1=\\\"\\{0,1\\}\\([^\\\"]*\\)\\\"\\{0,1\\}\$/\\1/p\" /srv/sites/shop.firma.local/shared/.env; }
		mariadb -h \"\$(envv DB_HOST)\" -P \"\$(envv DB_PORT)\" -u \"\$(envv DB_USERNAME)\" -p\"\$(envv DB_PASSWORD)\" shop -e \"INSERT INTO t (v) VALUES ('$2')\""
}
has_row() { [[ $(sql "$1" "SELECT count(*) FROM t WHERE v = '$2'") -ge 1 ]]; }

# ---------------------------------------------------------------- the baseline

# The state every scenario starts from: the primary serves and holds the
# floating IP, the standby is in sync, both agree on their settings, no
# e2e firewall rule, every machine and agent up.
baseline() {
	local c
	for c in "$P" "$S"; do
		running "$c" || docker start "$c" >/dev/null
	done
	for c in "$P" "$S"; do
		docker network inspect "$NET" -f '{{range .Containers}}{{.Name}} {{end}}' | grep -qw "$c" ||
			docker network connect --ip "$([[ $c == "$P" ]] && echo "$PIP" || echo "$SIP")" "$NET" "$c"
	done
	unblock_all
	docker exec "$D" bash -c 'pgrep -x sshd >/dev/null || /usr/sbin/sshd' || true
	docker exec "$D" bash -c "ip addr del $VIP/32 dev eth0 2>/dev/null; ip addr del $VIP/24 dev eth0 2>/dev/null" || true
	for c in "$P" "$S"; do
		wait_until 240 docker exec "$c" php /opt/wspanel/dev/agent-call.php agent.ping || die "the agent of $c does not answer"
	done
	restore_agent_binary
	local pr sr
	pr=$(role "$P")
	sr=$(role "$S")
	log "roles before the baseline: $P $pr, $S $sr"
	if [[ $pr == fenced && $sr == primary ]]; then
		agent "$P" mirror.rejoin >/dev/null || die "rejoin of $P"
		wait_synced "$P"
		agent "$S" mirror.switchover >/dev/null || die "switchover back to $P"
	elif [[ $pr == primary && $sr == fenced ]]; then
		agent "$S" mirror.rejoin >/dev/null || die "rejoin of $S"
	elif [[ $pr == fenced && $sr == standby ]]; then
		agent "$P" mirror.unfence >/dev/null || die "unfence of $P"
	elif [[ $pr == standby && $sr == primary ]]; then
		wait_synced "$P"
		agent "$S" mirror.switchover >/dev/null || die "switchover back to $P"
	fi
	is_role "$P" primary && is_role "$S" standby || die "baseline roles: $P $(role "$P"), $S $(role "$S")"
	for c in "$P" "$S"; do
		agent "$c" mirror.settings "{\"witness\":[\"$DIP:22\"],\"failover_after\":$FAILOVER,\"sync_every\":30,\"auto_failover\":true}" >/dev/null || die "settings of $c"
	done
	wait_until 60 holds "$P" || die "the primary does not hold the floating IP"
	# The serving node's databases take writes (root would write anyway:
	# MariaDB's read_only does not bind it, so ask the variables).
	[[ $(docker exec "$P" mariadb -N -e 'SELECT @@read_only') == 0 ]] ||
		die "the primary's MariaDB is read-only"
	[[ $(docker exec "$P" runuser -u postgres -- psql -tAq -c 'SHOW default_transaction_read_only') == off ]] ||
		die "the primary's PostgreSQL is read-only"
	wait_synced "$S"
	log "baseline: $P primary with $VIP, $S standby in sync"
}

# wait_synced STANDBY: a sync cycle that started after now finished, and
# every layer is in order (a layer's status may be older than the change).
wait_synced() {
	local t0 l
	t0=$(docker exec "$1" date -u +%s)
	agent "$1" mirror.sync_now >/dev/null 2>&1 || true
	wait_until 600 synced_since "$1" "$t0" || die "no sync cycle on $1 since $(date -u -r "$t0" 2>/dev/null || echo "$t0")"
	for l in state sites mariadb postgresql; do
		wait_until 300 layer_ok "$1" "$l" || die "layer $l of $1: $(mget "$1" ".status.layers[\"$l\"]")"
	done
}
synced_since() { # synced_since NODE EPOCH: the last finished cycle is newer
	local last
	last=$(mget "$1" '(.status.last_sync // "1970-01-01T00:00:00Z") | sub("\\.[0-9]+"; "") | fromdateiso8601')
	[[ $(mget "$1" '.status.syncing') == false && ${last%.*} -ge $2 ]]
}

# A second agent build (another version) for the version scenario.
OTHER_AGENT="$ROOT/build/panel-agent-linux-$ARCH-other"
restore_agent_binary() {
	local c
	for c in "$P" "$S"; do
		if docker exec "$c" test -f /usr/sbin/panel-agent.e2e-orig; then
			docker exec "$c" bash -c 'mv /usr/sbin/panel-agent.e2e-orig /usr/sbin/panel-agent && systemctl restart panel-agent'
			wait_until 120 docker exec "$c" php /opt/wspanel/dev/agent-call.php agent.ping || die "agent of $c"
		fi
	done
}

# ---------------------------------------------------------------- writes under load

# A writer on a node: every 100 ms one row into MariaDB and one into
# PostgreSQL, tagged TAG-<n>; a row is acknowledged (written to
# /root/acked-TAG, on disk: it survives the node stopping) only when both
# databases committed it. Stops by itself after SECS, or with stop_writer.
# The site's own database account writes, as its application would (the
# read-only switch binds it; root it does not, see root-write-on-standby).
start_writer() { # start_writer NODE TAG SECS
	docker exec "$1" bash -c "rm -f /root/acked-$2 /root/stop-$2"
	docker exec -d "$1" bash -c "envv() { sed -n \"s/^\$1=\\\"\\{0,1\\}\\([^\\\"]*\\)\\\"\\{0,1\\}\$/\\1/p\" /srv/sites/shop.firma.local/shared/.env; }
		DB_HOST=\$(envv DB_HOST) DB_PORT=\$(envv DB_PORT) DB_USERNAME=\$(envv DB_USERNAME) DB_PASSWORD=\$(envv DB_PASSWORD)
		end=\$((SECONDS + $3)); i=0
		while (( SECONDS < end )) && [[ ! -e /root/stop-$2 ]]; do
			i=\$((i + 1))
			if mariadb -h \"\${DB_HOST:-127.0.0.1}\" -P \"\${DB_PORT:-3306}\" -u \"\$DB_USERNAME\" -p\"\$DB_PASSWORD\" shop -e \"INSERT INTO t (v) VALUES ('$2-\$i')\" 2>/dev/null &&
				runuser -u postgres -- psql -q -d shoppg -c \"INSERT INTO t (v) VALUES ('$2-\$i')\" >/dev/null 2>&1; then
				echo \$i >> /root/acked-$2; sync /root/acked-$2
			fi
			sleep 0.1
		done"
}
stop_writer() { docker exec "$1" touch "/root/stop-$2" 2>/dev/null || true; sleep 1; }
acked() { docker exec "$1" bash -c "wc -l < /root/acked-$2 2>/dev/null || echo 0"; } # acked NODE TAG
# lost_rows WRITER TAG READER: acknowledged on WRITER, missing on READER.
lost_rows() {
	local have
	have=$(docker exec "$3" mariadb -N shop -e "SELECT REPLACE(v, '$2-', '') FROM t WHERE v LIKE '$2-%'" | sort)
	docker exec "$1" cat "/root/acked-$2" 2>/dev/null | sort | comm -23 - <(printf '%s\n' "$have") | grep -c . || true
}
pg_lost_rows() {
	local have
	have=$(docker exec "$3" runuser -u postgres -- psql -tAq -d shoppg -c "SELECT replace(v, '$2-', '') FROM t WHERE v LIKE '$2-%'" | sort)
	docker exec "$1" cat "/root/acked-$2" 2>/dev/null | sort | comm -23 - <(printf '%s\n' "$have") | grep -c . || true
}

# ---------------------------------------------------------------- the copy is the same

# Checksums of what the mirror copies, per node: the shop table in both
# databases, the sites' files (without the excluded tmp/ and valkey/, the
# trash and logs), the Valkey key.
digest_mariadb() { docker exec "$1" mariadb -N shop -e "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT(id, ':', v))), 0) FROM t"; }
digest_pg() { docker exec "$1" runuser -u postgres -- psql -tAq -d shoppg -c "SELECT count(*), coalesce(sum(hashtext(id::text || ':' || v)), 0) FROM t"; }
digest_files() {
	docker exec "$1" bash -c 'cd /srv/sites && find . \( -path ./.trash -o -path "*/tmp" -o -path "*/valkey" -o -path "*/storage/logs" -o -path "*/storage/framework" \) -prune -o -type f -print0 |
		sort -z | xargs -0 -r sha256sum | sha256sum | cut -c1-16'
}
# Owners and modes of the sites' top levels (without what is in valkey/ and
# tmp/: each node's own; the folders themselves count); kept for a diff.
digest_owners() {
	local list="$ARTIFACTS/owners-$SCENARIO-$1.txt"
	docker exec "$1" bash -c 'cd /srv/sites && find . -maxdepth 3 \( -path ./.trash -prune \) -o -printf "%P %u %g %m\n" | grep -v -e "/valkey/" -e "/tmp/" | sort' >"$list"
	shasum -a 256 <"$list" | cut -c1-16
}
# assert_consistent SERVING STANDBY: after a sync, the copy equals the original.
assert_consistent() {
	expect "MariaDB: the same rows on both (count, checksum)" "$(digest_mariadb "$2")" "$(digest_mariadb "$1")"
	expect "PostgreSQL: the same rows on both (count, checksum)" "$(digest_pg "$2")" "$(digest_pg "$1")"
	expect "the sites' files: identical trees" "$(digest_files "$2")" "$(digest_files "$1")"
	local a b
	a=$(digest_owners "$1")
	b=$(digest_owners "$2")
	if [[ $a == "$b" ]]; then
		ok "the sites' owners and modes: identical"
	else
		bad "the sites' owners and modes: identical" "$(diff "$ARTIFACTS/owners-$SCENARIO-$1.txt" "$ARTIFACTS/owners-$SCENARIO-$2.txt" | head -12 | tr '\n' ';')"
	fi
}
