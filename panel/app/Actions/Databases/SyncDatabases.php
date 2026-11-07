<?php

namespace App\Actions\Databases;

use App\Enums\DatabaseEngine;
use App\Models\Backup;
use App\Models\Database;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\CurrentClient;
use Illuminate\Support\Carbon;

/**
 * Mirrors the databases that really exist (with sizes) and their backups.
 * Databases created outside the panel show up unlinked; dropped ones disappear.
 */
class SyncDatabases
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly SystemInfo $system,
    ) {}

    public function handle(): int
    {
        return CurrentClient::unrestricted(function () {
            $count = 0;
            foreach ($this->system->dbEngines() as $engine) {
                $seen = [];
                foreach ($this->agent->call('db.list', ['engine' => $engine->value]) as $db) {
                    $record = Database::updateOrCreate(
                        ['engine' => $engine->value, 'name' => $db['name']],
                        ['size' => $db['size'], 'synced_at' => now()],
                    );
                    $seen[] = $record->id;
                    $this->syncBackups($record);
                    $count++;
                }
                Database::query()->where('engine', $engine->value)->whereNotIn('id', $seen)->delete();
            }

            return $count;
        });
    }

    public function syncBackups(Database $database): void
    {
        CurrentClient::unrestricted(function () use ($database) {
            try {
                $out = $this->agent->call('db.backups', $database->agentParams());
            } catch (AgentException) {
                return;
            }
            $keep = [];
            foreach ($out['backups'] as $b) {
                $keep[] = Backup::updateOrCreate(
                    ['database_id' => $database->id, 'file' => $b['file']],
                    ['size' => $b['size'], 'taken_at' => Carbon::parse($b['created'])],
                )->id;
            }
            $database->backups()->whereNotIn('id', $keep)->delete();
            $schedule = $out['schedule'] ?? ['frequency' => 'off'];
            $database->update([
                'backup_frequency' => $schedule['frequency'],
                'backup_keep' => $schedule['keep'] ?? $database->backup_keep,
            ]);
        });
    }

    /** @return list<DatabaseEngine> */
    public static function engines(): array
    {
        return app(SystemInfo::class)->dbEngines();
    }
}
