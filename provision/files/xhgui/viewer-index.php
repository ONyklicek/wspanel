<?php

/**
 * WSPanel: XHGui's front controller, run in the site's own pool. First takes
 * in what the site's requests profiled since the last visit (the spool file
 * the prepend appends to), then shows XHGui over the site's SQLite.
 */

use XHGui\Application;

require dirname(__DIR__).'/vendor/autoload.php';

$spool = getenv('WSP_XHGUI_SPOOL');
if (is_string($spool) && $spool !== '' && @filesize($spool) > 0) {
    $batch = $spool.'.'.getmypid();
    if (@rename($spool, $batch)) { // atomic: new profiles start a fresh file
        $saver = (new Application())->getSaver();
        $fp = fopen($batch, 'r');
        while ($fp && ($line = fgets($fp)) !== false) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                try {
                    $saver->save($data);
                } catch (Throwable $e) {
                    error_log('xhgui import: '.$e->getMessage());
                }
            }
        }
        $fp && fclose($fp);
        @unlink($batch);
    }
}

session_start();

$app = new Application();
$app->run();
