<?php
// WSPanel sign-on for phpMyAdmin (auth_type = signon).
require '/etc/wspanel-dbtools/token.php';
$data = wspanel_consume_token('phpmyadmin');

session_set_cookie_params(['lifetime' => 0, 'path' => '/dbtools/pma/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_name('WSPanelSignon');
session_start();
session_regenerate_id(true);
$_SESSION['PMA_single_signon_user'] = $data['user'];
$_SESSION['PMA_single_signon_password'] = $data['password'];
$_SESSION['PMA_single_signon_host'] = '127.0.0.1';
$_SESSION['PMA_single_signon_port'] = '3306';
session_write_close();

// An empty db = the server-wide sign-on (every database).
header('Location: '.($data['db'] === '' ? 'index.php' : 'index.php?route=/database/structure&db='.rawurlencode($data['db'])));
