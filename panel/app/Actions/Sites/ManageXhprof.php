<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use Illuminate\Support\Facades\Cache;

/**
 * XHProf + XHGui: a site's requests profiled into its own XHGui (classic
 * PHP-FPM sites), viewed in the panel at /xhgui/<domain>/.
 */
class ManageXhprof
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
    ) {}

    public function installed(): bool
    {
        return (bool) Cache::remember('xhprof.installed', 60, fn () => rescue(fn () => $this->agent->call('xhprof.status')['installed'] ?? false, false, report: false));
    }

    /** @throws AgentException */
    public function install(): bool
    {
        $job = $this->agent->call('xhprof.install');
        Cache::forget('xhprof.installed');

        return isset($job['job_id']) ? $this->jobs->await($job['job_id'], 900) !== null : true;
    }

    /**
     * @param  int|null  $ratio  percent of requests profiled; null = off
     *
     * @throws AgentException
     */
    public function set(Site $site, ?int $ratio): void
    {
        $this->agent->call('site.xhprof', $ratio === null
            ? ['domain' => $site->domain, 'enabled' => false]
            : ['domain' => $site->domain, 'enabled' => true, 'ratio' => $ratio]);
        $site->update(['xhprof' => $ratio === null ? null : ['ratio' => $ratio]]);
    }

    /** The panel link that signs in to the site's XHGui (on the site's domain). */
    public static function viewerUrl(Site $site): string
    {
        return route('sites.xhgui', $site);
    }
}
