<?php

namespace App\Actions\Monitoring;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteHealthCheck;
use App\Models\SiteTrafficHour;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\CurrentClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Traffic stats: takes in what the edge logged since the last time (the agent
 * sums it by hour) and adds it to the hours already stored; and the numbers
 * the site's traffic page shows.
 */
class SiteTraffic
{
    /** How long the history is kept (days). */
    public const KEEP_TRAFFIC = 90;

    public const KEEP_CHECKS = 30;

    public function __construct(private readonly AgentClient $agent) {}

    public function collectAll(): int
    {
        $n = 0;
        foreach (CurrentClient::unrestricted(fn () => Site::acrossClients()->where('status', SiteStatus::Active)->get()) as $site) {
            try {
                $this->collect($site);
                $n++;
            } catch (AgentException) {
                // next run
            }
        }
        SiteTrafficHour::query()->where('hour', '<', now()->subDays(self::KEEP_TRAFFIC))->delete();
        SiteHealthCheck::query()->where('checked_at', '<', now()->subDays(self::KEEP_CHECKS))->delete();

        return $n;
    }

    /** @throws AgentException */
    public function collect(Site $site): void
    {
        for ($round = 0; $round < 10; $round++) { // a big backlog comes in parts
            $out = $this->agent->call('site.traffic', ['domain' => $site->domain]);
            foreach ($out['buckets'] ?? [] as $b) {
                $this->add($site, $b);
            }
            if (! ($out['more'] ?? false)) {
                return;
            }
        }
    }

    /** @param  array<string, mixed>  $b */
    private function add(Site $site, array $b): void
    {
        DB::transaction(function () use ($site, $b) {
            $hour = Carbon::createFromTimestampUTC((int) $b['hour']);
            $row = SiteTrafficHour::query()->firstOrNew(['site_id' => $site->id, 'hour' => $hour]);
            foreach (['requests', 'bytes', 's2xx', 's3xx', 's4xx', 's5xx', 'bots', 'total_ms'] as $k) {
                $row->{$k} = (int) ($row->{$k} ?? 0) + (int) ($b[$k] ?? 0);
            }
            $top = $row->top ?? [];
            foreach (['paths', 'ips', 'referers'] as $k) {
                $merged = $top[$k] ?? [];
                foreach ($b[$k] ?? [] as $value => $count) {
                    $merged[$value] = ($merged[$value] ?? 0) + (int) $count;
                }
                arsort($merged);
                $top[$k] = array_slice($merged, 0, 50, true);
            }
            $row->top = $top;
            $row->save();
        });
    }

    /**
     * The traffic page: totals, per-hour (or per-day) series, top lists and
     * uptime over the last $hours.
     *
     * @return array{requests: int, bytes: int, errors: int, bots: int, avg_ms: int|null, uptime: float|null, checks_avg_ms: int|null,
     *     series: array<string, array{requests: int, errors: int}>, paths: array<string, int>, ips: array<string, int>, referers: array<string, int>}
     */
    public function summary(Site $site, int $hours): array
    {
        $since = now()->subHours($hours)->startOfHour();
        $rows = SiteTrafficHour::query()->where('site_id', $site->id)->where('hour', '>=', $since)->orderBy('hour')->get();
        $byDay = $hours > 72;
        $series = [];
        for ($t = $since->copy(); $t <= now(); $byDay ? $t->addDay() : $t->addHour()) {
            $series[$byDay ? $t->format('j. n.') : $t->format('j. n. H:00')] = ['requests' => 0, 'errors' => 0];
        }
        $tops = ['paths' => [], 'ips' => [], 'referers' => []];
        foreach ($rows as $r) {
            $label = $byDay ? $r->hour->copy()->setTimezone(config('app.timezone'))->format('j. n.') : $r->hour->copy()->setTimezone(config('app.timezone'))->format('j. n. H:00');
            $series[$label] ??= ['requests' => 0, 'errors' => 0];
            $series[$label]['requests'] += $r->requests;
            $series[$label]['errors'] += $r->s5xx;
            foreach ($tops as $k => $_) {
                foreach ($r->top[$k] ?? [] as $value => $count) {
                    $tops[$k][$value] = ($tops[$k][$value] ?? 0) + $count;
                }
            }
        }
        foreach ($tops as $k => $list) {
            arsort($list);
            $tops[$k] = array_slice($list, 0, 15, true);
        }
        $requests = (int) $rows->sum('requests');
        $checks = SiteHealthCheck::query()->where('site_id', $site->id)->where('checked_at', '>=', $since);
        $total = (clone $checks)->count();

        return [
            'requests' => $requests,
            'bytes' => (int) $rows->sum('bytes'),
            'errors' => (int) $rows->sum('s5xx'),
            'bots' => (int) $rows->sum('bots'),
            'avg_ms' => $requests > 0 ? (int) round($rows->sum('total_ms') / $requests) : null,
            'uptime' => $total > 0 ? round(100 * (clone $checks)->where('ok', true)->count() / $total, 2) : null,
            'checks_avg_ms' => $total > 0 ? (int) round((float) (clone $checks)->avg('ms')) : null,
            'series' => $series,
        ] + $tops;
    }
}
