<?php

namespace App\Actions\System;

use App\Actions\Server\ManageMirror;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\PublicAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The addresses the internet reaches this server at: every suggested DNS
 * record (sites, mail, nameservers) points to them. Behind NAT they are the
 * router's, not the interface's — a server installed there got its internal
 * address, and so did every record suggested from it.
 *
 * They live in the panel's .env (PANEL_PUBLIC_IPV4/6), per server: a mirror's
 * other node keeps its own.
 */
class ManagePublicAddress
{
    public const DETECTED = 'server.public_address.detected';

    private const LOCAL = 'server.addresses.local';

    /** Where the address the internet sees is asked (it answers ip=…). */
    public const TRACE_URL = 'https://cloudflare.com/cdn-cgi/trace';

    public function __construct(private readonly AgentClient $agent) {}

    /** @return array{ipv4: string, ipv6: string} */
    public function current(): array
    {
        return ['ipv4' => (string) config('panel.public_ipv4'), 'ipv6' => (string) config('panel.public_ipv6')];
    }

    /** Whether the IPv4 the records point to cannot be reached from the internet (missing or internal). */
    public function internal(): bool
    {
        return ! PublicAddress::host($this->current()['ipv4']);
    }

    /**
     * The addresses this server's traffic leaves from, as the internet sees
     * them (kept for a while; empty when not found).
     *
     * @return array{ipv4: string, ipv6: string}
     */
    public function detected(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::DETECTED);
        }

        return Cache::remember(self::DETECTED, 600, fn () => ['ipv4' => $this->ask('v4'), 'ipv6' => $this->ask('v6')]);
    }

    /**
     * Every address a name may point to and still reach this server: the
     * public ones, and inside the network its own (an internal DNS server —
     * Windows DNS, split horizon — answers with the LAN address), with a
     * mirror the other node's too (the internal DNS points to whichever is
     * active).
     *
     * @return list<string>
     */
    public function own(): array
    {
        $local = Cache::remember(self::LOCAL, 600, function (): array {
            $ips = [];
            foreach (rescue(fn () => app(ManageSystem::class)->network()['interfaces'] ?? [], [], report: false) as $if) {
                foreach ($if['addresses'] ?? [] as $a) {
                    $ips[] = explode('/', (string) $a)[0];
                }
            }
            $mirror = rescue(fn () => app(ManageMirror::class)->cached(), [], report: false);
            foreach ([$mirror['address'] ?? '', $mirror['lan_address'] ?? '', $mirror['public_ipv4'] ?? '',
                $mirror['peer']['address'] ?? '', $mirror['peer']['lan_address'] ?? '', $mirror['peer']['public_ipv4'] ?? ''] as $a) {
                $ips[] = (string) $a;
            }

            return array_values(array_filter($ips, fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP) && ! in_array($ip, ['127.0.0.1', '::1'], true)
                && ! str_starts_with(strtolower($ip), 'fe80:')));
        });

        return array_values(array_unique(array_filter([...array_values($this->current()), ...$local])));
    }

    /**
     * Whether a name's answer points here: the address it should point to,
     * or another of this server's own (see own()).
     *
     * @param  list<string>  $answer
     */
    public function pointsHere(array $answer, string $want): bool
    {
        return in_array($want, $answer, true) || ($answer !== [] && array_diff($answer, $this->own()) === []);
    }

    /**
     * Sets both addresses (empty = none).
     *
     * @throws AgentException
     */
    public function set(string $ipv4, string $ipv6): void
    {
        $this->agent->call('panel.public_address', ['ipv4' => $ipv4, 'ipv6' => $ipv6]);
        config(['panel.public_ipv4' => $ipv4, 'panel.public_ipv6' => $ipv6]);
        Cache::forget('server.dns');
    }

    private function ask(string $family): string
    {
        try {
            $body = Http::timeout(5)->withOptions(['force_ip_resolve' => $family])->get(self::TRACE_URL)->throw()->body();
        } catch (Throwable) {
            return '';
        }
        $ip = preg_match('/^ip=(\S+)$/m', $body, $m) ? $m[1] : '';
        $flag = $family === 'v4' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;

        return filter_var($ip, FILTER_VALIDATE_IP, $flag | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : '';
    }
}
