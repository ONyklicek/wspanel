<?php

namespace App\Http\Controllers;

use App\Enums\DatabaseEngine;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\Response;

class OpenDatabaseServerToolController
{
    /**
     * phpMyAdmin / Adminer over every database of an engine: the agent makes a
     * temporary superuser (30 minutes) and hands it over by a one-time token,
     * like the per-database sign-on.
     */
    public function __invoke(AgentClient $agent, string $engine, string $tool): Response
    {
        $engineCase = DatabaseEngine::tryFrom($engine);
        abort_if($engineCase === null || ($tool === 'phpmyadmin' && $engineCase !== DatabaseEngine::MariaDb), 404);

        try {
            $access = $agent->call('db.access', ['engine' => $engine, 'tool' => $tool, 'scope' => 'all']);
        } catch (AgentException $e) {
            return response()->view('dbtools.error', ['message' => $e->getMessage()], 502);
        }

        return response()
            ->view('dbtools.open', ['action' => $access['signon_path'], 'token' => $access['token'], 'tool' => $tool, 'label' => __('všechny databáze ').$engineCase->getLabel()])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
