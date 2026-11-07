<?php

namespace App\Dashboards;

use App\Actions\Monitoring\ServerMetrics;
use App\Enums\SiteStatus;
use App\Livewire\Dashboards\ShowOverview;
use App\Models\Certificate;
use App\Models\Deployment;
use App\Models\ServerMetric;
use App\Models\Service;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Attention;
use App\Wire\Resources\SiteResource;
use Illuminate\Support\Number;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ConfiguresRoutes;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Widgets\Dashboard;
use NyonCode\WireCore\Widgets\ListItem;
use NyonCode\WireCore\Widgets\ListWidget;
use NyonCode\WireCore\Widgets\Stat;
use NyonCode\WireCore\Widgets\StatsOverviewWidget;

/** The panel's landing page: what needs attention, the server, the sites. */
class OverviewDashboard extends Dashboard implements ConfiguresRoutes, ProvidesNavigation, ProvidesPages
{
    public static function key(): string
    {
        return 'overview';
    }

    public static function label(): string
    {
        return 'Přehled';
    }

    public function widgets(): array
    {
        return [
            ListWidget::make()
                ->key('attention')
                ->heading('Vyžaduje pozornost')
                ->columnSpan(2)
                ->pollingInterval('30s')
                ->items(fn () => array_map(fn (array $i) => ListItem::make($i['title'])
                    ->description($i['detail'] ?: null)
                    ->icon(['danger' => 'outline:exclamation-circle', 'warning' => 'outline:exclamation-triangle', 'info' => 'outline:information-circle'][$i['level']])
                    ->color($i['level'])
                    ->url($i['url']), Attention::items()))
                ->emptyState('Vše v pořádku: služby běží, weby odpovídají, certifikáty platí.'),

            StatsOverviewWidget::make()
                ->key('server')
                ->heading('Server')
                ->columnSpan(2)
                ->columns(4)
                ->pollingInterval('15s')
                ->stats($this->serverStats()),

            StatsOverviewWidget::make()
                ->key('hosting')
                ->heading('Hosting')
                ->columnSpan(2)
                ->columns(4)
                ->stats($this->hostingStats()),

            ListWidget::make()
                ->key('deploys')
                ->heading('Poslední deploye')
                ->items(fn () => Deployment::query()->with('site')->latest('started_at')->limit(6)->get()
                    ->map(fn (Deployment $d) => ListItem::make($d->site?->domain ?? '?')
                        ->description(trim(($d->ref ? $d->ref.' · ' : '').($d->commit ? substr($d->commit, 0, 10) : '').($d->source === 'artifact' ? ' · build z CI' : '')))
                        ->meta($d->started_at?->diffForHumans())
                        ->icon('outline:rocket-launch')
                        ->color($d->statusColor())
                        ->url($d->site ? SiteResource::url('deploy', $d->site) : null))
                    ->all())
                ->emptyState('Zatím žádný deploy.'),

            ListWidget::make()
                ->key('sites')
                ->heading('Weby')
                ->items(fn () => Site::query()->orderBy('domain')->limit(8)->get()
                    ->map(fn (Site $s) => ListItem::make($s->domain)
                        ->description($s->framework->getLabel().' · '.$s->runtimeLabel())
                        ->meta($s->stateLabel())
                        ->icon('outline:globe-alt')
                        ->color($s->stateColor())
                        ->url(SiteResource::url('view', $s)))
                    ->all())
                ->emptyState('Zatím žádný web. Založte ho v sekci Weby.'),
        ];
    }

    /** @return list<Stat> */
    private function serverStats(): array
    {
        try {
            $m = app(ServerMetrics::class)->now();
        } catch (AgentException $e) {
            return [Stat::make('Server', 'agent nedostupný')->description($e->getMessage())->color('danger')];
        }
        $history = ServerMetric::query()->where('recorded_at', '>=', now()->subHour())->orderBy('recorded_at')->get();
        $cpus = max(1, (int) ($m['cpus'] ?? 1));
        $mem = $m['memory'] ?? [];
        $memPct = ($mem['total'] ?? 0) > 0 ? 100 * $mem['used'] / $mem['total'] : 0;
        $fullest = collect($m['filesystems'] ?? [])->filter(fn ($f) => $f['total'] > 0)->sortByDesc(fn ($f) => $f['used'] / $f['total'])->first();
        $disk = $fullest ? 100 * $fullest['used'] / $fullest['total'] : 0;
        $level = fn (float $v, float $w, float $d) => $v >= $d ? 'danger' : ($v >= $w ? 'warning' : 'success');

        return [
            Stat::make('CPU', Number::format($m['cpu']['percent'] ?? 0, 1).' %')
                ->description("{$cpus} jader · zátěž ".Number::format($m['load'][0] ?? 0, 2))
                ->icon('outline:cpu-chip')->color($level((float) ($m['cpu']['percent'] ?? 0), 70, 90))
                ->chart($history->pluck('cpu')->map(fn ($v) => (float) $v)->all()),
            Stat::make('Paměť', Number::format($memPct, 0).' %')
                ->description(Number::fileSize($mem['used'] ?? 0, 1).' z '.Number::fileSize($mem['total'] ?? 0, 1))
                ->icon('outline:circle-stack')->color($level($memPct, 80, 92))
                ->chart($history->map(fn ($r) => $r->mem_total > 0 ? round(100 * $r->mem_used / $r->mem_total, 1) : 0)->all()),
            Stat::make('Disk', Number::format($disk, 0).' %')
                ->description($fullest ? $fullest['mount'].' · volno '.Number::fileSize($fullest['free'], 1) : '—')
                ->icon('outline:server')->color($level($disk, 80, 90)),
            Stat::make('Síť', '↓ '.Number::fileSize((int) ($m['net']['rx_bps'] ?? 0), 1).'/s')
                ->description('↑ '.Number::fileSize((int) ($m['net']['tx_bps'] ?? 0), 1).'/s')
                ->icon('outline:arrows-up-down')->color('gray')
                ->chart($history->pluck('net_rx')->map(fn ($v) => (int) $v)->all()),
        ];
    }

    /** @return list<Stat> */
    private function hostingStats(): array
    {
        $sites = Site::query()->where('status', SiteStatus::Active)->get();
        $failing = $sites->where('enabled', true)->where('health_ok', false)->count();
        $services = Service::query()->get();
        $certs = Certificate::query()->get();
        $badCerts = $certs->whereIn('status', ['expired', 'missing', 'expiring'])->count();

        return [
            Stat::make('Weby', (string) $sites->count())
                ->description($failing ? "{$failing} neodpovídá" : 'všechny odpovídají')
                ->icon('outline:globe-alt')->color($failing ? 'danger' : 'success'),
            Stat::make('Služby', $services->where('active_state', 'active')->count().' / '.$services->count())
                ->description($services->where('active_state', 'failed')->count() ? $services->where('active_state', 'failed')->count().' v chybě' : 'běží')
                ->icon('outline:server-stack')->color($services->where('active_state', 'failed')->count() ? 'danger' : 'success'),
            Stat::make('Certifikáty', (string) $certs->count())
                ->description($badCerts ? "{$badCerts} vyžaduje pozornost" : 'všechny platí')
                ->icon('outline:lock-closed')->color($badCerts ? 'warning' : 'success'),
            Stat::make('Deploye dnes', (string) Deployment::query()->where('started_at', '>=', now()->startOfDay())->count())
                ->description(Deployment::query()->where('started_at', '>=', now()->startOfDay())->whereIn('status', ['failed', 'rolled_back'])->count().' neúspěšných')
                ->icon('outline:rocket-launch')->color('gray'),
        ];
    }

    public function columns(): int
    {
        return 2;
    }

    public static function pages(): array
    {
        return ['index' => ShowOverview::class];
    }

    public static function routePrefix(): ?string
    {
        return self::ROOT;
    }

    public static function routeMiddleware(): array
    {
        return [];
    }

    public static function routeDomain(): ?string
    {
        return null;
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make('Přehled')->icon('outline:home')->sort(1);
    }
}
