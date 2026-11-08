<?php

namespace App\Services\Notify;

use App\Actions\Monitoring\ServerMetrics;
use App\Enums\SiteStatus;
use App\Models\Certificate;
use App\Models\Service;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\CurrentClient;
use Illuminate\Support\Number;

/**
 * The problems worth a notification, each with a stable key (to tell a new
 * problem from one already announced) and its owner (null = the server, the
 * administrator's). Read across all clients: who hears about what is decided
 * when it is sent.
 */
class Alerts
{
    /** @return list<array{key: string, client_id: int|null, level: string, title: string, detail: string}> */
    public function current(): array
    {
        return CurrentClient::unrestricted(function (): array {
            $out = [];
            $add = function (string $key, ?int $client, string $level, string $title, string $detail = '') use (&$out) {
                $out[] = ['key' => $key, 'client_id' => $client, 'level' => $level, 'title' => $title, 'detail' => $detail];
            };
            foreach (Service::query()->with('site')->where('active_state', 'failed')->get() as $s) {
                $add('service:'.$s->unit, $s->site?->client_id, 'danger', 'Služba neběží: '.$s->label(), $s->unit);
            }
            foreach (Site::acrossClients()->where('status', SiteStatus::Active)->where('enabled', true)->where('health_ok', false)->get() as $site) {
                $add('site-down:'.$site->domain, $site->client_id, 'danger', "{$site->domain} neodpovídá", trim('HTTP '.($site->health_status ?? '—').' '.($site->health_error ?? '')));
            }
            $owners = Site::acrossClients()->pluck('client_id', 'domain');
            foreach (Certificate::query()->whereIn('status', ['expired', 'missing', 'expiring'])->get() as $c) {
                $add('cert:'.$c->domain.':'.$c->status, $owners[$c->domain] ?? null, $c->status === 'expiring' ? 'warning' : 'danger',
                    match ($c->status) {
                        'expired' => "Certifikát vypršel: {$c->domain}", 'missing' => "Chybí certifikát: {$c->domain}", default => "Certifikát brzy vyprší: {$c->domain}"
                    }, (string) ($c->last_error ?? ($c->not_after ? 'platí do '.$c->not_after->format('j. n. Y') : '')));
            }
            try {
                foreach (app(ServerMetrics::class)->now()['filesystems'] ?? [] as $f) {
                    $pct = $f['total'] > 0 ? 100 * $f['used'] / $f['total'] : 0;
                    if ($pct >= 90) {
                        $add('disk:'.$f['mount'], null, $pct >= 95 ? 'danger' : 'warning', "Disk {$f['mount']} je zaplněný z ".round($pct).' %', 'Volno '.Number::fileSize($f['free'], 1));
                    }
                }
            } catch (AgentException $e) {
                $add('agent', null, 'danger', 'Agent neodpovídá', $e->getMessage());
            }

            return $out;
        });
    }
}
