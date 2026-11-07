<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One minute of server load (see RecordServerMetrics). */
class ServerMetric extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
