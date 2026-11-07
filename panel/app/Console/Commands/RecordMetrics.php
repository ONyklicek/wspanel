<?php

namespace App\Console\Commands;

use App\Actions\Monitoring\ServerMetrics;
use App\Services\Agent\AgentException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:metrics')]
#[Description('Record one minute of server load (CPU, memory, network, disk) for the history charts')]
class RecordMetrics extends Command
{
    public function handle(ServerMetrics $metrics): int
    {
        try {
            $row = $metrics->record();
        } catch (AgentException $e) {
            $this->error("Agent: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info("cpu {$row->cpu}%, load {$row->load1}");

        return self::SUCCESS;
    }
}
