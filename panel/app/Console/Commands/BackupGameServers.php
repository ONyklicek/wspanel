<?php

namespace App\Console\Commands;

use App\Actions\Games\ManageGameServers;
use App\Models\AgentTask;
use App\Models\GameServer;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('panel:game-backups')]
#[Description('Back up every game server (its data), copied off-site when set up')]
class BackupGameServers extends Command
{
    public function handle(ManageGameServers $games): int
    {
        $failed = 0;
        foreach (GameServer::acrossClients()->where('status', 'active')->orderBy('id')->get() as $server) {
            try {
                // Nobody to show it to: the command waits for the backup.
                $task = AgentTasks::wait($games->backup($server), 600);
                if ($task->status === AgentTask::FAILED) {
                    throw new AgentException('job_failed', (string) $task->error, 'game.backup');
                }
                $out = $task->isRunning() ? null : (array) $task->output;
                $this->info($server->name.': '.($out['file'] ?? 'running').(isset($out['offsite_error']) ? ' (off-site: '.$out['offsite_error'].')' : ''));
                if (isset($out['offsite_error'])) {
                    Log::warning('game server backup not copied off-site', ['server' => $server->id, 'error' => $out['offsite_error']]);
                }
            } catch (AgentException $e) {
                $failed++;
                $this->error($server->name.': '.$e->getMessage());
                Log::error('game server backup failed', ['server' => $server->id, 'error' => $e->raw]);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
