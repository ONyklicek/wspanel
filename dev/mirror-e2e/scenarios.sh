# shellcheck shell=bash disable=SC2016
# The scenarios. Each starts from the baseline (cluster.sh) and leaves the
# cluster in whatever state it tested; the next one restores the baseline.
# pairing and replication always run first: every other scenario needs a
# paired mirror with data.
#
# macOS runs this with bash 3.2: there "$(cmd "$x" "{a,b}")" brace-expands
# the JSON into separate words. Take such output into a variable first
# (o=$(cmd ...); contains NAME "$o" TEXT), as below.

CORE=(pairing replication)
# A code in the right shape (it decodes) that no primary issued.
WELLFORMED_CODE=WSPM1-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA-AAAAAAAAAAAAAAAAAAAAAAAA
SCENARIOS=(
	wrong-role guard floating-ip windns settings source-guard
	partition witness-down standby-down flapping agent-restart reboot
	switchover-under-load failover-under-load failover-and-back fenced-reboot power-cut isolation promotion-race
	switchover-incomplete switchover-cycles root-write-on-standby site-domains deleted-site game-servers version unpair
)

describe() {
	case $1 in
	pairing) echo "pairing code, refusals, the mirror port open for the standby only" ;;
	replication) echo "every layer copied: files, MariaDB, PostgreSQL, Valkey; deletes follow" ;;
	wrong-role) echo "every change of role refused where it does not apply, and nothing changes" ;;
	guard) echo "the standby refuses every change of the service, allows the node's own" ;;
	floating-ip) echo "who holds the address, served through it, conflicts, validation" ;;
	windns) echo "Windows DNS points at the floating IP and keeps away from other names" ;;
	settings) echo "both nodes must agree (failover time, floating IP, version)" ;;
	source-guard) echo "an emptied folder on the primary never empties the copy" ;;
	partition) echo "nodes cut apart while both see the witness: no takeover" ;;
	witness-down) echo "the primary down and no witness: no takeover" ;;
	standby-down) echo "the standby fails: the primary serves on without a break" ;;
	flapping) echo "a primary link that drops for seconds, again and again: no change of role" ;;
	agent-restart) echo "an agent restart neither moves the floating IP nor the role" ;;
	reboot) echo "a quick reboot of the primary: it serves again, no takeover" ;;
	switchover-under-load) echo "planned switchovers both ways while writing: nothing acknowledged is lost" ;;
	failover-under-load) echo "the primary fails while writing: how much is lost, within bounds" ;;
	fenced-reboot) echo "a fenced node rebooted stays fenced: no sites, read-only, no floating IP" ;;
	power-cut) echo "both nodes lose power, only the primary comes back: it serves after the failover time" ;;
	switchover-cycles) echo "five switchovers in a row, the copy identical after each" ;;
	site-domains) echo "one Laravel site on two domains: the app routes by the domain it was called on, also after a switchover" ;;
	root-write-on-standby) echo "a root session writes on the standby's replica: detected, seeded again, kept in a dump" ;;
	failover-and-back) echo "the primary fails, the standby takes over, the old one returns, rejoins, switch back" ;;
	isolation) echo "the primary loses its network: it fences itself, the standby takes over" ;;
	promotion-race) echo "the old primary comes back in the middle of a promotion" ;;
	switchover-incomplete) echo "a switchover the standby never completes: the primary serves again" ;;
	deleted-site) echo "a site deleted on the other node does not come back" ;;
	game-servers) echo "game servers stay on their node across switchovers" ;;
	version) echo "another WSPanel version blocks the automatic takeover" ;;
	unpair) echo "leaving the mirror: replication stops, the floating IP goes" ;;
	esac
}

# ---------------------------------------------------------------- core

scenario_pairing() {
	local code out
	code=$(agent "$P" mirror.init "{\"address\":\"$PIP\",\"peer_address\":\"$SIP\"}" | jqr "$P" .code)
	contains "the primary shows a pairing code" "$code" "WSPM1-"
	o=$(agent_out "$S" mirror.join "{\"primary\":\"$PIP\",\"code\":\"WSPM1-AAAA-BBBB\",\"address\":\"$SIP\"}")
	contains "a damaged code is refused" "$o" "damaged"
	o=$(agent_out "$S" mirror.join "{\"primary\":\"$PIP\",\"code\":\"$WELLFORMED_CODE\",\"address\":\"$SIP\"}")
	lacks "a well-formed code of another primary is refused" "$o" '"role":"standby"'
	expect "the refused standby stays outside a mirror" "$(role "$S")" ""
	expect "and the primary still waits for its standby" "$(mget "$P" '.peer // "none"')" none
	out=$(agent "$S" mirror.join "{\"primary\":\"$PIP\",\"code\":\"$code\",\"address\":\"$SIP\"}")
	contains "the standby pairs" "$out" '"role":"standby"'
	o=$(agent_out "$S" mirror.join "{\"primary\":\"$PIP\",\"code\":\"$code\",\"address\":\"$SIP\"}")
	contains "the code is used up" "$o" "already in a mirror"
	eventually 90 "the primary sees the standby" mget_is "$P" '.status.peer.role' standby
	expect "the mirror port is open only for the standby" "$(on "$P" ufw status | grep -c "7443/tcp.*ALLOW.*$SIP")" 1
	expect "and not for anyone else" "$(on "$P" ufw status | grep "7443/tcp" | grep -vc "$SIP")" 0
	# A client without the mirror's certificate gets no answer from the port.
	never 6 "a client without the mirror's certificate is refused" docker exec "$S" bash -c "curl -sk --max-time 3 https://$PIP:7443/ | grep -q ."
	for c in "$P" "$S"; do
		agent "$c" mirror.settings "{\"witness\":[\"$DIP:22\"],\"failover_after\":$FAILOVER,\"sync_every\":30,\"auto_failover\":true}" >/dev/null &&
			ok "$c: witness $DIP:22, automatic failover after ${FAILOVER}s" || bad "$c: settings"
		contains "$c: floating IP set" "$(agent_out "$c" mirror.vip_set "{\"address\":\"$VIP\"}")" "\"address\":\"$VIP\""
	done
	eventually 30 "the primary holds the floating IP" holds "$P"
	expect "each node keeps its own panel" "$(mget "$S" .status.peer.panel_url)" "https://$P.firma.local"
}

scenario_replication() {
	seed_data
	agent "$S" mirror.sync_now >/dev/null
	local l
	for l in version state accounts sites mariadb postgresql kv vip settings; do
		eventually 600 "layer $l in order" layer_ok "$S" "$l"
	done
	eventually 600 "the site's files arrived" docker exec "$S" test -f /srv/sites/shop.firma.local/current/public/index.html
	eventually 300 "the site's Valkey replica runs" docker exec "$S" systemctl is-active --quiet wspanel-mirror-kv-web_shop_firma_local.service
	expect "site user with the primary's uid" "$(on "$S" id -u web_shop_firma_local)" "$(on "$P" id -u web_shop_firma_local)"
	expect "site files copied" "$(on "$S" cat /srv/sites/shop.firma.local/current/public/index.html 2>/dev/null)" hello-from-primary

	write_row "$P" replicated
	eventually 30 "MariaDB replicates within seconds" has_row "$S" replicated
	eventually 30 "PostgreSQL replicates within seconds" pg_has_row "$S" replicated
	sql "$P" "UPDATE t SET v = 'updated' WHERE v = 'seed'"
	eventually 30 "an update replicates" has_row "$S" updated
	sql "$P" "DELETE FROM t WHERE v = 'updated'"
	eventually 30 "a delete replicates" lacks_row "$S" updated
	expect "the MariaDB replica is read-only" "$(on "$S" mariadb -N -e 'SELECT @@read_only')" 1
	expect "PostgreSQL is a hot standby" "$(on "$S" runuser -u postgres -- psql -tAq -c 'SELECT pg_is_in_recovery()')" t
	eventually 30 "Valkey replicates" valkey_is "$S" kv greeting before

	# Files: a change and a delete follow on the next cycle.
	docker exec "$P" bash -c "echo v2 | runuser -u web_shop_firma_local -- tee /srv/sites/shop.firma.local/current/public/v.txt" >/dev/null
	agent "$S" mirror.sync_now >/dev/null
	eventually 180 "a new file follows" docker exec "$S" grep -qx v2 /srv/sites/shop.firma.local/current/public/v.txt
	docker exec "$P" rm /srv/sites/shop.firma.local/current/public/v.txt
	agent "$S" mirror.sync_now >/dev/null
	eventually 180 "a deleted file goes" absent "$S" /srv/sites/shop.firma.local/current/public/v.txt

	expect "the standby serves no sites" "$(on "$S" bash -c 'ls /etc/panel-proxy/sites/*.caddy 2>/dev/null | wc -l')" 0
	expect "a tunnel refuses other users" "$(on "$S" runuser -u nobody -- bash -c 'exec 3<>/dev/tcp/127.0.0.1/17306 && echo open' 2>/dev/null)" ""
	lacks "the standby lists no game server of the primary's" "$(agent "$S" game.list 2>/dev/null)" '"id"'
	wait_synced "$S"
	assert_consistent "$P" "$S"
}

# ---------------------------------------------------------------- roles

scenario_wrong_role() {
	local pid sid
	pid=$(mget "$P" .node_id)
	sid=$(mget "$S" .node_id)
	contains "the primary cannot be promoted" "$(agent_out "$P" mirror.promote "{\"confirm\":\"$pid\"}")" "only a standby"
	contains "a switchover starts on the primary, not the standby" "$(agent_out "$S" mirror.switchover)" "starts on the primary"
	contains "the primary cannot rejoin" "$(agent_out "$P" mirror.rejoin)" "only a fenced node"
	contains "a serving node cannot be unfenced" "$(agent_out "$P" mirror.unfence)" "not fenced"
	o=$(agent_out "$P" mirror.init "{\"address\":\"$PIP\",\"peer_address\":\"$SIP\"}")
	contains "a paired node cannot pair again (init)" "$o" "already in a mirror"
	o=$(agent_out "$S" mirror.join "{\"primary\":\"$PIP\",\"code\":\"$WELLFORMED_CODE\",\"address\":\"$SIP\"}")
	contains "a paired node cannot join another" "$o" "already in a mirror"
	contains "a promotion needs the node's own id" "$(agent_out "$S" mirror.promote "{\"confirm\":\"$pid\"}")" "this node's id"
	contains "leaving needs the node's own id" "$(agent_out "$P" mirror.unpair "{\"confirm\":\"$sid\"}")" "this node's id"
	contains "a pairing code exists only while waiting for a standby" "$(agent_out "$P" mirror.pairing_code)" "waiting for its standby"
	expect "nothing changed: the primary" "$(role "$P")" primary
	expect "nothing changed: the standby" "$(role "$S")" standby
	check "the floating IP did not move" holds "$P"
}

# ---------------------------------------------------------------- the standby

scenario_guard() {
	local op params
	while IFS='|' read -r op params; do
		contains "refused on the standby: $op" "$(agent_out "$S" "$op" "$params")" "managed on the serving server"
	done <<'EOF'
site.create|{"domain":"new.firma.local","framework":"laravel","php_version":"8.4"}
site.delete|{"domain":"shop.firma.local","confirm":"shop.firma.local"}
site.disable|{"domain":"shop.firma.local"}
db.create|{"engine":"mariadb","name":"other"}
db.drop|{"engine":"mariadb","name":"shop","confirm":"shop"}
db.password|{"engine":"mariadb","name":"shop","password":"Abcdefgh12345678"}
dns.zone_create|{"zone":"example.org"}
client.create|{"name":"x"}
cron.add|{"domain":"shop.firma.local","schedule":"* * * * *","command":"true"}
deploy.run|{"domain":"shop.firma.local"}
server.backup|{}
EOF
	o=$(agent_out "$S" site.create '{"domain":"x.firma.local","framework":"laravel","php_version":"8.4"}')
	contains "the refusal names the primary's panel" "$o" "https://$P.firma.local"
	expect "the replicated database is still there" "$(on "$S" mariadb -N -e "SHOW DATABASES LIKE 'shop'")" shop
	check "the site is still there" docker exec "$S" test -f /srv/sites/shop.firma.local/current/public/index.html
	check "reading works on the standby (mirror.status)" agent "$S" mirror.status
	check "reading works on the standby (db.list)" agent "$S" db.list '{"engine":"mariadb"}'
	check "the node itself is managed (sys.resources)" agent "$S" sys.resources
	contains "the primary is unaffected (site.health)" "$(agent_out "$P" site.health '{"domain":"shop.firma.local"}')" "shop.firma.local"
	eventually 120 "replication still runs" layer_ok "$S" mariadb
}

# ---------------------------------------------------------------- the floating IP

scenario_floating_ip() {
	for bad_addr in "$DIP:in use by another device" "172.30.42.0:the network's or the broadcast" "172.30.42.255:the network's or the broadcast" \
		"$PIP:this server's own address" "$SIP:the other server's address" "10.9.9.9:in none of this server's networks"; do
		contains "refused: ${bad_addr%%:*}" "$(agent_out "$P" mirror.vip_set "{\"address\":\"${bad_addr%%:*}\"}")" "${bad_addr#*:}"
	done
	expect "the setting is unchanged after refusals" "$(mget "$P" .vip.address)" "$VIP"
	expect "held as a host address of its own" "$(on "$P" ip -4 -o addr show | grep " $VIP/" | grep -o "$VIP/[0-9]*")" "$VIP/32"
	expect "the primary's own address stays its primary one" "$(on "$P" ip -4 -o addr show eth0 | head -1 | grep -o "$PIP")" "$PIP"
	check "the standby does not hold it" not_holds "$S"
	expect "exactly one machine holds it" "$(holders)" 1
	eventually 30 "the site answers on the floating IP from a third machine" served_at "$VIP"
	contains "the standby sees who holds it" "$(mget "$S" .status.peer.vip)" "$VIP"
	# Traffic to the standby keeps working on its own address (the mirror).
	eventually 60 "the mirror link is unaffected" layer_ok "$S" state

	# A stranger takes the address: the holder reports it, keeps it, announces again.
	docker exec "$D" ip addr add "$VIP/32" dev eth0
	eventually 90 "the holder reports the conflict" mget_has "$P" '[.status.events[].message]|join(" ")' 'konflikt adres'
	expect "a stranger does not make it step down" "$(role "$P")" primary
	check "it keeps the address" holds "$P"
	docker exec "$D" ip addr del "$VIP/32" dev eth0
	eventually 60 "the site answers on the floating IP again" served_at "$VIP"

	# The ARP caches learn the holder: the third machine's entry is the primary's MAC.
	local pmac
	pmac=$(on "$P" cat /sys/class/net/eth0/address)
	docker exec "$D" ping -c1 -W2 "$VIP" >/dev/null 2>&1 || true
	contains "a LAN client resolves it to the primary's MAC" "$(on "$D" ip neigh show "$VIP")" "$pmac"
}

scenario_windns() {
	local c pub
	for c in "$P" "$S"; do
		pub=$(agent "$c" mirror.windns_set "{\"host\":\"$DIP\",\"user\":\"wspanel-dns\",\"zones\":[\"firma.local\"],\"extra\":[\"www.firma.local\"]}" | jqr "$c" .public_key)
		contains "$c: a key of its own" "$pub" "ssh-ed25519"
		docker exec -i "$D" bash -c "grep -qxF '$pub' /etc/wspanel-dns.keys || echo '$pub' >> /etc/wspanel-dns.keys"
		contains "$c trusts the Windows host key" "$(agent "$c" mirror.windns_trust)" "ssh-ed25519"
	done
	contains "the connection works" "$(agent "$P" mirror.windns_test)" '"zone":"firma.local"'
	# Records nobody asked about stay as they are.
	docker exec "$D" bash -c 'f=/var/lib/fake-dns/firma.local.json; [[ -s $f ]] || echo "{}" > $f; jq ".\"dc1/A\"={ttl:3600,values:[\"172.30.42.5\"]}" $f > $f.t && mv $f.t $f'
	eventually 120 "the sites point at the floating IP" dns_is shop "$VIP"
	eventually 60 "so do the extra names" dns_is www "$VIP"
	expect "the panels' own names are not touched" "$(dns_a "$P")$(dns_a "$S")" ""
	expect "other records stay" "$(dns_a dc1)" "$DIP"
	eventually 60 "the layer reports it" layer_ok "$P" windns
	expect "the standby writes nothing" "$(mget "$S" '.status.layers.windns.detail // ""')" ""
}

scenario_settings() {
	eventually 60 "in agreement at first" layer_ok "$S" settings
	agent "$S" mirror.settings '{"failover_after":45}' >/dev/null
	eventually 60 "a different failover time is reported" mget_has "$S" '.status.layers.settings.error // ""' 'doba přepnutí'
	eventually 60 "on the primary too" mget_has "$P" '.status.layers.settings.error // ""' 'doba přepnutí'
	agent "$S" mirror.settings "{\"failover_after\":$FAILOVER}" >/dev/null
	agent "$S" mirror.vip_remove >/dev/null
	eventually 60 "a missing floating IP is reported" mget_has "$S" '.status.layers.settings.error // ""' 'plovoucí IP'
	agent "$S" mirror.vip_set "{\"address\":\"$VIP\"}" >/dev/null
	eventually 60 "in agreement again" layer_ok "$S" settings
	contains "the last witness cannot go while automatic failover is on" "$(agent_out "$P" mirror.settings '{"witness":[]}')" "needs a witness"
	expect "the refused change changed nothing" "$(mget "$P" '.witness | length')" 1
}

# ---------------------------------------------------------------- protecting the copy

scenario_source_guard() {
	# The primary's site folder disappears (a mount that did not come up).
	docker exec "$P" bash -c 'mkdir -p /srv/off && mv /srv/sites/shop.firma.local /srv/sites/old.firma.local /srv/off/'
	agent "$S" mirror.sync_now >/dev/null
	eventually 180 "the standby refuses that copy" mget_has "$S" '.status.layers.sites.error // ""' 'unmounted or wiped'
	check "and keeps its copy of the site" docker exec "$S" test -f /srv/sites/shop.firma.local/current/public/index.html
	check "of both sites" docker exec "$S" test -f /srv/sites/old.firma.local/current/public/index.html
	docker exec "$P" bash -c 'mv /srv/off/* /srv/sites/ && rmdir /srv/off'
	agent "$S" mirror.sync_now >/dev/null
	eventually 180 "back in order once the folder is back" layer_ok "$S" sites
	check "nothing was lost" docker exec "$S" test -f /srv/sites/shop.firma.local/current/public/index.html
}

# ---------------------------------------------------------------- not an outage

scenario_partition() {
	# The primary drops every IP packet from the standby, its host still
	# answers ARP: alive, only unreachable (a firewall, a broken path).
	block "$P" INPUT -s "$SIP" -j DROP
	never $((FAILOVER * 2 + 20)) "the standby does not take over" is_role "$S" primary
	expect "the primary still serves" "$(role "$P")" primary
	contains "the standby says why" "$(events "$S")" "odpovídá (ARP"
	check "the floating IP stays on the primary" holds "$P"
	expect "one holder" "$(holders)" 1
	check "the site is served all along" served_at "$VIP"
	unblock_all
	eventually 90 "the link comes back, nothing changed" mget_is "$S" '.status.peer.role' primary
	expect "the standby is still the standby" "$(role "$S")" standby
}

scenario_witness_down() {
	docker exec "$D" pkill -x sshd || true
	docker stop "$P" >/dev/null
	never $((FAILOVER * 2 + 20)) "no takeover without a witness" is_role "$S" primary
	contains "the standby says it may be the one cut off" "$(events "$S")" "ani žádný svědek"
	check "the standby does not hold the floating IP" not_holds "$S"
	docker exec "$D" /usr/sbin/sshd
	docker start "$P" >/dev/null
	eventually 240 "the primary is back" docker exec "$P" php /opt/wspanel/dev/agent-call.php agent.ping
	eventually 120 "and serves again" served_at "$VIP"
	expect "it is still the primary" "$(role "$P")" primary
	eventually 30 "the site's application can write" app_write "$P" after-witness-down
}

scenario_agent_restart() {
	docker exec "$P" systemctl restart panel-agent
	eventually 60 "the agent is back" docker exec "$P" php /opt/wspanel/dev/agent-call.php agent.ping
	check "the floating IP stayed (never released)" holds "$P"
	expect "still the primary" "$(role "$P")" primary
	check "the site was served throughout" served_at "$VIP"
	docker exec "$S" systemctl restart panel-agent
	eventually 60 "the standby's agent is back" docker exec "$S" php /opt/wspanel/dev/agent-call.php agent.ping
	expect "still the standby" "$(role "$S")" standby
	check "it does not take the floating IP" not_holds "$S"
	eventually 120 "it syncs again" layer_ok "$S" state
}

scenario_reboot() {
	# Longer than a container restart, shorter than a failover.
	for c in "$P" "$S"; do agent "$c" mirror.settings '{"failover_after":180}' >/dev/null; done
	docker restart -t 30 "$P" >/dev/null
	eventually 240 "the primary is back" docker exec "$P" php /opt/wspanel/dev/agent-call.php agent.ping
	contains "it waited for the standby before serving (the start gate)" "$(events "$P")" "po startu čeká na potvrzení zrcadla"
	eventually 60 "the standby confirmed: it serves again" is_role "$P" primary
	contains "and says so" "$(events "$P")" "zrcadlo potvrdilo"
	eventually 90 "it takes the floating IP again" holds "$P"
	expect "the standby did not take over" "$(role "$S")" standby
	expect "one holder" "$(holders)" 1
	eventually 120 "the site is served again" served_at "$VIP"
	check "the site's application can write again" app_write "$P" after-reboot
	eventually 120 "the standby syncs again" layer_ok "$S" state
}

scenario_standby_down() {
	local t0
	t0=$(date +%s)
	docker stop "$S" >/dev/null && ok "the standby is down"
	never $((FAILOVER * 2 + 20)) "the primary does not fence itself (it sees the witness)" is_role "$P" fenced
	check "it keeps the floating IP" holds "$P"
	outage_at_most "$t0" 0 "the site was served without a break"
	write_row "$P" "while-standby-down" && ok "writes go on"
	docker start "$S" >/dev/null
	eventually 240 "the standby is back" docker exec "$S" php /opt/wspanel/dev/agent-call.php agent.ping
	expect "as the standby" "$(role "$S")" standby
	wait_synced "$S"
	check "it caught up with what it missed" has_row "$S" while-standby-down
	assert_consistent "$P" "$S"
}

scenario_flapping() {
	local t0 i
	t0=$(date +%s)
	for i in 1 2 3 4; do
		docker network disconnect "$NET" "$P"
		sleep 5
		docker network connect --ip "$PIP" "$NET" "$P"
		sleep 12
	done
	ok "the primary's link dropped 4 times for 5 s"
	never 20 "the standby never took over" is_role "$S" primary
	expect "the primary is still the primary" "$(role "$P")" primary
	lacks "it never fenced itself" "$(monitor_lines "$t0" | awk '{print $2}' | sort -u | tr '\n' ' ')" fenced
	eventually 60 "it holds the floating IP" holds "$P"
	eventually 60 "the site is served" served_at "$VIP"
	eventually 120 "the copy goes on" layer_ok "$S" state
}

# ---------------------------------------------------------------- under load

scenario_switchover_under_load() {
	local t0 lost
	t0=$(date +%s)
	start_writer "$P" load1 600
	sleep 8
	contains "a switchover to the standby while writing" "$(agent "$P" mirror.switchover)" '"switched":true'
	stop_writer "$P" load1
	log "acknowledged on the old primary: $(acked "$P" load1) rows"
	(($(acked "$P" load1) > 20)) && ok "the writer wrote ($(acked "$P" load1) rows)" || bad "the writer wrote" "$(acked "$P" load1) rows"
	lost=$(lost_rows "$P" load1 "$S")
	expect "MariaDB: nothing acknowledged is lost in a planned switchover" "$lost" 0
	expect "PostgreSQL: nothing acknowledged is lost" "$(pg_lost_rows "$P" load1 "$S")" 0
	outage_at_most "$t0" 90 "the switchover's outage is short"
	mac_changes_at_most "$t0" 1 "clients moved to the new node once"

	t0=$(date +%s)
	start_writer "$S" load2 600
	sleep 8
	wait_synced "$P"
	contains "and back while writing" "$(agent "$S" mirror.switchover)" '"switched":true'
	stop_writer "$S" load2
	expect "MariaDB: nothing lost on the way back" "$(lost_rows "$S" load2 "$P")" 0
	expect "PostgreSQL: nothing lost on the way back" "$(pg_lost_rows "$S" load2 "$P")" 0
	outage_at_most "$t0" 90 "the switchback's outage is short"
	mac_changes_at_most "$t0" 1 "clients moved back once"
	wait_synced "$S"
	assert_consistent "$P" "$S"
}

scenario_failover_under_load() {
	local t0 lost
	t0=$(date +%s)
	start_writer "$P" crash 600
	sleep 10
	docker kill "$P" >/dev/null && ok "the primary dies (SIGKILL) while writing"
	eventually $((FAILOVER * 3 + 60)) "the standby takes over" is_role "$S" primary
	eventually 240 "and serves" served_at "$VIP"
	outage_at_most "$t0" $((FAILOVER * 3 + 120)) "the outage stays within the failover time"
	mac_changes_at_most "$t0" 1 "clients moved once"
	docker start "$P" >/dev/null
	eventually 240 "the old primary is back (fenced)" is_role "$P" fenced
	eventually 60 "it learnt that the other took over" mget_is "$P" .fenced replaced
	never 20 "it never served after its start" bash -c "[[ \$(docker exec $P bash -c 'ls /etc/panel-proxy/sites/*.caddy 2>/dev/null | wc -l') != 0 ]]"
	lost=$(lost_rows "$P" crash "$S")
	log "acknowledged $(acked "$P" crash) rows, lost in MariaDB: $lost, in PostgreSQL: $(pg_lost_rows "$P" crash "$S")"
	(($(acked "$P" crash) > 20)) && ok "the writer wrote ($(acked "$P" crash) rows)" || bad "the writer wrote"
	((lost <= 10)) && ok "MariaDB: at most a second of acknowledged writes lost ($lost rows)" || bad "MariaDB loss" "$lost rows (> 10, 1 s at 10/s)"
	lost=$(pg_lost_rows "$P" crash "$S")
	((lost <= 10)) && ok "PostgreSQL: at most a second lost ($lost rows)" || bad "PostgreSQL loss" "$lost rows (> 10)"
	write_row "$S" "after-crash" && ok "the new primary takes writes"
}

scenario_fenced_reboot() {
	docker stop "$P" >/dev/null
	eventually $((FAILOVER * 3 + 60)) "the standby takes over" is_role "$S" primary
	docker start "$P" >/dev/null
	eventually 240 "the old primary comes back fenced" is_role "$P" fenced
	docker restart -t 30 "$P" >/dev/null && ok "the fenced node reboots"
	eventually 240 "its agent is back" docker exec "$P" php /opt/wspanel/dev/agent-call.php agent.ping
	expect "it is still fenced" "$(role "$P")" fenced
	eventually 60 "no site is on its edge after the boot" no_edges "$P"
	eventually 90 "its MariaDB is read-only after the boot" read_only_is "$P" 1
	never 30 "it never takes the floating IP" holds "$P"
	never 10 "its mail does not run" docker exec "$P" systemctl is-active --quiet postfix
	never 10 "its sites' units do not run" docker exec "$P" bash -c "systemctl list-units --state=running 'wspanel-*' --no-legend --plain | awk '{print \$1}' | grep -v -e '^wspanel-mirror-' -e '^wspanel-site-guard' -e '^wspanel-game' | grep -q ."
	expect "one holder" "$(holders)" 1
}

scenario_power_cut() {
	local t0
	docker kill "$P" "$S" >/dev/null && ok "both nodes lose power"
	docker start "$P" >/dev/null
	t0=$(date +%s)
	eventually 240 "the primary's agent is back" docker exec "$P" php /opt/wspanel/dev/agent-call.php agent.ping
	expect "it does not serve at once (the standby may have taken over)" "$(role "$P")" fenced
	check "no site is on its edge meanwhile" no_edges "$P"
	eventually $((FAILOVER * 2 + 60)) "the standby stays silent: after the failover time it serves" is_role "$P" primary
	(($(date +%s) - t0 >= FAILOVER)) && ok "not before the failover time ($(($(date +%s) - t0))s)" || bad "it waited the failover time" "$(($(date +%s) - t0))s"
	contains "and says why" "$(events "$P")" "zrcadlo neodpovídá, svědek ano"
	eventually 60 "it holds the floating IP" holds "$P"
	eventually 120 "the site is served" served_at "$VIP"
	eventually 30 "the site's application can write" app_write "$P" after-power-cut
	docker start "$S" >/dev/null
	eventually 240 "the standby is back as the standby" is_role "$S" standby
	wait_synced "$S"
	assert_consistent "$P" "$S"
}

scenario_switchover_cycles() {
	local i from to
	for i in 1 2 3 4 5; do
		if ((i % 2)); then from=$P to=$S; else from=$S to=$P; fi
		write_row "$from" "cycle-$i"
		# A change of the edge's configuration just before: the new primary
		# must serve the current blocks, not old ones it kept aside.
		agent "$from" site.edge "{\"domain\":\"shop.firma.local\",\"headers\":[{\"name\":\"X-E2E-Cycle\",\"value\":\"$i\"}]}" >/dev/null
		wait_synced "$to"
		contains "switchover $i: $from → $to" "$(agent "$from" mirror.switchover)" '"switched":true'
		expect "switchover $i: $to serves" "$(role "$to")" primary
		eventually 60 "switchover $i: $to holds the floating IP" holds "$to"
		eventually 120 "switchover $i: served" served_at "$VIP"
		check "switchover $i: the last write is there" has_row "$to" "cycle-$i"
		expect "switchover $i: the edge's latest configuration came along" \
			"$(docker exec "$D" curl -skI --max-time 5 --resolve "shop.firma.local:443:$VIP" https://shop.firma.local/index.html | tr -d '\r' | sed -n 's/^x-e2e-cycle: //Ip')" "$i"
		wait_synced "$from"
		assert_consistent "$to" "$from"
	done
}

scenario_root_write_on_standby() {
	# MariaDB's read_only does not bind root (and MariaDB has no
	# super_read_only): a root session on the standby writes into the replica.
	docker exec "$S" mariadb shop -e "INSERT INTO t (v) VALUES ('root-on-standby')" && ok "root writes on the standby"
	write_row "$P" "after-root-write"
	eventually 180 "the standby notices the write outside replication" events_have "$S" "zapisoval mimo replikaci"
	wait_synced "$S"
	ok "replication runs again"
	check "the primary's newer write arrived" has_row "$S" after-root-write
	check "the replica is the primary's again (the local row is gone)" lacks_row "$S" root-on-standby
	check "the local row is kept in the dump made before seeding" docker exec "$S" bash -c "zcat \$(ls -t /var/backups/wspanel/mirror/mariadb-before-seed-*.sql.gz | head -1) | grep -q root-on-standby"
	assert_consistent "$P" "$S"
	write_row "$P" "later"
	eventually 30 "and it keeps replicating" has_row "$S" later
}

scenario_site_domains() {
	# A real Laravel (composer create-project) on aaa.firma.local with the
	# alias ccc.firma.local and the redirect host www.aaa.firma.local; its
	# routes are bound to a domain each (Route::domain).
	agent "$P" site.create '{"domain":"aaa.firma.local","framework":"laravel","php_version":"8.4"}' >/dev/null && ok "the site aaa.firma.local" || bad "site.create aaa.firma.local"
	o=$(agent_out "$P" site.edge '{"domain":"aaa.firma.local","aliases":["ccc.firma.local"],"redirect_hosts":["www.aaa.firma.local"]}')
	contains "its alias and redirect host" "$o" '"aliases":["ccc.firma.local"]'
	if docker exec "$P" runuser -u web_aaa_firma_local -- env HOME=/tmp/lara-home COMPOSER_HOME=/tmp/lara-composer \
		bash -c 'cd /srv/sites/aaa.firma.local/releases && composer create-project laravel/laravel lara --no-interaction --prefer-dist -q' >/dev/null 2>&1; then
		ok "a fresh Laravel ($(on "$P" php /srv/sites/aaa.firma.local/releases/lara/artisan --version))"
	else
		bad "composer create-project laravel/laravel"
		return
	fi
	docker exec -i "$P" runuser -u web_aaa_firma_local -- bash -c 'cat >> /srv/sites/aaa.firma.local/releases/lara/routes/web.php' <<'ROUTES'

// e2e: routing by the domain the site was called on.
$who = fn (string $app) => fn () => response()->json([
    'app' => $app, 'host' => request()->getHost(), 'secure' => request()->isSecure(),
    'ip' => request()->ip(), 'url' => url('/x'), 'aaa' => route('aaa.whoami'), 'ccc' => route('ccc.whoami'),
]);
Route::domain('aaa.firma.local')->get('/whoami', $who('aaa'))->name('aaa.whoami');
Route::domain('ccc.firma.local')->get('/whoami', $who('ccc'))->name('ccc.whoami');
Route::domain('aaa.firma.local')->get('/only-aaa', fn () => 'only-aaa');
ROUTES
	docker exec "$P" runuser -u web_aaa_firma_local -- ln -sfn releases/lara /srv/sites/aaa.firma.local/current
	domain_checks "$P"
	wait_synced "$S"
	contains "switch over to the standby" "$(agent "$P" mirror.switchover)" '"switched":true'
	domain_checks "$S"
	wait_synced "$P"
	contains "and back" "$(agent "$S" mirror.switchover)" '"switched":true'
	domain_checks "$P"
}
whoami_is() { # whoami_is HOST APP: the host's /whoami answers from that route
	docker exec "$D" curl -sk --max-time 5 --resolve "$1:443:$VIP" "https://$1/whoami" | grep -q "\"app\":\"$2\""
}
# domain_checks NODE: what a LAN client sees through the floating IP, NODE serving.
domain_checks() {
	local o
	eventually 60 "[$1] aaa.firma.local is served" whoami_is aaa.firma.local aaa
	o=$(docker exec "$D" curl -sk --max-time 5 --resolve "aaa.firma.local:443:$VIP" https://aaa.firma.local/whoami)
	contains "[$1] the main domain reaches its route" "$o" '"app":"aaa","host":"aaa.firma.local"'
	contains "[$1] Laravel sees https" "$o" '"secure":true'
	contains "[$1] and the client's address" "$o" "\"ip\":\"$DIP\""
	contains "[$1] url() is on the domain called" "$o" '"url":"https:\/\/aaa.firma.local\/x"'
	contains "[$1] route() builds the other domain's address" "$o" '"ccc":"https:\/\/ccc.firma.local\/whoami"'
	o=$(docker exec "$D" curl -sk --max-time 5 --resolve "ccc.firma.local:443:$VIP" https://ccc.firma.local/whoami)
	contains "[$1] the alias reaches its own route" "$o" '"app":"ccc","host":"ccc.firma.local"'
	contains "[$1] url() follows the alias" "$o" '"url":"https:\/\/ccc.firma.local\/x"'
	expect "[$1] a route of one domain is not on the other" "$(docker exec "$D" curl -sk -o /dev/null -w '%{http_code}' --max-time 5 --resolve "ccc.firma.local:443:$VIP" https://ccc.firma.local/only-aaa)" 404
	expect "[$1] and is on its own" "$(docker exec "$D" curl -sk --max-time 5 --resolve "aaa.firma.local:443:$VIP" https://aaa.firma.local/only-aaa)" only-aaa
	expect "[$1] the redirect host answers 308 to the main domain, path kept" \
		"$(docker exec "$D" curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 5 --resolve "www.aaa.firma.local:443:$VIP" 'https://www.aaa.firma.local/whoami?q=1')" \
		"308 https://aaa.firma.local/whoami?q=1"
	# A client's own forwarding headers never pass for the truth.
	o=$(docker exec "$D" curl -sk --max-time 5 -H 'X-Forwarded-For: 6.6.6.6' -H 'X-Forwarded-Host: evil.example' -H 'X-Forwarded-Proto: http' \
		--resolve "aaa.firma.local:443:$VIP" https://aaa.firma.local/whoami)
	contains "[$1] a spoofed X-Forwarded-For is ignored (the real client's address)" "$o" "\"ip\":\"$DIP\""
	contains "[$1] a spoofed X-Forwarded-Host is ignored" "$o" '"host":"aaa.firma.local"'
	contains "[$1] a spoofed X-Forwarded-Proto is ignored" "$o" '"secure":true'
	expect "[$1] an unknown host gets nothing" "$(docker exec "$D" curl -sk -o /dev/null -w '%{http_code}' --max-time 5 --resolve "nobody.firma.local:443:$VIP" https://nobody.firma.local/ 2>/dev/null)" 000
}

# ---------------------------------------------------------------- failovers

scenario_failover_and_back() {
	local before
	write_row "$P" "before-outage"
	eventually 30 "the last write reached the standby" has_row "$S" before-outage
	before=$(rows "$P")
	docker stop "$P" >/dev/null && ok "the primary is down"
	eventually $((FAILOVER * 3 + 60)) "the standby promotes itself" is_role "$S" primary
	eventually 60 "the new primary holds the floating IP" holds "$S"
	eventually 240 "the site is served through the floating IP" served_at "$VIP"
	expect "MariaDB takes writes" "$(on "$S" mariadb -N -e 'SELECT @@read_only')" 0
	expect "PostgreSQL promoted" "$(on "$S" runuser -u postgres -- psql -tAq -c 'SELECT pg_is_in_recovery()')" f
	expect "no replicated row is lost" "$(rows "$S")" "$before"
	write_row "$S" "on-new-primary" && ok "a write on the new primary" || bad "a write on the new primary"
	check "the site's application can write on the new primary" app_write "$S" app-on-new-primary
	expect "Valkey data kept" "$(valkey "$S" valkey GET greeting)" before
	expect "the site's temp folder is there (uploads, sessions)" "$(on "$S" stat -c '%U %a' /srv/sites/shop.firma.local/tmp 2>&1)" "web_shop_firma_local 700"
	check "and PHP can write a session into it" docker exec "$S" runuser -u web_shop_firma_local -- php -d session.save_path=/srv/sites/shop.firma.local/tmp -r 'session_start(); $_SESSION["x"]=1; session_write_close(); exit(is_file(session_save_path()."/sess_".session_id()) ? 0 : 1);'
	expect "it keeps its own panel domain" "$(on "$S" jq -r .panel_domain /etc/panel-agent/agent.json)" "$S.firma.local"
	expect "and its own APP_URL" "$(on "$S" sed -n 's/^APP_URL=//p' /srv/panel/shared/.env)" "https://$S.firma.local"
	contains "the panel's users came along" "$(on "$S" sqlite3 /srv/panel/shared/database.sqlite 'SELECT count(*) FROM users')" ""
	expect "Windows DNS stays on the floating IP" "$(dns_a shop)" "$VIP"

	docker start "$P" >/dev/null
	eventually 240 "the old primary steps down" is_role "$P" fenced
	eventually 60 "it serves no sites" no_edges "$P"
	eventually 90 "its MariaDB is read-only" read_only_is "$P" 1
	never 30 "it never takes the floating IP" holds "$P"
	expect "one holder" "$(holders)" 1

	contains "it rejoins as the standby" "$(agent "$P" mirror.rejoin)" '"role":"standby"'
	local backup
	backup=$(on "$P" bash -c 'ls -d /var/backups/wspanel/mirror/rejoin-* | tail -1')
	check "its state is saved before the rejoin" docker exec "$P" bash -c "ls $backup/before-rejoin-*.wspbak"
	wait_synced "$P"
	ok "rejoined: every layer in order"
	eventually 60 "the new primary's write reached it" has_row "$P" on-new-primary
	assert_consistent "$S" "$P"
	check "PostgreSQL's data of the old primary is kept aside" docker exec "$P" bash -c 'ls -d /var/lib/postgresql/*/main.before-rejoin-*'

	before=$(rows "$S")
	contains "a planned switchover back" "$(agent "$S" mirror.switchover)" '"switched":true'
	expect "the old primary is primary again" "$(role "$P")" primary
	expect "and the other its standby" "$(role "$S")" standby
	eventually 60 "the floating IP is back" holds "$P"
	eventually 60 "the other let go of it" not_holds "$S"
	eventually 240 "served through the floating IP" served_at "$VIP"
	expect "no write lost in the switchover" "$(rows "$P")" "$before"
}

scenario_isolation() {
	# The primary's link goes (a cable, a switch port): no ARP either.
	docker network disconnect "$NET" "$P"
	eventually $((FAILOVER)) "the cut-off primary fences itself" mget_is "$P" '.role' fenced
	check "and lets go of the floating IP" not_holds "$P"
	eventually $((FAILOVER * 3 + 60)) "the standby takes over" is_role "$S" primary
	eventually 60 "and holds the floating IP" holds "$S"
	eventually 240 "the site is served" served_at "$VIP"
	docker network connect --ip "$PIP" "$NET" "$P"
	never 45 "the old primary stays fenced (a newer epoch serves)" is_role "$P" primary
	expect "one holder" "$(holders)" 1
}

scenario_promotion_race() {
	# The primary's agent and edge are down (the standby sees nothing), its
	# host is up. The administrator promotes the standby by hand; while that
	# runs (minutes), the old primary's agent comes back as a primary.
	docker exec "$P" systemctl stop panel-agent panel-proxy
	eventually 60 "the standby sees the primary down" mget_is "$S" '.status.peer_up' false
	local id
	id=$(mget "$S" .node_id)
	docker exec -d "$S" php /opt/wspanel/dev/agent-call.php mirror.promote "{\"confirm\":\"$id\"}"
	eventually 60 "the promotion runs" mget_is "$S" '.status.action' promoting
	docker exec "$P" systemctl start panel-agent
	eventually 60 "the returning primary steps down at once" is_role "$P" fenced
	eventually 5 "it lets go of the floating IP at once" not_holds "$P"
	eventually 600 "the promotion completes" is_role "$S" primary
	eventually 60 "the new primary holds the floating IP" holds "$S"
	expect "one holder" "$(holders)" 1
	expect "the old one is still fenced" "$(role "$P")" fenced
	docker exec "$P" systemctl start panel-proxy
}

scenario_switchover_incomplete() {
	# The standby fences the primary for a switchover, then its agent dies
	# before taking over: nobody would serve.
	docker exec -d "$P" php /opt/wspanel/dev/agent-call.php mirror.switchover
	eventually 60 "the primary is fenced for the switchover" mget_is "$P" '.fenced' switchover
	docker exec "$S" systemctl kill -s KILL panel-agent
	docker exec "$S" systemctl start panel-agent
	eventually 60 "the standby is back as a standby" is_role "$S" standby
	eventually 240 "the primary serves again by itself" is_role "$P" primary
	eventually 60 "with the floating IP" holds "$P"
	eventually 120 "and the site" served_at "$VIP"
	contains "and says why" "$(events "$P")" "plánované přepnutí nedoběhlo"
	expect "MariaDB takes writes again" "$(on "$P" mariadb -N -e 'SELECT @@read_only')" 0
	check "the site's application can write again" app_write "$P" after-incomplete-switchover
}

scenario_deleted_site() {
	contains "switch over to the standby" "$(agent "$P" mirror.switchover)" '"switched":true'
	expect "it serves" "$(role "$S")" primary
	agent "$S" site.delete '{"domain":"old.firma.local","confirm":"old.firma.local"}' >/dev/null && ok "a site deleted there" || bad "site.delete on the new primary"
	wait_synced "$P"
	contains "switch back" "$(agent "$S" mirror.switchover)" '"switched":true'
	expect "the first node serves again" "$(role "$P")" primary
	check "the deleted site's edge block did not come back" absent "$P" /etc/panel-proxy/sites/old.firma.local.caddy
	check "nor its state" absent "$P" /var/lib/panel-agent/sites/old.firma.local.json
	check "its configuration was moved aside" docker exec "$P" bash -c 'ls -d /var/backups/wspanel/mirror/stale-sites-*/old.firma.local'
	check "the other site serves" served_at "$VIP"
	never 10 "the deleted site is not served" old_site_served
}

scenario_game_servers() {
	# Each node has a game server of its own (state and panel row; the game
	# itself is not needed for what the mirror does with it).
	add_game "$P" 900 "game-of-primary" 25900
	add_game "$S" 901 "game-of-standby" 25910
	agent "$S" mirror.sync_now >/dev/null
	wait_synced "$S"
	check "the primary's game state is not copied" absent "$S" /var/lib/panel-agent/games/900.json
	contains "switch over to the standby" "$(agent "$P" mirror.switchover)" '"switched":true'
	expect "its panel keeps its own game server" "$(game_rows "$S")" "901:game-of-standby"
	check "its game state is intact" docker exec "$S" grep -q game-of-standby /var/lib/panel-agent/games/901.json
	expect "the other node's game is still its own" "$(game_rows "$P")" "900:game-of-primary"
	wait_synced "$P"
	contains "switch back" "$(agent "$S" mirror.switchover)" '"switched":true'
	expect "the first node keeps its game server" "$(game_rows "$P")" "900:game-of-primary"
	expect "and the second its own" "$(game_rows "$S")" "901:game-of-standby"
}
add_game() { # add_game NODE ID NAME PORT
	docker exec "$1" bash -c "install -d -m 0700 /var/lib/panel-agent/games && cat > /var/lib/panel-agent/games/$2.json <<EOF
{\"id\":$2,\"name\":\"$3\",\"game\":\"minecraft\",\"flavor\":\"paper\",\"version\":\"1.21.10\",\"port\":$4,\"rcon_port\":$(($4 + 1)),\"rcon_password\":\"e2e\",\"memory_mb\":512}
EOF
chmod 0600 /var/lib/panel-agent/games/$2.json"
	docker exec "$1" sqlite3 /srv/panel/shared/database.sqlite "INSERT OR REPLACE INTO game_servers (id, name, game, flavor, version, port, memory_mb, cpu_percent, status, created_at, updated_at) VALUES ($2, '$3', 'minecraft', 'paper', '1.21.10', $4, 512, 0, 'active', datetime('now'), datetime('now'))"
}
game_rows() { docker exec "$1" sqlite3 /srv/panel/shared/database.sqlite "SELECT id || ':' || name FROM game_servers ORDER BY id" | paste -sd, -; }

scenario_version() {
	[[ -x $OTHER_AGENT ]] || {
		bad "an agent of another version is built" "$OTHER_AGENT (run.sh builds it)"
		return
	}
	docker cp "$OTHER_AGENT" "$S:/usr/sbin/panel-agent.e2e-other"
	docker exec "$S" bash -c 'cp -a /usr/sbin/panel-agent /usr/sbin/panel-agent.e2e-orig && install -m 0755 /usr/sbin/panel-agent.e2e-other /usr/sbin/panel-agent && systemctl restart panel-agent'
	eventually 60 "the standby runs another version" versions_differ
	eventually 90 "both see the difference" mget_has "$P" '.status.layers.settings.error // ""' 'verze WSPanelu'
	docker stop "$P" >/dev/null
	never $((FAILOVER * 2 + 20)) "no automatic takeover across versions" is_role "$S" primary
	contains "the standby says why" "$(events "$S")" "sjednoťte verze"
	local id
	id=$(mget "$S" .node_id)
	contains "a manual promotion asks for confirmation" "$(agent_out "$S" mirror.promote "{\"confirm\":\"$id\"}")" "allow_version_mismatch"
	expect "and changed nothing" "$(role "$S")" standby
	docker start "$P" >/dev/null
	eventually 240 "the primary is back" docker exec "$P" php /opt/wspanel/dev/agent-call.php agent.ping
	restore_agent_binary
	eventually 120 "same version again: in agreement" layer_ok "$S" settings
}

scenario_unpair() {
	local sid pid
	sid=$(mget "$S" .node_id)
	pid=$(mget "$P" .node_id)
	contains "the standby leaves the mirror" "$(agent "$S" mirror.unpair "{\"confirm\":\"$sid\"}")" '"changed":true'
	eventually 120 "its MariaDB takes writes" read_only_is "$S" 0
	check "its copy stays" docker exec "$S" test -f /srv/sites/shop.firma.local/current/public/index.html
	contains "the primary leaves the mirror" "$(agent "$P" mirror.unpair "{\"confirm\":\"$pid\"}")" '"changed":true'
	eventually 30 "it lets go of the floating IP" not_holds "$P"
	expect "the mirror port is closed" "$(on "$P" ufw status | grep -c '7443/tcp')" 0
	eventually 60 "the site is served on the server's own address" served_at "$PIP"
	expect "nobody holds the floating IP" "$(holders)" 0
}
