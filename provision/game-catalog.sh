#!/usr/bin/env bash
# WSPanel's game catalog: the manifests in games/catalog/ as one catalog.json,
# signed beside it (catalog.json.asc) with the key the apt repository is
# signed with. Run by the release workflow (apt.yml).
#
#   provision/game-catalog.sh <manifest dir> <out dir>
#
# APT_SIGNING_KEY_ID selects the key when the keyring has several.
set -euo pipefail
SRC=${1:?manifest dir} OUT=${2:?out dir}
command -v jq >/dev/null || { echo "jq is needed" >&2; exit 1; }
mkdir -p "$OUT"
shopt -s nullglob
files=("$SRC"/*.json)
for f in "${files[@]}"; do
  jq -e 'type == "object" and (.id | test("^[a-z0-9][a-z0-9-]{1,40}$")) and (.flavors | length > 0)' "$f" >/dev/null \
    || { echo "$f: not a game package manifest" >&2; exit 1; }
  [[ "$(jq -r .id "$f").json" == "$(basename "$f")" ]] || { echo "$f: the file is named after its id" >&2; exit 1; }
done
jq -n --slurpfile p <(cat "${files[@]}" /dev/null) '{format: 1, packages: $p}' > "$OUT/catalog.json"
key=()
[[ -n "${APT_SIGNING_KEY_ID:-}" ]] && key=(--local-user "$APT_SIGNING_KEY_ID")
gpg --batch --yes "${key[@]}" --armor --detach-sign --output "$OUT/catalog.json.asc" "$OUT/catalog.json"
echo "game catalog: ${#files[@]} packages"
