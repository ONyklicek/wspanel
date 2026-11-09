<?php

namespace App\Http\Controllers;

use App\Actions\Sites\ManageFiles;
use App\Models\GameServer;
use App\Services\Agent\AgentException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadGameFileController
{
    /** Streams a game server's file (fetched by the agent as its user) and deletes the copy. */
    public function __invoke(Request $request, GameServer $server, ManageFiles $files): BinaryFileResponse
    {
        $path = (string) $request->query('path', '');
        abort_if($path === '' || strlen($path) > 4096, 404);
        try {
            $local = $files->download($server, $path);
        } catch (AgentException $e) {
            abort(404, $e->getMessage());
        }

        return response()->download($local, basename($path))->deleteFileAfterSend();
    }
}
