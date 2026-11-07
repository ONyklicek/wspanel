<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * A site's own web-server rules (Caddy syntax), placed in its edge in front
 * of the backend. The agent checks them against an allowlist and lets the web
 * server validate them before they go live.
 */
class ManageWebRules
{
    public function __construct(private readonly AgentClient $agent) {}

    /** @throws AgentException */
    public function set(Site $site, string $rules): void
    {
        $out = $this->agent->call('site.directives', ['domain' => $site->domain, 'directives' => $rules]);
        $site->update(['web_rules' => ($out['directives'] ?? '') ?: null]);
    }
}
