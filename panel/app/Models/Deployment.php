<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = ['site_id', 'release', 'status', 'commit', 'ref', 'source', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function statusLabel(): string
    {
        return ['running' => 'běží', 'success' => 'nasazeno', 'failed' => 'selhal', 'rolled_back' => 'vráceno zpět'][$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return ['running' => 'warning', 'success' => 'success', 'failed' => 'danger', 'rolled_back' => 'danger'][$this->status] ?? 'gray';
    }
}
