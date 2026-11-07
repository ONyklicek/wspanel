<?php

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Support\Domains;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

class CreateSite
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Stores the site as pending, asks the agent to provision it and records
     * the outcome. A failed site stays in the list with its error.
     *
     * @param  array{domain: string, framework: string, mode?: string, php_version?: ?string, node_version?: ?string, tls?: string}  $data
     */
    public function handle(array $data): Site
    {
        $data['mode'] ??= 'classic';
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
