<?php

namespace App\Actions\Services;

use App\Enums\ServiceType;
use App\Models\Service;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use Illuminate\Support\Facades\DB;

/**
 * Pulls the real unit states from the agent and mirrors them into `services`.
 * Rows for units that no longer exist are removed.
 */
class SyncServices
{
    public function __construct(private readonly AgentClient $agent) {}

    public function handle(): int
    {
        $units = $this->agent->call('sys.services');
        $now = now();
        $usersToSites = Site::query()->whereNotNull('system_user')->pluck('id', 'system_user');

        DB::transaction(function () use ($units, $now, $usersToSites) {
            $seen = [];
            foreach ($units as $u) {
                $parsed = self::parseUnit($u['unit']);
                if ($parsed === null) {
                    continue;
                }
                $seen[] = $u['unit'];
                $user = $parsed['_user'] ?? null;
                unset($parsed['_user']);
                Service::updateOrCreate(['unit' => $u['unit']], $parsed + [
                    'site_id' => $user !== null ? ($usersToSites[$user] ?? null) : null,
                    'load_state' => $u['load'] ?? 'unknown',
                    'active_state' => $u['active'] ?? 'unknown',
                    'sub_state' => $u['sub'] ?? null,
                    'enabled_state' => $u['enabled'] ?? null,
                    'synced_at' => $now,
                ]);
            }
            Service::query()->whereNotIn('unit', $seen)->delete();
        });

        return count($units);
    }

    /**
     * Maps a unit name to type/instance/worker. Worker units are
     * wspanel-<site user>-<type>.service; the site user maps back to the domain.
     *
     * @return array<string, string|null>|null
     */
    public static function parseUnit(string $unit): ?array
    {
        $name = preg_replace('/\.service$/', '', $unit);

        return match (true) {
            $name === 'panel-proxy' => ['type' => ServiceType::Proxy->value, 'instance' => null, 'worker' => null],
            $name === 'panel-web' => ['type' => ServiceType::Web->value, 'instance' => null, 'worker' => null],
            $name === 'mariadb' => ['type' => ServiceType::MariaDb->value, 'instance' => null, 'worker' => null],
            $name === 'postgresql' => ['type' => ServiceType::PostgreSql->value, 'instance' => null, 'worker' => null],
            $name === 'valkey-server' => ['type' => ServiceType::Valkey->value, 'instance' => null, 'worker' => null],
            (bool) preg_match('/^php(\d\.\d)-fpm$/', $name, $m) => ['type' => ServiceType::PhpFpm->value, 'instance' => $m[1], 'worker' => null],
            (bool) preg_match('/^wspanel-(web_[a-z0-9_-]+)-([a-z]+)$/', $name, $m) => self::workerFields($m[1], $m[2]),
            default => null,
        };
    }

    /** @return array<string, string|null> */
    private static function workerFields(string $user, string $worker): array
    {
        $domain = Site::query()->where('system_user', $user)->value('domain');

        return ['type' => ServiceType::Worker->value, 'instance' => $domain ?? $user, 'worker' => $worker, '_user' => $user];
    }
}
