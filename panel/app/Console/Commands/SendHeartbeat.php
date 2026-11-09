<?php

namespace App\Console\Commands;

use App\Services\Notify\Heartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:heartbeat')]
#[Description('Ping the outside check (Healthchecks.io): fine, or /fail with the serious problems')]
class SendHeartbeat extends Command
{
    public function handle(Heartbeat $heartbeat): int
    {
        if (Heartbeat::url() === null) {
            $this->info('no heartbeat check set');

            return self::SUCCESS;
        }
        $result = $heartbeat->ping();
        $this->info(($result['ok'] ? 'pinged' : 'ping failed').', '.count($result['failing']).' problems');

        return self::SUCCESS;
    }
}
