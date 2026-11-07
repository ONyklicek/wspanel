<?php

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Exceptions\LimitReachedException;
use App\Models\Client;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\CurrentClient;
use App\Support\Domains;
use App\Support\Limits;

class CreateSite
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly Limits $limits,
    ) {}

    /**
     * Stores the site as pending, asks the agent to provision it and records
     * the outcome. A failed site stays in the list with its error.
     *
     * A client's site counts against its package (sites, modes, PHP versions);
     * an admin may create one for a client by passing client_id.
     *
     * @param  array{domain: string, framework: string, mode?: string, php_version?: ?string, node_version?: ?string, tls?: string, client_id?: ?int}  $data
     *
     * @throws LimitReachedException
     */
    public function handle(array $data): Site
    {
        $data['mode'] ??= 'classic';
        $owner = Client::find(CurrentClient::id() ?? ($data['client_id'] ?? null));
        $this->limits->assertCanAdd($owner, 'sites');
        $this->limits->assertMode($owner, $data['mode'], $data['mode'] === 'classic' ? ($data['php_version'] ?? null) : null);
        $params = ['domain' => $data['domain']] + Domains::runtimeParams($data);
        $tls = $data['tls'] ?? (Domains::isInternal($data['domain']) ? 'internal' : 'auto');
        if (in_array($tls, ['auto', 'internal'], true)) {
            $params['tls'] = $tls;
        }

        $site = Site::create([
            'domain' => $data['domain'],
            'mode' => $data['mode'],
            'framework' => $data['framework'],
            'php_version' => $params['php_version'] ?? null,
            'node_version' => $params['node_version'] ?? null,
            'status' => SiteStatus::Pending,
            'tls_mode' => $params['tls'] ?? 'auto',
            'client_id' => $owner?->id,
        ]);

        try {
            $out = $this->agent->call('site.create', $params);
            $site->update(Site::attributesFromAgent($out) + ['status' => SiteStatus::Active, 'last_error' => null]);
        } catch (AgentException $e) {
            $site->update(['status' => SiteStatus::Failed, 'last_error' => $e->getMessage()]);
        }

        return $site;
    }
}
