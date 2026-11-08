<?php

namespace App\Models;

use App\Enums\NotificationChannelType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where a panel user gets notified: e-mail, Slack, Discord or Telegram. */
class NotificationChannel extends Model
{
    public const EVENTS = ['problems' => 'Nový problém', 'resolved' => 'Problém vyřešen', 'deploys' => 'Deploye'];

    protected $fillable = ['user_id', 'type', 'name', 'config', 'events', 'enabled', 'last_sent_at', 'last_error'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'type' => NotificationChannelType::class,
            'config' => 'encrypted:array',
            'events' => 'array',
            'enabled' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    /** Every user sees and manages only their own channels. */
    protected static function booted(): void
    {
        static::addGlobalScope('owner', function (Builder $q) {
            if (auth()->check()) {
                $q->where('user_id', auth()->id());
            }
        });
        static::creating(function (NotificationChannel $c) {
            $c->user_id ??= auth()->id();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wants(string $event): bool
    {
        return $this->enabled && in_array($event, $this->events ?? [], true);
    }
}
