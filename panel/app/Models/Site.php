<?php

namespace App\Models;

use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Enums\SiteStatus;
use App\Enums\SshAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    protected $fillable = [
        'domain', 'mode', 'framework', 'php_version', 'node_version', 'port', 'ssh_access', 'ssh_keys',
        'system_user', 'home', 'doc_root', 'status', 'last_error',
        'health_ok', 'health_status', 'health_error', 'health_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => SiteMode::class,
            'framework' => Framework::class,
            'status' => SiteStatus::class,
            'ssh_access' => SshAccess::class,
            'port' => 'integer',
            'health_ok' => 'boolean',
            'health_status' => 'integer',
            'health_checked_at' => 'datetime',
        ];
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
