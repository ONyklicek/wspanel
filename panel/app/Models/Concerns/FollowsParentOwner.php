<?php

namespace App\Models\Concerns;

use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Builder;

/**
 * A record that belongs to whoever owns its parent (a cron job to its site,
 * a backup to its database). For a client, only rows whose parent the client
 * can see are visible: whereHas() applies the parent's own client scope.
 *
 * The using model declares `protected static string $ownerRelation`.
 */
trait FollowsParentOwner
{
    public static function bootFollowsParentOwner(): void
    {
        static::addGlobalScope('client', function (Builder $query): void {
            if (CurrentClient::restricted()) {
                $query->whereHas(static::$ownerRelation);
            }
        });
    }
}
