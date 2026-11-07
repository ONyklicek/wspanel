<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

class DeleteSite
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Removes the site on the server (the agent moves its files to the trash),
     * then deletes the record. A site that never got provisioned is just deleted.
     *
     * @throws AgentException
     */
    public function handle(Site $site): void
    {
        try {
            $this->agent->call('site.delete', ['domain' => $site->domain, 'confirm' => $site->domain]);
        } catch (AgentException $e) {
            if ($e->errorCode !== 'not_found') {
                throw $e;
            }
        }

        $site->delete();
    }
}
