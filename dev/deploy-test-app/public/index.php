<?php
// Behaves like Laravel without APP_KEY: 500 until the key is set in .env.
// WSP_E2E_BROKEN=1 in .env breaks it on purpose (the health check's test).
$env = @file_get_contents(__DIR__.'/../.env') ?: '';
if (! preg_match('/^APP_KEY="?base64:/m', $env) || preg_match('/^WSP_E2E_BROKEN="?1/m', $env)) {
    http_response_code(500);
    exit("No application encryption key has been specified.\n");
}
$manifest = @json_decode(@file_get_contents(__DIR__.'/build/manifest.json'), true) ?: [];
header('Content-Type: text/plain');
echo "deploy-test-app ".trim(@file_get_contents(__DIR__.'/../VERSION'))."\n";
echo "assets built with node ".($manifest['node'] ?? '-')."\n";
echo "php ".PHP_VERSION." as ".posix_getpwuid(posix_geteuid())['name']."\n";
echo "release ".basename(realpath(__DIR__.'/..'))."\n";
