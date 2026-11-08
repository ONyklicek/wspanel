<?php

namespace App\Services\Dns;

use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/** Zones on this server's PowerDNS, managed by the agent. */
class LocalDns implements DnsZoneProvider
{
    public function __construct(private readonly AgentClient $agent) {}

    /** @param  list<string>  $nameservers */
    public function createZone(string $domain, array $nameservers): void
    {
        $this->wrap(fn () => $this->agent->call('dns.zone_create', ['domain' => $domain, 'nameservers' => $nameservers]));
    }

    public function deleteZone(string $domain): void
    {
        $this->wrap(fn () => $this->agent->call('dns.zone_delete', ['domain' => $domain, 'confirm' => $domain]));
    }

    public function rrsets(string $domain): array
    {
        return $this->wrap(fn () => $this->agent->call('dns.records', ['domain' => $domain])['rrsets'] ?? []);
    }

    public function set(string $domain, string $name, string $type, int $ttl, array $records): void
    {
        $this->wrap(fn () => $this->agent->call('dns.record_set', [
            'domain' => $domain, 'name' => $name, 'type' => $type, 'ttl' => $ttl, 'records' => array_values($records),
        ]));
    }

    /**
     * The secondary servers (may transfer the zones, get NOTIFY). The panel
     * keeps a copy for the default nameservers of new zones.
     *
     * @param  list<string>  $ips
     * @param  list<string>  $nameservers
     */
    public function setSecondary(array $ips, array $nameservers): void
    {
        $out = $this->wrap(fn () => $this->agent->call('dns.secondary', ['ips' => array_values($ips), 'nameservers' => array_values($nameservers)]));
        Setting::put(self::SECONDARY, ['ips' => $out['ips'] ?? [], 'nameservers' => $out['nameservers'] ?? []]);
    }

    /** @return array{ips: list<string>, nameservers: list<string>} */
    public static function secondary(): array
    {
        $s = (array) Setting::get(self::SECONDARY, []);

        return ['ips' => array_values($s['ips'] ?? []), 'nameservers' => array_values($s['nameservers'] ?? [])];
    }

    /**
     * A zone's DNSSEC: get, enable or disable.
     *
     * @return array{enabled: bool, ds: list<string>}
     */
    public function dnssec(string $domain, string $action = 'get'): array
    {
        $out = $this->wrap(fn () => $this->agent->call('dns.dnssec', ['domain' => $domain, 'action' => $action]));

        return ['enabled' => (bool) ($out['enabled'] ?? false), 'ds' => array_values($out['ds'] ?? [])];
    }

    public const SECONDARY = 'dns.secondary';

    private function wrap(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (AgentException $e) {
            throw new DnsException($e->getMessage(), previous: $e);
        }
    }
}
