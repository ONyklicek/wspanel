<?php

namespace App\Actions\Sites;

use App\Actions\Services\SyncServices;
use App\Models\Site;
use App\Services\Agent\AgentClient;

/**
 * Switching a site off and on, restarting it, and reading what runs for it
 * (app process, workers, scheduled tasks) and its logs.
 */
class ControlSite
{
    /** Log sources the agent knows, with labels for the UI. */
    public const LOG_SOURCES = [
        'php' => 'PHP chyby (logs/php-error.log)',
        'framework' => 'Log aplikace (laravel.log / prod.log)',
        'app' => 'Proces aplikace (journal)',
        'worker' => 'Worker (journal)',
        'task' => 'Naplánovaná úloha (journal)',
    ];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly SyncServices $sync,
    ) {}

    public function enable(Site $site): void
    {
        $this->agent->call('site.enable', ['domain' => $site->domain]);
        $site->update(['enabled' => true]);
        rescue(fn () => $this->sync->handle(), report: false);
        rescue(fn () => app(CheckSiteHealth::class)->handle($site), report: false);
    }

    public function disable(Site $site): void
    {
        $this->agent->call('site.disable', ['domain' => $site->domain]);
        $site->update(['enabled' => false]);
        rescue(fn () => $this->sync->handle(), report: false);
    }

    /** @return list<string> what was restarted */
    public function restart(Site $site, bool $workers = false): array
    {
        $params = ['domain' => $site->domain];
        if ($workers) {
            $params['workers'] = true;
        }
        $out = $this->agent->call('site.restart', $params);
        rescue(fn () => $this->sync->handle(), report: false);

        return $out['restarted'] ?? [];
    }

    /**
     * Live state of the site's app process, workers and tasks.
     *
     * @return list<array<string, mixed>>
     */
    public function processes(Site $site): array
    {
        return $this->agent->call('site.processes', ['domain' => $site->domain])['processes'] ?? [];
    }

    /** Starts, stops or restarts one process (the app or a worker). */
    public function control(Site $site, string $kind, string $name, string $action): void
    {
        abort_unless(in_array($action, ['start', 'stop', 'restart', 'reset'], true), 400);
        $params = $kind === 'app'
            ? ['service' => 'app', 'domain' => $site->domain]
            : ['service' => 'worker', 'domain' => $site->domain, 'worker' => $name];
        try {
            $this->agent->call("service.{$action}", $params);
        } finally {
            rescue(fn () => $this->sync->handle(), report: false);
        }
    }

    /** @return array{text: string, missing?: bool, file?: string, unit?: string} */
    public function logs(Site $site, string $source, ?string $name = null, int $lines = 300): array
    {
        $params = ['domain' => $site->domain, 'source' => $source, 'lines' => $lines];
        if ($name !== null && $name !== '') {
            $params['name'] = $name;
        }

        return $this->agent->call('site.logs', $params);
    }
}
