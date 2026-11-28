# shellcheck shell=bash
# The mirror's end-to-end suite: helpers, assertions, results (JUnit) and the
# diagnostics collected when a scenario fails. Sourced by run.sh.

# ---------------------------------------------------------------- the cluster

NET=wspanel-mirror-e2e
SUBNET=172.30.42.0/24
P=wsm-primary
S=wsm-standby
D=wsm-windns # the Windows DNS stand-in, also the witness and "a client in the LAN"
PIP=172.30.42.10
SIP=172.30.42.20
DIP=172.30.42.5
VIP=172.30.42.30
IMAGE=wspanel-dev-base
FAILOVER=30 # seconds (the suite's; production default is 60)

# ---------------------------------------------------------------- output

green() { printf '\033[32m%s\033[0m' "$*"; }
red() { printf '\033[31m%s\033[0m' "$*"; }
dim() { printf '\033[2m%s\033[0m' "$*"; }
log() { printf '    %s\n' "$(dim "$*")"; }
# die MESSAGE: the run cannot go on; recorded as a failure of the scenario
# (its diagnostics are collected on the way out).
die() {
	if [[ -n ${SCENARIO:-} ]] && declare -F record >/dev/null; then
		record 0 "aborted" "$*"
	else
		printf '%s\n' "$(red "$*")" >&2
	fi
	exit 1
}

# ---------------------------------------------------------------- results

# One row per check: scenario, name, ok (1/0), message, seconds.
R_SCEN=()
R_NAME=()
R_OK=()
R_MSG=()
R_SECS=()
SCENARIO=setup
SCENARIO_FAILED=0
CHECK_START=$SECONDS

record() { # record OK NAME [MESSAGE]
	R_SCEN+=("$SCENARIO")
	R_NAME+=("$2")
	R_OK+=("$1")
	R_MSG+=("${3:-}")
	R_SECS+=("$((SECONDS - CHECK_START))")
	CHECK_START=$SECONDS
	if [[ $1 == 1 ]]; then
		printf '  %s %s\n' "$(green PASS)" "$2"
	else
		SCENARIO_FAILED=1
		printf '  %s %s %s\n' "$(red FAIL)" "$2" "${3:+— ${3:0:600}}"
	fi
}
ok() { record 1 "$1"; }
bad() { record 0 "$1" "${2:-}"; }
expect() { if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1" "got '$2', want '$3'"; fi; }
contains() { if [[ "$2" == *"$3"* ]]; then ok "$1"; else bad "$1" "'$3' not in '${2:0:400}'"; fi; }
lacks() { if [[ "$2" != *"$3"* ]]; then ok "$1"; else bad "$1" "'$3' found in '${2:0:400}'"; fi; }
# check NAME COMMAND...: passes when the command succeeds.
check() {
	local name=$1
	shift
	if "$@" >/dev/null 2>&1; then ok "$name"; else bad "$name" "$*"; fi
}
# eventually SECS NAME COMMAND...: passes once the command succeeds within SECS.
eventually() {
	local secs=$1 name=$2
	shift 2
	if wait_until "$secs" "$@"; then ok "$name"; else bad "$name" "not within ${secs}s: $*"; fi
}
# never SECS NAME COMMAND...: passes when the command never succeeds for SECS.
never() {
	local secs=$1 name=$2
	shift 2
	local end=$((SECONDS + secs))
	while ((SECONDS < end)); do
		if "$@" >/dev/null 2>&1; then
			bad "$name" "happened: $*"
			return
		fi
		sleep 3
	done
	ok "$name"
}

wait_until() { # wait_until SECS COMMAND...
	local end=$((SECONDS + $1))
	shift
	while ((SECONDS < end)); do
		if "$@" >/dev/null 2>&1; then return 0; fi
		sleep 3
	done
	"$@" >/dev/null 2>&1
}

xml() { sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' -e 's/"/\&quot;/g' <<<"$1" | tr -d '\000-\010\013\014\016-\037'; }

write_junit() { # write_junit FILE
	local n=${#R_NAME[@]} failures=0 i
	for ((i = 0; i < n; i++)); do [[ ${R_OK[$i]} == 1 ]] || failures=$((failures + 1)); done
	{
		printf '<?xml version="1.0" encoding="UTF-8"?>\n'
		printf '<testsuites name="wspanel-mirror-e2e" tests="%d" failures="%d" time="%d">\n' "$n" "$failures" "$SECONDS"
		printf '  <testsuite name="mirror" tests="%d" failures="%d">\n' "$n" "$failures"
		for ((i = 0; i < n; i++)); do
			printf '    <testcase classname="mirror.%s" name="%s" time="%s"' "$(xml "${R_SCEN[$i]}")" "$(xml "${R_NAME[$i]}")" "${R_SECS[$i]}"
			if [[ ${R_OK[$i]} == 1 ]]; then
				printf '/>\n'
			else
				printf '>\n      <failure message="%s"/>\n    </testcase>\n' "$(xml "${R_MSG[$i]}")"
			fi
		done
		printf '  </testsuite>\n</testsuites>\n'
	} >"$1"
}

summary() {
	local n=${#R_NAME[@]} failed=0 i
	for ((i = 0; i < n; i++)); do [[ ${R_OK[$i]} == 1 ]] || failed=$((failed + 1)); done
	printf '\n%s passed, %s failed (%dm%02ds)\n' "$((n - failed))" "$failed" $((SECONDS / 60)) $((SECONDS % 60))
	for ((i = 0; i < n; i++)); do
		[[ ${R_OK[$i]} == 1 ]] || printf '  - [%s] %s\n' "${R_SCEN[$i]}" "${R_NAME[$i]}"
	done
	((failed == 0))
}

# ---------------------------------------------------------------- containers

on() { docker exec "$@"; }
on_i() { docker exec -i "$@"; }
# agent NODE OP [JSON]: the op's output (JSON) on stdout, its error on stderr.
agent() {
	local params=${3:-}
	[[ -n $params ]] || params='{}'
	docker exec "$1" php /opt/wspanel/dev/agent-call.php "$2" "$params"
}
# agent_out NODE OP [JSON]: output and error together (for refusals).
agent_out() { agent "$@" 2>&1; }
jqr() { docker exec -i "$1" jq -r "$2"; }
mstatus() { agent "$1" mirror.status; }
mget() { mstatus "$1" | jqr "$1" "$2"; } # mget NODE FILTER
role() { mget "$1" .role; }
is_role() { [[ $(role "$1") == "$2" ]]; }
layer_ok() { [[ $(mget "$1" ".status.layers[\"$2\"].ok // false") == true ]]; }
events() { mget "$1" '[.status.events[].message] | join("\n")'; }
mget_is() { [[ $(mget "$1" "$2") == "$3" ]]; }    # mget_is NODE FILTER VALUE
mget_has() { [[ $(mget "$1" "$2") == *"$3"* ]]; } # mget_has NODE FILTER TEXT
events_have() { [[ $(events "$1") == *"$2"* ]]; }
absent() { ! docker exec "$1" test -e "$2"; }
no_edges() { [[ $(docker exec "$1" bash -c 'ls /etc/panel-proxy/sites/*.caddy 2>/dev/null | wc -l') == 0 ]]; }
read_only_is() { [[ $(docker exec "$1" mariadb -N -e 'SELECT @@read_only' 2>/dev/null) == "$2" ]]; }
versions_differ() { [[ $(mget "$S" .version) != "$(mget "$P" .version)" ]]; }
holds() { docker exec "$1" ip -4 -o addr show | grep -q " $VIP/"; }
not_holds() { ! holds "$1"; }
holders() { # how many machines answer for the floating IP
	local n=0 c
	for c in "$P" "$S" "$D"; do docker exec "$c" ip -4 -o addr show 2>/dev/null | grep -q " $VIP/" && n=$((n + 1)); done
	echo "$n"
}
running() { [[ $(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null) == true ]]; }
sql() { docker exec "$1" mariadb -N shop -e "$2"; }
psql_shop() { docker exec "$1" runuser -u postgres -- psql -tAq -d shoppg -c "$2"; }
# served NODE_OR_IP: the shop site answers from that address, as a LAN client sees it.
served_at() { docker exec "$D" curl -sk --max-time 5 --resolve "shop.firma.local:443:$1" https://shop.firma.local/index.html | grep -q hello-from-primary; }
old_site_served() { docker exec "$D" curl -sk --max-time 3 --resolve "old.firma.local:443:$VIP" https://old.firma.local/index.html | grep -q old-site; }
dns_is() { [[ $(dns_a "$1") == "$2" ]]; }
dns_a() { docker exec "$D" jq -r ".\"$1/A\".values[0] // empty" /var/lib/fake-dns/firma.local.json 2>/dev/null; }
# e2e firewall rules carry a comment, so the baseline removes exactly them.
block() { # block NODE CHAIN ARGS...: an iptables rule (tagged)
	local node=$1 chain=$2
	shift 2
	docker exec "$node" iptables -I "$chain" -m comment --comment wsm-e2e "$@"
}
unblock_all() {
	local c
	for c in "$P" "$S"; do
		running "$c" || continue
		docker exec "$c" bash -c 'iptables -S | grep -- "wsm-e2e" | sed "s/^-A /-D /" | while read -r r; do eval iptables "$r"; done' 2>/dev/null || true
	done
}

# ---------------------------------------------------------------- diagnostics

ARTIFACTS=""
SCENARIO_SINCE=""

collect_artifacts() { # into $ARTIFACTS/<scenario>/
	local dir="$ARTIFACTS/$SCENARIO" c
	mkdir -p "$dir"
	for c in "$P" "$S"; do
		running "$c" || {
			echo "not running" >"$dir/$c.down"
			continue
		}
		mstatus "$c" >"$dir/$c-status.json" 2>&1 || true
		docker exec "$c" journalctl -u panel-agent --no-pager --since "$SCENARIO_SINCE" >"$dir/$c-agent.log" 2>&1 || true
		docker exec "$c" bash -c 'ip -4 addr; echo; ip neigh; echo; iptables -S | grep -v "^-A ufw" | head -80' >"$dir/$c-net.txt" 2>&1 || true
		docker exec "$c" bash -c 'systemctl --failed --no-pager; ls -la /etc/panel-proxy/sites /etc/panel-proxy/sites-fenced 2>&1' >"$dir/$c-units.txt" 2>&1 || true
	done
	docker exec "$D" cat /var/lib/fake-dns/firma.local.json >"$dir/windns.json" 2>&1 || true
	log "diagnostics: $dir"
}

# scenario NAME DESCRIPTION: starts one (from the baseline, unless it is setup).
begin_scenario() {
	SCENARIO=$1
	SCENARIO_FAILED=0
	SCENARIO_SINCE=$(date -u '+%Y-%m-%d %H:%M:%S')
	SCENARIO_T0=$(date +%s)
	CHECK_START=$SECONDS
	printf '\n\033[1m== %s\033[0m %s\n' "$1" "$(dim "$2")"
}
end_scenario() {
	check_invariants
	if ((SCENARIO_FAILED)); then collect_artifacts; fi
}

# ---------------------------------------------------------------- the monitor

# All through the run, about every second, one line of what a client and
# the two nodes see, into $ARTIFACTS/monitor.tsv:
#   epoch  P-role P-holds-vip P-edges  S-role S-holds-vip S-edges
#          http-via-vip vip-mac  http-at-P http-at-S
# Each scenario's end checks the invariants over its own lines: never two
# holders of the floating IP, never two primaries that both serve.
MONITOR_PID=""
node_sample() { # node_sample NODE: role holds edges (or "down 0 0"); no PHP, a fraction of a second
	timeout 5 docker exec "$1" bash -c "r=\$(jq -r '.role // \"\"' /var/lib/panel-agent/mirror/mirror.json 2>/dev/null); \
		h=\$(ip -4 -o addr show | grep -c ' $VIP/'); e=\$(ls /etc/panel-proxy/sites/*.caddy 2>/dev/null | wc -l); echo \"\${r:--} \$h \$e\"" 2>/dev/null || echo "down 0 0"
}
# client_sample: as the LAN client sees it: the site through the floating
# IP, the floating IP's MAC, and the site straight at each node's own
# address (who really serves: two 200s at once is a split brain).
client_sample() {
	timeout 6 docker exec "$D" bash -c "get() { curl -sk -o /dev/null -w '%{http_code}' --max-time 2 --resolve shop.firma.local:443:\$1 https://shop.firma.local/index.html; }
		get $VIP >/tmp/v & get $PIP >/tmp/p & get $SIP >/tmp/s & wait
		m=\$(ip neigh show $VIP | awk '{print \$5}'); echo \"\$(cat /tmp/v) \${m:--} \$(cat /tmp/p) \$(cat /tmp/s)\"" 2>/dev/null || echo "000 - 000 000"
}
# A sample is "role holds edges" or nothing: a Docker error text (an exec
# into a restarting container) must never pass for one.
valid_node() { [[ $1 =~ ^(primary|standby|fenced|-|down)\ [0-9]+\ [0-9]+$ ]] && echo "$1" || echo "down 0 0"; }
valid_client() { [[ $1 =~ ^[0-9]{3}\ [0-9a-f:-]+\ [0-9]{3}\ [0-9]{3}$ ]] && echo "$1" || echo "000 - 000 000"; }
start_monitor() {
	local file="$ARTIFACTS/monitor.tsv" dir="$ARTIFACTS/.monitor"
	mkdir -p "$dir"
	(
		while :; do
			# The three at the same moment (one sample is then one instant).
			node_sample "$P" >"$dir/p" &
			node_sample "$S" >"$dir/s" &
			client_sample >"$dir/c" &
			wait
			printf '%s %s %s %s\n' "$(date +%s)" "$(valid_node "$(cat "$dir/p")")" "$(valid_node "$(cat "$dir/s")")" "$(valid_client "$(cat "$dir/c")")" >>"$file"
			sleep 0.5
		done
	) &
	MONITOR_PID=$!
	log "monitor: $file"
}
stop_monitor() { [[ -n $MONITOR_PID ]] && kill "$MONITOR_PID" 2>/dev/null; MONITOR_PID=""; }
SCENARIO_T0=0
# monitor_lines FROM: the samples since that epoch.
monitor_lines() { awk -v t="$1" '$1 >= t' "$ARTIFACTS/monitor.tsv" 2>/dev/null; }
# The invariants, over the scenario's samples (end_scenario runs it).
check_invariants() {
	[[ -n $MONITOR_PID ]] || return 0
	local lines dual twop n
	lines=$(monitor_lines "$SCENARIO_T0")
	n=$(grep -c . <<<"$lines")
	dual=$(awk '$3 > 0 && $6 > 0' <<<"$lines" | head -3)
	# Two nodes that really serve the site at the same instant (each asked
	# at its own address), not files that may lie there before an edge starts.
	twop=$(awk '$10 == "200" && $11 == "200"' <<<"$lines" | head -3)
	# An invariant over too few samples proves little: one at least every 3 s.
	local span=$(($(date +%s) - SCENARIO_T0))
	if ((n * 3 >= span)); then ok "the monitor sampled densely ($n samples in ${span}s)"; else bad "the monitor sampled densely" "$n samples in ${span}s"; fi
	[[ -z $dual ]] && ok "invariant over $n samples: never two holders of the floating IP" || bad "invariant: two holders of the floating IP" "$dual"
	[[ -z $twop ]] && ok "invariant over $n samples: never two serving primaries" || bad "invariant: two serving primaries" "$twop"
}
# availability FROM: "longest-outage-seconds mac-changes samples" since FROM
# (an outage: the site does not answer 200 through the floating IP).
availability() {
	monitor_lines "$1" | awk '
		{ ok = ($8 == "200") }
		!ok && start == 0 { start = $1 }
		ok && start > 0 { d = $1 - start; if (d > worst) worst = d; start = 0 }
		$9 != "-" { if (last != "" && $9 != last) changes++; last = $9 }
		{ n++; lastt = $1 }
		END { if (start > 0) { d = lastt - start; if (d > worst) worst = d }; printf "%d %d %d\n", worst, changes, n }'
}
# outage_at_most FROM SECS NAME / mac_changes_at_most FROM N NAME
outage_at_most() {
	local a
	read -ra a <<<"$(availability "$1")"
	if ((a[0] <= $2)); then ok "$3 (longest outage ${a[0]}s, ${a[2]} samples)"; else bad "$3" "longest outage ${a[0]}s > $2s"; fi
}
mac_changes_at_most() {
	local a
	read -ra a <<<"$(availability "$1")"
	if ((a[1] <= $2)); then ok "$3 (${a[1]} changes)"; else bad "$3" "the floating IP's MAC changed ${a[1]} times (> $2): clients flapped between nodes"; fi
}
