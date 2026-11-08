<?php

namespace App\Models;

use App\Models\Concerns\OwnedByClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A domain the server receives mail for (mail hosting). */
class MailDomain extends Model
{
    use OwnedByClient;

    protected $fillable = ['domain', 'client_id', 'webmail', 'autoconfig'];

    /** Host names the edge serves for the domain's mail (webmail, client settings). */
    public const SERVICE_PREFIXES = ['webmail' => ['webmail'], 'autoconfig' => ['autoconfig', 'autodiscover']];

    protected function casts(): array
    {
        return ['webmail' => 'boolean', 'autoconfig' => 'boolean'];
    }

    public function webmailUrl(): ?string
    {
        return $this->webmail ? 'https://webmail.'.$this->domain : null;
    }

    /** @return list<string> host names taken by the features switched on */
    public function serviceHosts(): array
    {
        $hosts = [];
        foreach (self::SERVICE_PREFIXES as $feature => $prefixes) {
            if ($this->{$feature} ?? true) {
                foreach ($prefixes as $p) {
                    $hosts[] = $p.'.'.$this->domain;
                }
            }
        }

        return $hosts;
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class)->orderBy('local_part');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MailAlias::class)->orderBy('local_part');
    }
}
