<?php

namespace App\Models;

use App\Actions\Dns\ManageSiteDns;
use App\Enums\DnsProvider;
use App\Models\Concerns\OwnedByClient;
use App\Services\Dns\CloudflareDns;
use App\Services\Dns\DnsZoneProvider;
use App\Services\Dns\LocalDns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class DnsZone extends Model
{
    use OwnedByClient;

    protected $fillable = ['domain', 'provider', 'cloudflare_zone_id', 'cloudflare_token', 'site_id'];

    protected $hidden = ['cloudflare_token'];

    protected function casts(): array
    {
        return [
            'provider' => DnsProvider::class,
            'cloudflare_token' => 'encrypted',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** The backend that holds this zone's records. */
    /**
     * The sites whose name lies in this zone (its own domain or below it).
     *
     * @return Collection<int, Site>
     */
    public function sitesBelow(): Collection
    {
        return Site::query()->where(fn ($q) => $q->where('domain', $this->domain)->orWhere('domain', 'like', '%.'.$this->domain))
            ->orderBy('domain')->get()
            // a deeper zone in the panel owns its own names
            ->filter(fn (Site $site) => ManageSiteDns::zoneFor($site->domain)?->is($this) ?? false)
            ->values();
    }

    public function api(): DnsZoneProvider
    {
        return match ($this->provider) {
            DnsProvider::Local => app(LocalDns::class),
            DnsProvider::Cloudflare => new CloudflareDns((string) $this->cloudflare_token, (string) $this->cloudflare_zone_id),
        };
    }
}
