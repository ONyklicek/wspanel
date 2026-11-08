<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;

/**
 * A site's network disks (SMB share, FTP/FTPS or SFTP server) mounted at
 * <home>/mounts/<name>, writable by the site. The agent keeps the list and
 * the passwords; the panel never stores either.
 */
class ManageSiteMounts
{
    public const TYPES = ['smb' => 'SMB (Windows, NAS, Samba)', 'ftp' => 'FTP / FTPS', 'sftp' => 'SFTP (SSH)'];

    public const SMB_VERSIONS = ['3.1.1' => 'SMB 3.1.1', '3.0' => 'SMB 3.0', '2.1' => 'SMB 2.1', '2.0' => 'SMB 2.0', '1.0' => 'SMB 1 (staré NAS)'];

    public const FTP_TLS = ['' => 'bez šifrování', 'explicit' => 'FTPS explicitní (AUTH TLS)', 'implicit' => 'FTPS implicitní (port 990)'];

    /** @var array<string, list<array<string, mixed>>> per request (scoped): a settings page asks several times */
    private array $listed = [];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
    ) {}

    /** @return list<array{mount: array<string, mixed>, path: string, unit: string, active: string}> */
    public function list(Site $site): array
    {
        return $this->listed[$site->domain] ??= rescue(fn () => $this->agent->call('site.mounts', ['domain' => $site->domain])['mounts'] ?? [], [], report: false);
    }

    /**
     * Connects the disk; nothing is kept when it does not connect.
     *
     * @param  array<string, mixed>  $mount
     * @return array<string, mixed>|null the agent's answer (not_visible_to: processes
     *                                   to restart), null while still connecting
     *
     * @throws AgentException
     */
    public function add(Site $site, array $mount, string $password): ?array
    {
        return $this->change($site, 'add', $mount, $password);
    }

    /** @throws AgentException */
    public function remove(Site $site, string $name): void
    {
        $this->change($site, 'remove', ['name' => $name]);
    }

    /** @throws AgentException */
    public function remount(Site $site, string $name): void
    {
        $this->change($site, 'remount', ['name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $mount
     * @return array<string, mixed>|null
     */
    private function change(Site $site, string $action, array $mount, ?string $password = null): ?array
    {
        $params = ['domain' => $site->domain, 'action' => $action, 'mount' => $mount];
        if ($password !== null) {
            $params['password'] = $password;
        }
        unset($this->listed[$site->domain]);
        $job = $this->agent->call('site.mount', $params);

        return isset($job['job_id']) ? $this->jobs->await($job['job_id'], 300) : $job;
    }
}
