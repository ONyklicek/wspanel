<?php

namespace App\Services\Dns;

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

    private function wrap(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (AgentException $e) {
            throw new DnsException($e->getMessage(), previous: $e);
        }
    }
}
