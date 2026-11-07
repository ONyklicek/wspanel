<?php

namespace App\Console\Commands;

use App\Actions\Databases\SyncDatabases;
use App\Actions\Firewall\SyncFirewall;
use App\Actions\Services\SyncServices;
use App\Actions\Sites\CheckSiteHealth;
use App\Actions\Sites\DeployPoller;
use App\Actions\Sites\DeployTrigger;
use App\Actions\SyncCertificatesAndTasks;
use App\Services\Agent\AgentException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:sync')]
#[Description('Mirror the real service, firewall and site health state from the agent into the panel database')]
class SyncState extends Command
{
    public function handle(SyncServices $services, SyncFirewall $firewall, CheckSiteHealth $health, SyncDatabases $databases, SyncCertificatesAndTasks $certsAndTasks, DeployTrigger $deploys, DeployPoller $poller): int
    {
        try {
            $deploys->runQueued(); // pushes that arrived during a deploy
            $poller->all(); // repositories watched without a webhook
            $n = $services->handle();
            $fw = $firewall->handle();
            $health->all();
            $databases->handle();
            $certsAndTasks->certificates();
            $certsAndTasks->tasks();
        } catch (AgentException $e) {
            $this->error("Agent: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info("services: {$n}, firewall rules: {$fw['rules']} (".($fw['active'] ? 'active' : 'inactive').')');

        return self::SUCCESS;
    }
}
