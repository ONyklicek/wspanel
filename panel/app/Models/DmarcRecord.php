<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sending source in a DMARC report: its address, how many messages it
 * sent as the domain, and whether DKIM and SPF passed (aligned with the domain).
 */
class DmarcRecord extends Model
{
    public $timestamps = false;

    protected $fillable = ['source_ip', 'count', 'disposition', 'dkim', 'spf', 'header_from', 'dkim_domain', 'spf_domain'];

    protected function casts(): array
    {
        return ['count' => 'integer'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DmarcReport::class, 'dmarc_report_id');
    }

    /** DMARC passes when DKIM or SPF passed (aligned). */
    public function passed(): bool
    {
        return $this->dkim === 'pass' || $this->spf === 'pass';
    }
}
