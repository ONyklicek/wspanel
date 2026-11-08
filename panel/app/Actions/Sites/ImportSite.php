<?php

namespace App\Actions\Sites;

use App\Actions\Databases\ManageDatabases;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;

/**
 * Moves a site in from a cPanel backup (cpmove-*.tar.gz) or a plain archive
 * uploaded over SFTP into the site's ~/tmp: the files into the site, each
 * database dump into a new database of the site (MariaDB).
 */
class ImportSite
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
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
     * @return array{databases: list<string>, wp_config?: string}
     *
     * @throws AgentException
     */
    public function import(Site $site, string $file): array
    {
        $map = [];
        foreach ($this->scan($site, $file)['dumps'] as $dump) {
            $map[$dump] = $this->databases->create('mariadb', substr((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($dump)), 0, 32), $site)['database']->name;
        }
        $job = $this->agent->call('site.import', ['domain' => $site->domain, 'file' => $file, 'databases' => (object) $map]);

        return isset($job['job_id'])
            ? ($this->jobs->await($job['job_id'], 1800) ?? throw new AgentException('timeout', 'Import ještě běží, výsledek uvidíte za chvíli.', 'panel'))
            : $job;
    }
}
