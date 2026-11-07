<?php

namespace App\Actions\Sites;

use App\Actions\Services\SyncServices;
use App\Support\Domains;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/** Mode and version changes. The agent builds the new backend before removing the old one. */
class ChangeRuntime
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly SyncServices $sync,
    ) {}

    /**
     * @param  array<string, mixed>  $data  mode, framework, php_version / node_version
     *
     * @throws AgentException
     */
    public function switchMode(Site $site, array $data): Site
    {
        $out = $this->agent->call('site.switch_mode', ['domain' => $site->domain] + Domains::runtimeParams($data));

        return $this->record($site, $out);
    }

    /** @throws AgentException */
    public function setVersion(Site $site, string $version): Site
    {
        $out = $this->agent->call('site.set_version', ['domain' => $site->domain, 'version' => $version]);

        return $this->record($site, $out);
    }

    /** @param array<string, mixed> $out */
    private function record(Site $site, array $out): Site
    {
        $site->update(Site::attributesFromAgent($out) + ['health_checked_at' => null, 'health_ok' => null]);
        rescue(fn () => $this->sync->handle(), report: false);

        return $site;
    }
}
