<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One hour of a site's traffic, summed from the edge's access log. */
class SiteTrafficHour extends Model
{
    protected $table = 'site_traffic_hourly';

    public $timestamps = false;

    protected $fillable = ['site_id', 'hour', 'requests', 'bytes', 's2xx', 's3xx', 's4xx', 's5xx', 'bots', 'total_ms', 'top'];

    protected function casts(): array
    {
        return ['hour' => 'datetime', 'top' => 'array'];
    }
}
