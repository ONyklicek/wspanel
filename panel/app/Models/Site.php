<?php

namespace App\Models;

use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Enums\SiteStatus;
use App\Enums\SshAccess;
use App\Support\AgentMessages;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->latest('id');
    }

    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    protected $fillable = [
        'domain', 'mode', 'framework', 'php_version', 'node_version', 'port', 'ssh_access', 'ssh_keys', 'ssh_password', 'mail_mode', 'tls_mode',
        'system_user', 'home', 'doc_root', 'status', 'enabled', 'last_error',
        'health_ok', 'health_status', 'health_error', 'health_checked_at',
        'webhook_id', 'webhook_secret', 'webhook_enabled', 'deploy_trigger', 'deploy_requested_at', 'deploy_requested_ref', 'deploy_polled_ref', 'deploy_polled_at', 'deploy_poll_error', 'php_settings', 'ci_id', 'ci_token_hash',
    ];

    protected $hidden = ['webhook_secret', 'ci_token_hash'];

    /** The agent's English text, shown in Czech (stored as it came). */
    protected function lastError(): Attribute
    {
        return Attribute::get(fn (?string $value) => AgentMessages::cs($value));
    }

    /** The agent's English text, shown in Czech (stored as it came). */
    protected function healthError(): Attribute
    {
        return Attribute::get(fn (?string $value) => AgentMessages::cs($value));
    }

    protected function casts(): array
    {
        return [
            'mode' => SiteMode::class,
            'framework' => Framework::class,
            'status' => SiteStatus::class,
            'ssh_access' => SshAccess::class,
            'port' => 'integer',
            'enabled' => 'boolean',
            'ssh_password' => 'boolean',
            'health_ok' => 'boolean',
            'health_status' => 'integer',
            'health_checked_at' => 'datetime',
            'webhook_secret' => 'encrypted',
            'webhook_enabled' => 'boolean',
            'deploy_requested_at' => 'datetime',
            'deploy_polled_at' => 'datetime',
            'php_settings' => 'array',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Public URL of the site. The port follows the panel's own URL, so links
     * also work in the dev container (published on :8443).
     */
    public function url(string $path = '/'): string
    {
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return 'https://'.$this->domain.($port && $port !== 443 ? ":{$port}" : '').$path;
    }

    /** Operator-facing state: switched off beats everything else. */
    public function stateLabel(): string
    {
        return match (true) {
            $this->status !== SiteStatus::Active => $this->status->getLabel(),
            ! $this->enabled => 'Vypnutý',
            $this->health_checked_at !== null && ! $this->health_ok => 'Neodpovídá',
            default => 'Běží',
        };
    }

    /** Badge colour per state label. */
    public const STATE_COLORS = ['Běží' => 'success', 'Vypnutý' => 'gray', 'Zakládá se' => 'warning', 'Neodpovídá' => 'danger', 'Chyba' => 'danger'];

    public function stateColor(): string
    {
        return self::STATE_COLORS[$this->stateLabel()] ?? 'danger';
    }

    public function isOperational(): bool
    {
        return $this->status === SiteStatus::Active;
    }

    /** SFTP/SSH login of the site user on this server. */
    public function sshTarget(): string
    {
        return $this->system_user.'@'.(config('panel.ssh_host') ?: request()->getHost());
    }

    /** Human label of what runs the site, e.g. "PHP 8.3", "PHP 8.5 · FrankenPHP", "Node 22". */
    public function runtimeLabel(): string
    {
        return match ($this->mode) {
            SiteMode::Classic => "PHP {$this->php_version}",
            SiteMode::Worker => "PHP {$this->php_version} · FrankenPHP",
            SiteMode::Node => "Node {$this->node_version}",
        };
    }

    /**
     * Fills runtime fields from an agent site.* response.
     *
     * @param  array<string, mixed>  $out
     * @return array<string, mixed>
     */
    public static function attributesFromAgent(array $out): array
    {
        return [
            'mode' => $out['mode'],
            'framework' => $out['framework'],
            'php_version' => ($out['php_version'] ?? '') ?: null,
            'node_version' => $out['node_version'] ?? null,
            'port' => $out['port'] ?? null,
            'system_user' => $out['system_user'] ?? null,
            'home' => $out['home'] ?? null,
            'doc_root' => $out['doc_root'] ?? null,
        ];
    }
}
