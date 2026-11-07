<?php
// WSPanel sign-on for Adminer: replays Adminer's own login POST with the
// credentials from the one-time token, then Adminer redirects to index.php.
require '/etc/wspanel-dbtools/token.php';
$data = wspanel_consume_token('adminer');

define('WSPANEL_SIGNON', true);
$_GET = [];
$_POST = ['auth' => [
    'driver' => $data['engine'] === 'mariadb' ? 'server' : 'pgsql',
    'server' => '127.0.0.1',
    'username' => $data['user'],
    'password' => $data['password'],
    'db' => $data['db'],
    'permanent' => '',
]];
$_SERVER['REQUEST_URI'] = '/dbtools/adminer/index.php'; // Adminer builds its redirect from this
require __DIR__.'/index.php';
