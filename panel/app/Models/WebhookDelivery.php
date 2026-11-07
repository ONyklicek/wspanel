<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['site_id', 'provider', 'event', 'ref', 'commit', 'result', 'message', 'ip'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function resultColor(): string
    {
        return ['deployed' => 'success', 'queued' => 'info', 'ignored' => 'gray', 'rejected' => 'danger', 'failed' => 'danger'][$this->result] ?? 'gray';
    }
}
