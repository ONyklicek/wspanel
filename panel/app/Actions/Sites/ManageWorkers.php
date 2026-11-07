<?php

namespace App\Actions\Sites;

use App\Actions\Services\SyncServices;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Support\Limits;

class ManageWorkers
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly SyncServices $sync,
    ) {}

    /** Workers that keep their data in the site's Valkey. */
    public const NEEDS_VALKEY = ['horizon', 'pulsework'];

    /**
     * Adds a worker and prepares what it needs: Horizon and Pulse's Redis
     * ingest get the site's Valkey switched on (nothing evicted, kept on disk)
     * when it is not yet; the agent sets the app's .env (queue connection,
     * Reverb keys and addresses) before the worker starts.
     *
     * @return array<string, mixed> agent output (unit, started)
     */
    public function create(Site $site, string $worker): array
    {
        app(Limits::class)->assertCanAdd($site->client, 'workers');
        if (in_array($worker, self::NEEDS_VALKEY, true) && $site->valkey === null) {
            $limit = $site->client?->limit('max_valkey_mb');
            app(ManageValkey::class)->set($site, [
                'enabled' => true,
                'maxmemory_mb' => $limit === null ? 128 : min(128, (int) $limit),
                'policy' => 'noeviction',
                'persist' => true,
                'use' => $worker === 'horizon' ? ['queue'] : [],
            ]);
        }
        $params = ['domain' => $site->domain, 'worker' => $worker];
        if ($worker === 'reverb') {
            $params['public_port'] = Site::publicHttpsPort(); // where browsers reach Reverb
        }
        $out = $this->agent->call('worker.create', $params);
        rescue(fn () => $this->sync->handle(), report: false);

        return $out;
    }

    public function delete(Site $site, string $worker): void
    {
        $this->agent->call('worker.delete', ['domain' => $site->domain, 'worker' => $worker]);
        rescue(fn () => $this->sync->handle(), report: false);
    }
}
