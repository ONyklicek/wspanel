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

    /** @return array<string, mixed> agent output (unit, started) */
    public function create(Site $site, string $worker): array
    {
        app(Limits::class)->assertCanAdd($site->client, 'workers');
        $out = $this->agent->call('worker.create', ['domain' => $site->domain, 'worker' => $worker]);
        rescue(fn () => $this->sync->handle(), report: false);

        return $out;
    }

    public function delete(Site $site, string $worker): void
    {
        $this->agent->call('worker.delete', ['domain' => $site->domain, 'worker' => $worker]);
        rescue(fn () => $this->sync->handle(), report: false);
    }
}
