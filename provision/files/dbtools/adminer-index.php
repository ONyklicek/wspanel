<?php
// WSPanel wrapper around Adminer:
// - logins only through the panel's one-time token (wspanel-signon.php),
// - the server is pinned to 127.0.0.1 (no SSRF via the server field,
//   CVE-2021-21311), only the MariaDB and PostgreSQL drivers.
namespace {
    const WSPANEL_SERVER = '127.0.0.1';

    foreach (array_keys($_GET) as $key) {
        if (in_array($key, ['sqlite', 'sqlite2', 'oracle', 'mssql', 'mongo', 'elastic', 'clickhouse', 'firebird', 'simpledb', 'imap'], true)) {
            http_response_code(403);
            exit;
        }
    }
    foreach (['server', 'pgsql'] as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '' && $_GET[$key] !== WSPANEL_SERVER) {
            http_response_code(403);
            exit;
        }
    }
    if (isset($_POST['auth']) && ! defined('WSPANEL_SIGNON')) {
        http_response_code(403); // the login form is not used; sign in from the panel
        exit;
    }

    function adminer_object()
    {
        class WSPanelAdminer extends \Adminer\Adminer
        {
            public function name()
            {
                return 'WSPanel · Adminer';
            }

            public function credentials()
            {
                return [WSPANEL_SERVER, $_GET['username'] ?? '', \Adminer\get_password()];
            }

            public function login($login, $password)
            {
                return true; // credentials only ever come from a panel token
            }

            public function loginForm()
            {
                echo '<p>Otevřete databázi z panelu WSPanel (stránka Databáze).</p>';
            }

            public function verifyLoginToken()
            {
                // Adminer's CSRF token for its login form: the only login
                // POST that gets this far is our own sign-on (see above),
                // which is guarded by the agent's one-time token instead.
                return false;
            }

            public function permanentLogin($create = false)
            {
                return ''; // no "remember me"
            }
        }

        return new WSPanelAdminer;
    }

    require __DIR__.'/adminer.php';
}
