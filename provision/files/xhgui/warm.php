<?php

/**
 * WSPanel: compiles XHGui's templates once, as root, at install. The cache
 * directory then stays read-only for the sites whose XHGui runs from it: a
 * site that could write compiled templates there would run code in every
 * other site's viewer.
 */
require '/usr/share/wspanel-xhgui/xhgui/vendor/autoload.php';

putenv('XHGUI_SAVE_HANDLER=pdo');
putenv('XHGUI_PDO_DSN=sqlite::memory:');
putenv('XHGUI_PATH_PREFIX=/xhgui/warm');
$_SERVER += ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'localhost'];

$app = new XHGui\Application();
$app->boot();
$twig = $app['app']->getContainer()['view']->getEnvironment();
$dir = '/usr/share/wspanel-xhgui/xhgui/templates';
$n = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (str_ends_with($file->getFilename(), '.twig')) {
        $twig->load(substr($file->getPathname(), strlen($dir) + 1));
        $n++;
    }
}
echo "compiled {$n} templates\n";
