<?php

namespace App\Dashboards;

use App\Actions\Monitoring\ServerMetrics;
use App\Livewire\Dashboards\ShowMonitoring;
use App\Services\Agent\AgentException;
use App\Wire\Resources\SiteResource;
use App\Models\Site;
use Illuminate\Support\Number;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Widgets\ChartWidget;
use NyonCode\WireCore\Widgets\CustomWidget;
use NyonCode\WireCore\Widgets\Dashboard;
use NyonCode\WireCore\Widgets\Stat;
use NyonCode\WireCore\Widgets\StatsOverviewWidget;

/** Server load: live values, 30-day history, disks, per-site usage, processes. */
class MonitoringDashboard extends Dashboard implements ProvidesNavigation, ProvidesPages
{
    public static function key(): string
    {
        return 'monitoring';
    }

    public static function label(): string
    {
        return 'Vytížení';
    }

    private function metrics(): ?array
    {
        try {
            return app(ServerMetrics::class)->now();
        } catch (AgentException) {
            return null;
        }
    }

    public function widgets(): array
    {
        $m = $this->metrics();
        $ranges = collect(ServerMetrics::RANGES)->map(fn ($r) => $r[0])->all();

        return [
            StatsOverviewWidget::make()->key('live')->heading('Teď (obnova každých 5 s)')->columnSpan(2)->columns(4)
                ->pollingInterval('5s')->stats($this->liveStats($m)),
            $this->chart('cpu', 'Procesor (%)', ['cpu' => ['CPU', '#f59e0b'], 'iowait' => ['iowait', '#ef4444']], $ranges),
            $this->chart('memory', 'Paměť (GB)', ['mem_used' => ['RAM', '#3b82f6'], 'swap_used' => ['swap', '#a855f7'], 'mem_total' => ['celkem', '#94a3b8']], $ranges, 1 << 30),
            $this->chart('load', 'Zátěž (load 1 min)', ['load1' => ['load', '#10b981']], $ranges),
            $this->chart('network', 'Síť (MB/s)', ['net_rx' => ['příchozí', '#0ea5e9'], 'net_tx' => ['odchozí', '#f97316']], $ranges, 1 << 20),
            $this->chart('disk_io', 'Disk – čtení a zápis (MB/s)', ['io_read' => ['čtení', '#14b8a6'], 'io_write' => ['zápis', '#e11d48']], $ranges, 1 << 20),
            $this->chart('disk_usage', 'Zaplnění disku (%)', ['disk_percent' => ['obsazeno', '#64748b']], $ranges),
            CustomWidget::make()->key('sites')
                ->columnSpan(2)->pollingInterval('15s')
                ->view('livewire.monitoring.table')
                ->viewData(['heading' => 'Vytížení podle webů', 'description' => 'Součet všech procesů webu (PHP-FPM, aplikace, workery, úlohy). CPU v % jednoho jádra; místo na disku se přepočítává po 15 minutách.',
                    'columns' => ['domain' => 'Web', 'cpu_label' => 'CPU', 'rss_label' => 'Paměť', 'processes' => 'Procesů', 'disk_label' => 'Na disku'],
                    'rows' => $this->siteRows($m), 'link' => 'url', 'empty' => 'Žádné weby.']),
            CustomWidget::make()->key('filesystems')
                ->view('livewire.monitoring.table')
                ->viewData(['heading' => 'Disky', 'description' => 'Inody = počet souborů; když dojdou, nejde zapisovat ani při volném místě.', 'columns' => ['mount' => 'Oddíl', 'used_pct' => 'Obsazeno', 'free' => 'Volno', 'total' => 'Celkem', 'inodes' => 'Inody'],
                    'rows' => collect($m['filesystems'] ?? [])->map(fn ($f) => [
                        'mount' => $f['mount'].' ('.$f['type'].')',
                        'used_pct' => $f['total'] > 0 ? round(100 * $f['used'] / $f['total']).' %' : '—',
                        'free' => Number::fileSize($f['free'], 1), 'total' => Number::fileSize($f['total'], 1),
                        'inodes' => $f['inodes_total'] > 0 ? round(100 * (1 - $f['inodes_free'] / $f['inodes_total'])).' %' : '—',
                    ])->all(), 'empty' => 'Žádné oddíly.']),
            CustomWidget::make()->key('processes')->pollingInterval('15s')
                ->view('livewire.monitoring.table')
                ->viewData(['heading' => 'Nejnáročnější procesy', 'description' => 'Podle CPU a paměti, měřeno teď.', 'columns' => ['name' => 'Proces', 'user' => 'Uživatel', 'cpu' => 'CPU', 'rss' => 'Paměť'],
                    'rows' => collect($m['top'] ?? [])->map(fn ($p) => [
                        'name' => $p['name'], 'hint' => mb_strimwidth((string) $p['cmd'], 0, 80, '…'), 'user' => $p['user'],
                        'cpu' => Number::format($p['cpu'], 1).' %', 'rss' => Number::fileSize($p['rss'], 1),
                    ])->all(), 'empty' => 'Žádné procesy.']),
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $series
     * @param  array<string, string>  $ranges
     */
    private function chart(string $key, string $heading, array $series, array $ranges, int $divisor = 1): ChartWidget
    {
        return ChartWidget::make()->key($key)->heading($heading)->type('line')
            ->filter($ranges, '1h')
            ->pollingInterval('60s')
            ->labels(fn (?string $filter) => app(ServerMetrics::class)->series($filter ?? '1h', array_keys($series))->pluck('label')->all())
            ->datasets(function (?string $filter) use ($series, $divisor) {
                $rows = app(ServerMetrics::class)->series($filter ?? '1h', array_keys($series));

                return collect($series)->map(fn ($s, $col) => [
                    'label' => $s[0],
                    'data' => $rows->map(fn ($r) => round((float) $r->{$col} / $divisor, 2))->all(),
                    'borderColor' => $s[1],
                    'backgroundColor' => $s[1].'22',
                    'fill' => true,
                    'pointRadius' => 0,
                    'tension' => 0.25,
                ])->values()->all();
            });
    }

    /** @return list<Stat> */
    private function liveStats(?array $m): array
    {
        if ($m === null) {
            return [Stat::make('Server', 'agent nedostupný')->color('danger')];
        }
        $cpus = max(1, (int) ($m['cpus'] ?? 1));
        $mem = $m['memory'] ?? [];
        $memPct = ($mem['total'] ?? 0) > 0 ? 100 * $mem['used'] / $mem['total'] : 0;
        $swapPct = ($mem['swap_total'] ?? 0) > 0 ? 100 * $mem['swap_used'] / $mem['swap_total'] : 0;
        $fullest = collect($m['filesystems'] ?? [])->filter(fn ($f) => $f['total'] > 0)->sortByDesc(fn ($f) => $f['used'] / $f['total'])->first();
        $disk = $fullest ? 100 * $fullest['used'] / $fullest['total'] : 0;
        $load = $m['load'] ?? [0, 0, 0];
        $up = (int) ($m['uptime_seconds'] ?? 0);
        $level = fn (float $v, float $w, float $d) => $v >= $d ? 'danger' : ($v >= $w ? 'warning' : 'success');
        $rate = fn ($b) => Number::fileSize((int) $b, 1).'/s';

        return [
            Stat::make('CPU', Number::format($m['cpu']['percent'] ?? 0, 1).' %')->icon('outline:cpu-chip')
                ->description("{$cpus} jader · iowait ".Number::format($m['cpu']['iowait'] ?? 0, 1).' %')->color($level((float) ($m['cpu']['percent'] ?? 0), 70, 90)),
            Stat::make('Paměť', Number::format($memPct, 0).' %')->icon('outline:circle-stack')
                ->description(Number::fileSize($mem['used'] ?? 0, 1).' z '.Number::fileSize($mem['total'] ?? 0, 1).' · cache '.Number::fileSize($mem['cache'] ?? 0, 1))->color($level($memPct, 80, 92)),
            Stat::make('Zátěž', Number::format($load[0], 2))->icon('outline:chart-bar')
                ->description('5 min '.Number::format($load[1], 2).' · 15 min '.Number::format($load[2], 2).' · '.($m['processes'] ?? 0).' procesů')->color($level($load[0] / $cpus, 0.7, 1.0)),
            Stat::make('Disk', Number::format($disk, 0).' %')->icon('outline:server')
                ->description($fullest ? $fullest['mount'].' · volno '.Number::fileSize($fullest['free'], 1) : '—')->color($level($disk, 80, 90)),
            Stat::make('Swap', ($mem['swap_total'] ?? 0) > 0 ? Number::format($swapPct, 0).' %' : 'není')->icon('outline:arrows-right-left')
                ->description(($mem['swap_total'] ?? 0) > 0 ? Number::fileSize($mem['swap_used'], 1).' z '.Number::fileSize($mem['swap_total'], 1) : 'server nemá swap')->color($level($swapPct, 30, 70)),
            Stat::make('Síť', '↓ '.$rate($m['net']['rx_bps'] ?? 0))->icon('outline:arrows-up-down')->description('↑ '.$rate($m['net']['tx_bps'] ?? 0))->color('gray'),
            Stat::make('Disk I/O', 'čtení '.$rate($m['io']['read_bps'] ?? 0))->icon('outline:arrow-path')->description('zápis '.$rate($m['io']['write_bps'] ?? 0))->color('gray'),
            Stat::make('Běží', intdiv($up, 86400).' d '.intdiv($up % 86400, 3600).' h')->icon('outline:clock')->description('od posledního restartu serveru')->color('gray'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function siteRows(?array $m): array
    {
        $disk = rescue(fn () => app(ServerMetrics::class)->siteDisk(), [], report: false);
        $ids = Site::query()->pluck('id', 'domain');

        return collect($m['sites'] ?? [])->sortByDesc('cpu')->map(fn ($s) => [
            'id' => $s['domain'], 'domain' => $s['domain'], 'processes' => $s['processes'],
            'cpu_label' => Number::format($s['cpu'], 1).' %',
            'rss_label' => $s['rss'] > 0 ? Number::fileSize($s['rss'], 1) : '—',
            'disk_label' => isset($disk[$s['domain']]) ? Number::fileSize($disk[$s['domain']], 1) : '…',
            'url' => isset($ids[$s['domain']]) ? SiteResource::url('view', $ids[$s['domain']]) : null,
        ])->values()->all();
    }

    public function columns(): int
    {
        return 2;
    }

    public static function pages(): array
    {
        return ['index' => ShowMonitoring::class];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make('Vytížení')->icon('outline:chart-bar')->group('server')->sort(10);
    }
}
