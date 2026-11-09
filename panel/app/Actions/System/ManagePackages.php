<?php

namespace App\Actions\System;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;

/** Debian packages, through the agent (Server → Balíčky). */
class ManagePackages
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
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
     * true when installed, false while still running.
     *
     * @throws AgentException
     */
    public function install(string $name): bool
    {
        return $this->run('pkg.install', $name);
    }

    /** @throws AgentException */
    public function remove(string $name): bool
    {
        return $this->run('pkg.remove', $name);
    }

    /** @throws AgentException */
    public function hold(string $name, bool $hold): void
    {
        $this->agent->call('pkg.hold', ['name' => $name, 'hold' => $hold]);
    }

    /** @throws AgentException */
    private function run(string $op, string $name): bool
    {
        $job = $this->agent->call($op, ['name' => $name]);

        return isset($job['job_id']) ? $this->jobs->await($job['job_id'], 600) !== null : true;
    }
}
