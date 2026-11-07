<?php

namespace App\Actions\Databases;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * The database servers as a whole: the superuser's password (root / postgres)
 * and access from the internet. The panel keeps no password; the agent keeps
 * nothing but MariaDB's password hash.
 */
class ManageDatabaseServers
{
    public const ACCESS_LOCAL = 'local';

    public const ACCESS_ALLOWLIST = 'allowlist';

    public const ACCESS_ANYWHERE = 'anywhere';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array<string, array{port: int, remote: bool, anywhere: bool, allow: list<string>, root_user: string, root_password: bool, root_set_at?: string, version?: string, tls_sha256?: string}>
     *
     * @throws AgentException
     */
    public function status(): array
    {
        return $this->agent->call('db.server_status')['engines'] ?? [];
    }

    /**
     * Sets the superuser's password. Returns the generated one (shown once), or
     * null when the admin chose their own.
     *
     * @throws AgentException
     */
    public function setRootPassword(string $engine, ?string $password = null): ?string
    {
        $out = $this->agent->call('db.root_password', array_filter(['engine' => $engine, 'password' => $password]));

        return $out['password'] ?? null;
    }

    /** @throws AgentException */
    public function removeRootPassword(string $engine): void
    {
        $this->agent->call('db.root_password', ['engine' => $engine, 'remove' => true]);
    }

    /**
     * @param  list<string>  $allow
     * @return array<string, mixed> the agent's answer (may carry a fail2ban warning)
     *
     * @throws AgentException
     */
    public function setAccess(string $engine, string $access, array $allow = []): array
    {
        return $this->agent->call('db.remote', [
            'engine' => $engine,
            'enabled' => $access !== self::ACCESS_LOCAL,
            'anywhere' => $access === self::ACCESS_ANYWHERE,
            'allow' => $access === self::ACCESS_ALLOWLIST ? array_values($allow) : [],
        ]);
    }

    /** @param  array<string, mixed>  $engine  one entry of status() */
    public static function access(array $engine): string
    {
        return match (true) {
            ! ($engine['remote'] ?? false) => self::ACCESS_LOCAL,
            (bool) ($engine['anywhere'] ?? false) => self::ACCESS_ANYWHERE,
            default => self::ACCESS_ALLOWLIST,
        };
    }

    /** @return list<string> one address or network per line; blanks and comments dropped */
    public static function parseAllow(?string $text): array
    {
        return collect(preg_split('/[\s,;]+/', (string) $text) ?: [])
            ->map(fn (string $v) => trim($v))
            ->filter(fn (string $v) => $v !== '' && ! str_starts_with($v, '#'))
            ->unique()
            ->values()
            ->all();
    }

    public static function validAddress(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 1 && (int) $bits <= $max;
    }
}
