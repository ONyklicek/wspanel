<?php

namespace App\Http\Controllers;

use App\Models\Database;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\Response;

class OpenDatabaseToolController
{
    /**
     * Asks the agent for temporary access to one database and hands the
     * one-time token to phpMyAdmin / Adminer by an auto-submitted POST form,
     * so it never appears in a URL or access log.
     */
    public function __invoke(AgentClient $agent, Database $database, string $tool): Response
    {
        abort_unless(in_array($tool, ['phpmyadmin', 'adminer'], true), 404);
        // A site's dedicated server has no network: the tools cannot reach it.
        abort_if($database->isDedicated(), 409);
        abort_unless($database->engine->hasTools(), 404);

        try {
            $access = $agent->call('db.access', $database->agentParams() + ['tool' => $tool]);
        } catch (AgentException $e) {
            return response()->view('dbtools.error', ['message' => $e->getMessage()], 502);
        }

        return response()
            ->view('dbtools.open', ['action' => $access['signon_path'], 'token' => $access['token'], 'tool' => $tool, 'database' => $database])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
