<?php

namespace App\Support;

use App\Actions\Databases\ManageDatabaseServers;
use App\Actions\Monitoring\ServerMetrics;
use App\Enums\SiteStatus;
use App\Livewire\Pages\DatabaseServersPage;
use App\Models\Certificate;
use App\Models\Deployment;
use App\Models\Service;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Illuminate\Support\Number;

/**
 * What needs the operator's attention right now, most serious first.
 * Read from the state the panel already mirrors (panel:sync each minute)
 * plus one live snapshot of the server's load.
 */
final class Attention
{
    /**
     * @return list<array{level: 'danger'|'warning'|'info', title: string, detail: string, url: ?string}>
     */
    public static function items(): array
    {
        $items = [];
        $add = function (string $level, string $title, string $detail, ?string $url = null) use (&$items) {
            $items[] = compact('level', 'title', 'detail', 'url');
        };

        // A client sees what concerns its own sites; the server is the administrator's.
        $admin = ! CurrentClient::restricted();
        foreach ($admin ? Service::query()->where('active_state', 'failed')->get() : [] as $s) {
            $add('danger', __('Služba neběží: ').$s->label(), $s->unit, $s->site ? $s->site->url() : null);
        }
        foreach (Site::query()->where('status', SiteStatus::Active)->where('enabled', true)->where('health_ok', false)->get() as $site) {
            $add('danger', __(':domain neodpovídá', ['domain' => $site->domain]), $site->health_error ?: __('Web vrátil HTTP :status.', ['status' => $site->health_status ?? '—']));
        }
        foreach (Site::query()->where('status', SiteStatus::Failed)->get() as $site) {
            $add('danger', "{$site->domain}: chyba", (string) $site->last_error);
        }
        $certificates = Certificate::query()->whereIn('status', ['expired', 'missing', 'expiring']);
        if (! $admin) {
            $certificates->whereIn('domain', Site::query()->pluck('domain'));
        }
        foreach ($certificates->get() as $c) {
            $add($c->status === 'expiring' ? 'warning' : 'danger',
                match ($c->status) {
                    'expired' => __('Certifikát vypršel: :domain', ['domain' => $c->domain]), 'missing' => __('Chybí certifikát: :domain', ['domain' => $c->domain]), default => __('Certifikát brzy vyprší: :domain', ['domain' => $c->domain])
                },
                (string) ($c->last_error ?? ($c->not_after ? __('platí do ').$c->not_after->format('j. n. Y') : '')));
        }
        foreach (Deployment::query()->with('site')->whereIn('status', ['failed', 'rolled_back'])->where('started_at', '>=', now()->subDay())->latest('started_at')->limit(5)->get() as $d) {
            $add('warning', 'Deploy selhal: '.($d->site?->domain ?? '?'), $d->statusLabel().' · '.$d->started_at?->diffForHumans().($d->error ? ' · '.$d->error : ''));
        }
        if ($admin) {
            try {
                $m = app(ServerMetrics::class)->now();
                foreach ($m['filesystems'] ?? [] as $f) {
                    $pct = $f['total'] > 0 ? 100 * $f['used'] / $f['total'] : 0;
                    if ($pct >= 85) {
                        $add($pct >= 95 ? 'danger' : 'warning', __('Disk :mount je zaplněný z ', ['mount' => $f['mount']]).round($pct).' %', 'Volno '.Number::fileSize($f['free'], 1));
                    }
                }
                $mem = $m['memory'] ?? [];
                if (($mem['total'] ?? 0) > 0 && $mem['used'] / $mem['total'] >= 0.9) {
                    $add('warning', __('Dochází paměť'), round(100 * $mem['used'] / $mem['total']).' % RAM je obsazeno');
                }
                if (($m['load'][0] ?? 0) > max(1, (int) ($m['cpus'] ?? 1)) * 1.5) {
                    $add('warning', __('Server je přetížený'), __('Zátěž ').$m['load'][0].__(' při ').$m['cpus'].__(' jádrech'));
                }
            } catch (AgentException $e) {
                $add('danger', __('Agent neodpovídá'), $e->getMessage());
            }
            try {
                foreach (app(ManageDatabaseServers::class)->status() as $engine => $db) {
                    if (($db['remote'] ?? false) && ($db['anywhere'] ?? false)) {
                        $add('warning', DatabaseServersPage::engineLabel($engine).__(' je přístupná z celého internetu'),
                            __('Port :port je otevřený všem. Bezpečnější je povolit jen vybrané adresy.', ['port' => $db['port']]), DatabaseServersPage::url());
                    }
                }
            } catch (AgentException) {
                // reported above when the agent is down
            }
        }
        foreach (Site::query()->where('enabled', false)->get() as $site) {
            $add('info', __(':domain je vypnutý', ['domain' => $site->domain]), __('Návštěvníci vidí stránku 503.'));
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $items;
    }
}
