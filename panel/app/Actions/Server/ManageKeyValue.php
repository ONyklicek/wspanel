<?php

namespace App\Actions\Server;

use App\Models\AgentTask;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use Illuminate\Support\Facades\Cache;

/**
 * The sites' key-value servers (Server → Moduly, install.sh --kv=…): Valkey
 * from Debian, Redis 8 from packages.redis.io, or both. Each site that
 * switches one on runs its own instance; a server may have only one of them.
 */
class ManageKeyValue
{
    public const SERVERS = ['valkey' => 'Valkey', 'redis' => 'Redis 8'];

    private const CACHE = 'kv.status';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array{servers?: array<string, array{installed: bool, sites: list<string>}>, default?: string, mail?: string}
     */
    public function status(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => rescue(fn () => $this->agent->call('kv.status'), [], report: false));
    }

    public function installed(string $server): bool
    {
        return (bool) ($this->status()['servers'][$server]['installed'] ?? $server === 'valkey');
    }

    /** @return array<string, string> the installed ones, value => label */
    public function available(): array
    {
        return array_filter(self::SERVERS, fn (string $label, string $server) => $this->installed($server), ARRAY_FILTER_USE_BOTH);
    }

    /** What a site gets when it does not choose: Valkey where it is. */
    public function default(): string
    {
        return (string) ($this->status()['default'] ?? 'valkey');
    }

    /** @throws AgentException */
    public function install(string $server): AgentTask
    {
        $server = $this->known($server);
        $job = $this->agent->call('kv.install', ['server' => $server]);
        Cache::forget(self::CACHE);

        return AgentTasks::start($job, 'kv.install', [
            'title' => __('Instalace :server', ['server' => self::SERVERS[$server]]),
            'done' => __(':server nainstalován', ['server' => self::SERVERS[$server]]),
            'body' => __('U webu ho zvolíte v Nastavení → Valkey / Redis.'),
        ], self::class.'@changed', key: 'kv');
    }

    /**
     * Takes one away: refused while a site runs it, and the last one stays.
     * The mail filter moves to the other one (its statistics start again).
     *
     * @throws AgentException
     */
    public function uninstall(string $server): AgentTask
    {
        $server = $this->known($server);
        $sites = Site::acrossClients()->whereNotNull('valkey')->get()
            ->filter(fn (Site $site) => ($site->valkey['server'] ?? 'valkey') === $server)->pluck('domain')->sort()->values();
        if ($sites->isNotEmpty()) {
            throw new AgentException('conflict', __(':server používají weby :sites: nejdřív je přepněte nebo ho u nich vypněte.', ['server' => self::SERVERS[$server], 'sites' => $sites->implode(', ')]), 'panel');
        }
        $job = $this->agent->call('kv.uninstall', ['server' => $server]);
        Cache::forget(self::CACHE);

        return AgentTasks::start($job, 'kv.uninstall', [
            'title' => __('Odinstalace :server', ['server' => self::SERVERS[$server]]),
            'done' => __(':server odinstalován', ['server' => self::SERVERS[$server]]),
        ], self::class.'@changed', key: 'kv');
    }

    /** Task handler: the modules' state is read again. */
    public function changed(AgentTask $task): void
    {
        Cache::forget(self::CACHE);
    }

    private function known(string $server): string
    {
        if (! isset(self::SERVERS[$server])) {
            throw new AgentException('invalid', __('Neznámý server :server.', ['server' => $server]), 'panel');
        }

        return $server;
    }
}
