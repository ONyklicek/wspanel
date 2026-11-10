<?php

namespace App\Actions\Sites;

use App\Actions\Databases\ManageDatabases;
use App\Models\AgentTask;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;

/**
 * Moves a site in from a cPanel backup (cpmove-*.tar.gz) or a plain archive
 * uploaded over SFTP into the site's ~/tmp: the files into the site, each
 * database dump into a new database of the site (MariaDB).
 */
class ImportSite
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly ManageDatabases $databases,
    ) {}

    /**
     * @return array{cpanel: bool, dumps: list<string>}
     *
     * @throws AgentException
     */
    public function scan(Site $site, string $file): array
    {
        return $this->agent->call('site.import_scan', ['domain' => $site->domain, 'file' => $file]);
    }

    /**
     * The databases are created at once, the unpacking runs in the background.
     *
     * @throws AgentException
     */
    public function import(Site $site, string $file): AgentTask
    {
        $map = [];
        foreach ($this->scan($site, $file)['dumps'] as $dump) {
            $map[$dump] = $this->databases->create('mariadb', substr((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($dump)), 0, 32), $site)['database']->name;
        }
        $job = $this->agent->call('site.import', ['domain' => $site->domain, 'file' => $file, 'databases' => (object) $map]);

        return AgentTasks::start($job, 'site.import', [
            'title' => __('Import :file do :domain', ['file' => $file, 'domain' => $site->domain]),
            'done' => __('Web :domain importován', ['domain' => $site->domain]),
        ], self::class.'@imported', $site);
    }

    /** What the import brought, for the message. */
    public function imported(AgentTask $task): void
    {
        if ($task->status !== AgentTask::DONE || ($task->output['lost'] ?? false)) {
            return;
        }
        $databases = (array) ($task->output['databases'] ?? []);
        $task->update(['body' => $databases !== []
            ? __('Databáze: ').implode(', ', $databases).(isset($task->output['wp_config']) ? __('; wp-config.php upraven.') : '')
            : __('Bez databází.')]);
    }
}
