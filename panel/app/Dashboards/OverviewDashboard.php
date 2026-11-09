<?php

namespace App\Dashboards;

use App\Actions\Monitoring\ServerMetrics;
use App\Enums\SiteStatus;
use App\Livewire\Dashboards\ShowOverview;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Deployment;
use App\Models\ServerMetric;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Attention;
use App\Support\Lang;
use App\Support\Limits;
use App\Support\SetupChecklist;
use App\Wire\Resources\SiteResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use NyonCode\WireCore\Actions\Action;
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
        return __('Přehled');
    }

    public function widgets(): array
    {
        $client = Limits::forCurrentUser();

        return [
            ...(SetupChecklist::shown() ? [
                ListWidget::make()
                    ->key('setup')
                    ->heading(__('Dokončení nastavení serveru'))
                    ->description(__('Kroky k serveru, který je bezpečné provozovat. Až budou všechny hotové, tento seznam zmizí.'))
                    ->columnSpan(2)
                    ->headerActions([
                        Action::make('hideSetup')->label(__('Skrýt'))->icon('outline:eye-slash')->color('gray')->size('sm')
                            ->action(function () {
                                abort_unless(Gate::allows('admin'), 403);
                                Setting::put(SetupChecklist::DISMISSED, true);
                            }),
                    ])
                    ->items(fn () => array_map(fn (array $s) => ListItem::make($s['title'])
                        ->description($s['detail'])
                        ->icon($s['done'] ? 'outline:check-circle' : 'outline:arrow-right-circle')
                        ->color($s['done'] ? 'success' : 'warning')
                        ->meta($s['done'] ? __('hotovo') : null)
                        ->url($s['done'] ? null : $s['url']), SetupChecklist::steps())),
            ] : []),

            ListWidget::make()
                ->key('attention')
                ->heading(__('Vyžaduje pozornost'))
                ->columnSpan(2)
                ->pollingInterval('30s')
                ->items(fn () => array_map(fn (array $i) => ListItem::make($i['title'])
                    ->description($i['detail'] ?: null)
                    ->icon(['danger' => 'outline:exclamation-circle', 'warning' => 'outline:exclamation-triangle', 'info' => 'outline:information-circle'][$i['level']])
                    ->color($i['level'])
                    ->url($i['url']), Attention::items()))
                ->emptyState(__('Nic nevyžaduje pozornost: žádná služba ani web v chybě, certifikáty platí.')),

            ...($client === null ? [
                StatsOverviewWidget::make()
                    ->key('server')
                    ->heading(__('Server'))
                    ->columnSpan(2)
                    ->columns(4)
                    ->pollingInterval('15s')
                    ->stats($this->serverStats()),

                StatsOverviewWidget::make()
                    ->key('hosting')
                    ->heading(__('Hosting'))
                    ->columnSpan(2)
                    ->columns(4)
                    ->stats($this->hostingStats()),
            ] : [
                StatsOverviewWidget::make()
                    ->key('account')
                    ->heading(__('Můj účet').($client->package ? __(' · balíček ').$client->package->name : ''))
                    ->columnSpan(2)
                    ->columns(3)
                    ->stats($this->accountStats($client)),
            ]),

            ListWidget::make()
                ->key('deploys')
                ->heading(__('Poslední deploye'))
                ->items(fn () => Deployment::query()->with('site')->latest('started_at')->limit(6)->get()
                    ->map(fn (Deployment $d) => ListItem::make($d->site?->domain ?? '?')
                        ->description(collect([$d->ref, $d->commit ? substr($d->commit, 0, 10) : null, $d->source === 'artifact' ? __('build z CI') : null])->filter()->join(' · '))
                        ->meta($d->started_at?->diffForHumans())
                        ->icon('outline:rocket-launch')
                        ->color($d->statusColor())
                        ->url($d->site ? SiteResource::url('deploy', $d->site) : null))
                    ->all())
                ->emptyState(__('Zatím žádný deploy.')),

            ListWidget::make()
                ->key('sites')
                ->heading(__('Weby'))
                ->items(fn () => Site::query()->orderBy('domain')->limit(8)->get()
                    ->map(fn (Site $s) => ListItem::make($s->domain)
                        ->description($s->framework->getLabel().' · '.$s->runtimeLabel())
                        ->meta($s->stateLabel())
                        ->icon('outline:globe-alt')
                        ->color($s->stateColor())
                        ->url(SiteResource::url('view', $s)))
                    ->all())
                ->emptyState(__('Zatím žádný web. Založte ho v sekci Weby.')),
        ];
    }

    /** @return list<Stat> */
    private function serverStats(): array
    {
        try {
            $m = app(ServerMetrics::class)->now();
        } catch (AgentException $e) {
            return [Stat::make('Server', __('agent nedostupný'))->description($e->getMessage())->color('danger')];
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
                ->description(__(':cpus jader · zátěž ', ['cpus' => $cpus]).Number::format($m['load'][0] ?? 0, 2))
                ->icon('outline:cpu-chip')->color($level((float) ($m['cpu']['percent'] ?? 0), 70, 90))
                ->chart($history->pluck('cpu')->map(fn ($v) => (float) $v)->all()),
            Stat::make(__('Paměť'), Number::format($memPct, 0).' %')
                ->description(Number::fileSize($mem['used'] ?? 0, 1).' z '.Number::fileSize($mem['total'] ?? 0, 1))
                ->icon('outline:circle-stack')->color($level($memPct, 80, 92))
                ->chart($history->map(fn ($r) => $r->mem_total > 0 ? round(100 * $r->mem_used / $r->mem_total, 1) : 0)->all()),
            Stat::make('Disk', Number::format($disk, 0).' %')
                ->description($fullest ? $fullest['mount'].' · volno '.Number::fileSize($fullest['free'], 1) : '—')
                ->icon('outline:server')->color($level($disk, 80, 90)),
            Stat::make(__('Síť'), '↓ '.Number::fileSize((int) ($m['net']['rx_bps'] ?? 0), 1).'/s')
                ->description('↑ '.Number::fileSize((int) ($m['net']['tx_bps'] ?? 0), 1).'/s')
                ->icon('outline:arrows-up-down')->color('gray')
                ->chart($history->pluck('net_rx')->map(fn ($v) => (int) $v)->all()),
        ];
    }

    /** @return list<Stat> */
    /**
     * What the client uses against its package.
     *
     * @return list<Stat>
     */
    private function accountStats(Client $client): array
    {
        $stats = [];
        foreach (app(Limits::class)->usage($client) as $u) {
            $ratio = $u['limit'] ? $u['used'] / $u['limit'] : 0;
            $stats[] = Stat::make($u['label'], number_format($u['used'], 0, ',', ' ').($u['unit'] ? ' '.$u['unit'] : ''))
                ->description($u['limit'] === null ? 'bez limitu' : 'z '.number_format($u['limit'], 0, ',', ' ').($u['unit'] ? ' '.$u['unit'] : ''))
                ->color($u['limit'] === null ? 'gray' : ($ratio >= 1 ? 'danger' : ($ratio >= 0.8 ? 'warning' : 'success')));
        }

        return $stats;
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
            Stat::make(__('Weby'), (string) $sites->count())
                ->description($failing ? __(':failing neodpovídá', ['failing' => $failing]) : __('všechny odpovídají'))
                ->icon('outline:globe-alt')->color($failing ? 'danger' : 'success'),
            // Workers waiting for their site's first deploy are not counted: not a fault, nothing to run yet.
            Stat::make(__('Služby'), ($live = $services->reject(fn (Service $s) => $s->isWaitingForDeploy()))->where('active_state', 'active')->count().' / '.$live->count())
                ->description(match (true) {
                    ($failed = $services->where('active_state', 'failed')->count()) > 0 => $failed.__(' v chybě'),
                    ($stopped = $live->where('active_state', '!=', 'active')->count()) > 0 => Lang::count($stopped, ':count zastavená', ':count zastavené', ':count zastavených'),
                    ($waiting = $services->count() - $live->count()) > 0 => __('běží; :n čeká na první deploy', ['n' => $waiting]),
                    default => __('všechny běží'),
                })
                ->icon('outline:server-stack')->color($services->where('active_state', 'failed')->count() ? 'danger' : ($live->where('active_state', '!=', 'active')->count() ? 'warning' : 'success')),
            Stat::make(__('Certifikáty'), (string) $certs->count())
                ->description($badCerts ? __(':bad_certs vyžaduje pozornost', ['bad_certs' => $badCerts]) : __('všechny platí'))
                ->icon('outline:lock-closed')->color($badCerts ? 'warning' : 'success'),
            Stat::make(__('Deploye dnes'), (string) Deployment::query()->where('started_at', '>=', now()->startOfDay())->count())
                ->description(Lang::count(Deployment::query()->where('started_at', '>=', now()->startOfDay())->whereIn('status', ['failed', 'rolled_back'])->count(), ':count neúspěšný', ':count neúspěšné', ':count neúspěšných'))
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
        return NavigationItem::make(__('Přehled'))->icon('outline:home')->sort(1);
    }
}
