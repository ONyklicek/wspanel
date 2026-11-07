<?php

namespace App\Services\Dns;

/**
 * Records are handled as RRsets: all values of one name + type together.
 *
 * @phpstan-type RRset array{name: string, type: string, ttl: int, records: list<string>}
 */
interface DnsZoneProvider
{
    /** @return list<array{name: string, type: string, ttl: int, records: list<string>}> */
    public function rrsets(string $domain): array;

    /**
     * Replaces the RRset; an empty list of records deletes it.
     *
     * @param  list<string>  $records
     */
    public function set(string $domain, string $name, string $type, int $ttl, array $records): void;
}
