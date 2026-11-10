<?php

namespace App\Actions\Sites;

use App\Models\AgentTask;
use App\Models\Database;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
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
     * A backup, in the background.
     *
     * @throws AgentException
     */
    public function backupNow(Site $site, bool $withDatabases = true): AgentTask
    {
        $job = $this->agent->call('site.backup', ['domain' => $site->domain, 'databases' => $withDatabases ? $this->databases($site) : []]);

        return AgentTasks::start($job, 'site.backup', [
            'title' => __('Záloha webu :domain', ['domain' => $site->domain]),
            'done' => __('Záloha webu :domain vytvořena', ['domain' => $site->domain]),
        ], subject: $site);
    }

    /**
     * Restores a backup (a backup of the current state is taken first), in
     * the background.
     *
     * @throws AgentException
     */
    public function restore(Site $site, string $file, bool $files, bool $databases): AgentTask
    {
        // Only the site's own databases: the agent restores nothing else from
        // the archive, whatever its manifest says.
        $job = $this->agent->call('site.restore', [
            'domain' => $site->domain, 'backup' => $file, 'files' => $files,
            'databases' => $databases ? $this->databases($site) : [], 'confirm' => $site->domain,
        ]);

        return AgentTasks::start($job, 'site.restore', [
            'title' => __('Obnova webu :domain ze zálohy', ['domain' => $site->domain]),
            'done' => __('Web :domain obnoven ze zálohy', ['domain' => $site->domain]),
        ], self::class.'@restored', $site);
    }

    /** What the restore did, for the message. */
    public function restored(AgentTask $task): void
    {
        if ($task->status !== AgentTask::DONE || ($task->output['lost'] ?? false)) {
            return;
        }
        $out = (array) $task->output;
        $notes = [];
        if (! empty($out['release'])) {
            $notes[] = __('Nový release ').$out['release'].(($out['healthy'] ?? false) ? __(', web odpovídá.') : __(', ale web neodpovídá: ').($out['health_error'] ?? ''));
        }
        if (! empty($out['databases'])) {
            $notes[] = __('Databáze: ').implode(', ', $out['databases']).'.';
        }
        if (! empty($out['skipped_databases'])) {
            $notes[] = __('Vynechány (na serveru už nejsou): ').implode(', ', $out['skipped_databases']).'.';
        }
        if (! empty($out['snapshot'])) {
            $notes[] = __('Stav před obnovou: ').$out['snapshot'].'.';
        }
        $task->update(['body' => implode("\n", $notes)]);
        if (! ($out['healthy'] ?? true)) {
            // Restored, but the site does not answer: told as a failure.
            $task->update(['status' => AgentTask::FAILED, 'error' => implode("\n", $notes), 'title' => __('Záloha obnovena, web ale neodpovídá')]);
        }
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
