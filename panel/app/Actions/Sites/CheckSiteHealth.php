<?php

namespace App\Actions\Sites;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteHealthCheck;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/** Asks the agent whether each site's backend answers HTTP (bypassing the edge). */
class CheckSiteHealth
{
    public function __construct(private readonly AgentClient $agent) {}

    public function handle(Site $site): void
    {
        try {
            $h = $this->agent->call('site.health', ['domain' => $site->domain]);
            $site->update([
                'health_ok' => (bool) ($h['ok'] ?? false),
                'health_status' => $h['status'] ?? null,
                'health_error' => isset($h['error']) ? mb_substr((string) $h['error'], 0, 250) : null,
                'health_checked_at' => now(),
            ]);
            $this->record($site, (bool) ($h['ok'] ?? false), $h['status'] ?? null, $h['ms'] ?? null);
        } catch (AgentException $e) {
            $site->update(['health_ok' => false, 'health_status' => null, 'health_error' => mb_substr($e->getMessage(), 0, 250), 'health_checked_at' => now()]);
            $this->record($site, false, null, null);
        }
    }

    /** Uptime history: only while the site is meant to be up. */
    private function record(Site $site, bool $ok, mixed $status, mixed $ms): void
    {
        if ($site->enabled) {
            SiteHealthCheck::create(['site_id' => $site->id, 'checked_at' => now(), 'ok' => $ok,
                'status' => is_numeric($status) ? (int) $status : null, 'ms' => is_numeric($ms) ? (int) $ms : null]);
        }
    }

    public function all(): int
    {
        $sites = Site::query()->where('status', SiteStatus::Active)->get();
        $sites->each(fn (Site $s) => $this->handle($s));

        return $sites->count();
    }
}
