<?php

namespace App\Console\Commands;

use App\Actions\Firewall\SyncFirewall;
use App\Actions\Services\SyncServices;
use App\Services\Agent\AgentException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:sync')]
#[Description('Mirror the real service and firewall state from the agent into the panel database')]
class SyncState extends Command
{
    public function handle(SyncServices $services, SyncFirewall $firewall): int
    {
        try {
            $n = $services->handle();
            $fw = $firewall->handle();
        } catch (AgentException $e) {
            $this->error("Agent: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info("services: {$n}, firewall rules: {$fw['rules']} (".($fw['active'] ? 'active' : 'inactive').')');

        return self::SUCCESS;
    }
}
