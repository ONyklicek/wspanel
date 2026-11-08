<?php

namespace App\Models;

use App\Models\Concerns\OwnedByClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A domain the server receives mail for (mail hosting). */
class MailDomain extends Model
{
    use OwnedByClient;

    protected $fillable = ['domain', 'client_id'];

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class)->orderBy('local_part');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MailAlias::class)->orderBy('local_part');
    }
}
