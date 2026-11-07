<?php

namespace App\Services\Agent;

use App\Enums\DatabaseEngine;
use Illuminate\Support\Facades\Cache;

/**
 * What is installed on the server, as reported by the agent (sys.versions).
 * The panel never assumes — it asks.
 */
class SystemInfo
{
    public function __construct(private readonly AgentClient $agent) {}

    /** @return array<string, mixed> */
    public function versions(): array
    {
        return Cache::remember('agent.sys.versions', 60, fn () => $this->agent->call('sys.versions'));
    }

    /** @return array<string, string> version => label */
    public function phpVersionOptions(): array
    {
        try {
            $php = $this->versions()['php'] ?? [];
        } catch (AgentException) {
            return [];
        }

        return collect($php)
            ->filter(fn (array $v) => $v['installed'])
            ->mapWithKeys(fn (array $v) => [$v['version'] => 'PHP '.$v['version']])
            ->sortKeysDesc()
            ->all();
    }

    /** Units the panel runs on: never stoppable from the UI (the agent refuses too). @return list<string> */
    public function panelUnits(): array
    {
        try {
            return $this->versions()['panel_units'] ?? [];
        } catch (AgentException) {
            return [];
        }
    }

    /** @return list<string> worker types available for a framework */
    public function workerTypes(string $framework): array
    {
        try {
            return $this->versions()['workers'][$framework] ?? [];
        } catch (AgentException) {
            return [];
        }
    }

    /** PHP version built into FrankenPHP (worker mode), e.g. "8.5". */
    public function workerPhpVersion(): ?string
    {
        try {
            return ($this->versions()['worker_php'] ?? '') ?: null;
        } catch (AgentException) {
            return null;
        }
    }

    /** @return array<string, string> major => label */
    public function nodeVersionOptions(): array
    {
        try {
            $majors = $this->versions()['node'] ?? [];
        } catch (AgentException) {
            return [];
        }

        return collect($majors)->sortDesc()->mapWithKeys(fn (string $v) => [$v => "Node {$v}"])->all();
    }

    /** @return list<DatabaseEngine> engines installed on the server */
    public function dbEngines(): array
    {
        try {
            $engines = $this->versions()['db_engines'] ?? [];
        } catch (AgentException) {
            return [];
        }

        return array_values(array_filter(array_map(fn (string $e) => DatabaseEngine::tryFrom($e), $engines)));
    }

    public function defaultPhpVersion(): ?string
    {
        try {
            return $this->versions()['php_default'] ?? null;
        } catch (AgentException) {
            return null;
        }
    }
}
