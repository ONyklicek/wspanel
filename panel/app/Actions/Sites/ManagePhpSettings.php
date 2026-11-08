<?php

namespace App\Actions\Sites;

use App\Enums\SiteMode;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * php.ini values per site (FPM pool or FrankenPHP php_ini) and, in worker
 * mode, the number of workers and how many requests a worker serves.
 */
class ManagePhpSettings
{
    /** What can be set, with the label and the default PHP / the panel uses. */
    public const FIELDS = [
        'memory_limit' => ['Paměťový limit (memory_limit)', '256M'],
        'upload_max_filesize' => ['Max. velikost nahraného souboru (upload_max_filesize)', '2M'],
        'post_max_size' => ['Max. velikost požadavku (post_max_size)', '8M'],
        'max_execution_time' => ['Max. doba běhu skriptu, s (max_execution_time)', '30'],
        'max_input_time' => ['Max. doba načítání vstupu, s (max_input_time)', '60'],
        'max_input_vars' => ['Max. počet vstupních proměnných (max_input_vars)', '1000'],
        'session.gc_maxlifetime' => ['Životnost session, s (session.gc_maxlifetime)', '1440'],
        'date.timezone' => ['Časové pásmo (date.timezone)', 'UTC'],
        'display_errors' => ['Zobrazovat chyby (display_errors)', 'off'],
        'error_reporting' => ['Úroveň hlášení chyb (error_reporting)', 'production'],
        'allow_url_fopen' => ['Otevírání URL jako souborů (allow_url_fopen)', 'on'],
        'short_open_tag' => ['Krátké značky <? (short_open_tag)', 'off'],
        'worker_threads' => ['Počet workerů FrankenPHP', 'podle CPU'],
        'max_requests' => ['Recyklovat worker po N požadavcích', '500'],
    ];

    public const WORKER_ONLY = ['worker_threads', 'max_requests'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @param  array<string, mixed>  $settings  empty values = default
     * @return array<string, string> what the agent applied (normalized)
     *
     * @throws AgentException
     */
    public function set(Site $site, array $settings): array
    {
        $settings = collect($settings)
            ->only(array_keys(self::FIELDS))
            ->reject(fn ($v, $k) => $site->mode !== SiteMode::Worker && in_array($k, self::WORKER_ONLY, true))
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn (string $v) => $v !== '')
            ->all();
        $out = $this->agent->call('site.php', ['domain' => $site->domain, 'settings' => (object) $settings]);
        $applied = $out['settings'] ?? [];
        $site->update(['php_settings' => $applied ?: null]);

        return $applied;
    }

    /** "memory_limit 512M · upload 64M …" for the overview. */
    public static function summary(Site $site): string
    {
        $s = $site->php_settings ?? [];
        if ($s === []) {
            return __('výchozí');
        }

        return collect($s)->map(fn ($v, $k) => "{$k} {$v}")->join(' · ');
    }
}
