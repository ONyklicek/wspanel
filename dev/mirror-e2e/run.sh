#!/usr/bin/env bash
# The mirror end to end, on this machine with Docker: two Debian 13 servers
# installed by provision/install.sh (a primary and its standby, each with a
# panel domain of its own) and a third machine standing in for the Windows
# DNS server, the witness and a client in the LAN. Scenario by scenario:
# pairing, every copied layer, the standby's guard, the floating IP,
# Windows DNS, both nodes' settings, the copy's protection, partitions that
# are not outages, restarts and reboots, failover and failback, a primary
# cut off its network, an old primary returning mid-promotion, a switchover
# that does not complete, deleted sites, game servers per node, version
# skew and leaving the mirror.
#
#   make agent-build && dev/mirror-e2e/run.sh [options]
#
#   --only=a,b     run these scenarios (pairing and replication always run)
#   --list         list the scenarios and exit
#   --keep         keep the containers afterwards (also MIRROR_KEEP=1)
#   --reuse        keep the installed servers as images and start from them
#                  next time (re-installed when the agent, provision/ or
#                  panel/ change; also MIRROR_REUSE=1)
#
# Results: var/mirror-e2e/<time>/ (junit.xml, the installers' logs, and the
# diagnostics of every failed scenario: both nodes' mirror status, the
# agents' journal, network, units, the DNS stand-in's records). A full run
# takes about an hour; the installation alone about 15 minutes.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1
ROOT=$PWD
ARCH=${ARCH:-amd64}
HERE=$ROOT/dev/mirror-e2e
# shellcheck source=lib.sh
source "$HERE/lib.sh"
# shellcheck source=cluster.sh
source "$HERE/cluster.sh"
# shellcheck source=scenarios.sh
source "$HERE/scenarios.sh"

KEEP=${MIRROR_KEEP:-0}
REUSE=${MIRROR_REUSE:-0}
ONLY=""
for arg in "$@"; do
	case $arg in
	--only=*) ONLY=${arg#*=} ;;
	--keep) KEEP=1 ;;
	--reuse) REUSE=1 ;;
	--list)
		for s in "${CORE[@]}" "${SCENARIOS[@]}"; do printf '  %-24s %s\n' "$s" "$(describe "$s")"; done
		exit 0
		;;
	-h | --help)
		sed -n '2,/^set -uo/p' "$0" | sed '$d; s/^# \{0,1\}//'
		exit 0
		;;
	*) die "unknown option: $arg (see --help)" ;;
	esac
done

SELECTED=()
if [[ -n $ONLY ]]; then
	IFS=, read -ra want <<<"$ONLY"
	for w in "${want[@]}"; do
		[[ " ${CORE[*]} " == *" $w "* ]] && continue
		[[ " ${SCENARIOS[*]} " == *" $w "* ]] || die "unknown scenario: $w (see --list)"
	done
	for s in "${SCENARIOS[@]}"; do [[ ",$ONLY," == *",$s,"* ]] && SELECTED+=("$s"); done
else
	SELECTED=("${SCENARIOS[@]}")
fi

command -v docker >/dev/null || die "Docker is needed"
docker info >/dev/null 2>&1 || die "Docker does not run"
# Two installed servers, their images (--reuse) and the logs: refuse to start
# a run that would fill the disk (a full disk takes Docker down mid-run).
free_gb=$(docker run --rm "$IMAGE" df -BG --output=avail / 2>/dev/null | tail -1 | tr -dc 0-9)
host_gb=$(df -g "$ROOT" 2>/dev/null | awk 'NR==2 {print $4}')
[[ -z $free_gb || $free_gb -ge 25 ]] || die "only ${free_gb} GB free for Docker (25 GB needed): docker image prune, docker builder prune"
[[ -z $host_gb || $host_gb -ge 10 ]] || die "only ${host_gb} GB free on this disk (10 GB needed)"
[[ -x $ROOT/build/panel-agent-linux-$ARCH ]] || die "build the agent first: make agent-build"
RUN=$(date -u +%Y%m%dT%H%M%SZ)
ARTIFACTS=$ROOT/var/mirror-e2e/$RUN
mkdir -p "$ARTIFACTS"
printf 'mirror e2e %s: %s\n' "$RUN" "$(dim "${CORE[*]} ${SELECTED[*]}")"

# Another version of the same agent, for the version scenario.
if [[ " ${SELECTED[*]} " == *" version "* ]]; then
	(cd "$ROOT/agent" && CGO_ENABLED=0 GOOS=linux GOARCH=$ARCH go build -trimpath \
		-ldflags "-s -w -X wspanel/agent/internal/ops.Version=e2e-other-$RUN" -o "$OTHER_AGENT" ./cmd/panel-agent) ||
		die "building the second agent (GOFLAGS is honoured, e.g. -overlay)"
fi

finish() {
	local rc=$?
	stop_monitor
	SCENARIO=${SCENARIO:-setup}
	((SCENARIO_FAILED)) && collect_artifacts
	write_junit "$ARTIFACTS/junit.xml"
	((KEEP)) || remove_cluster
	summary || rc=1
	printf 'results: %s\n' "$ARTIFACTS"
	exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT TERM

setup_cluster

begin_scenario pairing "$(describe pairing)"
scenario_pairing
end_scenario
((SCENARIO_FAILED)) && die "pairing failed: the other scenarios need a paired mirror"

# From here on the monitor samples both nodes and a client all the time.
start_monitor
begin_scenario replication "$(describe replication)"
scenario_replication
end_scenario

for s in "${SELECTED[@]}"; do
	begin_scenario "$s" "$(describe "$s")"
	baseline
	"scenario_${s//-/_}"
	end_scenario
done
