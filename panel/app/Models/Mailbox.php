<?php

namespace App\Models;

use App\Models\Concerns\FollowsParentOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mailbox (IMAP/POP3); its password lives hashed on the server only.
 *
 * @property array{fetch?: array{protocol: string, host: string, port: int, user: string, keep_days: int}|null, relay?: array{host: string, port: int, user: string}|null}|null $external
 */
class Mailbox extends Model
{
    use FollowsParentOwner;

    protected static string $ownerRelation = 'mailDomain';

    protected $fillable = ['mail_domain_id', 'local_part', 'quota_mb', 'enabled', 'autoreply', 'forward', 'external', 'used_kb'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'autoreply' => 'array', 'forward' => 'array', 'external' => 'array', 'quota_mb' => 'integer', 'used_kb' => 'integer'];
    }

    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }

    public function address(): string
    {
        return $this->local_part.'@'.$this->mailDomain->domain;
    }
}
