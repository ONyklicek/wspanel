<?php

namespace App\Actions\Sites;

use App\Models\Database;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use Illuminate\Support\Carbon;

/**
 * Whole-site backups: the live release, the shared folder (.env, uploads) and
 * the site's databases in one archive. The agent keeps the list; the panel
 * only shows it.
 */
class ManageSiteBackups
{
    public const FREQUENCIES = ['off' => 'Vypnuto', 'daily' => 'Denně', 'weekly' => 'Týdně'];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
    ) {}

    /**
     * @return array{backups: list<array{file: string, size: int, created: Carbon, pre_restore: bool}>, schedule: array{frequency: string, keep: int, databases: bool}}
     *
     * @throws AgentException
     */
    public function list(Site $site): array
    {
        $out = $this->agent->call('site.backups', ['domain' => $site->domain]);
        $backups = array_map(fn (array $b) => [
            'file' => (string) $b['file'],
            'size' => (int) $b['size'],
            'created' => Carbon::parse($b['created'])->timezone(config('app.timezone')),
            'pre_restore' => str_contains((string) $b['file'], '-pre-restore'),
        ], $out['backups'] ?? []);
        $schedule = $out['schedule'] ?? [];

        return ['backups' => $backups, 'schedule' => [
            'frequency' => (string) ($schedule['frequency'] ?? 'off'),
            'keep' => (int) ($schedule['keep'] ?? 7),
            'databases' => ! empty($schedule['databases']),
        ]];
    }

    /** @return list<array{engine: string, name: string}> */
    private function databases(Site $site): array
    {
        return $site->databases()->orderBy('name')->get()
            ->map(fn (Database $db) => ['engine' => $db->engine->value, 'name' => $db->name])->values()->all();
    }

    /**
     * Starts a backup and waits a while for it.
     *
     * @return bool true when finished, false when it goes on in the background
     *
     * @throws AgentException
     */
    public function backupNow(Site $site, bool $withDatabases = true): bool
    {
        $job = $this->agent->call('site.backup', ['domain' => $site->domain, 'databases' => $withDatabases ? $this->databases($site) : []]);

        return $this->jobs->await($job['job_id'], 60) !== null;
    }

    /**
     * Restores a backup (a backup of the current state is taken first).
     *
     * @return array<string, mixed>|null the result, or null while it still runs
     *
     * @throws AgentException
     */
    public function restore(Site $site, string $file, bool $files, bool $databases): ?array
    {
        // Only the site's own databases: the agent restores nothing else from
        // the archive, whatever its manifest says.
        $job = $this->agent->call('site.restore', [
            'domain' => $site->domain, 'backup' => $file, 'files' => $files,
            'databases' => $databases ? $this->databases($site) : [], 'confirm' => $site->domain,
        ]);

        return $this->jobs->await($job['job_id'], 120);
    }

    /** @throws AgentException */
    public function delete(Site $site, string $file): void
    {
        $this->agent->call('site.backup_delete', ['domain' => $site->domain, 'backup' => $file]);
    }

    /** @throws AgentException */
    public function schedule(Site $site, string $frequency, int $keep, bool $withDatabases): void
    {
        $this->agent->call('site.backup_schedule', [
            'domain' => $site->domain, 'frequency' => $frequency, 'keep' => $keep,
            'databases' => $withDatabases ? $this->databases($site) : [],
        ]);
    }

    /** Absolute path of a backup of the site; null for anything else. */
    public static function path(Site $site, string $file): ?string
    {
        $prefix = $site->domain.'-';
        if (! str_starts_with($file, $prefix)
            || ! preg_match('/^\d{8}T\d{6}Z(-pre-restore)?\.tar\.gz$/', substr($file, strlen($prefix)))) {
            return null;
        }

        return rtrim((string) config('agent.backup_dir'), '/').'/sites/'.$site->domain.'/'.$file;
    }
}
