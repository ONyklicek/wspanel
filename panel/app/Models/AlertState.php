<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A problem the alert check has seen (panel:alerts), so only changes are announced. */
class AlertState extends Model
{
    protected $fillable = ['key', 'client_id', 'level', 'title', 'detail', 'seen', 'notified_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }
}
