#!/usr/bin/env bash
# WSPanel end-to-end test, run INSIDE the dev container as root after
# provision/install.sh:   docker compose -f dev/compose.yaml exec devserver /opt/wspanel/dev/e2e.sh
#
# Drives everything through the panel's own code (as the panel user, i.e. the
# same path the UI takes) and checks the result on the real system: HTTP via
# the edge proxy, SSH, files, processes, databases. Prints PASS/FAIL per check.
# Leaves demo data behind (sites shop/sf/node/worker.localhost etc.).
set -uo pipefail

PASS=0
FAIL=0
FAILED=()
W=/tmp/e2e
mkdir -p "$W" && chmod 1777 "$W" # the panel user writes hand-over files here

green() { printf '\033[32m%s\033[0m' "$*"; }
red() { printf '\033[31m%s\033[0m' "$*"; }
section() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
ok() { PASS=$((PASS + 1)); printf '  %s %s\n' "$(green PASS)" "$1"; }
bad() { FAIL=$((FAIL + 1)); FAILED+=("$1"); printf '  %s %s %s\n' "$(red FAIL)" "$1" "${2:+— $2}"; }
check() { local name=$1; shift; if "$@" >/dev/null 2>&1; then ok "$name"; else bad "$name"; fi; }
expect() { # expect NAME ACTUAL WANTED
	if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1" "got '$2', want '$3'"; fi
}
contains() { # contains NAME HAYSTACK NEEDLE
	if [[ "$2" == *"$3"* ]]; then ok "$1"; else bad "$1" "'$3' not in '${2:0:160}'"; fi
}
not_contains() { # not_contains NAME HAYSTACK NEEDLE
	if [[ "$2" != *"$3"* ]]; then ok "$1"; else bad "$1" "'$3' unexpectedly in '${2:0:160}'"; fi
}

# php CODE [VAR=VALUE ...]: run PHP in the panel's context (Laravel booted) as the panel user.
php() {
	local code=$1; shift
	printf '<?php\n%s\n' "$code" >"$W/snippet.php"
	chmod 644 "$W/snippet.php"
	(cd /srv/panel/current && runuser -u panel -- env "$@" php artisan tinker --execute "require '$W/snippet.php';" 2>&1)
}
site() { echo "\$s = App\\Models\\Site::where('domain', '$1')->sole();"; }
https() { # https HOST PATH -> body
	curl -sk --max-time 15 --resolve "$1:443:127.0.0.1" "https://$1$2"
}
code() { curl -sk --max-time 15 -o /dev/null -w '%{http_code}' --resolve "$1:443:127.0.0.1" "https://$1$2"; }
me=$(hostname -I | awk '{print $1}') # a non-loopback address of this host = an outsider
wait_body() { # wait_body HOST PATH NEEDLE -> last body (retries ~3 min)
	local b=""
	for _ in $(seq 1 90); do
		b=$(https "$1" "$2"); [[ "$b" == *"$3"* ]] && break
		sleep 2
	done
	echo "$b"
}
wait_deploy() { # wait_deploy DOMAIN RELEASE -> final status
	local st=running
	for _ in $(seq 1 90); do
		st=$(php "$(site "$1") echo app(App\\Actions\\Sites\\ManageDeploys::class)->log(\$s, '$2', 0)['status'];" | tr -d '[:space:]')
		[[ "$st" != running ]] && break
		sleep 2
	done
	echo "$st"
}

# ----------------------------------------------------------------------------
section "Services"
for u in panel-agent panel-proxy panel-web panel-scheduler php8.3-fpm php8.4-fpm mariadb postgresql ssh fail2ban; do
	check "$u active" systemctl is-active --quiet "$u"
done
check "ufw active" bash -c 'ufw status | grep -q "Status: active"'
check "no shared Valkey: each site has its own" bash -c '! systemctl is-active --quiet valkey-server && ! ss -ltn | grep -q ":6379 "'
expect "ip_unprivileged_port_start = 1024" "$(sysctl -n net.ipv4.ip_unprivileged_port_start)" "1024"
expect "agent socket root:panel 0660" "$(stat -c '%U:%G %a' /run/panel-agent.sock)" "root:panel 660"
expect "panel login page" "$(code panel.localhost /login)" "200"
expect "panel requires login" "$(code panel.localhost /sites)" "302"
expect "unknown host gets no site" "$(curl -sk -o /dev/null -w '%{http_code}' --resolve nothing.localhost:443:127.0.0.1 https://nothing.localhost/)" "000"

php 'app(App\Actions\Services\SyncServices::class)->handle(); echo App\Models\Service::count();' >/dev/null
check "services mirrored into the panel" test "$(php 'echo App\Models\Service::where("active_state","active")->count();' | tr -d '[:space:]')" -ge 7

# ----------------------------------------------------------------------------
section "Sites in all modes"
php 'foreach ([
    ["shop.localhost", ["mode" => "classic", "framework" => "laravel", "php_version" => "8.4"]],
    ["sf.localhost", ["mode" => "classic", "framework" => "symfony", "php_version" => "8.3"]],
    ["worker.localhost", ["mode" => "worker", "framework" => "laravel"]],
    ["node.localhost", ["mode" => "node", "framework" => "node", "node_version" => "24"]],
    ["other.localhost", ["mode" => "classic", "framework" => "php", "php_version" => "8.4"]],
] as [$d, $r]) {
    if (! App\Models\Site::where("domain", $d)->exists()) {
        $s = app(App\Actions\Sites\CreateSite::class)->handle(["domain" => $d] + $r);
        echo $d, " ", $s->status->value, " ", $s->last_error, PHP_EOL;
    }
}' | sed 's/^/    /'
for d in shop sf node other; do
	expect "$d.localhost answers via edge" "$(code $d.localhost /)" "200"
done
contains "classic PHP 8.3 site runs 8.3" "$(https sf.localhost /)" "PHP 8.3"
contains "node site runs Node 24" "$(https node.localhost /)" "Node 24"
check "classic FPM worker runs as site user" bash -c 'https() { curl -sk --resolve shop.localhost:443:127.0.0.1 https://shop.localhost/ >/dev/null; }; https; ps -o user= -C php-fpm8.4 | grep -q web_shop_localhost'
expect "node port is privileged (< 1024)" "$(php 'echo App\Models\Site::where("domain","node.localhost")->value("port") < 1024 ? "yes" : "no";' | tr -d '[:space:]')" "yes"

# worker mode: a real FrankenPHP worker script keeps state between requests
install -o web_worker_localhost -g web_worker_localhost -m 0640 /dev/stdin /srv/sites/worker.localhost/current/public/frankenphp-worker.php <<'PHP'
<?php
$n = 0;
while (frankenphp_handle_request(function () use (&$n) {
    $n++;
    header('Content-Type: text/plain');
    echo "worker.localhost request #{$n} php ".PHP_VERSION.' user '.posix_getpwuid(posix_geteuid())['name']."\n";
})) {}
PHP
php 'app(App\Actions\Services\SyncServices::class)->handle(); app(App\Actions\Services\ControlService::class)->handle(App\Models\Service::where("unit","wspanel-web_worker_localhost-app.service")->sole(), "restart");' >/dev/null
sleep 2
first=$(https worker.localhost /a)
second=$(https worker.localhost /b)
contains "worker mode answers (FrankenPHP)" "$first" "request #"
check "worker keeps state between requests" test "${first%% php*}" != "${second%% php*}"
contains "worker runs as site user" "$second" "user web_worker_localhost"

# ----------------------------------------------------------------------------
section "Isolation"
check "site user cannot read another site" bash -c '! runuser -u web_shop_localhost -- ls /srv/sites/sf.localhost'
check "site user cannot reach the agent socket" bash -c '! runuser -u web_shop_localhost -- php8.4 -r "exit(@stream_socket_client(\"unix:///run/panel-agent.sock\") ? 0 : 1);"'
check "site user cannot read agent config" bash -c '! runuser -u web_shop_localhost -- cat /etc/panel-agent/agent.json'
check "web tier cannot read .env" bash -c '! runuser -u panel-web -- cat /srv/sites/shop.localhost/shared/.env'
runuser -u web_shop_localhost -- ln -sfn /srv/sites/sf.localhost/shared/.env.local /srv/sites/shop.localhost/current/public/leak.txt
runuser -u web_shop_localhost -- ln -sfn /var/lib/panel-proxy /srv/sites/shop.localhost/current/public/leak-tls
check "planted symlink does not leak another site's .env" bash -c '! curl -sk --resolve shop.localhost:443:127.0.0.1 https://shop.localhost/leak.txt | grep -q DATABASE_URL'
check "planted symlink does not reach TLS keys" bash -c '! curl -sk --resolve shop.localhost:443:127.0.0.1 https://shop.localhost/leak-tls/ | grep -qi "certificates"'
rm -f /srv/sites/shop.localhost/current/public/leak.txt /srv/sites/shop.localhost/current/public/leak-tls
node_port=$(php 'echo App\Models\Site::where("domain","node.localhost")->value("port");' | tr -d '[:space:]')
systemctl stop wspanel-web_node_localhost-app
check "other site user cannot squat the node port" bash -c "! runuser -u web_shop_localhost -- php8.4 -r 'exit(@stream_socket_server(\"tcp://127.0.0.1:$node_port\") ? 0 : 1);'"
systemctl start wspanel-web_node_localhost-app

# ----------------------------------------------------------------------------
section "Workers (Laravel)"
php "$(site shop.localhost) foreach (['horizon', 'scheduler', 'pulse', 'reverb'] as \$w) if (! file_exists('/etc/systemd/system/wspanel-web_shop_localhost-'.\$w.'@.service')) app(App\\Actions\\Sites\\ManageWorkers::class)->create(\$s, \$w);" >/dev/null
for w in horizon scheduler pulse; do
	check "worker $w installed (one instance per release)" test -f /etc/systemd/system/wspanel-web_shop_localhost-$w@.service
done
check "no single-unit workers left (migrated at agent start)" bash -c '! ls /etc/systemd/system/wspanel-*-{horizon,queue,scheduler,reverb,pulse}.service 2>/dev/null | grep -q .'
check "worker units are sandboxed (ProtectSystem=strict)" grep -q ProtectSystem=strict /etc/systemd/system/wspanel-web_shop_localhost-horizon@.service
check "workers run from their release, not current" grep -q 'WorkingDirectory=/srv/sites/shop.localhost/releases/%i' /etc/systemd/system/wspanel-web_shop_localhost-horizon@.service
contains "reverb gets a privileged port" "$(grep -o -- '--port=[0-9]*' /etc/systemd/system/wspanel-web_shop_localhost-reverb@.service)" "--port="
contains "edge routes /app to reverb" "$(cat /etc/panel-proxy/sites/shop.localhost.caddy)" "@reverb path /app"

# ----------------------------------------------------------------------------
section "Databases"
php "$(site shop.localhost) if (! App\\Models\\Database::where('name','shop_db')->exists()) app(App\\Actions\\Databases\\ManageDatabases::class)->create('mariadb', 'shop_db', \$s);
$(site sf.localhost) if (! App\\Models\\Database::where('name','sf_db')->exists()) app(App\\Actions\\Databases\\ManageDatabases::class)->create('postgresql', 'sf_db', \$s);" >/dev/null
contains "Laravel .env got DB_* (MariaDB)" "$(cat /srv/sites/shop.localhost/shared/.env)" 'DB_DATABASE="shop_db"'
contains "Symfony .env.local got DATABASE_URL (PostgreSQL)" "$(cat /srv/sites/sf.localhost/shared/.env.local)" 'DATABASE_URL="postgresql://sf_db:'
expect ".env belongs to the site user, 0600" "$(stat -c '%U %a' /srv/sites/shop.localhost/shared/.env)" "web_shop_localhost 600"
cat >"$W/db.php" <<'PHP'
<?php
// usage: db.php <env file> <write|count|delete>
$e = [];
foreach (file($argv[1]) as $l) {
    if (preg_match('/^(DB_\w+)="(.*)"$/', trim($l), $m)) $e[$m[1]] = $m[2];
}
$pdo = new PDO("mysql:host={$e['DB_HOST']};port={$e['DB_PORT']};dbname={$e['DB_DATABASE']}", $e['DB_USERNAME'], $e['DB_PASSWORD']);
match ($argv[2]) {
    'write' => $pdo->exec("CREATE TABLE IF NOT EXISTS orders (id INT PRIMARY KEY, note VARCHAR(50)); REPLACE INTO orders VALUES (1, 'e2e')"),
    'delete' => $pdo->exec('DELETE FROM orders'),
    default => null,
};
echo $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
PHP
chmod 644 "$W/db.php"
dbq() { runuser -u web_shop_localhost -- php8.4 "$W/db.php" /srv/sites/shop.localhost/shared/.env "$1" 2>&1; }
expect "site connects with its credentials and writes" "$(dbq write)" "1"
php "\$db = App\\Models\\Database::where('name','shop_db')->sole(); app(App\\Actions\\Databases\\ManageDatabases::class)->backupNow(\$db);" >/dev/null
expect "data deleted" "$(dbq delete)" "0"
php "\$db = App\\Models\\Database::where('name','shop_db')->sole(); \$b = \$db->backups()->where('file','not like','%pre-restore%')->first(); app(App\\Actions\\Databases\\ManageDatabases::class)->restore(\$b);" >/dev/null
expect "restore brings the data back" "$(dbq count)" "1"
check "pre-restore snapshot exists" bash -c 'ls /var/backups/wspanel/mariadb/shop_db/ | grep -q pre-restore'
expect "dumps are root:panel 0640" "$(stat -c '%U:%G %a' "$(ls -d /var/backups/wspanel/mariadb/shop_db/* | head -1)")" "root:panel 640"
sfpw=$(sed -n 's/^DATABASE_URL="postgresql:\/\/sf_db:\([^@]*\)@.*/\1/p' /srv/sites/sf.localhost/shared/.env.local)
php 'if (! App\Models\Database::where("name","other_pg")->exists()) app(App\Actions\Databases\ManageDatabases::class)->create("postgresql", "other_pg", null);' >/dev/null
check "PostgreSQL role cannot connect to another database" bash -c "! PGPASSWORD='$sfpw' psql -h 127.0.0.1 -U sf_db -d other_pg -Atc 'select 1'"
check "PostgreSQL role connects to its own database" bash -c "PGPASSWORD='$sfpw' psql -h 127.0.0.1 -U sf_db -d sf_db -Atc 'select 1' | grep -q 1"
contains "MariaDB GRANT escapes _" "$(mariadb --raw -N -e "SHOW GRANTS FOR 'shop_db'@'127.0.0.1'" | tail -1)" 'shop\_db'

tok() { php "\$db = App\\Models\\Database::where('name','$1')->sole(); echo app(App\\Services\\Agent\\AgentClient::class)->call('db.access', \$db->agentParams() + ['tool' => '$2'])['token'];" | tr -d '[:space:]'; }
pc() { curl -sk --resolve panel.localhost:443:127.0.0.1 "$@"; }
t=$(tok shop_db phpmyadmin)
pc -c "$W/jar1" -b "$W/jar1" -L -o "$W/pma.html" -d "token=$t" https://panel.localhost/dbtools/pma/wspanel-signon.php
contains "phpMyAdmin opens the database via token" "$(cat "$W/pma.html")" "orders"
expect "phpMyAdmin token is single-use" "$(pc -o /dev/null -w '%{http_code}' -d "token=$t" https://panel.localhost/dbtools/pma/wspanel-signon.php)" "403"
t=$(tok sf_db adminer)
pc -c "$W/jar2" -b "$W/jar2" -L -o "$W/adm.html" -w '%{url_effective}' -d "token=$t" https://panel.localhost/dbtools/adminer/wspanel-signon.php >"$W/adm.url"
contains "Adminer opens PostgreSQL via token" "$(cat "$W/adm.url")" "pgsql=127.0.0.1"
expect "Adminer refuses another server (SSRF)" "$(pc -b "$W/jar2" -o /dev/null -w '%{http_code}' 'https://panel.localhost/dbtools/adminer/?server=10.0.0.1&username=x')" "403"
expect "Adminer refuses its own login form" "$(pc -o /dev/null -w '%{http_code}' -d 'auth[driver]=server&auth[server]=10.0.0.1' https://panel.localhost/dbtools/adminer/)" "403"

# ----------------------------------------------------------------------------
section "Deploy and webhook"
# A fresh site every run, so the deploy checks start from a known state.
php 'if ($s = App\Models\Site::where("domain","app.localhost")->first()) app(App\Actions\Sites\DeleteSite::class)->handle($s);
app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "app.localhost", "mode" => "classic", "framework" => "laravel", "php_version" => "8.4"]);' >/dev/null
id git >/dev/null 2>&1 || useradd --create-home --shell /usr/bin/git-shell git
rm -rf /home/git/app.git "$W/app" && runuser -u git -- git init -q --bare -b main /home/git/app.git
cp -r /opt/wspanel/dev/deploy-test-app "$W/app" && echo v1 >"$W/app/VERSION" && chown -R git:git "$W/app"
gitc() { runuser -u git -- git -c user.email=e2e@local -c user.name=e2e -C "$W/app" "$@"; }
gitc init -q -b main && gitc add -A && gitc commit -qm v1 && gitc push -q /home/git/app.git main
php "$(site app.localhost) app(App\\Actions\\Sites\\ManageDeploys::class)->configure(\$s, ['repo' => 'git@localhost:app.git', 'branch' => 'main', 'migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 5, 'commands' => \"php artisan install:permissions --force\"]);
file_put_contents('$W/deploy.pub', app(App\\Actions\\Sites\\ManageDeploys::class)->publicKey(\$s));" >/dev/null
install -d -m 0700 -o git -g git /home/git/.ssh && cat "$W/deploy.pub" >/home/git/.ssh/authorized_keys && chown git:git /home/git/.ssh/authorized_keys
# Start without APP_KEY (repeatable runs): the first deploy must answer 500 and roll back.
php "$(site app.localhost) \$m = app(App\\Actions\\Sites\\ManageDeploys::class); \$m->envSet(\$s, preg_replace('/^(APP_KEY|APP_NAME)=.*\\R/m', '', \$m->envGet(\$s)));" >/dev/null
rel=$(php "$(site app.localhost) echo app(App\\Actions\\Sites\\ManageDeploys::class)->start(\$s);" | tr -d '[:space:]')
expect "deploy without APP_KEY rolls back (health check)" "$(wait_deploy app.localhost "$rel")" "rolled_back"
contains "site still serves the previous release" "$(https app.localhost /)" "app.localhost"
php "$(site app.localhost) \$m = app(App\\Actions\\Sites\\ManageDeploys::class); \$m->envSet(\$s, 'APP_NAME=Shop'.PHP_EOL.'APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL.\$m->envGet(\$s));" >/dev/null
rel=$(php "$(site app.localhost) echo app(App\\Actions\\Sites\\ManageDeploys::class)->start(\$s);" | tr -d '[:space:]')
expect "deploy succeeds" "$(wait_deploy app.localhost "$rel")" "success"
contains "deployed app answers" "$(https app.localhost /)" "deploy-test-app v1"
# Workers are rotated by a deploy: the new release's instance takes over.
php "$(site app.localhost) try { app(App\\Actions\\Sites\\ManageWorkers::class)->create(\$s, 'queue'); } catch (Throwable) {}" >/dev/null
rel_q=$(basename "$(readlink /srv/sites/app.localhost/current)")
check "queue runs as the current release's instance" systemctl is-active --quiet "wspanel-web_app_localhost-queue@$rel_q.service"
contains "build ran as the site user" "$(cat /srv/sites/app.localhost/shared/storage/logs/artisan.log)" "as web_app_localhost"
contains "the app's own command ran after optimize" "$(cat /srv/sites/app.localhost/shared/storage/logs/artisan.log)" "install:permissions --force as web_app_localhost"
contains "assets built and served by the web tier" "$(https app.localhost /build/manifest.json)" '"node":"v'
check "env.set content is redacted in the audit log" bash -c '! grep "\"op\":\"env.set\"" /var/log/panel-agent/audit.log | grep -q APP_KEY=base64'

php "$(site app.localhost) app(App\\Actions\\Sites\\ManageWebhook::class)->enable(\$s); file_put_contents('$W/hook', App\\Actions\\Sites\\ManageWebhook::url(\$s).PHP_EOL.\$s->webhook_secret);" >/dev/null
url=$(sed -n 1p "$W/hook" | sed -E 's#^(https://[^/:]+):[0-9]+#\1#'); secret=$(sed -n 2p "$W/hook") # public port -> in-container 443
echo v2 >"$W/app/VERSION" && chown git:git "$W/app/VERSION" && gitc commit -qam v2 && gitc push -q /home/git/app.git main
php "$(site app.localhost) app(App\\Actions\\Sites\\ManageDeploys::class)->configure(\$s, ['repo' => 'git@localhost:app.git', 'branch' => 'main', 'migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 5, 'commands' => 'install:permissions --force', 'maintenance' => true]);" >/dev/null
body="{\"ref\":\"refs/heads/main\",\"after\":\"$(gitc rev-parse HEAD)\"}"
sig="sha256=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$secret" | awk '{print $NF}')"
hook() { pc -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -H 'X-GitHub-Event: push' "$@" -d "$body" "$url"; }
expect "webhook with a bad signature is refused" "$(hook -H 'X-Hub-Signature-256: sha256=00')" "403"
expect "signed webhook starts a deploy" "$(hook -H "X-Hub-Signature-256: $sig")" "202"
expect "replayed webhook is ignored" "$(hook -H "X-Hub-Signature-256: $sig" -H 'X-GitHub-Delivery: replay-0000-0000')" "200"
sleep 2
rel=$(php "$(site app.localhost) echo App\\Models\\Deployment::where('site_id', \$s->id)->latest('started_at')->value('release');" | tr -d '[:space:]')
php "$(site app.localhost) app(App\\Actions\\Sites\\ManageDeploys::class)->history(\$s);" >/dev/null
rel=$(php "$(site app.localhost) echo App\\Models\\Deployment::where('site_id', \$s->id)->latest('started_at')->value('release');" | tr -d '[:space:]')
expect "webhook deploy succeeds" "$(wait_deploy app.localhost "$rel")" "success"
contains "v2 is live" "$(https app.localhost /)" "deploy-test-app v2"
v2log=$(php "$(site app.localhost) echo app(App\\Actions\\Sites\\ManageDeploys::class)->log(\$s, '$(basename "$(readlink /srv/sites/app.localhost/current)")', 0)['content'] ?? '';")
contains "maintenance page was up during the migrations" "$v2log" "Maintenance page up"
contains "and came down after the switch" "$v2log" "Maintenance page down"
check "no maintenance page left" bash -c "! grep -q wsp_maint /etc/panel-proxy/sites/app.localhost.caddy"
rel_q2=$(basename "$(readlink /srv/sites/app.localhost/current)")
check "the deploy started the queue of the new release" systemctl is-active --quiet "wspanel-web_app_localhost-queue@$rel_q2.service"
for _ in $(seq 1 20); do systemctl is-active --quiet "wspanel-web_app_localhost-queue@$rel_q.service" || break; sleep 0.5; done
check "the old release's queue stopped (it took no new job)" bash -c "! systemctl is-active --quiet wspanel-web_app_localhost-queue@$rel_q.service"
php "$(site app.localhost) app(App\\Actions\\Sites\\ManageDeploys::class)->rollback(\$s);" >/dev/null
contains "rollback brings v1 back" "$(https app.localhost /)" "deploy-test-app v1"

# CI: a release tag through the API, then a build artifact
ci_token=$(php "$(site app.localhost) echo app(App\\Actions\\Sites\\ManageCiToken::class)->generate(\$s);" | tr -d '[:space:]')
api=$(php "$(site app.localhost) echo route('api.deploy.trigger', ['hook' => \$s->refresh()->ci_id]);" | tr -d '[:space:]' | sed -E 's#^(https://[^/:]+):[0-9]+#\1#')
echo v3 >"$W/app/VERSION" && chown git:git "$W/app/VERSION" && gitc commit -qam v3 && gitc tag v3 && gitc push -q /home/git/app.git main v3
ci_wait() { # ci_wait JSON -> final status
	local su st=running
	su=$(echo "$1" | sed -E 's/.*"status_url":"([^"]+)".*/\1/; s#\\/#/#g' | sed -E 's#^(https://[^/:]+):[0-9]+#\1#')
	for _ in $(seq 1 90); do
		st=$(pc -H "Authorization: Bearer $ci_token" "$su" | sed -E 's/.*"status":"([^"]+)".*/\1/')
		[[ "$st" != running ]] && break
		sleep 2
	done
	echo "$st"
}
expect "CI API refuses the webhook secret" "$(pc -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $secret" "$api")" "404"
out=$(pc -X POST -H "Authorization: Bearer $ci_token" -H 'Content-Type: application/json' -d '{"ref":"v3"}' "$api")
expect "CI deploy of tag v3 succeeds" "$(ci_wait "$out")" "success"
contains "tag v3 is live" "$(https app.localhost /)" "deploy-test-app v3"
rm -rf "$W/artifact" && cp -r "$W/app" "$W/artifact" && rm -rf "$W/artifact/.git" && echo v4-ci >"$W/artifact/VERSION"
mkdir -p "$W/artifact/public/build" && echo '{"built":"in-ci"}' >"$W/artifact/public/build/manifest.json"
# A .env built in CI from GitHub secrets: merged, the panel's own keys win.
printf 'FROM_GITHUB_SECRET="s3cr3t"\nAPP_KEY=\nMAIL_HOST="smtp.from-github.example"\n' >"$W/artifact/.env"
mail_before=$(grep -oP '^MAIL_HOST="?\K[^"]*' /srv/sites/app.localhost/shared/.env || true)
tar -czf "$W/build.tar.gz" -C "$W/artifact" .
out=$(pc -X POST -H "Authorization: Bearer $ci_token" -F "artifact=@$W/build.tar.gz" -F ref=v4 -F commit=abcdef1 "$api/artifact")
expect "CI artifact deploy succeeds" "$(ci_wait "$out")" "success"
contains "artifact is live (no build on the server)" "$(https app.localhost /build/manifest.json)" "in-ci"
contains "a value from the CI .env reaches the app" "$(cat /srv/sites/app.localhost/shared/.env)" 'FROM_GITHUB_SECRET="s3cr3t"'
expect "the panel's own key (local mail) is kept" "$(grep -oP '^MAIL_HOST="?\K[^"]*' /srv/sites/app.localhost/shared/.env || true)" "$mail_before"
check "an empty APP_KEY from CI does not wipe the real one" grep -qE '^APP_KEY="?base64:' /srv/sites/app.localhost/shared/.env
check "the release links the shared .env" test -L /srv/sites/app.localhost/current/.env
# (file-manager transfers dl-*/up-* share the directory and expire on their own)
check "artifact file removed after the deploy" bash -c '[ -z "$(ls -A /var/lib/wspanel-artifacts | grep -vE "^(dl|up)-")" ]'

# ----------------------------------------------------------------------------
section "SSH / SFTP"
rm -f "$W/key" "$W/key.pub" && ssh-keygen -q -t ed25519 -N "" -C e2e -f "$W/key"
php "$(site shop.localhost) app(App\\Actions\\Sites\\ManageSsh::class)->handle(\$s, 'shell', file_get_contents('$W/key.pub'));" >/dev/null
SSH=(ssh -i "$W/key" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o BatchMode=yes)
contains "SSH shell as the site user with its PHP" "$("${SSH[@]}" web_shop_localhost@127.0.0.1 'id -un; php -r "echo PHP_VERSION;"')" "web_shop_localhost"
contains "composer and node available" "$("${SSH[@]}" web_shop_localhost@127.0.0.1 'composer --version; node -v')" "Composer version"
command -v sshpass >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -q sshpass >/dev/null
PWSSH=(ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o PubkeyAuthentication=no -o PreferredAuthentications=password)
check "password login refused without a password set" bash -c "! SSHPASS=wrong-password-123 sshpass -e \"\${@}\" web_shop_localhost@127.0.0.1 true" _ "${PWSSH[@]}"
pw=$(php "$(site shop.localhost) echo app(App\\Actions\\Sites\\ManageSsh::class)->handle(\$s, 'shell', file_get_contents('$W/key.pub'), 'generate');" | tr -d '[:space:]')
contains "password login works once a password is set" "$(SSHPASS=$pw sshpass -e "${PWSSH[@]}" web_shop_localhost@127.0.0.1 'id -un' 2>&1)" "web_shop_localhost"
expect "root never logs in with a password" "$(sshd -T -C user=root,host=x,addr=127.0.0.1 | awk '/^passwordauthentication/ {print $2}')" "no"
php "$(site shop.localhost) app(App\\Actions\\Sites\\ManageSsh::class)->handle(\$s, 'shell', file_get_contents('$W/key.pub'), 'remove');" >/dev/null
check "removed password no longer works" bash -c "! SSHPASS='$pw' sshpass -e \"\${@}\" web_shop_localhost@127.0.0.1 true" _ "${PWSSH[@]}"
"${SSH[@]}" -N -L 13306:127.0.0.1:3306 web_shop_localhost@127.0.0.1 2>"$W/tun.err" &
tun=$!
sleep 2
bytes=$( (exec 3<>/dev/tcp/127.0.0.1/13306 && timeout 2 head -c 10 <&3 | wc -c) 2>/dev/null || echo 0)
kill $tun 2>/dev/null
expect "SSH port forwarding into MariaDB is refused" "$(echo "$bytes" | tr -d '[:space:]')" "0"
php "$(site shop.localhost) app(App\\Actions\\Sites\\ManageSsh::class)->handle(\$s, 'sftp', file_get_contents('$W/key.pub'));" >/dev/null
check "SFTP-only refuses a shell" bash -c "! \"\${@}\" web_shop_localhost@127.0.0.1 id" _ "${SSH[@]}"
contains "SFTP works" "$(printf 'ls\n' | sftp -i "$W/key" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -b - web_shop_localhost@127.0.0.1 2>&1)" "releases"
php "$(site shop.localhost) app(App\\Actions\\Sites\\ManageSsh::class)->handle(\$s, 'off', '');" >/dev/null
check "access off removes the key" test ! -f /etc/ssh/authorized_keys/web_shop_localhost

# ----------------------------------------------------------------------------
section "Site on/off, restart, processes"
php "$(site other.localhost) app(App\\Actions\\Sites\\ControlSite::class)->disable(\$s);" >/dev/null
expect "disabled site answers 503" "$(code other.localhost /)" "503"
contains "disabled site shows the notice" "$(https other.localhost /)" "dočasně vypnutý"
php "$(site other.localhost) app(App\\Actions\\Sites\\ControlSite::class)->enable(\$s);" >/dev/null
expect "enabled site answers again" "$(code other.localhost /)" "200"
contains "restart reloads PHP-FPM" "$(php "$(site other.localhost) echo implode(',', app(App\\Actions\\Sites\\ControlSite::class)->restart(\$s));")" "fpm"
contains "processes of a site are listed" "$(php "$(site shop.localhost) echo json_encode(app(App\\Actions\\Sites\\ControlSite::class)->processes(\$s));")" '"name":"horizon"'

# ----------------------------------------------------------------------------
section "Mail"
mp_auth="Authorization: Basic $(cat /etc/mailpit/ui-basic)"
mailpit() { curl -s -H "$mp_auth" "http://127.0.0.1:8025/mailpit/api/v1/$1"; }
curl -s -X DELETE -H "$mp_auth" http://127.0.0.1:8025/mailpit/api/v1/messages >/dev/null
for u in postfix opendkim mailpit; do check "$u active" systemctl is-active --quiet "$u"; done
expect "Mailpit UI needs the panel login" "$(pc -o /dev/null -w '%{http_code}' https://panel.localhost/mailpit/)" "302"
# capture: PHP mail() of a site lands in Mailpit (shop.localhost is in capture mode in dev)
php "$(site shop.localhost) app(App\\Actions\\Mail\\ManageMail::class)->setSiteMode(\$s, 'capture');" >/dev/null
runuser -u web_shop_localhost -- sh -c 'cat > /srv/sites/shop.localhost/current/public/e2e-mail.php' <<'PHPEOF'
<?php echo mail('someone@example.com', 'e2e capture', 'hello') ? 'sent' : 'failed';
PHPEOF
contains "PHP mail() of a capture site succeeds" "$(https shop.localhost /e2e-mail.php)" "sent"
sleep 1
contains "the message is in Mailpit" "$(mailpit messages)" "e2e capture"
rm -f /srv/sites/shop.localhost/current/public/e2e-mail.php
# send: each site may only send as its own domain
php "$(site other.localhost) app(App\\Actions\\Mail\\ManageMail::class)->setSiteMode(\$s, 'send'); app(App\\Actions\\Mail\\ManageMail::class)->dkim('other.localhost');" >/dev/null
envf=/srv/sites/other.localhost/shared/.env
mu=$(sed -n 's/^MAIL_USERNAME="\(.*\)"$/\1/p' "$envf"); mpw=$(sed -n 's/^MAIL_PASSWORD="\(.*\)"$/\1/p' "$envf")
postconf -e defer_transports=smtp && postfix reload >/dev/null 2>&1 # keep test mail in the queue
printf 'From: noreply@other.localhost\r\nTo: x@example.com\r\nSubject: e2e\r\n\r\nhi\r\n' >"$W/msg"
smtp() { curl -s --max-time 10 smtp://127.0.0.1:25 --mail-rcpt x@example.com -T "$W/msg" "$@"; }
refused() { ! "$@"; }
check "SMTP without login is refused" refused smtp --mail-from noreply@other.localhost
check "site login may send as its own domain" smtp --user "$mu:$mpw" --mail-from noreply@other.localhost
check "site login may not send as another site" refused smtp --user "$mu:$mpw" --mail-from noreply@shop.localhost
check "sendmail as a site user may not use another domain" bash -c "! runuser -u web_other_localhost -- /usr/sbin/sendmail -f x@shop.localhost x@example.com < $W/msg"
sleep 2
qid=$(postqueue -j | grep -m1 '"sender": *"noreply@other.localhost"' | sed -E 's/.*"queue_id": *"([^"]+)".*/\1/')
contains "outgoing mail is DKIM-signed" "$( [[ -n $qid ]] && postcat -hq "$qid")" "d=other.localhost;"
postsuper -d ALL >/dev/null 2>&1
postconf -e defer_transports= && postfix reload >/dev/null 2>&1
php "$(site other.localhost) app(App\\Actions\\Mail\\ManageMail::class)->setSiteMode(\$s, 'capture'); app(App\\Actions\\Mail\\ManageMail::class)->dkim('other.localhost', 'delete');" >/dev/null

# ----------------------------------------------------------------------------
section "DNS (PowerDNS)"
command -v dig >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -q bind9-dnsutils >/dev/null
php 'App\Models\DnsZone::where("domain","e2e-zone.test")->delete(); rescue(fn () => app(App\Services\Dns\LocalDns::class)->deleteZone("e2e-zone.test"), report: false);
app(App\Actions\Dns\ManageDnsZones::class)->create(["domain" => "e2e-zone.test", "provider" => "local", "nameservers" => ["ns1.panel.localhost", "ns2.panel.localhost"], "recommended" => true]);
app(App\Services\Dns\LocalDns::class)->set("e2e-zone.test", "www.e2e-zone.test", "CNAME", 300, ["e2e-zone.test"]);' >/dev/null
check "pdns active" systemctl is-active --quiet pdns
expect "zone answers A with the server address" "$(dig +short @127.0.0.1 e2e-zone.test A)" "$(grep '^PANEL_PUBLIC_IPV4=' /srv/panel/shared/.env | cut -d= -f2)"
contains "CNAME added in the panel answers" "$(dig +short @127.0.0.1 www.e2e-zone.test CNAME)" "e2e-zone.test."
contains "SPF and DMARC suggested" "$(dig +short @127.0.0.1 _dmarc.e2e-zone.test TXT)" "v=DMARC1"
check "no recursion for other domains" bash -c "! dig +short @127.0.0.1 example.org A | grep -q ."
php 'app(App\Actions\Dns\ManageDnsZones::class)->delete(App\Models\DnsZone::where("domain","e2e-zone.test")->sole(), true);' >/dev/null
check "deleted zone is gone" bash -c "! dig @127.0.0.1 e2e-zone.test SOA | grep -q 'status: NOERROR'"

# ----------------------------------------------------------------------------
section "Mode and version switching"
php "$(site other.localhost) app(App\\Actions\\Sites\\ChangeRuntime::class)->setVersion(\$s, '8.3');" >/dev/null
contains "classic PHP 8.4 -> 8.3" "$(https other.localhost /)" "PHP 8.3"
php "$(site other.localhost) app(App\\Actions\\Sites\\ChangeRuntime::class)->switchMode(\$s, ['mode' => 'node', 'framework' => 'node', 'node_version' => '22']);" >/dev/null
check "classic -> node: FPM pool removed" test ! -f /etc/php/8.3/fpm/pool.d/web_other_localhost.conf
php "$(site other.localhost) app(App\\Actions\\Sites\\ChangeRuntime::class)->switchMode(\$s, ['mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4']);" >/dev/null
contains "node -> classic: serves PHP 8.4 again" "$(wait_body other.localhost / "PHP 8.4")" "PHP 8.4"
check "no app unit left after returning to classic" test ! -f /etc/systemd/system/wspanel-web_other_localhost-app.service

# ----------------------------------------------------------------------------
section "Deploy by polling the repository (no webhook)"
poll() { php "$(site app.localhost) echo app(App\\Actions\\Sites\\DeployPoller::class)->poll(\$s) ?? 'none';" | tr -d '[:space:]'; }
php "$(site app.localhost) \$s->update(['deploy_trigger' => 'poll_branch', 'deploy_polled_ref' => null]);" >/dev/null
echo v5 >"$W/app/VERSION" && chown git:git "$W/app/VERSION" && gitc commit -qam v5 && gitc push -q /home/git/app.git main
expect "new commit on the branch is picked up" "$(poll)" "deployed"
contains "v5 is live" "$(wait_body app.localhost / 'deploy-test-app v5')" "deploy-test-app v5"
sleep 3
expect "unchanged branch deploys nothing" "$(poll)" "none"
php "$(site app.localhost) \$s->update(['deploy_trigger' => 'poll_tags', 'deploy_polled_ref' => null]);" >/dev/null
echo v6 >"$W/app/VERSION" && chown git:git "$W/app/VERSION" && gitc commit -qam v6 && gitc tag -f v10.0.0 && gitc tag -f v9.0.0 HEAD~1 && gitc push -qf /home/git/app.git main v10.0.0 v9.0.0
for _ in $(seq 1 60); do [[ "$(php "$(site app.localhost) echo app(App\\Services\\Agent\\AgentClient::class)->call('deploy.history', ['domain' => \$s->domain])['running'] ? 1 : 0;" | tr -d '[:space:]')" == 0 ]] && break; sleep 2; done
expect "newest tag by version is deployed" "$(poll)" "deployed"
contains "tag v10.0.0 is live" "$(wait_body app.localhost / 'deploy-test-app v6')" "deploy-test-app v6"
contains "deploy took the tag" "$(php "$(site app.localhost) echo json_encode(app(App\\Services\\Agent\\AgentClient::class)->call('deploy.history', ['domain' => \$s->domain])['deploys'][0]);")" '"ref":"v10.0.0"'
php "$(site app.localhost) \$s->update(['deploy_trigger' => 'branch']);" >/dev/null

# ----------------------------------------------------------------------------
section "HTTPS for internal domains, custom certificates, private panel"
php 'if (! App\Models\Site::where("domain","intra.lan")->exists()) app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "intra.lan", "mode" => "classic", "framework" => "php", "php_version" => "8.4"]);' >/dev/null
expect "internal domain gets the local CA" "$(php 'echo App\Models\Site::where("domain","intra.lan")->value("tls_mode");' | tr -d '[:space:]')" "internal"
php 'file_put_contents("/tmp/e2e/root.pem", app(App\Actions\Sites\ManageTls::class)->rootCa()["pem"]);' >/dev/null
check "intra.lan verifies against the downloadable root certificate" curl -sf --max-time 15 --cacert "$W/root.pem" --resolve intra.lan:443:127.0.0.1 https://intra.lan/
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=intra.lan/O=E2E Firma CA" -addext "subjectAltName=DNS:intra.lan" -keyout "$W/custom.key" -out "$W/custom.crt" 2>/dev/null
chmod 644 "$W/custom.key" "$W/custom.crt"
php '$s = App\Models\Site::where("domain","intra.lan")->sole(); app(App\Actions\Sites\ManageTls::class)->set($s, "custom", file_get_contents("/tmp/e2e/custom.crt"), file_get_contents("/tmp/e2e/custom.key"));' >/dev/null
sleep 2
contains "custom certificate is served" "$(echo | openssl s_client -connect 127.0.0.1:443 -servername intra.lan 2>/dev/null | openssl x509 -noout -subject 2>/dev/null)" "E2E Firma CA"
contains "mismatched key is refused" "$(php '$s = App\Models\Site::where("domain","intra.lan")->sole(); try { app(App\Actions\Sites\ManageTls::class)->set($s, "custom", file_get_contents("/tmp/e2e/custom.crt"), file_get_contents("/tmp/e2e/root.pem")); echo "ACCEPTED"; } catch (Throwable $e) { echo "REFUSED"; }')" "REFUSED"
check "custom key is not in the audit log" bash -c '! grep -q "BEGIN PRIVATE KEY" /var/log/panel-agent/audit.log'
php '$s = App\Models\Site::where("domain","intra.lan")->sole(); app(App\Actions\Sites\ManageTls::class)->set($s, "internal");' >/dev/null

outside() { curl -sk --max-time 15 -o /dev/null -w '%{http_code}' --resolve "panel.localhost:443:$me" "https://panel.localhost$1"; }
contains "refuses a list without the admin's address" "$(php 'try { app(App\Actions\ManagePanelAccess::class)->set(["10.99.0.0/24"], true, "203.0.113.5"); echo "SAVED"; } catch (InvalidArgumentException $e) { echo "REFUSED"; }')" "REFUSED"
php 'app(App\Actions\ManagePanelAccess::class)->set(["10.99.0.0/24"], true, null);' >/dev/null
sleep 2
expect "outsider gets 404 for the panel" "$(outside /login)" "404"
expect "outsider gets 404 for Mailpit" "$(outside /mailpit/)" "404"
contains "webhooks stay reachable for outsiders (answered by the panel)" "$(curl -sk --max-time 15 --resolve "panel.localhost:443:$me" -X POST https://panel.localhost/hooks/deploy/00000000000000000000000000000000)" '"error":"not found"'
expect "loopback still reaches the panel" "$(code panel.localhost /login)" "200"
contains "sites are not affected" "$(curl -sk --max-time 15 --resolve "app.localhost:443:$me" https://app.localhost/)" "deploy-test-app"
php 'app(App\Actions\ManagePanelAccess::class)->set([], true, null);' >/dev/null
sleep 2
expect "panel is public again" "$(outside /login)" "200"

# ----------------------------------------------------------------------------
section "Database servers: root password and access from the internet"
dbs() { php "echo json_encode(app(App\\Actions\\Databases\\ManageDatabaseServers::class)->$1);" | tail -1; }
mpw=$(php 'echo app(App\Actions\Databases\ManageDatabaseServers::class)->setRootPassword("mariadb");' | tail -1)
ppw=$(php 'echo app(App\Actions\Databases\ManageDatabaseServers::class)->setRootPassword("postgresql");' | tail -1)
expect "generated root passwords are 32 characters" "${#mpw}/${#ppw}" "32/32"
expect "agent still reaches MariaDB over the socket" "$(mariadb -N -e 'select 1' 2>&1)" "1"
shop_pw=$(sed -n 's/^DB_PASSWORD="\(.*\)"$/\1/p' /srv/sites/shop.localhost/shared/.env)
dbs "setAccess('mariadb', 'allowlist', ['$me'])" >/dev/null
dbs "setAccess('postgresql', 'allowlist', ['$me'])" >/dev/null
sleep 2
contains "root reaches MariaDB from the allowlist over TLS" "$(mariadb -h "$me" -u root -p"$mpw" --ssl --ssl-verify-server-cert=0 -N -e "select variable_value from information_schema.session_status where variable_name='Ssl_cipher'" 2>&1)" "TLS_"
contains "MariaDB refuses a network login without TLS" "$(mariadb -h "$me" -u root -p"$mpw" --skip-ssl -N -e 'select 1' 2>&1)" "Access denied"
expect "a site's database owner reaches it from the allowlist" "$(mariadb -h "$me" -u shop_db -p"$shop_pw" --ssl --ssl-verify-server-cert=0 -N -e 'select database()' shop_db 2>&1)" "shop_db"
contains "postgres reaches PostgreSQL over TLS" "$(PGPASSWORD="$ppw" psql "host=$me user=postgres dbname=postgres sslmode=require" -Atc 'select ssl from pg_stat_ssl where pid=pg_backend_pid()' 2>&1)" "t"
contains "PostgreSQL refuses a network login without TLS" "$(PGPASSWORD="$ppw" psql "host=$me user=postgres dbname=postgres sslmode=disable" -Atc 'select 1' 2>&1)" "no encryption"
expect "sites still connect locally without TLS" "$(mariadb -h 127.0.0.1 -u shop_db -p"$shop_pw" --skip-ssl -N -e 'select 1' shop_db 2>&1)" "1"
contains "firewall opens 3306 for the allowlist only" "$(ufw status | grep '# wspanel-db-mariadb')" "$me"
contains "fail2ban guards both engines" "$(fail2ban-client status 2>&1)" "sshd, wspanel-mariadb, wspanel-postgres"
mariadb -h "$me" -u root -pwrong --ssl --ssl-verify-server-cert=0 -e 'select 1' >/dev/null 2>&1
# The jail ignores the server's own addresses (ignoreself), and this attempt comes
# from one, so check that the jail's filter catches the failed login in the journal.
contains "a failed MariaDB login is caught by the jail's filter" "$(fail2ban-regex --journalmatch '_SYSTEMD_UNIT=mariadb.service' 'systemd-journal[journalflags=1]' /etc/fail2ban/filter.d/wspanel-mariadb.conf 2>&1 | grep -oE ' [1-9][0-9]* matched')" "matched"
contains "server-wide phpMyAdmin access" "$(php 'echo json_encode(app(App\Services\Agent\AgentClient::class)->call("db.access", ["engine" => "mariadb", "tool" => "phpmyadmin", "scope" => "all"]));')" '"token":"'
dbs "setAccess('mariadb', 'local')" >/dev/null
dbs "setAccess('postgresql', 'local')" >/dev/null
sleep 2
expect "both engines listen on loopback only again" "$(ss -ltn | grep -cE '0.0.0.0:(3306|5432)|\*:(3306|5432)|\[::\]:(3306|5432)')" "0"
expect "no database rule left in the firewall" "$(ufw status | grep -c 'wspanel-db-')" "0"
php 'app(App\Actions\Databases\ManageDatabaseServers::class)->removeRootPassword("mariadb"); app(App\Actions\Databases\ManageDatabaseServers::class)->removeRootPassword("postgresql");' >/dev/null
contains "root without a password refuses TCP again" "$(mariadb -h 127.0.0.1 -u root -p"$mpw" --skip-ssl -N -e 'select 1' 2>&1)" "denied"

# ----------------------------------------------------------------------------
section "PHP settings, versions and extensions"
docroot=$(php 'echo App\Models\Site::where("domain","other.localhost")->value("doc_root");' | tail -1)
runuser -u web_other_localhost -- sh -c "printf '%s' '<?php echo ini_get(\"memory_limit\"),\"|\",ini_get(\"date.timezone\");' > $docroot/ini.php"
php '$s = App\Models\Site::where("domain","other.localhost")->sole(); app(App\Actions\Sites\ManagePhpSettings::class)->set($s, ["memory_limit" => "384M", "date.timezone" => "Europe/Prague"]);' >/dev/null
expect "php.ini values reach the site's FPM pool" "$(https other.localhost /ini.php)" "384M|Europe/Prague"
contains "an out-of-range value is refused" "$(php 'try { app(App\Actions\Sites\ManagePhpSettings::class)->set(App\Models\Site::where("domain","other.localhost")->sole(), ["memory_limit" => "99G"]); echo "SAVED"; } catch (Throwable $e) { echo $e->getMessage(); }')" "16M"
php 'app(App\Actions\Sites\ManagePhpSettings::class)->set(App\Models\Site::where("domain","other.localhost")->sole(), []);' >/dev/null
expect "defaults come back" "$(https other.localhost /ini.php)" "256M|UTC"
rm -f "$docroot/ini.php"
php '$s = App\Models\Site::where("domain","worker.localhost")->first(); if ($s) app(App\Actions\Sites\ManagePhpSettings::class)->set($s, ["memory_limit" => "512M", "worker_threads" => "3"]);' >/dev/null
contains "worker site gets php_ini and its worker count" "$(cat /etc/panel-sites/web_worker_localhost.Caddyfile)" "php_ini memory_limit 512M"
php '$s = App\Models\Site::where("domain","worker.localhost")->first(); if ($s) app(App\Actions\Sites\ManagePhpSettings::class)->set($s, []);' >/dev/null
contains "PHP status lists FrankenPHP's extensions" "$(php 'echo json_encode(app(App\Actions\Server\ManagePhp::class)->status()["frankenphp"]);')" '"pdo_mysql"'
expect "an extension installs" "$(php 'var_export(app(App\Actions\Server\ManagePhp::class)->extension("8.4", "tidy", "install"));' | tail -1)" "true"
contains "and is loaded by PHP-FPM 8.4" "$(php8.4 -m 2>/dev/null)" "tidy"
php 'app(App\Actions\Server\ManagePhp::class)->extension("8.4", "tidy", "remove");' >/dev/null
expect "and is removed again" "$(dpkg-query -W -f '${db:Status-Status}' php8.4-tidy 2>/dev/null)" "config-files"
contains "an extension others need is not removed" "$(php 'try { app(App\Actions\Server\ManagePhp::class)->extension("8.4", "igbinary", "remove"); echo "REMOVED"; } catch (Throwable $e) { echo $e->getMessage(); }')" "redis"
expect "and redis stays" "$(dpkg-query -W -f '${db:Status-Status}' php8.4-redis 2>/dev/null)" "installed"

# ----------------------------------------------------------------------------
section "Domains and access at the edge"
php '$s = App\Models\Site::where("domain","other.localhost")->sole(); app(App\Actions\Sites\ManageEdge::class)->set($s, [
  "aliases" => ["other2.localhost"], "redirect_hosts" => ["www.other.localhost"], "redirects" => [["from" => "/old", "to" => "/new", "code" => 301]],
  "auth_mode" => "path", "auth_path" => "/admin", "auth_user" => "boss", "auth_password" => "s3cret-pass",
  "allow_ips" => "127.0.0.1", "allow_path" => "/private", "hsts" => true, "security_headers" => true,
  "headers" => [["name" => "X-Robots-Tag", "value" => "noindex"]]]);' >/dev/null
sleep 2
ec() { curl -sk -o /dev/null -w '%{http_code}' --resolve "$1:443:${3:-127.0.0.1}" "https://$1$2" "${@:4}"; }
expect "an alias serves the site" "$(ec other2.localhost /)" "200"
expect "a redirect host keeps path and query" "$(curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' --resolve www.other.localhost:443:127.0.0.1 'https://www.other.localhost/x?y=1')" "308 https://other.localhost/x?y=1"
expect "a path redirect" "$(curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' --resolve other.localhost:443:127.0.0.1 https://other.localhost/old)" "301 https://other.localhost/new"
expect "the protected path asks for a password" "$(ec other.localhost /admin/)" "401"
expect "and opens with it" "$(ec other.localhost /admin/ 127.0.0.1 -u boss:s3cret-pass)" "200"
expect "the front-controller form is protected too" "$(ec other.localhost /index.php/admin)" "401"
expect "in any letter case" "$(ec other.localhost /ADMIN/x)" "401"
expect "the IP-limited path refuses others" "$(ec other.localhost /private/x "$me")" "403"
expect "also through the front controller" "$(ec other.localhost /index.php/private "$me")" "403"
expect "the rest stays public" "$(ec other.localhost / "$me")" "200"
contains "HSTS and own headers are sent" "$(curl -skI --resolve other.localhost:443:127.0.0.1 https://other.localhost/)" "x-robots-tag: noindex"
check "the password is not kept by the panel" bash -c "! grep -rq s3cret-pass /srv/panel/shared/database 2>/dev/null"
php '$s = App\Models\Site::where("domain","other.localhost")->sole(); app(App\Actions\Sites\ManageEdge::class)->set($s, []);' >/dev/null
sleep 1
expect "clearing the settings opens the site again" "$(ec other.localhost /admin/)" "200"

# ----------------------------------------------------------------------------
section "File manager (as the site user, inside its home)"
fm() { php "try { echo json_encode(app(App\Actions\Sites\ManageFiles::class)->$1); } catch (Throwable \$e) { echo 'ERR '.\$e->getMessage(); }" | tail -1; }
fsite='App\Models\Site::where("domain","other.localhost")->sole()'
fm "write($fsite, 'current/public/fm.txt', \"Ahoj\\n\")" >/dev/null
expect "a file written from the panel belongs to the site user" "$(stat -c %U /srv/sites/other.localhost/current/public/fm.txt)" "web_other_localhost"
contains "and is served" "$(https other.localhost /fm.txt)" "Ahoj"
contains "reading it back" "$(fm "read($fsite, 'current/public/fm.txt')")" "Ahoj"
contains "the shared .env is editable" "$(fm "read($fsite, 'shared/.env')")" '"content"'
contains "nothing outside the home (..)" "$(fm "read($fsite, '../../../etc/shadow')")" "ERR"
ln -sf /etc/shadow /srv/sites/other.localhost/current/public/sh
contains "nothing outside the home (symlink)" "$(fm "read($fsite, 'current/public/sh')")" "ERR"
rm -f /srv/sites/other.localhost/current/public/sh
contains "another site's home is out of reach" "$(fm "list($fsite, '../app.localhost')")" "ERR"
fm "compress($fsite, ['current/public/fm.txt'], 'current/public/fm.zip')" >/dev/null
fm "extract($fsite, 'current/public/fm.zip', 'current/public/fmx')" >/dev/null
expect "zip and unzip" "$(cat /srv/sites/other.localhost/current/public/fmx/current/public/fm.txt 2>/dev/null)" "Ahoj"
dl=$(php "echo app(App\Actions\Sites\ManageFiles::class)->download($fsite, 'current/public/fm.txt');" | tail -1)
expect "a download is handed to the panel user only" "$(stat -c '%U %a' "$dl" 2>/dev/null)" "panel 600"
rm -f "$dl"
fm "delete($fsite, 'current/public/fmx')" >/dev/null; fm "delete($fsite, 'current/public/fm.zip')" >/dev/null; fm "delete($fsite, 'current/public/fm.txt')" >/dev/null
check "deleted again" test ! -e /srv/sites/other.localhost/current/public/fm.txt

# ----------------------------------------------------------------------------
section "Whole-site backups (files + databases)"
sb() { php "\$s = App\\Models\\Site::where('domain','shop.localhost')->sole(); \$m = app(App\\Actions\\Sites\\ManageSiteBackups::class); $1" | tail -1; }
sbdir=/var/backups/wspanel/sites/shop.localhost
expect "site backup with its database" "$(sb 'echo json_encode($m->backupNow($s));')" "true"
sbfile=$(ls -t "$sbdir" | grep -v pre-restore | head -1)
expect "backup is root:panel 0640" "$(stat -c '%U:%G %a' "$sbdir/$sbfile")" "root:panel 640"
check "archive holds the release, shared and the dump" bash -c "tar -tzf '$sbdir/$sbfile' | grep -q '^files/shared/.env$' && tar -tzf '$sbdir/$sbfile' | grep -q '^db/mariadb-shop_db.sql.gz$' && tar -tzf '$sbdir/$sbfile' | grep -q '^files/releases/.*/public/index.php$'"
# Change files and data after the backup.
runuser -u web_shop_localhost -- bash -c 'echo after > /srv/sites/shop.localhost/shared/after-backup.txt'
expect "data deleted after the backup" "$(dbq delete)" "0"
sbout=$(sb "echo json_encode(\$m->restore(\$s, '$sbfile', true, true));")
contains "restore reports a healthy new release" "$sbout" '"healthy":true'
expect "restore brings the database back" "$(dbq count)" "1"
check "files from after the backup are gone from shared" test ! -e /srv/sites/shop.localhost/shared/after-backup.txt
check "previous shared kept beside it" bash -c 'ls -d /srv/sites/shop.localhost/shared.before-restore-*/after-backup.txt'
expect "restored files belong to the site user" "$(stat -c %U /srv/sites/shop.localhost/shared/.env)" "web_shop_localhost"
contains "current points to the restored release" "$(readlink /srv/sites/shop.localhost/current)" "$(echo "$sbout" | sed -n 's/.*"release":"\([^"]*\)".*/\1/p')"
expect "site still answers" "$(code shop.localhost /)" "200"
check "pre-restore backup taken" bash -c "ls $sbdir | grep -q pre-restore"
check "no staging folder left" bash -c '! ls -d /srv/sites/shop.localhost/.wspanel-restore-* 2>/dev/null'
sb '$m->schedule($s, "daily", 3, true);' >/dev/null
check "backup timer active" systemctl is-active --quiet wspanel-sitebackup-web_shop_localhost.timer
check "scheduled backup runs" bash -c 'systemctl start wspanel-sitebackup-web_shop_localhost.service && systemctl show -p Result --value wspanel-sitebackup-web_shop_localhost.service | grep -q success'
sb '$m->schedule($s, "off", 3, true);' >/dev/null
check "backup timer removed" test ! -e /etc/systemd/system/wspanel-sitebackup-web_shop_localhost.timer
rm -rf /srv/sites/shop.localhost/shared.before-restore-*

# ----------------------------------------------------------------------------
section "Backups outside the server (S3-compatible storage)"
pkill -f dev-fake-s3 2>/dev/null
/opt/wspanel/build/dev-fake-s3-linux-$(dpkg --print-architecture) -listen 127.0.0.1:9000 >"$W/fake-s3.log" 2>&1 &
fakes3=$!
sleep 1
off() { php "try { echo json_encode(app(App\\Actions\\Server\\ManageOffsite::class)->$1); } catch (Throwable \$e) { echo 'ERR '.\$e->getMessage(); }" | tail -1; }
offcfg="'enabled' => true, 'endpoint' => 'http://127.0.0.1:9000', 'region' => 'us-east-1', 'bucket' => 'e2e', 'prefix' => 'srv', 'path_style' => true, 'keep_days' => 30"
contains "wrong credentials are refused by the test upload" "$(off "save([$offcfg, 'access_key' => 'WRONG', 'secret_key' => 'x'])")" "Přístup odepřen"
off "save([$offcfg, 'access_key' => 'E2EKEY', 'secret_key' => 'e2e-secret'])" >/dev/null
contains "storage set up, the secret is not handed back" "$(off 'status()')" '"has_secret":true'
check "the secret is only in the root-only state" bash -c 'stat -c "%U %a" /var/lib/panel-agent/offsite.json | grep -qx "root 600" && ! grep -rq e2e-secret /srv/panel/current/storage /var/log/panel-agent'
sb 'echo json_encode($m->backupNow($s));' >/dev/null
sbnew=$(ls -t "$sbdir" | grep -v pre-restore | head -1)
contains "a new site backup is copied off-site" "$(off "siteCopies(App\\Models\\Site::where('domain','shop.localhost')->sole())")" "$sbnew"
php "\$db = App\\Models\\Database::where('name','shop_db')->sole(); app(App\\Actions\\Databases\\ManageDatabases::class)->backupNow(\$db);" >/dev/null
dbnew=$(ls -t /var/backups/wspanel/mariadb/shop_db | grep -v pre-restore | head -1)
contains "a database backup is copied off-site" "$(php "echo json_encode(app(App\\Services\\Agent\\AgentClient::class)->call('offsite.list', ['engine' => 'mariadb', 'name' => 'shop_db']));" | tail -1)" "$dbnew"
cp "$sbdir/$sbnew" "$W/original.tar.gz" && rm -f "$sbdir/$sbnew"
off "fetchSiteBackup(App\\Models\\Site::where('domain','shop.localhost')->sole(), '$sbnew')" >/dev/null
check "a lost backup comes back from the storage, byte for byte" cmp "$sbdir/$sbnew" "$W/original.tar.gz"
expect "and with the same owner and mode" "$(stat -c '%U:%G %a' "$sbdir/$sbnew")" "root:panel 640"
kill $fakes3 2>/dev/null

# FTPS: a real vsftpd (no MLSD, TLS session reuse required on the data connection).
if ! command -v vsftpd >/dev/null; then DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends vsftpd >/dev/null 2>&1; fi
id ftpbackup >/dev/null 2>&1 || useradd -m -s /usr/sbin/nologin ftpbackup
echo 'ftpbackup:Ftp-e2e-pass' | chpasswd
grep -qx /usr/sbin/nologin /etc/shells || echo /usr/sbin/nologin >>/etc/shells
[[ -f "$W/ftp.pem" ]] || openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj /CN=ftp.e2e -keyout "$W/ftp.pem" -out "$W/ftp.pem" >/dev/null 2>&1
cat >"$W/vsftpd.conf" <<CONF
listen=YES
listen_port=2121
anonymous_enable=NO
local_enable=YES
write_enable=YES
chroot_local_user=YES
allow_writeable_chroot=YES
pasv_min_port=40000
pasv_max_port=40100
ssl_enable=YES
force_local_logins_ssl=YES
force_local_data_ssl=YES
rsa_cert_file=$W/ftp.pem
secure_chroot_dir=/var/run/vsftpd/empty
seccomp_sandbox=NO
CONF
mkdir -p /var/run/vsftpd/empty
pkill -f "vsftpd $W/vsftpd.conf" 2>/dev/null
vsftpd "$W/vsftpd.conf" &
sleep 1
ftpcfg="'enabled' => true, 'type' => 'ftp', 'host' => '127.0.0.1', 'port' => 2121, 'username' => 'ftpbackup', 'password' => 'Ftp-e2e-pass', 'ftp_tls' => 'explicit', 'prefix' => 'srv', 'keep_days' => 30"
ftpout=$(off "save([$ftpcfg])")
contains "an untrusted FTPS certificate is refused and its fingerprint shown" "$ftpout" "otisk SHA-256"
fp=$(openssl x509 -in "$W/ftp.pem" -noout -fingerprint -sha256 | cut -d= -f2)
contains "with the fingerprint pinned the storage works" "$(off "save([$ftpcfg, 'tls_pin' => '$fp'])"; off 'status()')" '"type":"ftp"'
sb 'echo json_encode($m->backupNow($s));' >/dev/null
ftpnew=$(ls -t "$sbdir" | grep -v pre-restore | head -1)
check "a site backup lands on the FTP server with its signature" test -s "/home/ftpbackup/srv/sites/shop.localhost/$ftpnew" -a -s "/home/ftpbackup/srv/sites/shop.localhost/$ftpnew.sig"
contains "and is listed" "$(off "siteCopies(App\\Models\\Site::where('domain','shop.localhost')->sole())")" "$ftpnew"
rm -f "$sbdir/$ftpnew"
off "fetchSiteBackup(App\\Models\\Site::where('domain','shop.localhost')->sole(), '$ftpnew')" >/dev/null
check "fetched back from FTP, byte for byte" cmp "$sbdir/$ftpnew" "/home/ftpbackup/srv/sites/shop.localhost/$ftpnew"
# Someone changes the copy on the storage: it is refused.
rm -f "$sbdir/$ftpnew"
printf x >>"/home/ftpbackup/srv/sites/shop.localhost/$ftpnew"
contains "a changed copy is refused" "$(off "fetchSiteBackup(App\\Models\\Site::where('domain','shop.localhost')->sole(), '$ftpnew')")" "Podpis kopie nesedí"
check "and nothing was kept" test ! -e "$sbdir/$ftpnew"
pkill -f "vsftpd $W/vsftpd.conf" 2>/dev/null

# SMB: a real Samba share; the agent installs smbclient itself.
if ! command -v smbd >/dev/null; then DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends samba >/dev/null 2>&1; fi
id smbbackup >/dev/null 2>&1 || useradd -M -s /usr/sbin/nologin smbbackup
mkdir -p /srv/smbtest && chown smbbackup /srv/smbtest
grep -q '^\[zalohy\]' /etc/samba/smb.conf || printf '[zalohy]\n   path = /srv/smbtest\n   read only = no\n   valid users = smbbackup\n' >>/etc/samba/smb.conf
printf 'Smb-e2e-pass\nSmb-e2e-pass\n' | smbpasswd -s -a smbbackup >/dev/null
systemctl restart smbd 2>/dev/null || (pkill smbd; smbd -D)
sleep 1
smbcfg="'enabled' => true, 'type' => 'smb', 'host' => '127.0.0.1', 'share' => 'zalohy', 'username' => 'smbbackup', 'smb_encrypt' => true, 'prefix' => 'srv', 'keep_days' => 30"
contains "a wrong SMB password is refused" "$(off "save([$smbcfg, 'password' => 'wrong'])")" "odmítl přihlášení"
contains "SMB share works (SMB3, encrypted)" "$(off "save([$smbcfg, 'password' => 'Smb-e2e-pass'])"; off 'status()')" '"type":"smb"'
check "smbclient was installed by the agent" test -x /usr/bin/smbclient
sb 'echo json_encode($m->backupNow($s));' >/dev/null
smbnew=$(ls -t "$sbdir" | grep -v pre-restore | head -1)
check "a site backup lands on the share with its signature" test -s "/srv/smbtest/srv/sites/shop.localhost/$smbnew" -a -s "/srv/smbtest/srv/sites/shop.localhost/$smbnew.sig"
rm -f "$sbdir/$smbnew"
off "fetchSiteBackup(App\\Models\\Site::where('domain','shop.localhost')->sole(), '$smbnew')" >/dev/null
check "fetched back from SMB, byte for byte" cmp "$sbdir/$smbnew" "/srv/smbtest/srv/sites/shop.localhost/$smbnew"
check "no SMB password on disk outside the agent's state" bash -c "! grep -rqs Smb-e2e-pass /var/lib/panel-agent/offsite-tmp /srv/panel/current/storage /var/log/panel-agent"
off "save([$smbcfg, 'enabled' => false, 'password' => ''])" >/dev/null

# ----------------------------------------------------------------------------
section "Scheduled tasks, firewall, SSL, monitoring, audit"
php "$(site app.localhost) app(App\\Actions\\ManageCronJobs::class)->create(\$s, ['kind' => 'artisan', 'command' => 'optimize', 'schedule' => '* * * * *']);" >/dev/null
check "task timer active" systemctl is-active --quiet wspanel-web_app_localhost-cron-1.timer
php 'app(App\Actions\Firewall\ManageFirewall::class)->add(["action" => "allow", "port" => 8081, "proto" => "tcp", "description" => "e2e"]);' >/dev/null
check "firewall rule added" bash -c 'ufw status | grep -q "8081/tcp"'
contains "SSH port is protected" "$(php 'try { app(App\Actions\Firewall\ManageFirewall::class)->add(["action" => "deny", "port" => 22, "proto" => "tcp"]); echo "ALLOWED"; } catch (Throwable $e) { echo $e->getMessage(); }')" "chráněný"
php '$r = App\Models\FirewallRule::where("port", 8081)->first(); if ($r) app(App\Actions\Firewall\ManageFirewall::class)->delete($r);' >/dev/null
check "firewall rule removed" bash -c '! ufw status | grep -q "8081/tcp"'
php 'app(App\Actions\SyncCertificatesAndTasks::class)->certificates();' >/dev/null
expect "all certificates valid" "$(php 'echo App\Models\Certificate::where("status","!=","valid")->count();' | tr -d '[:space:]')" "0"
contains "resources reported" "$(php 'echo json_encode(app(App\Services\Agent\AgentClient::class)->call("sys.resources"));')" '"memory"'
contains "audit log readable from the panel" "$(php 'echo count(app(App\Services\Agent\AgentClient::class)->call("audit.tail", ["limit" => 5]));')" "5"
check "panel:sync runs cleanly" bash -c 'cd /srv/panel/current && runuser -u panel -- php artisan panel:sync'
for _ in $(seq 1 35); do
	[[ "$(systemctl show -p Result --value wspanel-web_app_localhost-cron-1.service)" == success && -n "$(systemctl show -p ExecMainStartTimestamp --value wspanel-web_app_localhost-cron-1.service)" ]] && break
	sleep 2
done
contains "scheduled task ran successfully" "$(systemctl show -p Result,ExecMainStartTimestamp wspanel-web_app_localhost-cron-1.service | tr '\n' ' ')" "Result=success"
php "$(site app.localhost) app(App\\Actions\\Sites\\ControlSite::class)->control(\$s, 'task', '1', 'stop');" >/dev/null
check "task paused from the panel" bash -c '! systemctl is-active --quiet wspanel-web_app_localhost-cron-1.timer'
php "$(site app.localhost) app(App\\Actions\\Sites\\ControlSite::class)->control(\$s, 'task', '1', 'start');" >/dev/null
check "task scheduled again" systemctl is-active --quiet wspanel-web_app_localhost-cron-1.timer
contains "credentials show the site's database" "$(php "$(site shop.localhost) echo json_encode(app(App\\Actions\\Sites\\ControlSite::class)->credentials(\$s));")" '"database"'


# ----------------------------------------------------------------------------
section "Clients and packages"
# panel_login EMAIL PASSWORD JAR: sign in through the real login form (Fortify).
panel_login() {
	rm -f "$3"
	local tok
	tok=$(curl -sk -c "$3" -b "$3" --resolve panel.localhost:443:127.0.0.1 https://panel.localhost/login | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
	curl -sk -c "$3" -b "$3" -o /dev/null --resolve panel.localhost:443:127.0.0.1 -X POST https://panel.localhost/login \
		--data-urlencode "_token=$tok" --data-urlencode "email=$1" --data-urlencode "password=$2"
}
pcode() { curl -sk -o /dev/null -w '%{http_code}' -b "$1" --resolve panel.localhost:443:127.0.0.1 "https://panel.localhost$2"; }
php '$p = App\Models\Package::firstOrCreate(["name" => "e2e"], ["max_sites" => 1, "ssh" => "sftp"]);
foreach (["e2e-a" => "a", "e2e-b" => "b"] as $name => $k) {
    $c = App\Models\Client::firstOrCreate(["name" => $name], ["package_id" => $p->id]);
    App\Models\User::updateOrCreate(["email" => "$k@e2e.local"], ["name" => $name, "password" => "E2e-heslo-12345", "role" => "client", "client_id" => $c->id]);
}
foreach (App\Models\Client::whereIn("name", ["e2e-a", "e2e-b"])->get() as $c) { app(App\Actions\Clients\ManageClients::class)->applyToServer($c); }
$a = App\Models\Client::where("name", "e2e-a")->sole();
app(App\Actions\Clients\ManageClients::class)->assignSite(App\Models\Site::where("domain", "shop.localhost")->sole(), $a);' >/dev/null
ga=$(php 'echo App\Models\Client::where("name","e2e-a")->value("linux_group");' | tr -d '[:space:]')
check "client group exists on the server" getent group "$ga"
expect "site user's primary group is the client's" "$(id -gn web_shop_localhost)" "$ga"
expect "site files moved to the client's group" "$(stat -c %G /srv/sites/shop.localhost/shared)" "$ga"
expect "site still answers after the move" "$(code shop.localhost /)" "200"
# A second site of the same client (an administrator may go past the limit): same group, still isolated.
php 'app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "sibling.localhost", "framework" => "php", "php_version" => "8.4", "client_id" => App\Models\Client::where("name","e2e-a")->value("id")]);' >/dev/null
expect "sibling site shares the client's group" "$(id -gn web_sibling_localhost)" "$ga"
check "sibling site cannot read the other site's home" bash -c '! runuser -u web_sibling_localhost -- ls /srv/sites/shop.localhost'
expect "web tier still serves the moved site" "$(code shop.localhost /)" "200"
php '$s = App\Models\Site::where("domain","sibling.localhost")->sole(); app(App\Actions\Sites\DeleteSite::class)->handle($s);' >/dev/null
contains "disk limit reported as measured only here (overlay, no quota)" "$(php 'echo json_encode(App\Models\Client::where("name","e2e-a")->value("disk_enforced"));')" "false"
panel_login a@e2e.local E2e-heslo-12345 "$W/a.jar"
panel_login b@e2e.local E2e-heslo-12345 "$W/b.jar"
shop_id=$(php 'echo App\Models\Site::where("domain","shop.localhost")->value("id");' | tr -d '[:space:]')
expect "client sees its own site" "$(pcode "$W/a.jar" "/sites/$shop_id")" "200"
expect "another client gets 404 for it" "$(pcode "$W/b.jar" "/sites/$shop_id")" "404"
expect "client cannot open the firewall" "$(pcode "$W/a.jar" /firewall)" "403"
expect "client cannot open panel accounts" "$(pcode "$W/a.jar" /users)" "403"
contains "package limit refuses a second site" "$(php 'auth()->login(App\Models\User::where("email","a@e2e.local")->sole()); try { app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "over.localhost", "framework" => "laravel", "php_version" => "8.4"]); echo "CREATED"; } catch (Throwable $e) { echo $e->getMessage(); }')" "Limit balíčku"
php 'app(App\Actions\Clients\ManageClients::class)->suspend(App\Models\Client::where("name","e2e-a")->sole());' >/dev/null
expect "suspended client site answers 503" "$(code shop.localhost /)" "503"
expect "suspended client is signed out" "$(pcode "$W/a.jar" /sites)" "302"
php 'app(App\Actions\Clients\ManageClients::class)->resume(App\Models\Client::where("name","e2e-a")->sole());' >/dev/null
expect "resumed client site answers again" "$(code shop.localhost /)" "200"
php 'app(App\Actions\Clients\ManageClients::class)->assignSite(App\Models\Site::where("domain", "shop.localhost")->sole(), null);' >/dev/null
# A site created by the client itself: its user has no group of its own.
php 'auth()->login(App\Models\User::where("email","b@e2e.local")->sole());
$s = app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "client-b.localhost", "framework" => "laravel", "php_version" => "8.4"]);
app(App\Actions\Databases\ManageDatabases::class)->create("mariadb", "app", $s);' >/dev/null
gb=$(php 'echo App\Models\Client::where("name","e2e-b")->value("linux_group");' | tr -d '[:space:]')
expect "client's own site is in the client's group" "$(id -gn web_client-b_localhost 2>/dev/null || id -gn web_client_b_localhost)" "$gb"
for _ in $(seq 1 20); do [[ "$(code client-b.localhost /)" == 200 ]] && break; sleep 0.5; done # the new FPM pool comes up with the reload
expect "client's own site answers" "$(code client-b.localhost /)" "200"
contains "database credentials written into its .env (as the site user)" "$(cat /srv/sites/client-b.localhost/shared/.env)" "DB_DATABASE=\"c"
contains "file manager lists the client's site" "$(php '$s = App\Models\Site::where("domain","client-b.localhost")->sole(); echo json_encode(app(App\Actions\Sites\ManageFiles::class)->list($s, ""));')" "releases"
php '$s = App\Models\Site::where("domain","client-b.localhost")->sole(); foreach ($s->databases as $d) { app(App\Actions\Databases\ManageDatabases::class)->drop($d); } app(App\Actions\Sites\DeleteSite::class)->handle($s);' >/dev/null
check "client's site removed" bash -c '! id web_client_b_localhost 2>/dev/null && ! id web_client-b_localhost 2>/dev/null'
expect "site back with the administrator keeps its own group" "$(stat -c %G /srv/sites/shop.localhost/shared)" "web_shop_localhost"

# ----------------------------------------------------------------------------
section "Valkey per site"
php "$(site shop.localhost) app(App\\Actions\\Sites\\ManageValkey::class)->set(\$s, ['enabled' => true, 'maxmemory_mb' => 32, 'policy' => 'allkeys-lru', 'persist' => false]);" >/dev/null
vport=$(grep -oP '^REDIS_PORT="\K[0-9]+' /srv/sites/shop.localhost/shared/.env)
vpass=$(grep -oP '^REDIS_PASSWORD="\K[0-9a-f]+' /srv/sites/shop.localhost/shared/.env)
for _ in $(seq 1 20); do REDISCLI_AUTH=$vpass valkey-cli -h 127.0.0.1 -p "$vport" PING 2>/dev/null | grep -q PONG && break; sleep 0.5; done
check "site's Valkey unit active" systemctl is-active --quiet wspanel-web_shop_localhost-valkey.service
contains ".env has host, port and password" "$(cat /srv/sites/shop.localhost/shared/.env)" 'REDIS_HOST="127.0.0.1"'
expect "Laravel's way works: 127.0.0.1, port, password" "$(REDISCLI_AUTH=$vpass valkey-cli -h 127.0.0.1 -p "$vport" PING 2>&1)" "PONG"
contains "without the password it refuses" "$(valkey-cli -h 127.0.0.1 -p "$vport" GET x 2>&1)" "NOAUTH"
check "listens only on loopback" bash -c "ss -ltn | grep -q '127.0.0.1:$vport ' && ! ss -ltn | grep -q '0.0.0.0:$vport '"
contains "the password is in no world-readable unit or config" "$(grep -l "$vpass" /etc/systemd/system/wspanel-web_shop_localhost-valkey.service /etc/panel-sites/web_shop_localhost.valkey.conf 2>&1 || echo none)" "none"
REDISCLI_AUTH=$vpass valkey-cli -h 127.0.0.1 -p "$vport" SET e2e:key v >/dev/null
contains "status counts keys" "$(php "$(site shop.localhost) echo json_encode(app(App\\Actions\\Sites\\ManageValkey::class)->status(\$s));")" '"keys":1'
php "$(site shop.localhost) app(App\\Actions\\Sites\\ManageValkey::class)->flush(\$s);" >/dev/null
expect "flush empties it" "$(REDISCLI_AUTH=$vpass valkey-cli -h 127.0.0.1 -p "$vport" DBSIZE)" "0"
expect "maxmemory from the panel" "$(REDISCLI_AUTH=$vpass valkey-cli -h 127.0.0.1 -p "$vport" CONFIG GET maxmemory | tail -1)" "33554432"
if [[ -d /srv/sites/laravel.localhost/current/vendor/laravel ]]; then # demo app (make dev-demo)
	php "$(site laravel.localhost) app(App\\Actions\\Sites\\ManageValkey::class)->set(\$s, ['enabled' => true, 'maxmemory_mb' => 32]);" >/dev/null
	sleep 1
	contains "a real Laravel app uses it through phpredis (.env as written)" "$(cd /srv/sites/laravel.localhost/current && runuser -u web_laravel_localhost -- php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\Redis::set("lv", "ok"); echo Illuminate\Support\Facades\Redis::get("lv");' 2>&1)" "ok"
	php "$(site laravel.localhost) app(App\\Actions\\Sites\\ManageValkey::class)->set(\$s, ['enabled' => false]);" >/dev/null
fi
contains "Valkey stays while Horizon keeps its data there" "$(php "$(site shop.localhost) try { app(App\\Actions\\Sites\\ManageValkey::class)->set(\$s, ['enabled' => false]); echo 'OFF'; } catch (Throwable \$e) { echo \$e->getMessage(); }")" "horizon má data ve Valkey"
check "and keeps running" systemctl is-active --quiet wspanel-web_shop_localhost-valkey.service

# ----------------------------------------------------------------------------
section "Web server rules"
php "$(site shop.localhost) app(App\\Actions\\Sites\\ManageWebRules::class)->set(\$s, app(App\\Support\\WebRules\\RulesConverter::class)->convert('add_header X-Rule \"e2e\";
rewrite ^/old/(.*)\$ /new/\$1 permanent;'));" >/dev/null
contains "custom header served" "$(curl -sk -D - -o /dev/null --resolve shop.localhost:443:127.0.0.1 https://shop.localhost/)" "x-rule: e2e"
contains "converted nginx redirect works" "$(curl -sk -D - -o /dev/null --resolve shop.localhost:443:127.0.0.1 https://shop.localhost/old/page)" "location: /new/page"
contains "proxying elsewhere is refused" "$(php "$(site shop.localhost) try { app(App\\Actions\\Sites\\ManageWebRules::class)->set(\$s, 'reverse_proxy 127.0.0.1:8025'); echo 'ACCEPTED'; } catch (Throwable \$e) { echo \$e->getMessage(); }")" "reverse_proxy"
contains "rules the web server rejects are refused" "$(php "$(site shop.localhost) try { app(App\\Actions\\Sites\\ManageWebRules::class)->set(\$s, 'encode nonsense-codec'); echo 'ACCEPTED'; } catch (Throwable \$e) { echo \$e->getMessage(); }")" "Řádek 1"
contains "an import hidden in a block is refused" "$(php "$(site shop.localhost) try { app(App\\Actions\\Sites\\ManageWebRules::class)->set(\$s, \"header {\n\timport /etc/panel-agent/agent.json\n}\"); echo 'ACCEPTED'; } catch (Throwable \$e) { echo \$e->getMessage(); }")" "Řádek 2"
contains "previous rules still active" "$(curl -sk -D - -o /dev/null --resolve shop.localhost:443:127.0.0.1 https://shop.localhost/)" "x-rule: e2e"
php "$(site shop.localhost) app(App\\Actions\\Sites\\ManageWebRules::class)->set(\$s, '');" >/dev/null
check "rules removed" bash -c "! curl -sk -D - -o /dev/null --resolve shop.localhost:443:127.0.0.1 https://shop.localhost/ | grep -qi x-rule"

# ----------------------------------------------------------------------------
section "Laravel set up by the panel (Reverb, Horizon)"
LV=/srv/sites/laravel.localhost
if [[ -d $LV/current/vendor/laravel/reverb && -d $LV/current/vendor/laravel/horizon ]]; then # demo app with both installed
	php "$(site laravel.localhost) \$w = app(App\\Actions\\Sites\\ManageWorkers::class); foreach (['horizon','reverb'] as \$x) { try { \$w->delete(\$s, \$x); } catch (Throwable) {} } app(App\\Actions\\Sites\\ManageValkey::class)->set(\$s, ['enabled' => false]); \$w->create(\$s, 'reverb'); \$w->create(\$s, 'horizon');" >/dev/null
	sleep 3
	lenv=$(cat $LV/shared/.env)
	contains "Reverb: app publishes on the server" "$lenv" 'REVERB_HOST="127.0.0.1"'
	contains "Reverb: browsers through the site's HTTPS" "$lenv" 'VITE_REVERB_HOST="laravel.localhost"'
	contains "Horizon: queue in the site's Valkey" "$lenv" 'QUEUE_CONNECTION="redis"'
	rkey=$(grep -oP '^REVERB_APP_KEY="\K[^"]+' $LV/shared/.env)
	contains "WebSocket upgrade through the edge" "$(curl -sk --http1.1 -i -N --max-time 3 --resolve laravel.localhost:443:127.0.0.1 -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: x3JJHMbDL1EzLkh9GBhXDw==' "https://laravel.localhost/app/$rkey?protocol=7&client=js&version=8.4.0" 2>&1 | head -1)" "101"
	expect "Reverb's HTTP API is not public" "$(code laravel.localhost /apps/1/events)" "404"
	LU="runuser -u web_laravel_localhost -- env HOME=$LV/tmp"
	contains "the app publishes an event" "$(cd $LV/current && $LU php artisan tinker --execute 'Illuminate\Support\Facades\Broadcast::connection("reverb")->broadcast(["news"], "Hello", ["a" => 1]); echo "published";' 2>&1)" "published"
	if [[ -f $LV/current/app/Jobs/WspE2e.php ]]; then
		(cd $LV/current && $LU php artisan tinker --execute 'App\Jobs\WspE2e::dispatch();' >/dev/null 2>&1)
		sleep 5
		contains "Horizon runs a job through Valkey" "$(cd $LV/current && $LU php artisan tinker --execute 'echo Cache::get("wsp-e2e");' 2>&1)" "done via redis"
	fi
else
	printf '  (skipped: laravel.localhost without laravel/reverb and laravel/horizon)\n'
fi

# ----------------------------------------------------------------------------
section "Zero downtime"
if [[ -S /run/wspanel-sites/web_worker_localhost/admin.sock ]]; then
	rm -f "$W/codes"
	(for i in $(seq 1 200); do code worker.localhost / >>"$W/codes"; echo >>"$W/codes"; done) &
	sleep 0.5
	curl -s --unix-socket /run/wspanel-sites/web_worker_localhost/admin.sock -X POST http://localhost/frankenphp/workers/restart >/dev/null
	wait
	expect "worker mode: PHP workers restart without a failed request" "$(grep -v '^$' "$W/codes" | sort -u | tr '\n' ' ')" "200 "
fi
rm -f "$W/ncodes"
(for i in $(seq 1 200); do code node.localhost / >>"$W/ncodes"; echo >>"$W/ncodes"; done) &
sleep 0.3
php "$(site node.localhost) app(App\\Actions\\Sites\\ControlSite::class)->restart(\$s);" >/dev/null
wait
expect "Node: a restart goes through the other slot, no failed request" "$(grep -v '^$' "$W/ncodes" | sort -u | tr '\n' ' ')" "200 "
contains "Node now serves from the other slot" "$(cat /var/lib/panel-agent/sites/node.localhost.json)" '"port_b"'


# ----------------------------------------------------------------------------
section "Mail hosting (mailboxes, IMAP, submission, antispam)"
command -v swaks >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -q swaks libnet-ssleay-perl libio-socket-ssl-perl >/dev/null 2>&1
check "dovecot, rspamd and its valkey active" systemctl is-active --quiet dovecot rspamd rspamd-valkey
php '$m = app(App\Actions\Mail\ManageMailHosting::class);
foreach (App\Models\MailDomain::where("domain","shop.localhost")->get() as $d) { foreach ($d->mailboxes as $mb) { $m->deleteMailbox($mb); } $m->setAliases($d, []); $m->removeDomain($d); }
$d = $m->addDomain("shop.localhost");
$m->saveMailbox($d, "alice", "Alice-heslo-123", 50, true);
$m->saveMailbox($d, "bob", "Bob-heslo-12345", 50, true);
$m->setAliases($d, ["info" => ["alice@shop.localhost"], "" => ["bob@shop.localhost"]]);' >/dev/null
imap_count() { curl -sk --max-time 10 "imaps://127.0.0.1/INBOX" -u "$1:$2" -X "STATUS INBOX (MESSAGES)" 2>/dev/null | grep -o 'MESSAGES [0-9]*' | awk '{print $2}'; }
a0=$(imap_count alice@shop.localhost Alice-heslo-123); b0=$(imap_count bob@shop.localhost Bob-heslo-12345)
contains "inbound mail to an alias is accepted" "$(swaks --server 127.0.0.1 --to info@shop.localhost --from x@example.org --header 'Subject: e2e-alias' --body hi 2>&1)" "queued"
swaks --server 127.0.0.1 --to whatever@shop.localhost --from x@example.org --header 'Subject: e2e-catchall' --body hi >/dev/null 2>&1
sleep 3
expect "IMAP: the alias delivered to alice" "$(imap_count alice@shop.localhost Alice-heslo-123)" "$((${a0:-0} + 1))"
expect "IMAP: catch-all delivered to bob" "$(imap_count bob@shop.localhost Bob-heslo-12345)" "$((${b0:-0} + 1))"
contains "submission (587) as the mailbox" "$(swaks --server 127.0.0.1 --port 587 --tls --auth LOGIN --auth-user alice@shop.localhost --auth-password Alice-heslo-123 --from alice@shop.localhost --to bob@shop.localhost --header 'Subject: e2e-sub' 2>&1)" "queued"
contains "a mailbox may send as its alias" "$(swaks --server 127.0.0.1 --port 587 --tls --auth LOGIN --auth-user alice@shop.localhost --auth-password Alice-heslo-123 --from info@shop.localhost --to bob@shop.localhost 2>&1)" "queued"
contains "but not as anyone else" "$(swaks --server 127.0.0.1 --port 587 --tls --auth LOGIN --auth-user alice@shop.localhost --auth-password Alice-heslo-123 --from bob@shop.localhost --to bob@shop.localhost 2>&1)" "not owned by user"
contains "SMTPS (465) works" "$(swaks --server 127.0.0.1 --port 465 --tlsc --auth LOGIN --auth-user alice@shop.localhost --auth-password Alice-heslo-123 --from alice@shop.localhost --to bob@shop.localhost 2>&1)" "queued"
contains "no relay without login" "$(swaks --server 127.0.0.1 --to someone@example.net --from x@example.org 2>&1)" "Relay access denied"
contains "spam (GTUBE) is rejected by Rspamd" "$(swaks --server 127.0.0.1 --to info@shop.localhost --from x@example.org --body 'XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X' 2>&1)" "Gtube"
# Port 25 is public with mail hosting: the sites' SMTP logins stay a loopback thing.
PUBLIC_IP=$(hostname -I | awk '{print $1}')
smtp_probe() { python3 - "$@" <<'PY'
import smtplib, sys, base64
ip, user, pw = sys.argv[1:4]
s = smtplib.SMTP(ip, 25, timeout=10); s.ehlo()
adv = "auth" in s.esmtp_features
code, _ = s.docmd("AUTH", "PLAIN " + base64.b64encode(f"\0{user}\0{pw}".encode()).decode())
try:
    s.sendmail(user, ["someone@example.net"], "Subject: relay\r\n\r\nx"); relay = "relayed"
except Exception:
    relay = "refused"
print(f"advertised={adv} auth={code} {relay}")
PY
}
# A login like a site's (sasldb), made for the probe and removed after it.
SMTP_USER=e2eprobe@$(postconf -h myhostname); SMTP_PASS=Probe-$RANDOM-$RANDOM
echo "$SMTP_PASS" | saslpasswd2 -p -c -f /etc/sasldb2 -u "${SMTP_USER#*@}" e2eprobe
{
	contains "port 25 from outside: no AUTH offered, a site login relays nothing" "$(smtp_probe "$PUBLIC_IP" "$SMTP_USER" "$SMTP_PASS" 2>&1)" "advertised=False"
	contains "  … and the relay is refused" "$(smtp_probe "$PUBLIC_IP" "$SMTP_USER" "$SMTP_PASS" 2>&1)" "refused"
	contains "the site's login still works on the loopback" "$(smtp_probe 127.0.0.1 "$SMTP_USER" "$SMTP_PASS" 2>&1)" "auth=235"
}
saslpasswd2 -d -f /etc/sasldb2 -u "${SMTP_USER#*@}" e2eprobe
check "Rspamd controller refuses without its password" bash -c '[[ $(curl -s -o /dev/null -w "%{http_code}" -X POST http://127.0.0.1:11334/learnspam -d x) == 401 ]]'

# Webmail: every mail domain has its own, a mailbox signs in by name alone.
WM="--resolve webmail.shop.localhost:443:127.0.0.1"
wm_login() { # user password -> HTTP code and redirect after the login form
	rm -f /tmp/e2e-wm; local t
	t=$(curl -sk $WM -c /tmp/e2e-wm -b /tmp/e2e-wm https://webmail.shop.localhost/ | grep -o 'name="_token" value="[^"]*' | head -1 | sed 's/.*value="//')
	curl -sk $WM -c /tmp/e2e-wm -b /tmp/e2e-wm -o /dev/null -w '%{http_code} %{redirect_url}' -d "_task=login&_action=login&_token=$t&_user=$1&_pass=$2&_timezone=UTC" "https://webmail.shop.localhost/?_task=login"
}
contains "webmail.<domain> serves Roundcube" "$(curl -sk $WM https://webmail.shop.localhost/)" "rcmloginuser"
contains "webmail: sign-in by the mailbox name alone" "$(wm_login alice Alice-heslo-123)" "_task=mail"
contains "  … the session is alice's" "$(curl -sk $WM -b /tmp/e2e-wm 'https://webmail.shop.localhost/?_task=mail&_mbox=INBOX')" "alice@shop.localhost"
not_contains "webmail: another domain's address is refused here" "$(wm_login alice@app.localhost Alice-heslo-123)" "_task=mail"
not_contains "webmail: a wrong password is refused" "$(wm_login alice spatne-heslo)" "_task=mail"
SSO=$(php 'echo app(App\Services\Agent\AgentClient::class)->call("mail.webmail.login", ["address" => "alice@shop.localhost"])["token"];')
rm -f /tmp/e2e-wm2
contains "webmail: one-time sign-in from the panel" "$(curl -sk $WM -c /tmp/e2e-wm2 -b /tmp/e2e-wm2 -o /dev/null -w '%{redirect_url}' -d "_wsp_sso=$SSO" 'https://webmail.shop.localhost/?_task=login')" "_task=mail"
not_contains "  … and the token works only once" "$(curl -sk $WM -o /dev/null -w '%{redirect_url}' -d "_wsp_sso=$SSO" 'https://webmail.shop.localhost/?_task=login')" "_task=mail"
master_imap() { PW=$(cat /etc/wspanel/mail/webmail-master.pw) python3 - "$@" <<'PY'
import imaplib, ssl, os, sys
try:
    m = imaplib.IMAP4_SSL(sys.argv[1], 993, ssl_context=ssl._create_unverified_context())
    m.authenticate("PLAIN", lambda _: f"{sys.argv[2]}\0webmail\0{os.environ['PW']}".encode()); print("ok")
except Exception as e:
    print("refused")
PY
}
expect "webmail master login acts for a mailbox (loopback)" "$(master_imap 127.0.0.1 alice@shop.localhost)" "ok"
expect "  … but never from outside" "$(master_imap "$PUBLIC_IP" alice@shop.localhost)" "refused"
contains "autoconfig for Thunderbird" "$(curl -sk --resolve autoconfig.shop.localhost:443:127.0.0.1 https://autoconfig.shop.localhost/mail/config-v1.1.xml)" "<hostname>panel.localhost</hostname>"
contains "autodiscover for Outlook" "$(curl -sk --resolve autodiscover.shop.localhost:443:127.0.0.1 -X POST https://autodiscover.shop.localhost/Autodiscover/Autodiscover.xml)" "<Server>panel.localhost</Server>"
# Moving a message into Junk teaches Rspamd (IMAPSieve).
learned() { curl -s -H @/var/mail/vmail/.wspanel-learn/rspamd.header http://127.0.0.1:11334/stat | python3 -c 'import json,sys; print(json.load(sys.stdin)["learned"])'; }
l0=$(learned)
swaks --server 127.0.0.1 --to alice@shop.localhost --from x@example.org --header "Subject: e2e-junk" --body "Cheap watches and miracle pills, limited offer today only, click the link to claim your prize now, unique $RANDOM $RANDOM $(date +%s%N)" >/dev/null 2>&1
sleep 2
curl -sk --max-time 10 "imaps://127.0.0.1/INBOX" -u alice@shop.localhost:Alice-heslo-123 -X "MOVE * Junk" >/dev/null 2>&1
for _ in $(seq 1 10); do [[ "$(learned)" -gt "${l0:-0}" ]] && break; sleep 1; done
check "moving mail into Junk teaches Rspamd spam" test "$(learned)" -gt "${l0:-0}"

# Autoreply: bob writes to alice, alice is away.
php '$mb = App\Models\Mailbox::whereHas("mailDomain", fn ($q) => $q->where("domain","shop.localhost"))->where("local_part","alice")->sole(); app(App\Actions\Mail\ManageMailHosting::class)->autoreply($mb, ["enabled" => true, "subject" => "Jsem na dovolene", "body" => "Vratim se v pondeli.", "days" => 1]);' >/dev/null
b1=$(imap_count bob@shop.localhost Bob-heslo-12345)
swaks --server 127.0.0.1 --port 587 --tls --auth LOGIN --auth-user bob@shop.localhost --auth-password Bob-heslo-12345 --from bob@shop.localhost --to alice@shop.localhost --header "Subject: e2e-vacation-$RANDOM" >/dev/null 2>&1
for _ in $(seq 1 20); do [[ "$(imap_count bob@shop.localhost Bob-heslo-12345)" -gt "${b1:-0}" ]] && break; sleep 1; done
check "the autoreply reached the sender" test "$(imap_count bob@shop.localhost Bob-heslo-12345)" -gt "${b1:-0}"
contains "quota usage is reported" "$(php 'echo json_encode(app(App\Services\Agent\AgentClient::class)->call("mail.usage", ["domain" => "shop.localhost"]));')" '"alice@shop.localhost"'
contains "IMAP uses the edge's certificate" "$(echo | openssl s_client -connect 127.0.0.1:993 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null)" "Caddy"
contains "blocking a mailbox" "$(php '$mb = App\Models\Mailbox::whereHas("mailDomain", fn ($q) => $q->where("domain","shop.localhost"))->where("local_part","bob")->sole(); app(App\Actions\Mail\ManageMailHosting::class)->saveMailbox($mb->mailDomain, "bob", null, 50, false, $mb); echo "BLOCKED";' 2>&1)" "BLOCKED"
for _ in $(seq 1 10); do [[ -z "$(imap_count bob@shop.localhost Bob-heslo-12345)" ]] && break; sleep 0.5; done # Dovecot re-reads the users file within a second
expect "a blocked mailbox cannot log in" "$(imap_count bob@shop.localhost Bob-heslo-12345)" ""
expect "  … not even through the webmail's master login" "$(master_imap 127.0.0.1 bob@shop.localhost)" "refused"
php '$mb = App\Models\Mailbox::whereHas("mailDomain", fn ($q) => $q->where("domain","shop.localhost"))->where("local_part","bob")->sole(); app(App\Actions\Mail\ManageMailHosting::class)->deleteMailbox($mb);' >/dev/null
check "a deleted mailbox's mail is gone" test ! -d /var/mail/vmail/shop.localhost/bob
check "the agent never stored a plain password" bash -c '! grep -rq "Alice-heslo-123" /var/lib/panel-agent /etc/dovecot/wspanel /var/log/panel-agent 2>/dev/null'

# ----------------------------------------------------------------------------
section "Delete cleans up"
php 'if (! App\Models\Site::where("domain","tmp.localhost")->exists()) app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "tmp.localhost", "mode" => "classic", "framework" => "laravel", "php_version" => "8.4"]);
$s = App\Models\Site::where("domain","tmp.localhost")->sole(); app(App\Actions\Sites\ManageWorkers::class)->create($s, "queue"); app(App\Actions\Sites\DeleteSite::class)->handle($s);' >/dev/null
check "system user removed" bash -c '! id web_tmp_localhost'
check "worker unit removed" test ! -f /etc/systemd/system/wspanel-web_tmp_localhost-queue@.service
check "edge block removed" test ! -f /etc/panel-proxy/sites/tmp.localhost.caddy
check "home moved to the root-only trash" bash -c 'ls /srv/sites/.trash | grep -q tmp.localhost'

# ----------------------------------------------------------------------------
printf '\n\033[1m%s passed, %s failed\033[0m\n' "$PASS" "$FAIL"
if ((FAIL > 0)); then
	printf '  - %s\n' "${FAILED[@]}"
	exit 1
fi
