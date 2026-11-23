<?php

namespace App\Actions\Server;

use App\Models\AgentTask;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use Illuminate\Support\Facades\Cache;

/**
 * Redis 8 as a module (Server → Moduly, or install.sh --redis=yes): the
 * official server from packages.redis.io, which a site can then run instead
 * of Valkey for its own key-value server.
 */
class ManageRedis
{
    private const CACHE = 'redis.status';

    public function __construct(private readonly AgentClient $agent) {}

    /** @return array{installed?: bool, version?: string, modules?: list<string>, sites?: list<string>} */
    public function status(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => rescue(fn () => $this->agent->call('redis.status'), [], report: false));
    }

    public function installed(): bool
    {
        return (bool) ($this->status()['installed'] ?? false);
    }

    /** @throws AgentException */
    public function install(): AgentTask
    {
        $job = $this->agent->call('redis.install');
        Cache::forget(self::CACHE);

        return AgentTasks::start($job, 'redis.install', [
            'title' => __('Instalace Redis 8'),
            'done' => __('Redis 8 nainstalován'),
            'body' => __('U webu ho zvolíte v Nastavení → Valkey / Redis.'),
        ], self::class.'@changed', key: 'redis');
    }

    /**
     * Takes Redis away. Refused while a site runs it.
     *
     * @throws AgentException
     */
    public function uninstall(): AgentTask
    {
        $sites = Site::acrossClients()->where('valkey->server', 'redis')->orderBy('domain')->pluck('domain');
        if ($sites->isNotEmpty()) {
            throw new AgentException('conflict', __('Redis používají weby :sites: nejdřív je přepněte na Valkey nebo ho vypněte.', ['sites' => $sites->implode(', ')]), 'panel');
        }
        $job = $this->agent->call('redis.uninstall');
        Cache::forget(self::CACHE);

        return AgentTasks::start($job, 'redis.uninstall', [
            'title' => __('Odinstalace Redis'),
            'done' => __('Redis odinstalován'),
        ], self::class.'@changed', key: 'redis');
    }

    /** Task handler: the module's state is read again. */
    public function changed(AgentTask $task): void
    {
        Cache::forget(self::CACHE);
    }
}
