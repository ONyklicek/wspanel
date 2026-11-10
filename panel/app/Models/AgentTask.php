<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A long agent job running in the background (see App\Support\AgentTasks).
 *
 * @property array<string, mixed>|null $output
 */
class AgentTask extends Model
{
    public const RUNNING = 'running';

    public const SETTLING = 'settling';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::RUNNING, self::SETTLING], true);
    }

    /** @param  Builder<self>  $query */
    public function scopeRunning(Builder $query): void
    {
        $query->whereIn('status', [self::RUNNING, self::SETTLING]);
    }

    /** What the person sees: their own tasks; an administrator every task. */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if ($user === null) {
            $query->whereRaw('1 = 0');
        } elseif (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }
    }
}
