<?php

namespace App\Support;

use App\Actions\Monitoring\ServerMetrics;
use App\Enums\SiteStatus;
use App\Models\Certificate;
use App\Models\Deployment;
use App\Models\Service;
use App\Models\Site;
use App\Services\Agent\AgentException;

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

        foreach (Service::query()->where('active_state', 'failed')->get() as $s) {
            $add('danger', 'Služba neběží: '.$s->label(), $s->unit, $s->site ? $s->site->url() : null);
        }
        foreach (Site::query()->where('status', SiteStatus::Active)->where('enabled', true)->where('health_ok', false)->get() as $site) {
            $add('danger', "{$site->domain} neodpovídá", trim('HTTP '.($site->health_status ?? '—').' '.($site->health_error ?? '')));
        }
        foreach (Site::query()->where('status', SiteStatus::Failed)->get() as $site) {
            $add('danger', "{$site->domain}: chyba", (string) $site->last_error);
        }
        foreach (Certificate::query()->whereIn('status', ['expired', 'missing', 'expiring'])->get() as $c) {
            $add($c->status === 'expiring' ? 'warning' : 'danger',
                match ($c->status) { 'expired' => "Certifikát vypršel: {$c->domain}", 'missing' => "Chybí certifikát: {$c->domain}", default => "Certifikát brzy vyprší: {$c->domain}" },
                (string) ($c->last_error ?? ($c->not_after ? 'platí do '.$c->not_after->format('j. n. Y') : '')));
        }
        foreach (Deployment::query()->with('site')->whereIn('status', ['failed', 'rolled_back'])->where('started_at', '>=', now()->subDay())->latest('started_at')->limit(5)->get() as $d) {
            $add('warning', 'Deploy selhal: '.($d->site?->domain ?? '?'), $d->statusLabel().' · '.$d->started_at?->diffForHumans().($d->error ? ' · '.$d->error : ''));
        }
        try {
            $m = app(ServerMetrics::class)->now();
            foreach ($m['filesystems'] ?? [] as $f) {
                $pct = $f['total'] > 0 ? 100 * $f['used'] / $f['total'] : 0;
                if ($pct >= 85) {
                    $add($pct >= 95 ? 'danger' : 'warning', "Disk {$f['mount']} je zaplněný z ".round($pct).' %', 'Volno '.\Illuminate\Support\Number::fileSize($f['free'], 1));
                }
            }
            $mem = $m['memory'] ?? [];
            if (($mem['total'] ?? 0) > 0 && $mem['used'] / $mem['total'] >= 0.9) {
                $add('warning', 'Dochází paměť', round(100 * $mem['used'] / $mem['total']).' % RAM je obsazeno');
            }
            if (($m['load'][0] ?? 0) > max(1, (int) ($m['cpus'] ?? 1)) * 1.5) {
                $add('warning', 'Server je přetížený', 'Zátěž '.$m['load'][0].' při '.$m['cpus'].' jádrech');
            }
        } catch (AgentException $e) {
            $add('danger', 'Agent neodpovídá', $e->getMessage());
        }
        foreach (Site::query()->where('enabled', false)->get() as $site) {
            $add('info', "{$site->domain} je vypnutý", 'Návštěvníci vidí stránku 503.');
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $items;
    }
}
