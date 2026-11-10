<?php

namespace App\Services\Notify;

use App\Actions\Mail\ManageMail;
use App\Actions\Monitoring\ServerMetrics;
use App\Enums\SiteStatus;
use App\Models\Certificate;
use App\Models\CronJob;
use App\Models\Service;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Dns\ServerDns;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

/**
 * The problems worth a notification, each with a stable key (to tell a new
 * problem from one already announced) and its owner (null = the server, the
 * administrator's). Read across all clients: who hears about what is decided
 * when it is sent.
 */
class Alerts
{
    /** Mail older than this in the queue means it is not getting out (seconds). */
    public const STUCK_MAIL_AGE = 3600;

    /** How many such messages are worth a notification. */
    public const STUCK_MAIL_COUNT = 10;

    /** @return list<array{key: string, client_id: int|null, level: string, title: string, detail: string}> */
    public function current(): array
    {
        return CurrentClient::unrestricted(function (): array {
            $out = [];
            $add = function (string $key, ?int $client, string $level, string $title, string $detail = '') use (&$out) {
                $out[] = ['key' => $key, 'client_id' => $client, 'level' => $level, 'title' => $title, 'detail' => $detail];
            };
            foreach (Service::query()->with('site')->where('active_state', 'failed')->get() as $s) {
                $add('service:'.$s->unit, $s->site?->client_id, 'danger', __('Služba neběží: ').$s->label(), $s->unit);
            }
            foreach (Site::acrossClients()->where('status', SiteStatus::Active)->where('enabled', true)->where('health_ok', false)->get() as $site) {
                $add('site-down:'.$site->domain, $site->client_id, 'danger', __(':domain neodpovídá', ['domain' => $site->domain]), trim('HTTP '.($site->health_status ?? '—').' '.($site->health_error ?? '')));
            }
            foreach (Site::acrossClients()->where('status', SiteStatus::Failed)->get() as $site) {
                $add('site-failed:'.$site->domain, $site->client_id, 'danger', __(':domain: chyba', ['domain' => $site->domain]), (string) $site->last_error);
            }
            // Scheduled tasks (backups among them) whose last run failed.
            foreach (CronJob::query()->with('site')->whereNotNull('last_result')->where('last_result', '!=', 'success')->get() as $job) {
                $add('cron:'.$job->id, $job->site?->client_id, 'warning',
                    __('Naplánovaná úloha selhala: :domain', ['domain' => $job->site?->domain ?? '?']),
                    $job->commandLine().' ('.$job->last_result.($job->last_run ? ', '.$job->last_run : '').')');
            }
            $owners = Site::acrossClients()->pluck('client_id', 'domain');
            foreach (Certificate::query()->whereIn('status', ['expired', 'missing', 'expiring'])->get() as $c) {
                $add('cert:'.$c->domain.':'.$c->status, $owners[$c->domain] ?? null, $c->status === 'expiring' ? 'warning' : 'danger',
                    match ($c->status) {
                        'expired' => __('Certifikát vypršel: :domain', ['domain' => $c->domain]), 'missing' => __('Chybí certifikát: :domain', ['domain' => $c->domain]), default => __('Certifikát brzy vyprší: :domain', ['domain' => $c->domain])
                    }, (string) ($c->last_error ?? ($c->not_after ? __('platí do ').$c->not_after->format('j. n. Y') : '')));
            }
            // The server's own DNS (its name, the mail server, PTR, nameservers).
            foreach (rescue(fn () => app(ServerDns::class)->failing(), [], report: false) as $r) {
                $add('dns:'.$r['key'], null, 'warning', ServerDns::problem($r), ServerDns::fix($r));
            }
            try {
                $m = app(ServerMetrics::class)->now();
                foreach ($m['filesystems'] ?? [] as $f) {
                    $pct = $f['total'] > 0 ? 100 * $f['used'] / $f['total'] : 0;
                    if ($pct >= 90) {
                        $add('disk:'.$f['mount'], null, $pct >= 95 ? 'danger' : 'warning', __('Disk :mount je zaplněný z ', ['mount' => $f['mount']]).round($pct).' %', 'Volno '.Number::fileSize($f['free'], 1));
                    }
                }
                $mem = $m['memory'] ?? [];
                if (($mem['total'] ?? 0) > 0 && $mem['used'] / $mem['total'] >= 0.95) {
                    $add('memory', null, 'warning', __('Dochází paměť'), __(':percent % RAM je obsazeno', ['percent' => round(100 * $mem['used'] / $mem['total'])]));
                }
                $cpus = max(1, (int) ($m['cpus'] ?? 1));
                if (($m['load'][0] ?? 0) > $cpus * 2) {
                    $add('load', null, 'warning', __('Server je přetížený'), __('Zátěž :load při :cpus jádrech', ['load' => $m['load'][0], 'cpus' => $cpus]));
                }
                $stuck = $this->stuckMail();
                if ($stuck >= self::STUCK_MAIL_COUNT) {
                    $add('mail-queue', null, 'warning', __('Pošta nechodí: :count zpráv čeká ve frontě déle než hodinu', ['count' => $stuck]),
                        __('Pošta → Log a fronta. Obvykle odmítnutí příjemcem, chybný relay nebo blokovaný port 25.'));
                }
            } catch (AgentException $e) {
                $add('agent', null, 'danger', __('Agent neodpovídá'), $e->getMessage());
            }

            return $out;
        });
    }

    /** Messages that have been in Postfix's queue for over an hour (asked every few minutes, not every check). */
    public function stuckMail(): int
    {
        return Cache::remember('alerts.stuck_mail', 300, function (): int {
            $before = now()->getTimestamp() - self::STUCK_MAIL_AGE;

            return collect(app(ManageMail::class)->queue())->filter(fn (array $m) => ($m['arrival'] ?? PHP_INT_MAX) < $before)->count();
        });
    }
}
