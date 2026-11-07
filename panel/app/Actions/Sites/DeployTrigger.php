<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * Starts a deploy, or queues one when a deploy is already running: pushes
 * arriving during a deploy collapse into a single follow-up deploy, which
 * fetches the newest commit of the branch.
 */
class DeployTrigger
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @param  array<string, mixed>|null  $history  deploy.history output, if already fetched
     * @return 'deployed'|'queued'
     *
     * @throws AgentException
     */
    public function request(Site $site, ?array $history = null): string
    {
        $history ??= $this->agent->call('deploy.history', ['domain' => $site->domain]);
        if ($history['running'] ?? false) {
            $site->update(['deploy_requested_at' => now()]);

            return 'queued';
        }
        $this->agent->call('deploy.run', ['domain' => $site->domain]);
        $site->update(['deploy_requested_at' => null]);

        return 'deployed';
    }

    /** Called every minute by panel:sync: starts queued deploys whose previous run finished. */
    public function runQueued(): int
    {
        $started = 0;
        foreach (Site::query()->whereNotNull('deploy_requested_at')->where('webhook_enabled', true)->get() as $site) {
            try {
                if ($this->request($site) === 'deployed') {
                    $started++;
                }
            } catch (AgentException) {
                // stays queued; retried next minute
            }
        }

        return $started;
    }
}
