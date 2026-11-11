<?php

namespace App\Actions\Server;

use App\Services\Agent\AgentClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * How much memory each optional part of the server uses now (its services'
 * cgroups), for Server → Moduly: on a 1 GB server the difference between a
 * module that is used and one that only runs is worth seeing.
 */
class ModuleMemory
{
    /** The services of each part, as names or globs (sys.unit_memory). */
    public const UNITS = [
        'mail' => ['dovecot', 'rspamd', 'rspamd-valkey', 'postsrsd', 'clamav-daemon', 'clamav-freshclam'],
        'dns' => ['pdns'],
        'mariadb' => ['mariadb'],
        'postgresql' => ['postgresql@*'],
        'mongodb' => ['mongod'],
        'dedicated' => ['wspanel-db-*'],
        'games' => ['wspanel-game-*'],
    ];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Bytes per part (kept half a minute); empty when the agent cannot tell.
     *
     * @return array<string, int>
     */
    public function usage(): array
    {
        return Cache::remember('modules.memory', 30, function (): array {
            // Only a figure beside the modules: never what breaks their page
            // (an older agent mid-upgrade does not know the operation yet).
            try {
                $out = $this->agent->call('sys.unit_memory', ['patterns' => array_values(array_unique(array_merge(...array_values(self::UNITS))))]);
            } catch (Throwable) {
                return [];
            }

            return collect(self::UNITS)->map(fn (array $units) => (int) collect($units)->sum(fn (string $u) => (int) ($out[$u]['bytes'] ?? 0)))->all();
        });
    }

    public function of(string $part): ?int
    {
        return $this->usage()[$part] ?? null;
    }
}
