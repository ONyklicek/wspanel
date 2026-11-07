<?php

namespace App\Actions\Sites;

use App\Enums\SshAccess;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

class ManageSsh
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Sets SSH/SFTP access. Keys are written by the agent into a root-owned
     * file the site user cannot edit; the panel keeps a copy for editing.
     *
     * @throws AgentException
     */
    public function handle(Site $site, SshAccess|string $access, string $keys): Site
    {
        $access = $access instanceof SshAccess ? $access->value : $access;
        $lines = self::parseKeys($keys);
        $this->agent->call('ssh.set', ['domain' => $site->domain, 'access' => $access, 'keys' => $lines]);
        $site->update(['ssh_access' => $access, 'ssh_keys' => $lines === [] ? null : implode("\n", $lines)]);

        return $site;
    }

    /** @return list<string> non-empty, non-comment lines */
    public static function parseKeys(string $keys): array
    {
        return collect(preg_split('/\R/', $keys) ?: [])
            ->map(fn (string $l) => trim($l))
            ->filter(fn (string $l) => $l !== '' && ! str_starts_with($l, '#'))
            ->values()
            ->all();
    }
}
