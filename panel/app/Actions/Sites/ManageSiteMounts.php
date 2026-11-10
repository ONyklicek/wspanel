<?php

namespace App\Actions\Sites;

use App\Models\AgentTask;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;

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
    ) {}

    /** @return list<array{mount: array<string, mixed>, path: string, unit: string, active: string}> */
    public function list(Site $site): array
    {
        return $this->listed[$site->domain] ??= rescue(fn () => $this->agent->call('site.mounts', ['domain' => $site->domain])['mounts'] ?? [], [], report: false);
    }

    /**
     * Connects the disk (in the background); nothing is kept when it does not
     * connect.
     *
     * @param  array<string, mixed>  $mount
     *
     * @throws AgentException
     */
    public function add(Site $site, array $mount, string $password): AgentTask
    {
        return $this->change($site, 'add', $mount, $password, __('Připojení disku :name', ['name' => $mount['name'] ?? '']), __('Disk :name připojen', ['name' => $mount['name'] ?? '']));
    }

    /** @throws AgentException */
    public function remove(Site $site, string $name): AgentTask
    {
        return $this->change($site, 'remove', ['name' => $name], null, __('Odpojení disku :name', ['name' => $name]), __('Disk :name odpojen', ['name' => $name]));
    }

    /** @throws AgentException */
    public function remount(Site $site, string $name): AgentTask
    {
        return $this->change($site, 'remount', ['name' => $name], null, __('Připojení disku :name', ['name' => $name]), __('Disk :name připojen', ['name' => $name]));
    }

    /** What connecting found: processes that do not see the disk yet, the server's key. */
    public function changed(AgentTask $task): void
    {
        $out = (array) $task->output;
        if ($task->status !== AgentTask::DONE) {
            return;
        }
        if (($out['not_visible_to'] ?? []) !== []) {
            $task->update(['done_title' => __('Disk připojen, některé procesy ho ještě nevidí'),
                'body' => __('Běžely už před připojením: :units. Do disku zapíšou až po restartu (do té doby zápis selže, nic se neuloží jinam).', ['units' => implode(', ', $out['not_visible_to'])])]);
        } elseif (($out['host_keys'] ?? []) !== []) {
            $task->update(['body' => __('klíč serveru (ověřte u správce serveru): :keys', ['keys' => implode(', ', $out['host_keys'])])]);
        }
    }

    /**
     * @param  array<string, mixed>  $mount
     */
    private function change(Site $site, string $action, array $mount, ?string $password, string $title, string $done): AgentTask
    {
        $params = ['domain' => $site->domain, 'action' => $action, 'mount' => $mount];
        if ($password !== null) {
            $params['password'] = $password;
        }
        unset($this->listed[$site->domain]);
        $job = $this->agent->call('site.mount', $params);

        return AgentTasks::start($job, 'site.mount', ['title' => $title, 'done' => $done], self::class.'@changed', $site);
    }
}
