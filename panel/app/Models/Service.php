<?php

namespace App\Models;

use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
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

    public function label(): string
    {
        return match ($this->type) {
            ServiceType::PhpFpm => "PHP-FPM {$this->instance}",
            ServiceType::Worker => ucfirst((string) $this->worker)." · {$this->instance}",
            ServiceType::App => ($this->site?->runtimeLabel() ?? 'Aplikace')." · {$this->instance}",
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
            default => ['service' => $this->type->value],
        };
    }

    public function isRunning(): bool
    {
        return $this->active_state === 'active';
    }

    public function isFailed(): bool
    {
        return $this->active_state === 'failed';
    }
}
