<?php

namespace App\Actions\System;

use App\Models\AgentTask;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;

/** Debian packages, through the agent (Server → Balíčky). */
class ManagePackages
{
    public function __construct(
        private readonly AgentClient $agent,
    ) {}

    /**
     * @return list<array{name: string, version: string, summary: string, size: int, manual: bool, held: bool, protected: bool}>
     *
     * @throws AgentException
     */
    public function installed(): array
    {
        return $this->agent->call('pkg.list')['packages'] ?? [];
    }

    /**
     * @return array{packages: list<array{name: string, version: string, summary: string, protected: bool}>, total: int}
     *
     * @throws AgentException
     */
    public function search(string $query): array
    {
        return $this->agent->call('pkg.search', ['query' => $query]);
    }

    /**
     * What installing or removing would do.
     *
     * @return array{install: list<string>, remove: list<string>, allowed: bool, reason?: string}
     *
     * @throws AgentException
     */
    public function preview(string $name, string $action): array
    {
        return $this->agent->call('pkg.preview', ['name' => $name, 'action' => $action]);
    }

    /**
     * Installs a package (and what it needs), in the background.
     *
     * @throws AgentException
     */
    public function install(string $name): AgentTask
    {
        return $this->run('pkg.install', $name, __('Instalace balíčku :name', ['name' => $name]), __(':name nainstalován', ['name' => $name]));
    }

    /** @throws AgentException */
    public function remove(string $name): AgentTask
    {
        return $this->run('pkg.remove', $name, __('Odebrání balíčku :name', ['name' => $name]), __(':name odebrán', ['name' => $name]));
    }

    /** @throws AgentException */
    public function hold(string $name, bool $hold): void
    {
        $this->agent->call('pkg.hold', ['name' => $name, 'hold' => $hold]);
    }

    /** @throws AgentException */
    private function run(string $op, string $name, string $title, string $done): AgentTask
    {
        $job = $this->agent->call($op, ['name' => $name]);

        return AgentTasks::start($job, $op, ['title' => $title, 'done' => $done], key: 'packages');
    }
}
