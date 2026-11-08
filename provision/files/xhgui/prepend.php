<?php

/**
 * WSPanel: profiles a share of this site's requests into its own XHGui
 * (SQLite in the site's home). Loaded only by pools with XHProf switched on
 * (auto_prepend_file); WSP_XHGUI_DSN and WSP_XHGUI_RATIO come from the pool.
 * Never breaks a request: any failure just skips the profile.
 */
(static function (): void {
    // Not the CLI, and not the site's XHGui itself (the same pool serves it).
    if (PHP_SAPI === 'cli' || ! extension_loaded('xhprof') || getenv('XHGUI_SAVE_HANDLER') !== false) {
        return;
    }
    $spool = getenv('WSP_XHGUI_SPOOL');
    $ratio = (int) getenv('WSP_XHGUI_RATIO');
    if (! is_string($spool) || $spool === '' || $ratio < 1 || random_int(1, 100) > $ratio) {
        return;
    }
    try {
        require_once '/usr/share/wspanel-xhgui/collector/vendor/autoload.php';
        $profiler = new \Xhgui\Profiler\Profiler([
            'profiler' => \Xhgui\Profiler\Profiler::PROFILER_XHPROF,
            'profiler.flags' => [\Xhgui\Profiler\ProfilingFlags::CPU, \Xhgui\Profiler\ProfilingFlags::MEMORY, \Xhgui\Profiler\ProfilingFlags::NO_BUILTINS],
            'save.handler' => \Xhgui\Profiler\Profiler::SAVER_FILE,
            'save.handler.file' => ['filename' => $spool],
            'profiler.enable' => static fn () => true,
            'profiler.simple_url' => static fn ($url) => preg_replace('/=\d+/', '', (string) $url),
            // Passwords and tokens in the environment never land in a profile.
            'profiler.exclude-all-env' => true,
        ]);
        $profiler->start();
    } catch (\Throwable) {
    }
})();
