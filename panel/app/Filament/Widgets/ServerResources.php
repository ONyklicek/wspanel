<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Monitoring;
use App\Filament\Resources\Services\ServiceResource;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\FrankenPhpRelease;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

class ServerResources extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        try {
            $r = Cache::remember('agent.sys.resources', 20, fn () => app(AgentClient::class)->call('sys.resources'));
        } catch (AgentException $e) {
            return [Stat::make('Server', 'agent nedostupný')->description($e->getMessage())->color('danger')];
        }

        $stats = [];
        $cpus = max(1, (int) ($r['cpus'] ?? 1));
        $load = $r['load'][0] ?? 0;
        $stats[] = Stat::make('Zátěž', number_format($load, 2, ',', ' '))
            ->description("{$cpus} CPU · 5 min ".number_format($r['load'][1] ?? 0, 2, ',', ' '))
            ->color($load > $cpus ? 'danger' : ($load > $cpus * 0.7 ? 'warning' : 'success'))
            ->url(Monitoring::getUrl());

        if (! empty($r['memory']['total'])) {
            $used = 1 - $r['memory']['available'] / $r['memory']['total'];
            $stats[] = Stat::make('Paměť', Number::percentage($used * 100))
                ->description('volno '.Number::fileSize($r['memory']['available'], 1).' z '.Number::fileSize($r['memory']['total'], 1))
                ->color($used > 0.9 ? 'danger' : ($used > 0.75 ? 'warning' : 'success'))
                ->url(Monitoring::getUrl());
        }

        $worst = collect($r['disks'] ?? [])->filter(fn ($d) => $d['total'] > 0)->sortByDesc(fn ($d) => 1 - $d['free'] / $d['total'])->first();
        if ($worst) {
            $used = 1 - $worst['free'] / $worst['total'];
            $stats[] = Stat::make('Disk', Number::percentage($used * 100))
                ->description("{$worst['path']} · volno ".Number::fileSize($worst['free'], 1))
                ->color($used > 0.9 ? 'danger' : ($used > 0.8 ? 'warning' : 'success'))
                ->url(Monitoring::getUrl());
        }

        $franken = app(FrankenPhpRelease::class);
        $installed = $franken->installed();
        $stats[] = $franken->updateAvailable()
            ? Stat::make('FrankenPHP', 'v'.$installed)->description('Dostupná v'.$franken->latest().' – binárka se neaktualizuje sama (OpenSSL a PHP jsou v ní). Aktualizujte na stránce Služby.')->color('warning')->url(ServiceResource::getUrl())
            : Stat::make('FrankenPHP', $installed ? 'v'.$installed : '?')->description($franken->latest() ? 'aktuální' : 'nejnovější verzi nelze ověřit')->color('success');

        return $stats;
    }
}
