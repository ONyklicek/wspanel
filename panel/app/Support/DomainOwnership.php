<?php

namespace App\Support;

use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Who a domain name belongs to on this server. A name is related to every
 * name it equals, lies under or lies above (shop.firma.cz ~ firma.cz), across
 * sites (domain, aliases, redirected hosts), DNS zones and mail domains.
 * Nobody may take a name related to another owner's: a subdomain site of
 * another client must not hand the parent's mail to someone else, nor the
 * parent hand the subdomain.
 */
class DomainOwnership
{
    /** @return Collection<int, array{name: string, owner: int|null, kind: string}> */
    private static function names(): Collection
    {
        $names = collect();
        foreach (Site::acrossClients()->get(['domain', 'edge_settings', 'client_id']) as $site) {
            foreach (array_merge([$site->domain], $site->edge_settings['aliases'] ?? [], $site->edge_settings['redirect_hosts'] ?? []) as $host) {
                $names->push(['name' => strtolower((string) $host), 'owner' => $site->client_id, 'kind' => 'site']);
            }
        }
        foreach (DnsZone::acrossClients()->get(['domain', 'client_id']) as $zone) {
            $names->push(['name' => strtolower($zone->domain), 'owner' => $zone->client_id, 'kind' => 'zone']);
        }
        foreach (MailDomain::acrossClients()->get(['domain', 'client_id']) as $mail) {
            $names->push(['name' => strtolower($mail->domain), 'owner' => $mail->client_id, 'kind' => 'mail']);
        }

        return $names;
    }

    private static function related(string $a, string $b): bool
    {
        return $a === $b || str_ends_with($a, '.'.$b) || str_ends_with($b, '.'.$a);
    }

    /** Another owner (than $ownerId; null = the administrator) has a related name. */
    public static function takenByAnother(string $name, ?int $ownerId): bool
    {
        $name = strtolower($name);

        return self::names()->contains(fn ($n) => $n['owner'] !== $ownerId && self::related($n['name'], $name));
    }

    /**
     * The owner answers for the name: a site of theirs has exactly this name,
     * or a DNS zone of theirs covers it. (A subdomain site is no claim to the
     * parent domain.)
     */
    public static function owns(string $name, ?int $ownerId): bool
    {
        $name = strtolower($name);

        return self::names()->contains(fn ($n) => $n['owner'] === $ownerId && (
            ($n['kind'] === 'site' && $n['name'] === $name)
            || ($n['kind'] === 'zone' && ($n['name'] === $name || str_ends_with($name, '.'.$n['name'])))
        ));
    }
}
