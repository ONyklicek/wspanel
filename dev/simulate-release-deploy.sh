#!/usr/bin/env bash
# Simulates a team's release flow against the dev server, end to end:
#
#   GitHub: publish release vX.Y.Z  ->  Actions: composer + npm build, tar.gz
#   ->  POST /api/deploy/<id>/artifact (CI token)  ->  wait for the status
#
# for a real Laravel 13 app ("intranet.local", internal domain, internal CA)
# with a database, a queue worker and the scheduler: first release in
# classic mode (PHP-FPM), then the site switches to worker mode (FrankenPHP,
# Laravel Octane) and the next release deploys the same way.
#
# Run inside the dev container (make dev-provision first):
#   docker compose -f dev/compose.yaml exec devserver /opt/wspanel/dev/simulate-release-deploy.sh
set -euo pipefail
DOMAIN=intranet.local
CI=/var/tmp/sim-ci            # the "GitHub runner": the repository and the build happen here
PANEL=https://panel.localhost
PASS=0 FAIL=0
ok() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m %s %s\n' "$1" "${2:-}"; }
contains() { if [[ "$2" == *"$3"* ]]; then ok "$1"; else bad "$1" "— '$3' not in '${2:0:200}'"; fi; }
step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
php_panel() { printf '<?php\n%s\n' "$1" > /var/tmp/sim-snippet.php; chmod 644 /var/tmp/sim-snippet.php
  (cd /srv/panel/current && runuser -u panel -- php artisan tinker --execute "require '/var/tmp/sim-snippet.php';" 2>&1); }
node_bin="$(ls -d /opt/fnm/node-versions/v*/installation/bin | sort -V | tail -1)"
id ci &>/dev/null || useradd --create-home --shell /bin/bash ci
as_ci() { runuser -u ci -- env HOME=/home/ci PATH="$node_bin:/usr/local/bin:/usr/bin:/bin" COMPOSER_NO_INTERACTION=1 "$@"; }

step "Panel: the site, its database, workers and a CI token"
TOKEN=$(php_panel '
$s = App\Models\Site::where("domain", "'$DOMAIN'")->first();
if ($s) { app(App\Actions\Sites\DeleteSite::class)->handle($s); }
$s = app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "'$DOMAIN'", "mode" => "classic", "framework" => "laravel", "php_version" => "8.4"]);
app(App\Actions\Databases\ManageDatabases::class)->create("mariadb", "intranet_local", $s);
echo "TOKEN=".app(App\Actions\Sites\ManageCiToken::class)->generate($s)."\n";' | sed -n 's/^TOKEN=//p')
CI_ID=$(php_panel 'echo App\Models\Site::where("domain", "'$DOMAIN'")->value("ci_id");' | tail -1)
[[ -n "$TOKEN" && -n "$CI_ID" ]] && ok "site $DOMAIN with a database and a CI token" || { bad "panel setup"; exit 1; }

step "Repository (once): Laravel 13 + Octane for FrankenPHP"
if [[ ! -f $CI/repo/artisan ]]; then
  rm -rf $CI && install -d -o ci -g ci $CI
  as_ci composer create-project --quiet --prefer-dist laravel/laravel $CI/repo
  as_ci composer --working-dir=$CI/repo require --quiet laravel/octane
  # The worker script Octane's installer would write (frankenphp-worker.php).
  as_ci cp $CI/repo/vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php $CI/repo/public/frankenphp-worker.php
  cat > /var/tmp/sim-routes.php <<'PHP'
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// What the release shows, where it runs and that the database answers.
Route::get('/', fn () => response()->json([
    'app' => 'intranet', 'version' => trim((string) @file_get_contents(base_path('VERSION'))),
    'server' => isset($_SERVER['FRANKENPHP_WORKER']) || function_exists('frankenphp_handle_request') ? 'frankenphp' : PHP_SAPI,
    'db' => DB::connection()->getDatabaseName(), 'migrated' => DB::table('migrations')->count() > 0,
]));
PHP
  as_ci cp /var/tmp/sim-routes.php $CI/repo/routes/web.php
  ok "repository ready"
else
  ok "repository from the last run"
fi

release() { # tag: the GitHub Action of the release
  local tag=$1
  step "Release $tag: GitHub Actions builds and uploads"
  echo "$tag" | as_ci tee $CI/repo/VERSION >/dev/null
  as_ci composer --working-dir=$CI/repo install --quiet --no-dev --optimize-autoloader
  (cd $CI/repo && as_ci npm ci --no-audit --no-fund --loglevel=error && as_ci npm run build >/dev/null)
  as_ci tar --exclude=.git --exclude=node_modules --exclude=.env --exclude='storage/logs/*' -czf $CI/build.tar.gz -C $CI/repo .
  ok "build $(du -h $CI/build.tar.gz | cut -f1)"
  local resp status_url status
  resp=$(curl -sk --resolve panel.localhost:443:127.0.0.1 -H "Authorization: Bearer $TOKEN" \
    -F "artifact=@$CI/build.tar.gz" -F "ref=$tag" -F "commit=$(printf '%040d' 0 | tr 0 a)" "$PANEL/api/deploy/$CI_ID/artifact")
  status_url=$(sed -n 's/.*"status_url":"\([^"]*\)".*/\1/p' <<<"$resp" | sed 's#\\/#/#g; s#:8443##')
  [[ -n "$status_url" ]] && ok "artifact accepted (202)" || { bad "upload" "$resp"; return; }
  for _ in $(seq 1 120); do
    status=$(curl -sk --resolve panel.localhost:443:127.0.0.1 -H "Authorization: Bearer $TOKEN" "$status_url" | sed -n 's/.*"status":"\([a-z_]*\)".*/\1/p')
    [[ "$status" != running && -n "$status" ]] && break
    sleep 2
  done
  [[ "$status" == success ]] && ok "the Action sees the deploy succeed" || bad "deploy status" "$status"
}

site_json() { curl -sk --resolve $DOMAIN:443:127.0.0.1 "https://$DOMAIN/"; }

release v1.0.0
step "Classic mode (PHP-FPM)"
php_panel '$s = App\Models\Site::where("domain", "'$DOMAIN'")->sole(); foreach (["queue", "scheduler"] as $w) { app(App\Actions\Sites\ManageWorkers::class)->create($s, $w); }' >/dev/null
out=$(site_json)
contains "v1.0.0 is live" "$out" '"version":"v1.0.0"'
contains "  … served by PHP-FPM" "$out" '"server":"fpm-fcgi"'
contains "  … its database migrated by the deploy" "$out" '"migrated":true'
contains "  … on the internal CA's certificate" "$(echo | openssl s_client -connect 127.0.0.1:443 -servername $DOMAIN 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null)" "Caddy Local Authority"
contains "the queue worker runs from the release" "$(systemctl list-units --plain --no-legend 'wspanel-web_intranet_local-queue@*' 2>/dev/null)" "running"

step "Switch to worker mode (FrankenPHP + Octane)"
php_panel '$s = App\Models\Site::where("domain", "'$DOMAIN'")->sole(); app(App\Actions\Sites\ChangeRuntime::class)->switchMode($s, ["mode" => "worker", "framework" => "laravel"]);' >/dev/null
release v1.1.0
out=$(site_json)
contains "v1.1.0 is live" "$out" '"version":"v1.1.0"'
contains "  … served by the FrankenPHP worker" "$out" '"server":"frankenphp"'
contains "  … same database" "$out" '"db":"intranet_local"'
contains "workers moved to the new release" "$(systemctl list-units --plain --no-legend 'wspanel-web_intranet_local-queue@*' 2>/dev/null | grep running)" "running"

printf '\n\033[1m%s passed, %s failed\033[0m\n' "$PASS" "$FAIL"
((FAIL == 0))
