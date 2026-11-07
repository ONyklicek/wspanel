<?php

namespace App\Services\Dns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Zones at Cloudflare, through the v4 API with a scoped API token. */
class CloudflareDns implements DnsZoneProvider
{
    public const API = 'https://api.cloudflare.com/client/v4';

    public function __construct(private readonly string $token, private readonly string $zoneId) {}

    /** Finds the zone id for a domain; the token must see the zone. */
    public static function zoneId(string $token, string $domain): string
    {
        $res = self::client($token)->get('/zones', ['name' => $domain]);
        self::check($res);
        $id = $res->json('result.0.id');
        if (! is_string($id)) {
            throw new DnsException("Zóna {$domain} na Cloudflare neexistuje, nebo k ní token nemá přístup.");
        }

        return $id;
    }

    public function rrsets(string $domain): array
    {
        $res = self::client($this->token)->get("/zones/{$this->zoneId}/dns_records", ['per_page' => 5000]);
        self::check($res);

        return collect($res->json('result', []))
            ->filter(fn (array $r) => in_array($r['type'], ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'], true))
            ->groupBy(fn (array $r) => $r['name'].'|'.$r['type'])
            ->map(fn ($group) => [
                'name' => $group[0]['name'],
                'type' => $group[0]['type'],
                'ttl' => (int) $group[0]['ttl'],
                'records' => $group->map(fn (array $r) => self::presentation($r))->values()->all(),
                'proxied' => (bool) ($group[0]['proxied'] ?? false),
            ])
            ->sortBy(fn (array $s) => $s['name'].' '.$s['type'])
            ->values()
            ->all();
    }

    public function set(string $domain, string $name, string $type, int $ttl, array $records): void
    {
        $http = self::client($this->token);
        $existing = $http->get("/zones/{$this->zoneId}/dns_records", ['name' => $name, 'type' => $type, 'per_page' => 100]);
        self::check($existing);
        foreach ($existing->json('result', []) as $r) {
            self::check($http->delete("/zones/{$this->zoneId}/dns_records/{$r['id']}"));
        }
        foreach ($records as $value) {
            $body = ['type' => $type, 'name' => $name, 'ttl' => $ttl, 'content' => trim($value, '" ')];
            if ($type === 'MX' && preg_match('/^(\d+)\s+(\S+)$/', trim($value), $m)) {
                $body['priority'] = (int) $m[1];
                $body['content'] = rtrim($m[2], '.');
            }
            if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
                $body['proxied'] = false;
            }
            self::check($http->post("/zones/{$this->zoneId}/dns_records", $body));
        }
    }

    /** @param  array<string, mixed>  $r */
    private static function presentation(array $r): string
    {
        return match ($r['type']) {
            'MX' => ($r['priority'] ?? 0).' '.$r['content'],
            default => (string) $r['content'],
        };
    }

    private static function client(string $token): PendingRequest
    {
        return Http::baseUrl(self::API)->withToken($token)->acceptJson()->timeout(15);
    }

    private static function check(Response $res): void
    {
        if (! $res->successful() || $res->json('success') === false) {
            $msg = collect($res->json('errors', []))->pluck('message')->join('; ') ?: "HTTP {$res->status()}";
            throw new DnsException("Cloudflare: {$msg}");
        }
    }
}
