#!/usr/bin/env bash
# Fast loop for panel UI work: build assets on the Mac, copy the panel code into
# the dev container's current release (vendor stays), clear caches, reload FPM.
# Dependencies changed (composer.json)? Use `make dev-provision` instead.
set -euo pipefail
cd "$(dirname "$0")/../panel"
npm run build >/dev/null
docker compose -f ../dev/compose.yaml exec -T devserver bash -c '
  set -e
  rel=$(readlink -f /srv/panel/current)
  rsync -a --delete --exclude=/vendor --exclude=/node_modules --exclude=/.env --exclude=/storage --exclude=/bootstrap/cache \
    --exclude=/database/database.sqlite --exclude=/public/vendor /opt/wspanel/panel/ "$rel/"
  chown -R panel:panel "$rel"
  setfacl -R -m u:panel-web:rX,d:u:panel-web:rX "$rel/public"
  cd "$rel"
  runuser -u panel -- php artisan migrate --force >/dev/null
  runuser -u panel -- php artisan optimize:clear >/dev/null
  runuser -u panel -- php artisan optimize >/dev/null
  systemctl reload php8.4-fpm
'
echo "panel synced"
