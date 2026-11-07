<?php

namespace App\Actions\Dns;

use App\Enums\DnsProvider;
use App\Models\DnsZone;
use App\Models\Site;
use App\Services\Dns\CloudflareDns;
use App\Services\Dns\DnsAdvisor;
use App\Services\Dns\LocalDns;

class ManageDnsZones
{
    public function __construct(private readonly LocalDns $local) {}

    /**
     * Creates the zone at its provider (local) or links an existing one
     * (Cloudflare), optionally filling in the recommended records.
     *
     * @param  array{domain: string, provider: string, nameservers?: list<string>, cloudflare_token?: string|null, recommended?: bool}  $data
     */
    public function create(array $data): DnsZone
    {
        $provider = DnsProvider::from($data['provider']);
        $attributes = [
            'domain' => $data['domain'],
            'provider' => $provider,
            'site_id' => Site::query()->where('domain', $data['domain'])->value('id'),
        ];
        if ($provider === DnsProvider::Local) {
            $this->local->createZone($data['domain'], $data['nameservers'] ?? DnsAdvisor::defaultNameservers());
        } else {
            $attributes['cloudflare_token'] = $data['cloudflare_token'];
            $attributes['cloudflare_zone_id'] = CloudflareDns::zoneId((string) $data['cloudflare_token'], $data['domain']);
        }
        $zone = DnsZone::create($attributes);
        if ($data['recommended'] ?? false) {
            $this->addRecommended($zone);
        }

        return $zone;
    }

    /**
     * Adds the recommended RRsets that the zone does not have yet; existing
     * ones are never overwritten.
     *
     * @param  array{name: string, value: string}|null  $dkim
     * @return list<string> added "name TYPE"
     */
    public function addRecommended(DnsZone $zone, ?array $dkim = null): array
    {
        $api = $zone->api();
        $existing = collect($api->rrsets($zone->domain))->map(fn ($s) => rtrim($s['name'], '.').'|'.$s['type']);
        $added = [];
        foreach (DnsAdvisor::recommended($zone->domain, $dkim) as $r) {
            if ($existing->contains($r['name'].'|'.$r['type'])) {
                continue;
            }
            $api->set($zone->domain, $r['name'], $r['type'], $r['ttl'], $r['records']);
            $added[] = "{$r['name']} {$r['type']}";
        }

        return $added;
    }

    public function delete(DnsZone $zone, bool $removeAtProvider): void
    {
        if ($removeAtProvider && $zone->provider === DnsProvider::Local) {
            $this->local->deleteZone($zone->domain);
        }
        $zone->delete();
    }
}
