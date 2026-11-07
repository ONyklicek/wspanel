<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\Limits;

/**
 * The site's own Valkey (Redis-compatible) server: on/off, memory, eviction,
 * persistence; its state; emptying and restarting it.
 */
class ManageValkey
{
    /** Eviction policies offered in the panel: value => label. */
    public const POLICIES = [
        'allkeys-lru' => 'Cache: při zaplnění maže nejdéle nepoužité klíče',
        'allkeys-lfu' => 'Cache: při zaplnění maže nejméně používané klíče',
        'volatile-lru' => 'Smíšené: maže jen klíče s expirací',
        'noeviction' => 'Data a fronty: nic nemaže, při zaplnění zápis selže',
    ];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * What the app may keep in its Valkey, per framework: value => label.
     *
     * @return array<string, string>
     */
    public static function uses(Site $site): array
    {
        return match ($site->framework->value) {
            'laravel' => ['cache' => 'Cache (CACHE_STORE)', 'session' => 'Session (SESSION_DRIVER)', 'queue' => 'Fronty (QUEUE_CONNECTION)'],
            'symfony' => ['queue' => 'Messenger (MESSENGER_TRANSPORT_DSN)'],
            default => [],
        };
    }

    /**
     * @param  array{enabled: bool, maxmemory_mb?: int, policy?: string, persist?: bool}  $data
     *
     * @throws AgentException
     */
    public function set(Site $site, array $data): void
    {
        if (! $data['enabled']) {
            $this->agent->call('valkey.set', ['domain' => $site->domain, 'enabled' => false]);
            $site->update(['valkey' => null]);

            return;
        }
        $settings = [
            'maxmemory_mb' => (int) ($data['maxmemory_mb'] ?? 64),
            'policy' => (string) ($data['policy'] ?? 'allkeys-lru'),
            'persist' => (bool) ($data['persist'] ?? false),
            'use' => array_values(array_intersect((array) ($data['use'] ?? []), array_keys(self::uses($site)))),
        ];
        app(Limits::class)->assertValkey($site->client, $settings['maxmemory_mb']);
        $out = $this->agent->call('valkey.set', ['domain' => $site->domain, 'enabled' => true] + $settings);
        $site->update(['valkey' => $settings + ['host' => $out['host'] ?? '127.0.0.1', 'port' => $out['port'] ?? null, 'socket' => $out['socket'] ?? null]]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function status(Site $site): array
    {
        return $this->agent->call('valkey.status', ['domain' => $site->domain]);
    }

    /** @throws AgentException */
    public function flush(Site $site): void
    {
        $this->agent->call('valkey.flush', ['domain' => $site->domain]);
    }

    /** @throws AgentException */
    public function restart(Site $site): void
    {
        $this->agent->call('valkey.restart', ['domain' => $site->domain]);
    }
}
