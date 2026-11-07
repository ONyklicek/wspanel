<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resource a client owns (client_id; null = the admin's).
 *
 * A global scope, so every read path (lists, route binding, relations,
 * find() in a controller, update/delete) is narrowed for a signed-in client,
 * and a client's new record is attributed to them.
 */
trait OwnedByClient
{
    public static function bootOwnedByClient(): void
    {
        static::addGlobalScope('client', function (Builder $query): void {
            $client = CurrentClient::id();
            if ($client !== null) {
                $query->where($query->qualifyColumn('client_id'), $client);
            }
        });

        static::creating(function (Model $model): void {
            $client = CurrentClient::id();
            if ($client !== null) {
                $model->setAttribute('client_id', $client);
            }
        });
    }

    public function initializeOwnedByClient(): void
    {
        $this->mergeFillable(['client_id']);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The whole table, past the client scope, e.g. for a uniqueness check
     * that must see other clients' names.
     *
     * @return Builder<static>
     */
    public static function acrossClients(): Builder
    {
        return static::query()->withoutGlobalScope('client');
    }
}
