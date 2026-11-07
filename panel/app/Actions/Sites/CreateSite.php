<?php

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Filament\Resources\Sites\Schemas\SiteForm;
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
     * @param  array{domain: string, framework: string, mode?: string, php_version?: ?string, node_version?: ?string}  $data
     */
    public function handle(array $data): Site
    {
        $data['mode'] ??= 'classic';
        $params = ['domain' => $data['domain']] + SiteForm::runtimeParams($data);

        $site = Site::create([
            'domain' => $data['domain'],
            'mode' => $data['mode'],
            'framework' => $data['framework'],
            'php_version' => $params['php_version'] ?? null,
            'node_version' => $params['node_version'] ?? null,
            'status' => SiteStatus::Pending,
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
