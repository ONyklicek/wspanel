<?php

namespace App\Filament\Widgets;

use App\Enums\SiteStatus;
use App\Filament\Resources\FirewallRules\FirewallRuleResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Service;
use App\Models\Site;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ServerOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $failed = Service::query()->where('active_state', 'failed')->count();
        $running = Service::query()->where('active_state', 'active')->count();
        $total = Service::query()->count();
        $lastSync = Service::query()->max('synced_at');
        $fw = Cache::get('firewall.status');

        return [
            Stat::make('Weby', Site::query()->where('status', SiteStatus::Active)->count())
                ->description(($n = Site::query()->where('status', SiteStatus::Failed)->count()) ? "{$n} s chybou" : 'všechny v pořádku')
                ->color($n ? 'danger' : 'success')
                ->url(SiteResource::getUrl()),
            Stat::make('Služby', "{$running} / {$total} běží")
                ->description($failed ? "{$failed} selhalo" : ($lastSync ? 'ověřeno '.Carbon::parse($lastSync)->diffForHumans() : 'zatím neověřeno'))
                ->color($failed ? 'danger' : 'success')
                ->url(ServiceResource::getUrl()),
            Stat::make('Firewall', $fw === null ? '?' : ($fw['active'] ? 'aktivní' : 'vypnutý'))
                ->description($fw === null ? 'zatím neověřeno' : 'chráněné porty '.implode(', ', $fw['protected_ports']))
                ->color($fw['active'] ?? false ? 'success' : 'danger')
                ->url(FirewallRuleResource::getUrl()),
        ];
    }
}
