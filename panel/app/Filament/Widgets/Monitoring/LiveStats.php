<?php

namespace App\Filament\Widgets\Monitoring;

use App\Actions\Monitoring\ServerMetrics;
use App\Services\Agent\AgentException;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/** Current load, refreshed every few seconds. */
class LiveStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '5s';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    public static function level(float $value, float $warn, float $danger): string
    {
        return $value >= $danger ? 'danger' : ($value >= $warn ? 'warning' : 'success');
    }

    public static function rate(int|float $bytesPerSecond): string
    {
        return Number::fileSize((int) $bytesPerSecond, 1).'/s';
    }

    protected function getStats(): array
    {
        try {
            $m = app(ServerMetrics::class)->now();
        } catch (AgentException $e) {
            return [Stat::make('Server', 'agent nedostupný')->description($e->getMessage())->color('danger')];
        }
        $cpus = max(1, (int) ($m['cpus'] ?? 1));
        $cpu = (float) ($m['cpu']['percent'] ?? 0);
        $mem = $m['memory'] ?? [];
        $memPct = ($mem['total'] ?? 0) > 0 ? 100 * $mem['used'] / $mem['total'] : 0;
        $swapPct = ($mem['swap_total'] ?? 0) > 0 ? 100 * $mem['swap_used'] / $mem['swap_total'] : 0;
        $load = $m['load'] ?? [0, 0, 0];
        $fullest = collect($m['filesystems'] ?? [])->filter(fn ($f) => $f['total'] > 0)->sortByDesc(fn ($f) => $f['used'] / $f['total'])->first();
        $diskPct = $fullest ? 100 * $fullest['used'] / $fullest['total'] : 0;
        $up = (int) ($m['uptime_seconds'] ?? 0);

        return [
            Stat::make('CPU', Number::format($cpu, 1).' %')
                ->description("{$cpus} jader · iowait ".Number::format($m['cpu']['iowait'] ?? 0, 1).' %'.(($m['cpu']['steal'] ?? 0) > 1 ? ' · steal '.Number::format($m['cpu']['steal'], 1).' %' : ''))
                ->color(self::level($cpu, 70, 90)),
            Stat::make('Paměť', Number::format($memPct, 0).' %')
                ->description(Number::fileSize($mem['used'] ?? 0, 1).' z '.Number::fileSize($mem['total'] ?? 0, 1).' · cache '.Number::fileSize($mem['cache'] ?? 0, 1))
                ->color(self::level($memPct, 80, 92)),
            Stat::make('Zátěž', Number::format($load[0], 2))
                ->description('5 min '.Number::format($load[1], 2).' · 15 min '.Number::format($load[2], 2).' · '.($m['processes'] ?? 0).' procesů')
                ->color(self::level($load[0] / $cpus, 0.7, 1.0)),
            Stat::make('Disk', Number::format($diskPct, 0).' %')
                ->description($fullest ? "{$fullest['mount']} · volno ".Number::fileSize($fullest['free'], 1) : '—')
                ->color(self::level($diskPct, 80, 90)),
            Stat::make('Swap', ($mem['swap_total'] ?? 0) > 0 ? Number::format($swapPct, 0).' %' : 'není')
                ->description(($mem['swap_total'] ?? 0) > 0 ? Number::fileSize($mem['swap_used'], 1).' z '.Number::fileSize($mem['swap_total'], 1) : 'server nemá swap')
                ->color(self::level($swapPct, 30, 70)),
            Stat::make('Síť', '↓ '.self::rate($m['net']['rx_bps'] ?? 0))
                ->description('↑ '.self::rate($m['net']['tx_bps'] ?? 0))
                ->color('gray'),
            Stat::make('Disk I/O', 'čtení '.self::rate($m['io']['read_bps'] ?? 0))
                ->description('zápis '.self::rate($m['io']['write_bps'] ?? 0))
                ->color('gray'),
            Stat::make('Běží', intdiv($up, 86400).' d '.intdiv($up % 86400, 3600).' h')
                ->description('od posledního restartu serveru')
                ->color('gray'),
        ];
    }
}
