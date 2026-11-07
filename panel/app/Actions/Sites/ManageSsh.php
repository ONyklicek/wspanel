<?php

namespace App\Actions\Sites;

use App\Enums\SshAccess;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\Limits;
use Illuminate\Support\Str;

class ManageSsh
{
    /** keep | generate | set | remove */
    public const PASSWORD_MODES = ['keep', 'generate', 'set', 'remove'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Sets SSH/SFTP access. Keys are written by the agent into a root-owned
     * file the site user cannot edit; the panel keeps a copy for editing.
     * Passwords are never stored by the panel: a generated one is returned
     * once so it can be shown to the operator.
     *
     * @return string|null the new password when one was generated
     *
     * @throws AgentException
     */
    public function handle(Site $site, SshAccess|string $access, string $keys, string $passwordMode = 'keep', ?string $password = null): ?string
    {
        app(Limits::class)->assertSsh($site->client, $access instanceof SshAccess ? $access : SshAccess::from($access));
        $access = $access instanceof SshAccess ? $access->value : $access;
        $lines = self::parseKeys($keys);
        $params = ['domain' => $site->domain, 'access' => $access, 'keys' => $lines];

        $generated = null;
        if ($passwordMode === 'generate') {
            $generated = $password = Str::password(20, symbols: false);
            $passwordMode = 'set';
        }
        if ($passwordMode === 'set' || $passwordMode === 'remove') {
            $params['password_mode'] = $passwordMode;
        }
        if ($passwordMode === 'set') {
            $params['password'] = (string) $password;
        }

        $out = $this->agent->call('ssh.set', $params);
        $site->update([
            'ssh_access' => $access,
            'ssh_keys' => $lines === [] ? null : implode("\n", $lines),
            'ssh_password' => (bool) ($out['password'] ?? false),
        ]);

        return $generated;
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
