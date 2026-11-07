<?php

namespace App\Actions;

use App\Enums\SiteStatus;
use App\Models\Certificate;
use App\Models\CronJob;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;

/** Mirrors certificates (ssl.status) and scheduled tasks (cron.list) from the agent. */
class SyncCertificatesAndTasks
{
    public function __construct(private readonly AgentClient $agent) {}

    public function certificates(): int
    {
        $seen = [];
        foreach ($this->agent->call('ssl.status') as $c) {
            Certificate::updateOrCreate(['domain' => $c['domain']], [
                'issuer' => ($c['issuer'] ?? '') ?: null,
                'not_before' => isset($c['not_before']) ? Carbon::parse($c['not_before']) : null,
                'not_after' => isset($c['not_after']) ? Carbon::parse($c['not_after']) : null,
                'status' => $c['status'],
                'last_error' => $c['last_error'] ?? null,
                'synced_at' => now(),
            ]);
            $seen[] = $c['domain'];
        }
        Certificate::query()->whereNotIn('domain', $seen)->delete();

        return count($seen);
    }

    public function tasks(?Site $only = null): void
    {
        $sites = $only ? collect([$only]) : Site::query()->where('status', SiteStatus::Active)->get();
        foreach ($sites as $site) {
            try {
                $list = $this->agent->call('cron.list', ['domain' => $site->domain]);
            } catch (AgentException) {
                continue;
            }
            $ids = [];
            foreach ($list as $t) {
                CronJob::updateOrCreate(['site_id' => $site->id, 'agent_id' => $t['id']], [
                    'kind' => $t['kind'] ?? 'artisan',
                    'args' => $t['args'] ?? [],
                    'schedule' => $t['schedule'] ?? '',
                    'calendar' => $t['calendar'] ?? null,
                    'last_run' => ($t['last_run'] ?? '') ?: null,
                    'last_result' => ($t['last_result'] ?? '') ?: null,
                    'next_run' => ($t['next_run'] ?? '') ?: null,
                ]);
                $ids[] = $t['id'];
            }
            CronJob::query()->where('site_id', $site->id)->whereNotIn('agent_id', $ids)->delete();
        }
    }
}
