<?php

namespace App\Models;

use App\Services\GitHub\GitHubClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GitHub account connected with a token. Its owner links their sites to
 * repositories through it; nobody else sees or uses it. The token is stored
 * encrypted and never shown again.
 */
class GithubConnection extends Model
{
    protected $fillable = ['user_id', 'login', 'token', 'last_used_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'last_used_at' => 'datetime'];
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

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function client(): GitHubClient
    {
        return new GitHubClient($this->token);
    }
}
