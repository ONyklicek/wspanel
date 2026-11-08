#!/usr/bin/env bash
# WSPanel: XHProf + XHGui, switchable per site (classic PHP-FPM sites).
# Run by install.sh --xhprof=yes, or later by the agent (Server → Moduly).
# Idempotent.
#
#   xhprof.sh <php version>...
#
# The extension is installed for every given PHP version but loaded only by
# the pools of sites that have profiling on. XHGui and the collector come
# from Composer with the versions locked in files/xhgui (checked: no known
# advisories), owned by root and read-only for the sites.
set -euo pipefail
(($# > 0)) || { echo "usage: xhprof.sh <php version>..." >&2; exit 2; }
XHGUI_VERSION=0.23.7
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FILES="$HERE/files"; [[ -d "$FILES/xhgui" ]] || FILES=/usr/lib/wspanel/files
DIR=/usr/share/wspanel-xhgui
export DEBIAN_FRONTEND=noninteractive COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

pkgs=()
for v in "$@"; do
  [[ "$v" =~ ^[0-9]\.[0-9]$ ]] || { echo "invalid PHP version: $v" >&2; exit 2; }
  pkgs+=("php$v-xhprof" "php$v-sqlite3")
done
apt-get install -y -q --no-install-recommends "${pkgs[@]}" >/dev/null
for v in "$@"; do
  # Loaded per pool (php_admin_value[extension]), never everywhere.
  phpdismod -v "$v" -s ALL xhprof 2>/dev/null || true
done

install -d -m 0755 "$DIR"
if [[ "$(cat "$DIR/xhgui/.version" 2>/dev/null)" != "$XHGUI_VERSION" ]] || ! cmp -s "$FILES/xhgui/xhgui/composer.lock" "$DIR/xhgui/composer.lock"; then
  tmp="$(mktemp -d)"
  composer create-project --no-install --no-scripts --no-progress "perftools/xhgui:$XHGUI_VERSION" "$tmp/xhgui" >/dev/null
  cp "$FILES/xhgui/xhgui/composer.json" "$FILES/xhgui/xhgui/composer.lock" "$tmp/xhgui/"
  composer install -d "$tmp/xhgui" --no-dev --no-scripts --no-progress --optimize-autoloader >/dev/null
  rm -rf "$tmp/xhgui/tests" "$tmp/xhgui/docker"* "$tmp/xhgui/Dockerfile" "$tmp/xhgui/mongo.init.d" "$tmp/xhgui/webroot/web.config"
  echo "$XHGUI_VERSION" > "$tmp/xhgui/.version"
  rm -rf "$DIR/xhgui" && mv "$tmp/xhgui" "$DIR/xhgui"
  rm -rf "$tmp"
fi
if ! cmp -s "$FILES/xhgui/collector/composer.lock" "$DIR/collector/composer.lock"; then
  rm -rf "$DIR/collector" && install -d -m 0755 "$DIR/collector"
  cp "$FILES/xhgui/collector/composer.json" "$FILES/xhgui/collector/composer.lock" "$DIR/collector/"
  composer install -d "$DIR/collector" --no-dev --no-scripts --no-progress --optimize-autoloader >/dev/null
fi
install -m 0644 "$FILES/xhgui/prepend.php" "$DIR/prepend.php"
# The front controller first imports the site's new profiles.
install -m 0644 "$FILES/xhgui/viewer-index.php" "$DIR/xhgui/webroot/index.php"
# Templates compiled now, as root; the cache stays read-only afterwards.
rm -rf "$DIR/xhgui/cache" && install -d -m 0755 "$DIR/xhgui/cache"
php "$FILES/xhgui/warm.php"
chown -R root:root "$DIR" && chmod -R a+rX,go-w "$DIR"
install -d -m 0755 /etc/panel-web/xhgui
echo "xhprof ready"
