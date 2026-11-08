<?php

namespace App\Models;

use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GitHub account or organisation connected to the panel: an installation
 * of the panel's GitHub App (connected with a button, no token stored), or a
 * token pasted by hand (stored encrypted, never shown again). Its owner
 * links their sites to repositories through it; nobody else sees or uses it.
 */
class GithubConnection extends Model
{
    protected $fillable = ['user_id', 'login', 'installation_id', 'token', 'last_used_at'];

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

    public function isApp(): bool
    {
        return $this->installation_id !== null;
    }

    /** API access: a fresh installation token for the app, else the stored token. */
    public function client(): GitHubClient
    {
        if ($this->isApp()) {
            $app = GitHubApp::configured() ?? throw new GitHubException(__('GitHub aplikace panelu není nastavená.'));

            return new GitHubClient($app->installationToken((int) $this->installation_id), installation: true);
        }

        return new GitHubClient((string) $this->token);
    }
}
