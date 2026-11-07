# Managed by WSPanel install.sh.
# Site users (SSH shell) get their site's php / node / npm first on PATH,
# so composer and npm run with the same versions as the site itself.
_wspanel_bin="/etc/panel-sites/bin/$(id -un)"
if [ -d "$_wspanel_bin" ]; then
	PATH="$_wspanel_bin:$PATH"
	export PATH
	export COMPOSER_HOME="$HOME/tmp/composer"
	export npm_config_cache="$HOME/tmp/npm"
fi
unset _wspanel_bin
