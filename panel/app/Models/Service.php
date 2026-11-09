<?php

namespace App\Models;

use App\Actions\Databases\ManageDatabaseInstances;
use App\Enums\ServiceType;
use App\Models\Concerns\FollowsParentOwner;
use App\Wire\Resources\ServiceResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    use FollowsParentOwner;

    protected static string $ownerRelation = 'site';

    protected $fillable = [
        'unit', 'type', 'instance', 'worker', 'site_id',
        'load_state', 'active_state', 'sub_state', 'enabled_state', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'synced_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Short names of the site workers (the full ones say the command too). */
    public const WORKER_NAMES = [
        'horizon' => 'Horizon',
        'queue' => 'Fronta',
        'scheduler' => 'Plánovač',
        'messenger' => 'Messenger',
        'reverb' => 'Reverb',
        'pulse' => 'Pulse',
        'pulsework' => 'Pulse ingest',
        'ssr' => 'Inertia SSR',
        'celery' => 'Celery',
        'celerybeat' => 'Celery beat',
    ];

    /** The server's other services, as people know them. */
    public const SYSTEM_LABELS = [
        'panel-scheduler' => 'Plánovač panelu',
        'postfix' => 'Postfix (odesílání a příjem pošty)',
        'postsrsd' => 'PostSRSd (přeposílaná pošta)',
        'opendkim' => 'OpenDKIM (podpis pošty)',
        'mailpit' => 'Mailpit (zachycená pošta)',
        'dovecot' => 'Dovecot (schránky, IMAP/POP3)',
        'rspamd' => 'Rspamd (antispam)',
        'rspamd-valkey' => 'Valkey pro Rspamd',
        'clamav-daemon' => 'ClamAV (antivir)',
        'clamav-freshclam' => 'ClamAV (aktualizace databáze virů)',
        'pdns' => 'PowerDNS (DNS server)',
        'fail2ban' => 'Fail2ban',
    ];

    public static function workerName(string $worker): string
    {
        return __(self::WORKER_NAMES[$worker] ?? ucfirst($worker));
    }

    public function label(): string
    {
        return match ($this->type) {
            ServiceType::PhpFpm => "PHP-FPM {$this->instance}",
            ServiceType::Worker => self::workerName((string) $this->worker)." · {$this->instance}",
            ServiceType::App => ($this->site?->runtimeLabel() ?? __('Aplikace'))." · {$this->instance}",
            ServiceType::System => $this->systemLabel(),
            default => $this->type->getLabel(),
        };
    }

    /**
     * Params identifying this service for the agent's service.* operations.
     *
     * @return array<string, string>
     */
    public function agentParams(): array
    {
        return match ($this->type) {
            ServiceType::PhpFpm => ['service' => 'php-fpm', 'version' => (string) $this->instance],
            ServiceType::Worker => ['service' => 'worker', 'domain' => (string) $this->instance, 'worker' => (string) $this->worker],
            ServiceType::App => ['service' => 'app', 'domain' => (string) $this->instance],
            ServiceType::System => ['service' => 'system', 'unit' => (string) $this->unit],
            default => ['service' => $this->type->value],
        };
    }

    private function systemLabel(): string
    {
        $name = (string) preg_replace('/\.service$/', '', $this->unit);
        if (isset(self::SYSTEM_LABELS[$name])) {
            return __(self::SYSTEM_LABELS[$name]);
        }
        // A site's dedicated database server.
        $engine = str_starts_with($name, 'postgresql@') ? 'PostgreSQL' : 'MariaDB';
        $id = preg_match('/(\d+)$/', $name, $m) ? (int) $m[1] : 0;
        $site = collect(rescue(fn () => app(ManageDatabaseInstances::class)->list(), [], report: false))->firstWhere('id', $id)['domain'] ?? null;

        return __('Vyhrazená :engine', ['engine' => $engine]).($site ? " · {$site}" : '');
    }

    public function isRunning(): bool
    {
        return $this->active_state === 'active';
    }

    /**
     * A worker of a site that has no release yet: registered for its
     * placeholder (@initial) and started by the first deploy. Not a fault.
     */
    public function isWaitingForDeploy(): bool
    {
        return ! $this->isRunning() && str_contains($this->unit, '@initial.');
    }

    /** State as people read it: running, waiting for the first deploy, stopped, failed… */
    public function stateLabel(): string
    {
        return $this->isWaitingForDeploy() ? __('čeká na deploy') : __(ServiceResource::STATES[$this->active_state] ?? $this->active_state);
    }

    public function isFailed(): bool
    {
        return $this->active_state === 'failed';
    }
}
