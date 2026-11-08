<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One check of whether the site's backend answered (uptime history). */
class SiteHealthCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_id', 'checked_at', 'ok', 'status', 'ms'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'ok' => 'boolean'];
    }
}
