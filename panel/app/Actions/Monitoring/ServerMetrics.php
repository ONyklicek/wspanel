<?php

namespace App\Actions\Monitoring;

use App\Models\ServerMetric;
use App\Services\Agent\AgentClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Server load: a live snapshot from the agent and a per-minute history
 * (kept for 30 days) recorded by the scheduler.
 */
class ServerMetrics
{
    public const KEEP_DAYS = 30;

    /** Chart ranges: key => [label, seconds back, bucket seconds]. */
    public const RANGES = [
        '1h' => ['Hodina', 3600, 60],
        '24h' => ['24 hodin', 86400, 600],
        '7d' => ['7 dní', 604800, 3600],
        '30d' => ['30 dní', 2592000, 4 * 3600],
    ];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Live snapshot, shared by all widgets of one page view (cached briefly:
     * every call costs the agent a half-second sample).
     *
     * @return array<string, mixed>
     */
    public function now(): array
    {
        return Cache::remember('server.metrics.now', 4, fn () => $this->agent->call('sys.metrics'));
    }

    /** @return array<string, int> site domain => bytes on disk */
    public function siteDisk(): array
    {
        return Cache::remember('server.metrics.site_disk', 300, fn () => $this->agent->call('sys.site_disk')['sizes'] ?? []);
    }

    /**
     * Records one sample. CPU, network and disk I/O are averaged over the time
     * since the previous sample from the cumulative counters (exact even for
     * short spikes between samples); the first sample uses the agent's
     * half-second rates.
     */
    public function record(): ServerMetric
    {
        $m = $this->agent->call('sys.metrics');
        $c = $m['counters'] ?? [];
        $prev = Cache::get('server.metrics.counters');

        $cpu = $m['cpu']['percent'] ?? 0;
        $iowait = $m['cpu']['iowait'] ?? 0;
        $rx = $m['net']['rx_bps'] ?? 0;
        $tx = $m['net']['tx_bps'] ?? 0;
        $read = $m['io']['read_bps'] ?? 0;
        $write = $m['io']['write_bps'] ?? 0;
        if (is_array($prev) && isset($prev['at'], $c['cpu_total']) && ($c['cpu_total'] ?? 0) > ($prev['cpu_total'] ?? 0)) {
            $dt = max(1, now()->getTimestamp() - $prev['at']);
            $ticks = $c['cpu_total'] - $prev['cpu_total'];
            $cpu = round(100 * (1 - ($c['cpu_idle'] - $prev['cpu_idle']) / $ticks), 1);
            $iowait = round(100 * ($c['cpu_iowait'] - $prev['cpu_iowait']) / $ticks, 1);
            $per = fn (string $k) => max(0, (int) (($c[$k] - ($prev[$k] ?? $c[$k])) / $dt));
            [$rx, $tx, $read, $write] = [$per('net_rx'), $per('net_tx'), $per('io_read'), $per('io_write')];
        }
        Cache::put('server.metrics.counters', $c + ['at' => now()->getTimestamp()], now()->addMinutes(10));

        $disk = collect($m['filesystems'] ?? [])->filter(fn ($f) => $f['total'] > 0)
            ->map(fn ($f) => 100 * $f['used'] / $f['total'])->max() ?? 0;

        $row = ServerMetric::create([
            'recorded_at' => now(),
            'cpu' => max(0, min(100, $cpu)),
            'iowait' => max(0, $iowait),
            'load1' => $m['load'][0] ?? 0,
            'mem_used' => $m['memory']['used'] ?? 0,
            'mem_total' => $m['memory']['total'] ?? 0,
            'swap_used' => $m['memory']['swap_used'] ?? 0,
            'net_rx' => $rx, 'net_tx' => $tx,
            'io_read' => $read, 'io_write' => $write,
            'disk_percent' => round($disk, 1),
        ]);
        ServerMetric::query()->where('recorded_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        return $row;
    }

    /**
     * Averages per bucket for a chart range.
     *
     * @param  list<string>  $columns
     * @return Collection<int, object>
     */
    public function series(string $range, array $columns): Collection
    {
        [, $back, $bucket] = self::RANGES[$range] ?? self::RANGES['24h'];
        $avg = collect($columns)->map(fn ($c) => "avg({$c}) as {$c}")->join(', ');

        return DB::table('server_metrics')
            ->where('recorded_at', '>=', now()->subSeconds($back))
            ->selectRaw("(CAST(strftime('%s', recorded_at) AS INTEGER) / {$bucket}) * {$bucket} as t, {$avg}")
            ->groupBy('t')
            ->orderBy('t')
            ->get()
            ->map(function ($row) use ($range) {
                $row->label = Carbon::createFromTimestamp($row->t, config('app.timezone'))
                    ->format(in_array($range, ['1h', '24h'], true) ? 'H:i' : 'j. n. H:i');

                return $row;
            });
    }
}
