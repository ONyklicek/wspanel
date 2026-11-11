<?php

namespace App\Actions\Sites;

use App\Models\AgentTask;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * XHProf + XHGui: a site's requests profiled into its own XHGui (classic
 * PHP-FPM sites), viewed in the panel at /xhgui/<domain>/.
 */
class ManageXhprof
{
    public function __construct(
        private readonly AgentClient $agent,
    ) {}

    public function installed(): bool
    {
        return (bool) Cache::remember('xhprof.installed', 60, fn () => rescue(fn () => $this->agent->call('xhprof.status')['installed'] ?? false, false, report: false));
    }

    /** @throws AgentException */
    public function install(): AgentTask
    {
        $job = $this->agent->call('xhprof.install');
        Cache::forget('xhprof.installed');

        return AgentTasks::start($job, 'xhprof.install', [
            'title' => __('Instalace XHProf + XHGui'),
            'done' => __('XHProf nainstalován'),
            'body' => __('Zapnete ho u webu v menu Nastavení → Výkon a ladění → XHProf.'),
        ], self::class.'@installedNow', key: 'xhprof');
    }

    /**
     * Takes XHProf and XHGui away. Refused while a site has profiling on.
     *
     * @throws AgentException
     */
    public function uninstall(): AgentTask
    {
        $sites = Site::acrossClients()->whereNotNull('xhprof')->orderBy('domain')->pluck('domain');
        if ($sites->isNotEmpty()) {
            throw new AgentException('conflict', __('Profilování je zapnuté u webů :sites: nejdřív ho vypněte (Nastavení webu → Výkon a ladění).', ['sites' => $sites->implode(', ')]), 'panel');
        }
        $job = $this->agent->call('xhprof.uninstall');
        Cache::forget('xhprof.installed');

        return AgentTasks::start($job, 'xhprof.uninstall', [
            'title' => __('Odinstalace XHProf + XHGui'),
            'done' => __('XHProf odinstalován'),
        ], self::class.'@installedNow', key: 'xhprof');
    }

    public function installedNow(AgentTask $task): void
    {
        Cache::forget('xhprof.installed');
    }

    /** How long profiling runs when nobody says otherwise (hours), and at most. */
    public const DEFAULT_HOURS = 24;

    public const MAX_HOURS = 168;

    /**
     * Profiling slows every measured request several times over (a Livewire
     * page with tens of thousands of calls: 3-7x on a real site) and keeps
     * writing its profiles: it runs for a while, then switches itself off.
     *
     * @param  int|null  $ratio  percent of requests profiled; null = off
     * @param  int  $hours  how long it runs
     *
     * @throws AgentException
     */
    public function set(Site $site, ?int $ratio, int $hours = self::DEFAULT_HOURS): void
    {
        $this->agent->call('site.xhprof', $ratio === null
            ? ['domain' => $site->domain, 'enabled' => false]
            : ['domain' => $site->domain, 'enabled' => true, 'ratio' => $ratio]);
        $until = now()->addHours(min(max($hours, 1), self::MAX_HOURS))->toIso8601String();
        $site->update(['xhprof' => $ratio === null ? null : ['ratio' => $ratio, 'until' => $until]]);
    }

    /**
     * Switches off the profiling whose time is up (the scheduler, every few
     * minutes). Profiling switched on before it had an end gets a day from now.
     */
    public function expire(): int
    {
        $done = 0;
        foreach (Site::acrossClients()->whereNotNull('xhprof')->get() as $site) {
            $until = $site->xhprof['until'] ?? null;
            if ($until === null) {
                $site->update(['xhprof' => $site->xhprof + ['until' => now()->addHours(self::DEFAULT_HOURS)->toIso8601String()]]);

                continue;
            }
            if (now()->greaterThanOrEqualTo($until)) {
                rescue(function () use ($site, &$done) {
                    $this->set($site, null);
                    $done++;
                });
            }
        }

        return $done;
    }

    /** "měří 10 % požadavků do 12. 11. 21:30" */
    public static function describe(Site $site): string
    {
        $text = __('měří :ratio % požadavků', ['ratio' => $site->xhprof['ratio'] ?? 100]);
        if (isset($site->xhprof['until'])) {
            $text .= ' '.__('do :time', ['time' => Carbon::parse($site->xhprof['until'])->local()->format('j. n. H:i')]);
        }

        return $text;
    }

    /** The panel link that signs in to the site's XHGui (on the site's domain). */
    public static function viewerUrl(Site $site): string
    {
        return route('sites.xhgui', $site);
    }
}
