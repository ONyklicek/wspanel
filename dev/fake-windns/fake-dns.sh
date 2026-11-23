#!/usr/bin/env bash
# Dev/test stand-in for wspanel-dns.ps1 (the same JSON protocol), run by sshd
# as ForceCommand. Records live in /var/lib/fake-dns/<zone>.json as
# {"name/type": {"ttl": n, "values": [...]}}.
set -euo pipefail
ZONES=(firma.local)
DIR=/var/lib/fake-dns
req=$(cat)
action=$(jq -r .action <<<"$req")
allowed() { local z; for z in "${ZONES[@]}"; do [[ $z == "$1" ]] && return 0; done; return 1; }
case "$action" in
  list)
    zone=$(jq -r .zone <<<"$req")
    allowed "$zone" || { echo '{"ok":false,"error":"zone not allowed"}'; exit 0; }
    f=$DIR/$zone.json; [[ -f $f ]] || echo '{}' >"$f"
    jq -c --arg z "$zone" '{ok:true,records:[to_entries[]|{zone:$z,name:(.key|split("/")[0]),type:(.key|split("/")[1]),ttl:.value.ttl,values:.value.values}]}' "$f"
    ;;
  apply)
    changed=(); errors=()
    n=$(jq '.records|length' <<<"$req")
    for ((i = 0; i < n; i++)); do
      r=$(jq -c ".records[$i]" <<<"$req")
      zone=$(jq -r .zone <<<"$r"); name=$(jq -r .name <<<"$r"); type=$(jq -r .type <<<"$r")
      if ! allowed "$zone"; then errors+=("$name.$zone: zone not allowed"); continue; fi
      f=$DIR/$zone.json; [[ -f $f ]] || echo '{}' >"$f"
      want=$(jq -c '{ttl:.ttl,values:(.values|sort)}' <<<"$r")
      have=$(jq -c --arg k "$name/$type" '.[$k] // empty' "$f")
      [[ "$have" == "$want" ]] && continue
      jq --arg k "$name/$type" --argjson v "$want" '.[$k]=$v' "$f" >"$f.tmp" && mv "$f.tmp" "$f"
      changed+=("$name.$zone $type $(jq -r '.values|join(",")' <<<"$want")")
    done
    jq -cn --args '{ok:true,changed:$ARGS.positional}' "${changed[@]}" | jq -c --argjson e "$(printf '%s\n' "${errors[@]}" | jq -R . | jq -sc 'map(select(.!=""))')" '. + {errors:$e}'
    ;;
  *) echo '{"ok":false,"error":"unknown action"}' ;;
esac
