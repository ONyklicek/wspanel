<?php

namespace App\Actions\Databases;

use App\Models\AgentTask;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use Illuminate\Support\Facades\Cache;

/**
 * Dedicated database servers of single sites: their own MariaDB or
 * PostgreSQL, reachable only on a socket only that site may enter.
 */
class ManageDatabaseInstances
{
    private const CACHE = 'agent.db.instances';

    public function __construct(
        private readonly AgentClient $agent,
    ) {}

    /**
     * @return list<array{id: int, engine: string, domain: string, memory_mb: int, cpu_percent: int, databases: list<string>, active?: string, version?: string, size: int, host: string, port: int, socket?: string}>
     *
     * @throws AgentException
     */
    public function list(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE);
        }

        return Cache::remember(self::CACHE, 15, fn () => $this->agent->call('db.instances')['instances'] ?? []);
    }

    /** The site's dedicated server of the engine, if it has one. */
    public function forSite(Site $site, string $engine): ?array
    {
        $all = rescue(fn () => $this->list(), [], report: false);

        return collect($all)->first(fn (array $i) => $i['domain'] === $site->domain && $i['engine'] === $engine);
    }

    /** @throws AgentException when the id is not an instance */
    public function find(int $id): array
    {
        $i = collect($this->list())->firstWhere('id', $id);
        if ($i === null) {
            throw new AgentException('not_found', __('Vyhrazený server neexistuje.'), 'panel');
        }

        return $i;
    }

    /**
     * Starts setting one up (an agent job).
     *
     * @return array<string, mixed> the job
     *
     * @throws AgentException
     */
    public function create(Site $site, string $engine, int $memoryMb, int $cpuPercent): array
    {
        Cache::forget(self::CACHE);

        return $this->agent->call('db.instance_create', ['domain' => $site->domain, 'engine' => $engine, 'memory_mb' => $memoryMb, 'cpu_percent' => $cpuPercent]);
    }

    /**
     * New limits; the server restarts.
     *
     * @throws AgentException
     */
    public function update(int $id, int $memoryMb, int $cpuPercent): AgentTask
    {
        $job = $this->agent->call('db.instance_update', ['id' => $id, 'memory_mb' => $memoryMb, 'cpu_percent' => $cpuPercent]);
        Cache::forget(self::CACHE);

        return AgentTasks::start($job, 'db.instance_update', [
            'title' => __('Změna limitů databázového serveru #:id', ['id' => $id]),
            'done' => __('Limity databázového serveru #:id změněny', ['id' => $id]),
        ], self::class.'@changed', key: 'db-instance');
    }

    public function changed(AgentTask $task): void
    {
        Cache::forget(self::CACHE);
    }

    /** @throws AgentException */
    public function control(int $id, string $action): void
    {
        $this->agent->call('db.instance_action', ['id' => $id, 'action' => $action]);
        Cache::forget(self::CACHE);
    }

    /** @throws AgentException (also while it still holds databases) */
    public function delete(int $id): void
    {
        $i = $this->find($id);
        $this->agent->call('db.instance_delete', ['id' => $id, 'confirm' => $i['domain']]);
        Cache::forget(self::CACHE);
    }
}
