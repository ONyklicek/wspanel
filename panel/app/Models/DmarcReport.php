<?php

namespace App\Models;

use App\Models\Concerns\OwnedByClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A DMARC aggregate report about a hosted mail domain, from one receiving organisation. */
class DmarcReport extends Model
{
    use OwnedByClient;

    /** Reports older than this are deleted (days). */
    public const KEEP_DAYS = 180;

    protected $fillable = ['mail_domain_id', 'org_name', 'org_email', 'report_id', 'begins_at', 'ends_at', 'policy', 'messages', 'failed'];

    protected function casts(): array
    {
        return ['begins_at' => 'datetime', 'ends_at' => 'datetime', 'messages' => 'integer', 'failed' => 'integer'];
    }

    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(DmarcRecord::class);
    }
}
