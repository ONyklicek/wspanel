<?php

namespace App\Actions\Databases;

use App\Models\Backup;
use App\Models\Client;
use App\Models\Database;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use App\Support\CurrentClient;
use App\Support\Limits;

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
     * A client's database counts against its package and is named with the
     * client's prefix (c12_shop), so clients never compete for names.
     *
     * @return array{database: Database, password: ?string}
     *
     * @throws AgentException
     */
    public function create(string $engine, string $name, ?Site $site): array
    {
        $owner = $site !== null ? $site->client : Client::find(CurrentClient::id());
        app(Limits::class)->assertCanAdd($owner, 'databases');
        $name = self::nameFor($owner, $name);
        $params = ['engine' => $engine, 'name' => $name];
        if ($site !== null) {
            $params['domain'] = $site->domain;
        }
        $out = $this->agent->call('db.create', $params);
        $database = Database::acrossClients()->updateOrCreate(['engine' => $engine, 'name' => $name], ['site_id' => $site?->id, 'client_id' => $owner?->id]);

        return ['database' => $database, 'password' => $out['password'] ?? null];
    }

    /**
     * The name a database gets: a client's carries its prefix (c12_).
     *
     * @throws AgentException when the prefixed name is too long
     */
    public static function nameFor(?Client $owner, string $name): string
    {
        if ($owner === null) {
            return $name;
        }
        $prefix = 'c'.$owner->id.'_';
        $name = str_starts_with($name, $prefix) ? $name : $prefix.$name;
        if (strlen($name) > 48) {
            throw new AgentException('invalid_params', 'Název databáze je i s předponou '.$prefix.' delší než 48 znaků.', 'panel');
        }

        return $name;
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
