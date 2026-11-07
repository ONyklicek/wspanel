<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadBackupController
{
    /** Streams a dump file. Only reachable inside the authenticated panel. */
    public function __invoke(Backup $backup): BinaryFileResponse
    {
        $path = $backup->path();
        abort_if($path === null || ! is_file($path) || is_link($path), 404);

        return response()->download($path, $backup->file);
    }
}
