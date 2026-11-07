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
for u in panel-agent panel-proxy panel-web panel-scheduler php8.3-fpm php8.4-fpm mariadb postgresql valkey-server ssh fail2ban; do
	check "$u active" systemctl is-active --quiet "$u"
done
check "ufw active" bash -c 'ufw status | grep -q "Status: active"'
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
php "$(site shop.localhost) foreach (['horizon', 'scheduler', 'pulse', 'reverb'] as \$w) if (! file_exists('/etc/systemd/system/wspanel-web_shop_localhost-'.\$w.'.service')) app(App\\Actions\\Sites\\ManageWorkers::class)->create(\$s, \$w);" >/dev/null
for w in horizon scheduler pulse; do
	check "worker unit $w installed" test -f /etc/systemd/system/wspanel-web_shop_localhost-$w.service
done
check "worker units are sandboxed (ProtectSystem=strict)" grep -q ProtectSystem=strict /etc/systemd/system/wspanel-web_shop_localhost-horizon.service
contains "reverb gets a privileged port" "$(grep -o -- '--port=[0-9]*' /etc/systemd/system/wspanel-web_shop_localhost-reverb.service)" "--port="
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
php "$(site app.localhost) app(App\\Actions\\Sites\\ManageDeploys::class)->configure(\$s, ['repo' => 'git@localhost:app.git', 'branch' => 'main', 'migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 5]);
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
contains "build ran as the site user" "$(cat /srv/sites/app.localhost/shared/storage/logs/artisan.log)" "as web_app_localhost"
contains "assets built and served by the web tier" "$(https app.localhost /build/manifest.json)" '"node":"v'
check "env.set content is redacted in the audit log" bash -c '! grep "\"op\":\"env.set\"" /var/log/panel-agent/audit.log | grep -q APP_KEY=base64'

php "$(site app.localhost) app(App\\Actions\\Sites\\ManageWebhook::class)->enable(\$s); file_put_contents('$W/hook', App\\Actions\\Sites\\ManageWebhook::url(\$s).PHP_EOL.\$s->webhook_secret);" >/dev/null
url=$(sed -n 1p "$W/hook" | sed -E 's#^(https://[^/:]+):[0-9]+#\1#'); secret=$(sed -n 2p "$W/hook") # public port -> in-container 443
echo v2 >"$W/app/VERSION" && chown git:git "$W/app/VERSION" && gitc commit -qam v2 && gitc push -q /home/git/app.git main
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
tar -czf "$W/build.tar.gz" -C "$W/artifact" .
out=$(pc -X POST -H "Authorization: Bearer $ci_token" -F "artifact=@$W/build.tar.gz" -F ref=v4 -F commit=abcdef1 "$api/artifact")
expect "CI artifact deploy succeeds" "$(ci_wait "$out")" "success"
contains "artifact is live (no build on the server)" "$(https app.localhost /build/manifest.json)" "in-ci"
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
expect "an extension installs" "$(php 'var_export(app(App\Actions\Server\ManagePhp::class)->extension("8.4", "igbinary", "install"));' | tail -1)" "true"
contains "and is loaded by PHP-FPM 8.4" "$(php8.4 -m 2>/dev/null)" "igbinary"
php 'app(App\Actions\Server\ManagePhp::class)->extension("8.4", "igbinary", "remove");' >/dev/null
expect "and is removed again" "$(dpkg-query -W -f '${db:Status-Status}' php8.4-igbinary 2>/dev/null)" "config-files"

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

# ----------------------------------------------------------------------------
section "Delete cleans up"
php 'if (! App\Models\Site::where("domain","tmp.localhost")->exists()) app(App\Actions\Sites\CreateSite::class)->handle(["domain" => "tmp.localhost", "mode" => "classic", "framework" => "laravel", "php_version" => "8.4"]);
$s = App\Models\Site::where("domain","tmp.localhost")->sole(); app(App\Actions\Sites\ManageWorkers::class)->create($s, "queue"); app(App\Actions\Sites\DeleteSite::class)->handle($s);' >/dev/null
check "system user removed" bash -c '! id web_tmp_localhost'
check "worker unit removed" test ! -f /etc/systemd/system/wspanel-web_tmp_localhost-queue.service
check "edge block removed" test ! -f /etc/panel-proxy/sites/tmp.localhost.caddy
check "home moved to the root-only trash" bash -c 'ls /srv/sites/.trash | grep -q tmp.localhost'

# ----------------------------------------------------------------------------
printf '\n\033[1m%s passed, %s failed\033[0m\n' "$PASS" "$FAIL"
if ((FAIL > 0)); then
	printf '  - %s\n' "${FAILED[@]}"
	exit 1
fi
