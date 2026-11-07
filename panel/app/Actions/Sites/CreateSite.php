<?php

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
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
     * @param  array{domain: string, framework: string, php_version: string, mode?: string}  $data
     */
    public function handle(array $data): Site
    {
        $site = Site::create([
            'domain' => $data['domain'],
            'mode' => $data['mode'] ?? 'classic',
            'framework' => $data['framework'],
            'php_version' => $data['php_version'],
            'status' => SiteStatus::Pending,
        ]);

        try {
            $out = $this->agent->call('site.create', [
                'domain' => $site->domain,
                'framework' => $site->framework->value,
                'php_version' => $site->php_version,
                'mode' => $site->mode->value,
            ]);

            $site->update([
                'system_user' => $out['system_user'] ?? null,
                'home' => $out['home'] ?? null,
                'doc_root' => $out['doc_root'] ?? null,
                'status' => SiteStatus::Active,
                'last_error' => null,
            ]);
        } catch (AgentException $e) {
            $site->update(['status' => SiteStatus::Failed, 'last_error' => $e->getMessage()]);
        }

        return $site;
    }
}
