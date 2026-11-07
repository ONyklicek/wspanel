<?php

namespace App\Http\Controllers;

use App\Actions\Sites\ManageSiteBackups;
use App\Models\Site;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadSiteBackupController
{
    /** Streams a whole-site backup. Only reachable inside the authenticated panel. */
    public function __invoke(Site $site, string $file): BinaryFileResponse
    {
        $path = ManageSiteBackups::path($site, $file);
        abort_if($path === null || ! is_file($path) || is_link($path), 404);

        return response()->download($path, $file);
    }
}
