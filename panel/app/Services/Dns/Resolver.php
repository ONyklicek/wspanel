<?php

namespace App\Services\Dns;

/** Public DNS lookups (a seam, so checks are testable without the network). */
class Resolver
{
    /** @return list<string> the given field of each record */
    public function records(string $name, int $type, string $field): array
    {
        $records = @dns_get_record($name, $type) ?: [];

        return array_values(array_filter(array_map(fn ($r) => isset($r[$field]) ? (string) $r[$field] : null, $records)));
    }

    /** The reverse (PTR) name of an IP address, null when there is none. */
    public function reverse(string $ip): ?string
    {
        $name = @gethostbyaddr($ip);

        return ($name === false || $name === $ip) ? null : rtrim(strtolower($name), '.');
    }
}
