<?php

namespace App\Models;

use App\Models\Concerns\FollowsParentOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An alias / forward of a mail domain ('' local part = catch-all). */
class MailAlias extends Model
{
    use FollowsParentOwner;

    protected static string $ownerRelation = 'mailDomain';

    protected $fillable = ['mail_domain_id', 'local_part', 'destinations'];

    protected function casts(): array
    {
        return ['destinations' => 'array'];
    }

    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }
}
