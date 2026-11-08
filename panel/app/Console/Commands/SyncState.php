<?php

namespace App\Console\Commands;

use App\Actions\Databases\SyncDatabases;
use App\Actions\Firewall\SyncFirewall;
use App\Actions\Services\SyncServices;
use App\Actions\Sites\CheckSiteHealth;
use App\Actions\Sites\DeployPoller;
use App\Actions\Sites\DeployTrigger;
use App\Actions\Sites\ReportGitHubDeployments;
use App\Actions\SyncCertificatesAndTasks;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:sync')]
#[Description('Mirror the real service, firewall and site health state from the agent into the panel database')]
class SyncState extends Command
{
    public function handle(SyncServices $services, SyncFirewall $firewall, CheckSiteHealth $health, SyncDatabases $databases, SyncCertificatesAndTasks $certsAndTasks, DeployTrigger $deploys, DeployPoller $poller, ReportGitHubDeployments $github): int
    {
        try {
            $deploys->runQueued(); // pushes that arrived during a deploy
            $poller->all(); // repositories watched without a webhook
            rescue(fn () => $github->all(), report: true); // deploy statuses shown in GitHub
            $n = $services->handle();
            $fw = $firewall->handle();
            $health->all();
            $databases->handle();
            $certsAndTasks->certificates();
            // The mail server's certificate follows the edge's (renewals included).
            rescue(fn () => app(AgentClient::class)->call('mail.certs'), report: false);
            $certsAndTasks->tasks();
        } catch (AgentException $e) {
            $this->error("Agent: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info("services: {$n}, firewall rules: {$fw['rules']} (".($fw['active'] ? 'active' : 'inactive').')');

        return self::SUCCESS;
    }
}
