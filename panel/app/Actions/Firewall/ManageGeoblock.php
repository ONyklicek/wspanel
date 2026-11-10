<?php

namespace App\Actions\Firewall;

use App\Actions\ManagePanelAccess;
use App\Models\AgentTask;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;

/**
 * Blocking whole countries (agent: nftables, ipdeny.com ranges). The
 * administrator's current address and the panel's allowlist are never
 * blocked, so the setting cannot lock out the one who makes it.
 */
class ManageGeoblock
{
    public const SETTING = 'geoblock';

    public function __construct(private readonly AgentClient $agent) {}

    /** @return array{countries: list<string>, scope: string, updated: ?string} */
    public function current(): array
    {
        $s = (array) Setting::get(self::SETTING, []);

        return ['countries' => array_values($s['countries'] ?? []), 'scope' => (string) ($s['scope'] ?? 'all'), 'updated' => $s['updated'] ?? null];
    }

    /**
     * @param  list<string>  $countries
     *
     * @throws AgentException
     */
    public function set(array $countries, string $scope, ?string $currentIp): void
    {
        $allow = app(ManagePanelAccess::class)->current()['allow'] ?? [];
        if ($currentIp !== null && filter_var($currentIp, FILTER_VALIDATE_IP)) {
            $allow[] = $currentIp;
        }
        $this->apply(['action' => 'set', 'countries' => array_values($countries), 'scope' => $scope, 'allow' => array_values(array_unique($allow))]);
    }

    /** Downloads the ranges again (weekly, from the scheduler, which waits for it). */
    public function refresh(): void
    {
        if ($this->current()['countries'] !== []) {
            AgentTasks::wait($this->apply(['action' => 'refresh']), 300);
        }
    }

    /** @param  array<string, mixed>  $params */
    private function apply(array $params): AgentTask
    {
        $job = $this->agent->call('fw.geoblock', $params);

        return AgentTasks::start($job, 'fw.geoblock', [
            'title' => __('Blokování zemí (stažení rozsahů adres)'),
            'done' => ($params['countries'] ?? null) === [] ? __('Blokování zemí vypnuto') : __('Blokování zemí uloženo'),
        ], self::class.'@applied', key: 'geoblock');
    }

    /** The agent's answer is what the firewall now does. */
    public function applied(AgentTask $task): void
    {
        $out = $task->output;
        if ($task->status === AgentTask::DONE && is_array($out) && array_key_exists('countries', $out)) {
            Setting::put(self::SETTING, ['countries' => $out['countries'] ?? [], 'scope' => $out['scope'] ?? 'all', 'updated' => $out['updated'] ?? null]);
        }
    }
}
