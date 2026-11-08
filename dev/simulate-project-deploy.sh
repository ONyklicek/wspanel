#!/usr/bin/env bash
# Deploys a real Laravel project from this Mac to the dev server the way a
# team's GitHub Action would, end to end, and checks it:
#
#   build like CI (clean checkout, composer --no-dev, npm build, .env from
#   .env.example)  ->  site, database, Valkey and workers in the panel  ->
#   POST /api/deploy/<id>/artifact  ->  wait for the status  ->  checks;
#   with --franken also Octane + FrankenPHP worker mode and one more release.
#
# Run on the host (not in the container), after `make dev-up dev-provision`:
#   dev/simulate-project-deploy.sh ~/PhpstormProjects/firma/intranet.local \
#       --domain intranet.local --workers horizon,scheduler,reverb \
#       --commands install:permissions --franken
#
# Options:
#   --domain D        site domain (default: <folder name>.local)
#   --db NAME         MariaDB database (default: from the domain)
#   --workers LIST    workers to add, comma separated (queue, horizon, scheduler, reverb, pulse…)
#   --commands LIST   artisan commands after each deploy, comma separated
#   --build-cmd CMD   extra build step in the checkout (e.g. "bash scripts/publish-wire-assets.sh")
#   --env K=V         .env value for the release (repeatable; production defaults are set)
#   --franken         then add laravel/octane, switch to worker mode and deploy again
#
# Private Composer repositories: an auth.json in the project is used for the
# build and never packed into the release.
set -euo pipefail

PROJECT=${1:?usage: dev/simulate-project-deploy.sh <project path> [options]}
shift
PROJECT=$(cd "$PROJECT" && pwd)
NAME=$(basename "$PROJECT")
DOMAIN="" DB="" WORKERS="" COMMANDS="" BUILD_CMD="" FRANKEN=0 ENVS=()
while (($#)); do
  case $1 in
    --domain) DOMAIN=$2; shift 2 ;;
    --db) DB=$2; shift 2 ;;
    --workers) WORKERS=$2; shift 2 ;;
    --commands) COMMANDS=$2; shift 2 ;;
    --build-cmd) BUILD_CMD=$2; shift 2 ;;
    --env) ENVS+=("$2"); shift 2 ;;
    --franken) FRANKEN=1; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
DOMAIN=${DOMAIN:-${NAME%.local}.local}
DB=${DB:-$(echo "${DOMAIN%%.*}" | tr -c 'a-z0-9\n' '_')}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
CONTAINER=$(docker compose -f "$ROOT/dev/compose.yaml" ps -q devserver)
[[ -n "$CONTAINER" ]] || { echo "the dev server is not running (make dev-up dev-provision)" >&2; exit 1; }
WORK=${TMPDIR:-/tmp}/wspanel-sim/$NAME
PASS=0 FAIL=0
ok() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m %s %s\n' "$1" "${2:-}"; }
step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
in_box() { docker exec -i -e TOKEN -e CIID -e TAG "$CONTAINER" bash -s; }
# Runs PHP in the panel (as the panel user, like tinker).
php_panel() {
  printf '<?php\n%s\n' "$1" | docker exec -i "$CONTAINER" bash -c 'cat > /var/tmp/sim-snippet.php; chmod 644 /var/tmp/sim-snippet.php
    cd /srv/panel/current && runuser -u panel -- php artisan tinker --execute "require \"/var/tmp/sim-snippet.php\";" 2>&1'
}
set_env() { # key value: replace the key in .env or append it (awk: no sed escaping of the value)
  K="$1" V="$2" awk 'BEGIN{k=ENVIRON["K"];v=ENVIRON["V"];d=0} $0 ~ "^"k"=" {if(!d){print k"="v;d=1};next} {print} END{if(!d)print k"="v}' .env > .env.tmp && mv .env.tmp .env
}

# ------------------------------------------------------------------ build (the "GitHub runner")
build() { # version -> $WORK/build.tar.gz
  local version=$1
  step "CI build $version"
  (
    cd "$WORK/repo"
    sed -i '' "s/^APP_VERSION=.*/APP_VERSION=$version/" .env 2>/dev/null || true
    grep -q '^APP_VERSION=' .env || echo "APP_VERSION=$version" >> .env
    # COPYFILE_DISABLE: macOS tar would add ._* AppleDouble files to the release.
    COPYFILE_DISABLE=1 tar --exclude='storage/logs/*' -czf "$WORK/build.tar.gz" -C "$WORK/repo" .
  )
  ok "release $(du -h "$WORK/build.tar.gz" | cut -f1)"
}

step "Checkout and dependencies ($PROJECT)"
rm -rf "$WORK" && mkdir -p "$WORK/repo"
git -C "$PROJECT" archive HEAD | tar -x -C "$WORK/repo"
cd "$WORK/repo"
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
[[ -f "$PROJECT/auth.json" ]] && cp "$PROJECT/auth.json" auth.json
COMPOSER_MAX_PARALLEL_HTTP=2 composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress --quiet
ok "composer install --no-dev"
if [[ -f package.json ]]; then
  (npm ci --no-audit --no-fund --loglevel=error || npm install --no-audit --no-fund --loglevel=error) >/dev/null 2>&1
  npm run build >/dev/null 2>&1 && ok "npm run build" || bad "npm run build"
fi
[[ -n "$BUILD_CMD" ]] && { bash -c "$BUILD_CMD" >/dev/null && ok "$BUILD_CMD" || bad "$BUILD_CMD"; }
cp .env.example .env
for kv in APP_ENV=production APP_DEBUG=false "APP_URL=https://$DOMAIN" LOG_LEVEL=warning \
  QUEUE_CONNECTION=redis CACHE_STORE=redis CACHE_DRIVER=redis SESSION_DRIVER=redis SESSION_SECURE_COOKIE=true "${ENVS[@]+"${ENVS[@]}"}"; do
  set_env "${kv%%=*}" "${kv#*=}"
done
rm -rf .git .github node_modules tests .idea .junie .ai .claude .cursor
find storage/logs -name '*.log' -delete 2>/dev/null || true
cp -p auth.json "$WORK/auth.json" 2>/dev/null || true
rm -f auth.json
ok ".env for production, development files removed"

# ------------------------------------------------------------------ panel
step "Panel: $DOMAIN, database $DB, Valkey, deploy without a repository, CI token"
CMDS=$(echo "$COMMANDS" | tr ',' '\n')
OUT=$(php_panel '
$s = App\Models\Site::where("domain", "'"$DOMAIN"'")->first();
if ($s) { app(App\Actions\Sites\DeleteSite::class)->handle($s); }
if ($d = App\Models\Database::acrossClients()->where("name", "'"$DB"'")->first()) { app(App\Actions\Databases\ManageDatabases::class)->drop($d); }
$s = app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "'"$DOMAIN"'", "mode" => "classic", "framework" => "laravel", "php_version" => "8.4"]);
app(App\Actions\Databases\ManageDatabases::class)->create("mariadb", "'"$DB"'", $s);
app(App\Actions\Sites\ManageValkey::class)->set($s, ["enabled" => true, "maxmemory_mb" => 128, "policy" => "noeviction", "persist" => true, "use" => ["cache", "session", "queue"]]);
app(App\Actions\Sites\ManageDeploys::class)->configure($s, ["repo" => "", "branch" => "main", "migrate" => true, "assets" => false, "auto_rollback" => true, "keep" => 5, "commands" => '"$(printf '%s' "$CMDS" | php -r 'echo var_export(stream_get_contents(STDIN), true);')"', "maintenance" => true]);
echo "TOKEN=".app(App\Actions\Sites\ManageCiToken::class)->generate($s)."\n";
echo "CIID=".$s->fresh()->ci_id."\n";')
TOKEN=$(sed -n 's/^TOKEN=//p' <<<"$OUT") CIID=$(sed -n 's/^CIID=//p' <<<"$OUT")
[[ -n "$TOKEN" && -n "$CIID" ]] && ok "site ready" || { bad "panel setup" "$(tail -3 <<<"$OUT")"; exit 1; }

deploy() { # tag: upload the release like the Action and wait for the result
  local tag=$1 status
  docker cp "$WORK/build.tar.gz" "$CONTAINER:/var/tmp/sim-build.tar.gz"
  status=$(TOKEN=$TOKEN CIID=$CIID TAG=$tag in_box <<'SH'
resp=$(curl -sk --resolve panel.localhost:443:127.0.0.1 -H "Authorization: Bearer $TOKEN" \
  -F artifact=@/var/tmp/sim-build.tar.gz -F "ref=$TAG" https://panel.localhost/api/deploy/$CIID/artifact)
url=$(sed -n 's/.*"status_url":"\([^"]*\)".*/\1/p' <<<"$resp" | sed 's#\\/#/#g; s#:8443##')
[[ -n "$url" ]] || { echo "upload: $resp"; exit; }
for _ in $(seq 1 300); do
  s=$(curl -sk --resolve panel.localhost:443:127.0.0.1 -H "Authorization: Bearer $TOKEN" "$url" | sed -n 's/.*"status":"\([a-z_]*\)".*/\1/p')
  [[ -n "$s" && "$s" != running ]] && break; sleep 2
done
echo "$s"
SH
)
  [[ "$status" == success ]] && ok "deploy $tag: success" || bad "deploy $tag" "$status"
  docker exec "$CONTAINER" bash -c "grep -E '^==>|health:' \$(ls -t /var/log/panel-agent/deploys/$DOMAIN-*.log | head -1) | cut -c1-110 | sed 's/^/      /'"
}

check_site() { # what the release must do: answer, and its workers run
  local code
  code=$(docker exec "$CONTAINER" curl -sk -o /dev/null -w '%{http_code}' --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/")
  [[ "$code" =~ ^(200|302)$ ]] && ok "https://$DOMAIN/ answers $code" || bad "https://$DOMAIN/" "$code"
  [[ $(docker exec "$CONTAINER" readlink "/srv/sites/$DOMAIN/current/public/storage" 2>/dev/null) == */storage/app/public ]] \
    && ok "public/storage is the storage link" || bad "public/storage link"
  local failed
  failed=$(docker exec "$CONTAINER" systemctl list-units --all --plain --no-legend "wspanel-web_${DOMAIN//./_}*" | awk '$3=="failed"{print $1}')
  [[ -z "$failed" ]] && ok "no failed units" || bad "failed units" "$failed"
}

build v1.0.0-sim
deploy v1.0.0-sim
if [[ -n "$WORKERS" ]]; then
  step "Workers: $WORKERS"
  out=$(php_panel '$s = App\Models\Site::where("domain", "'"$DOMAIN"'")->sole(); foreach (explode(",", "'"$WORKERS"'") as $w) { try { app(App\Actions\Sites\ManageWorkers::class)->create($s, trim($w)); echo "ok $w\n"; } catch (Throwable $e) { echo "ERR $w: ".$e->getMessage()."\n"; } }')
  grep -q ERR <<<"$out" && bad "workers" "$(grep ERR <<<"$out")" || ok "workers added"
  sleep 10
fi
check_site

if ((FRANKEN)); then
  step "FrankenPHP: laravel/octane in the build, worker mode"
  (
    cd "$WORK/repo"
    [[ -f "$WORK/auth.json" ]] && cp "$WORK/auth.json" auth.json
    if ! grep -q '"laravel/octane"' composer.json; then
      COMPOSER_MAX_PARALLEL_HTTP=2 composer require laravel/octane --update-no-dev --no-interaction --no-progress --quiet -o
    fi
    cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php
    rm -f auth.json
  )
  ok "octane + public/frankenphp-worker.php"
  php_panel '$s = App\Models\Site::where("domain", "'"$DOMAIN"'")->sole(); app(App\Actions\Sites\ChangeRuntime::class)->switchMode($s, ["mode" => "worker", "framework" => "laravel"]);' >/dev/null
  build v1.1.0-sim
  deploy v1.1.0-sim
  # A second release: the first one's workers must stop cleanly (no SIGSEGV of FrankenPHP's php-cli).
  build v1.1.1-sim
  deploy v1.1.1-sim
  sleep 10
  docker exec "$CONTAINER" grep -q 'worker {' "/etc/panel-sites/web_${DOMAIN//./_}.Caddyfile" && ok "served by the FrankenPHP worker" || bad "worker mode"
  check_site
fi

printf '\n\033[1m%s passed, %s failed\033[0m  (release build: %s)\n' "$PASS" "$FAIL" "$WORK"
((FAIL == 0))
