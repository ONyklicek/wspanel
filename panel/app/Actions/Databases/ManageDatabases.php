<?php

namespace App\Actions\Databases;

use App\Models\Backup;
use App\Models\Database;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;

class ManageDatabases
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
        private readonly SyncDatabases $sync,
    ) {}

    /**
     * Creates a database with its owner. With a site, credentials go straight
     * into the site's env file and the password is never seen by the panel.
     *
     * @return array{database: Database, password: ?string}
     *
     * @throws AgentException
     */
    public function create(string $engine, string $name, ?Site $site): array
    {
        $params = ['engine' => $engine, 'name' => $name];
        if ($site !== null) {
            $params['domain'] = $site->domain;
        }
        $out = $this->agent->call('db.create', $params);
        $database = Database::updateOrCreate(['engine' => $engine, 'name' => $name], ['site_id' => $site?->id]);

        return ['database' => $database, 'password' => $out['password'] ?? null];
    }

    /** @throws AgentException */
    public function resetPassword(Database $database): ?string
    {
        $params = $database->agentParams();
        if ($database->site !== null) {
            $params['domain'] = $database->site->domain;
        }

        return $this->agent->call('db.password', $params)['password'] ?? null;
    }

    /** @throws AgentException */
    public function drop(Database $database): void
    {
        $this->agent->call('db.drop', $database->agentParams() + ['confirm' => $database->name]);
        $database->delete();
    }

    /**
     * Starts a dump; waits briefly for it to finish.
     *
     * @return bool true when finished, false when still running in the background
     *
     * @throws AgentException
     */
    public function backupNow(Database $database): bool
    {
        $job = $this->agent->call('db.backup', $database->agentParams());
        $done = $this->jobs->await($job['job_id']);
        $this->sync->syncBackups($database);

        return $done !== null;
    }

    /** @throws AgentException */
    public function restore(Backup $backup): bool
    {
        $database = $backup->database;
        $job = $this->agent->call('db.restore', $database->agentParams() + ['backup' => $backup->file, 'confirm' => $database->name]);
        $done = $this->jobs->await($job['job_id'], 60);
        $this->sync->syncBackups($database);

        return $done !== null;
    }

    /** @throws AgentException */
    public function deleteBackup(Backup $backup): void
    {
        $this->agent->call('db.backup_delete', $backup->database->agentParams() + ['backup' => $backup->file]);
        $backup->delete();
    }

    /** @throws AgentException */
    public function schedule(Database $database, string $frequency, int $keep): void
    {
        $this->agent->call('db.schedule', $database->agentParams() + ['frequency' => $frequency, 'keep' => $keep]);
        $database->update(['backup_frequency' => $frequency, 'backup_keep' => $keep]);
    }
}
