#!/usr/bin/env bash
# Real applications for trying the panel in the dev container (make dev-demo):
#
#   laravel.localhost    Laravel 13 skeleton deployed from GitHub, MariaDB, queue + scheduler
#   symfony.localhost    Symfony Demo deployed from GitHub (SQLite bundled with the demo)
#   wordpress.localhost  plain PHP: WordPress uploaded over SFTP with a password, MariaDB
#
# Everything goes through the panel's own actions (the same code the UI runs),
# so the result is what you would get by clicking. Safe to re-run.
set -uo pipefail

W=/tmp/wspanel-demo
install -d -m 1777 "$W"
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

php() { # php CODE: run in the panel's context as the panel user
	printf '<?php\n%s\n' "$1" >"$W/snippet.php"
	chmod 644 "$W/snippet.php"
	(cd /srv/panel/current && runuser -u panel -- php artisan tinker --execute "require '$W/snippet.php';" 2>&1)
}
site() { echo "\$s = App\\Models\\Site::where('domain', '$1')->sole();"; }
create_site() { # create_site DOMAIN FRAMEWORK PHP
	php "if (! App\\Models\\Site::where('domain', '$1')->exists()) app(App\\Actions\\Sites\\CreateSite::class)->handle(['domain' => '$1', 'mode' => 'classic', 'framework' => '$2', 'php_version' => '$3']);" >/dev/null
}
create_db() { # create_db ENGINE NAME DOMAIN
	php "$(site "$3") if (! App\\Models\\Database::where('name', '$2')->exists()) app(App\\Actions\\Databases\\ManageDatabases::class)->create('$1', '$2', \$s);" >/dev/null
}
deploy() { # deploy DOMAIN REPO BRANCH -> waits, prints final status
	local rel st=running
	php "$(site "$1") app(App\\Actions\\Sites\\ManageDeploys::class)->configure(\$s, ['repo' => '$2', 'branch' => '$3', 'migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 3]);" >/dev/null
	rel=$(php "$(site "$1") echo app(App\\Actions\\Sites\\ManageDeploys::class)->start(\$s);" | tr -d '[:space:]')
	for _ in $(seq 1 300); do
		st=$(php "$(site "$1") echo app(App\\Actions\\Sites\\ManageDeploys::class)->log(\$s, '$rel', 0)['status'];" | tr -d '[:space:]')
		[[ "$st" != running ]] && break
		sleep 3
	done
	php "\$s = App\\Models\\Site::where('domain', '$1')->sole(); Artisan::call('panel:sync');" >/dev/null
	echo "$st ($rel)"
}
env_set() { # env_set DOMAIN KEY VALUE: set one line of the shared env file
	php "$(site "$1") \$m = app(App\\Actions\\Sites\\ManageDeploys::class); \$e = preg_replace('/^$2=.*\\R?/m', '', \$m->envGet(\$s)); \$m->envSet(\$s, rtrim('$2=$3'.PHP_EOL.\$e).PHP_EOL);" >/dev/null
}

# ------------------------------------------------------------ Laravel
log "laravel.localhost: Laravel 13 from github.com/laravel/laravel"
create_site laravel.localhost laravel 8.4
create_db mariadb laravel_demo laravel.localhost
if ! php "$(site laravel.localhost) echo app(App\\Actions\\Sites\\ManageDeploys::class)->envGet(\$s);" | grep -q '^APP_KEY=base64:'; then
	env_set laravel.localhost APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
fi
env_set laravel.localhost APP_NAME '"Laravel demo"'
env_set laravel.localhost APP_ENV production
env_set laravel.localhost APP_URL https://laravel.localhost:8443
env_set laravel.localhost QUEUE_CONNECTION database
env_set laravel.localhost SESSION_DRIVER database
echo "    deploy: $(deploy laravel.localhost https://github.com/laravel/laravel.git 13.x)"
php "$(site laravel.localhost) foreach (['queue', 'scheduler'] as \$w) if (! file_exists('/etc/systemd/system/wspanel-web_laravel_localhost-'.\$w.'.service')) app(App\\Actions\\Sites\\ManageWorkers::class)->create(\$s, \$w);" >/dev/null

# ------------------------------------------------------------ Symfony
log "symfony.localhost: Symfony Demo from github.com/symfony/demo"
create_site symfony.localhost symfony 8.4
env_set symfony.localhost APP_ENV prod
if ! php "$(site symfony.localhost) echo app(App\\Actions\\Sites\\ManageDeploys::class)->envGet(\$s);" | grep -q '^APP_SECRET='; then
	env_set symfony.localhost APP_SECRET "$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
fi
echo "    deploy: $(deploy symfony.localhost https://github.com/symfony/demo.git main)"

# ------------------------------------------------------------ WordPress (plain PHP over SFTP)
log "wordpress.localhost: WordPress uploaded over SFTP (password login)"
create_site wordpress.localhost php 8.4
create_db mariadb wordpress_demo wordpress.localhost
pw_file=$W/wordpress-sftp-password
if [[ ! -s $pw_file ]]; then
	php "$(site wordpress.localhost) file_put_contents('$pw_file', app(App\\Actions\\Sites\\ManageSsh::class)->handle(\$s, 'sftp', '', 'generate'));" >/dev/null
fi
command -v sshpass >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -q sshpass >/dev/null
if [[ ! -f /srv/sites/wordpress.localhost/current/public/wp-login.php ]]; then
	rm -rf "$W/wp" && mkdir -p "$W/wp" && curl -fsSL https://wordpress.org/latest.tar.gz | tar -xz -C "$W/wp"
	# The panel wrote the credentials to ~/shared/.env of the site (never shown in the panel).
	dbpass=$(sed -n 's/^DB_PASSWORD="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' /srv/sites/wordpress.localhost/shared/.env)
	salts=$(curl -fsSL https://api.wordpress.org/secret-key/1.1/salt/)
	cat >"$W/wp/wordpress/wp-config.php" <<EOF
<?php
define('DB_NAME', 'wordpress_demo');
define('DB_USER', 'wordpress_demo');
define('DB_PASSWORD', '$dbpass');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$salts
\$table_prefix = 'wp_';
define('WP_DEBUG', false);
// Behind the panel's TLS proxy.
\$_SERVER['HTTPS'] = 'on';
define('FS_METHOD', 'direct');
if (! defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
EOF
	chmod -R a+rX "$W/wp"
	printf 'put -r %s/wp/wordpress/* public_html/\n' "$W" >"$W/sftp-batch"
	SSHPASS=$(cat "$pw_file") sshpass -e sftp -q -o BatchMode=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
		-o PubkeyAuthentication=no -b "$W/sftp-batch" web_wordpress_localhost@127.0.0.1 >/dev/null &&
		echo "    uploaded $(find "$W/wp/wordpress" -type f | wc -l) files over SFTP as web_wordpress_localhost"
	rm -f /srv/sites/wordpress.localhost/current/public/index.html
fi
# Run the WordPress installer once.
if curl -sk --resolve wordpress.localhost:443:127.0.0.1 https://wordpress.localhost/wp-admin/install.php | grep -q 'weblog_title'; then
	curl -sk --resolve wordpress.localhost:443:127.0.0.1 -o /dev/null \
		--data-urlencode 'weblog_title=WordPress na WSPanelu' --data-urlencode 'user_name=admin' \
		--data-urlencode 'admin_password=Demo-heslo-123' --data-urlencode 'admin_password2=Demo-heslo-123' \
		--data-urlencode 'pw_weak=1' --data-urlencode 'admin_email=admin@wordpress.localhost' --data-urlencode 'blog_public=0' \
		'https://wordpress.localhost/wp-admin/install.php?step=2' && echo "    WordPress installed (admin / Demo-heslo-123)"
fi

php "Artisan::call('panel:sync');" >/dev/null
log "Done"
for d in laravel symfony wordpress; do
	printf '    https://%s.localhost:8443  HTTP %s\n' "$d" "$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$d.localhost:443:127.0.0.1" "https://$d.localhost/")"
done
echo "    WordPress SFTP: sftp -P 2222 web_wordpress_localhost@localhost, password: $(cat "$pw_file")"
