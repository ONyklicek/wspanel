<?php

namespace App\Console\Commands;

use App\Actions\Server\ManageOffsite;
use App\Services\Agent\AgentException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:server-backup')]
#[Description('Back the server up (its state, configuration and mail) for wspanel-restore; copied off-site when set up')]
class BackupServer extends Command
{
    public function handle(ManageOffsite $offsite): int
    {
        try {
            $this->info('job '.$offsite->backupServer());
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
