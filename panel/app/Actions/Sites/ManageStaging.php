<?php

namespace App\Actions\Sites;

use App\Actions\Databases\ManageDatabases;
use App\Actions\Mail\ManageMail;
use App\Enums\SiteStatus;
use App\Models\Database;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;

/**
 * Staging: a copy of a site on staging.<domain>, and back.
 *
 *  - Copy: the site's current release (as a deploy of a ready artifact),
 *    its shared files without .env, a copy of each database. The copy's mail
 *    is always caught in Mailpit (a test never writes to real customers), it
 *    tells search engines to stay away, and it has its own settings.
 *  - Back to production: a backup of production first, then the files as a
 *    deploy (health check, rollback on failure) and/or the databases.
 */
class ManageStaging
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
        private readonly CreateSite $createSite,
        private readonly ManageDatabases $databases,
        private readonly ManageDeploys $deploys,
    ) {}

    public static function stagingDomain(Site $site): string
    {
        return 'staging.'.$site->domain;
    }

    /** @throws AgentException */
    public function create(Site $production): Site
    {
        if ($production->staging_of !== null) {
            throw new AgentException('conflict', __('Kopie stagingu se nekopíruje.'), 'panel');
        }
        if (Site::query()->where('staging_of', $production->id)->exists()) {
            throw new AgentException('conflict', __('Web už staging má.'), 'panel');
        }
        $staging = $this->createSite->handle(array_filter([
            'domain' => self::stagingDomain($production), 'framework' => $production->framework->value, 'mode' => $production->mode->value,
            'php_version' => $production->php_version, 'node_version' => $production->node_version, 'client_id' => $production->client_id,
            'tls' => $production->tls_mode === 'internal' ? 'internal' : null,
        ], fn ($v) => $v !== null));
        if ($staging->status === SiteStatus::Failed) {
            throw new AgentException('op_failed', __('Staging se nepodařilo založit: ').$staging->last_error, 'panel');
        }
        $staging->update(['staging_of' => $production->id]);

        // Its mail stays in Mailpit, its own settings, no indexing.
        app(ManageMail::class)->setSiteMode($staging, 'capture');
        $this->copyDatabases($production, $staging, create: true);
        $this->copyShared($production, $staging);
        $this->copyRelease($production, $staging);
        $edge = ManageEdge::formData($staging);
        $edge['headers'] = [...($edge['headers'] ?? []), ['name' => 'X-Robots-Tag', 'value' => 'noindex, nofollow']];
        app(ManageEdge::class)->set($staging, $edge);
        if (in_array($staging->framework->value, ['laravel', 'symfony', 'nuxt', 'next'], true)) {
            $this->setEnv($staging, ['APP_URL' => $staging->url()]);
        }

        return $staging->refresh();
    }

    /**
     * Back to production. A backup of production comes first.
     *
     * @throws AgentException
     */
    public function push(Site $staging, bool $files, bool $databases): void
    {
        $production = Site::query()->findOrFail($staging->staging_of);
        if (! app(ManageSiteBackups::class)->backupNow($production)) {
            throw new AgentException('timeout', __('Záloha produkce ještě běží, zkuste to za chvíli.'), 'panel');
        }
        if ($databases) {
            $this->copyDatabases($staging, $production, create: false);
        }
        if ($files) {
            $this->copyRelease($staging, $production);
        }
    }

    /** Copies the release as a deploy of a ready artifact; waits for it. */
    private function copyRelease(Site $from, Site $to): void
    {
        $artifact = $this->await($this->agent->call('site.pack', ['domain' => $from->domain]))['artifact'] ?? null;
        if (! is_string($artifact)) {
            throw new AgentException('op_failed', __('Release se nepodařilo zabalit.'), 'panel');
        }
        $release = $this->deploys->start($to, ['artifact' => $artifact]);
        for ($i = 0; $i < 120; $i++) {
            $d = collect($this->deploys->history($to)['deploys'] ?? [])->firstWhere('id', $release);
            if ($d !== null && $d['status'] !== 'running') {
                if ($d['status'] !== 'success') {
                    throw new AgentException('op_failed', __('Nasazení na ').$to->domain.__(' skončilo: ').$d['status'].($d['error'] ? ' ('.$d['error'].')' : ''), 'panel');
                }

                return;
            }
            usleep(500_000);
        }
    }

    private function copyShared(Site $from, Site $to): void
    {
        $this->await($this->agent->call('site.copy_shared', ['from' => $from->domain, 'to' => $to->domain]));
    }

    /**
     * Each database of $from into its counterpart of $to (by order); create
     * = give $to a database for each (its credentials go to $to's .env).
     */
    private function copyDatabases(Site $from, Site $to, bool $create): void
    {
        $targets = $to->databases()->orderBy('id')->get()->values();
        foreach ($from->databases()->orderBy('id')->get()->values() as $i => $db) {
            /** @var Database $db */
            $target = $targets[$i] ?? null;
            if ($target === null && $create) {
                $target = $this->databases->create($db->engine->value, substr('stg_'.$db->name, 0, 32), $to)['database'];
            }
            if ($target !== null && $target->engine === $db->engine) {
                $this->await($this->agent->call('db.copy', ['engine' => $db->engine->value, 'from' => $db->name, 'to' => $target->name]));
            }
        }
    }

    /** @param  array<string, string>  $values */
    private function setEnv(Site $site, array $values): void
    {
        $env = $this->deploys->envGet($site);
        foreach ($values as $k => $v) {
            $env = preg_match('/^'.$k.'=/m', $env) ? (string) preg_replace('/^'.$k.'=.*$/m', $k.'='.$v, $env) : rtrim($env, "\n")."\n{$k}={$v}\n";
        }
        $this->deploys->envSet($site, $env);
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function await(array $job): array
    {
        if (! isset($job['job_id'])) {
            return $job;
        }

        return $this->jobs->await($job['job_id'], 900) ?? throw new AgentException('timeout', __('Kopírování ještě běží, zkontrolujte web za chvíli.'), 'panel');
    }
}
