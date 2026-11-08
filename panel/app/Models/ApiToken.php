<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A personal API token: acts as its user (a client's token sees only that
 * client's things), read-only unless it may write. Only its hash is kept.
 */
class ApiToken extends Model
{
    public const PREFIX = 'wspa_';

    protected $fillable = ['user_id', 'name', 'token_hash', 'can_write', 'last_used_at', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['can_write' => 'boolean', 'last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('owner', function (Builder $q) {
            if (auth()->check()) {
                $q->where('user_id', auth()->id());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{0: ApiToken, 1: string} the token and its plain value (shown once)
     */
    public static function issue(User $user, string $name, bool $canWrite, ?int $days): array
    {
        $plain = self::PREFIX.Str::random(40);
        $token = self::withoutGlobalScopes()->create(['user_id' => $user->id, 'name' => $name, 'token_hash' => hash('sha256', $plain),
            'can_write' => $canWrite, 'expires_at' => $days ? now()->addDays($days) : null]);

        return [$token, $plain];
    }

    public static function findValid(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }
        $token = self::withoutGlobalScopes()->with('user.client')->where('token_hash', hash('sha256', $plain))->first();

        return $token !== null && ($token->expires_at === null || $token->expires_at->isFuture()) ? $token : null;
    }
}
