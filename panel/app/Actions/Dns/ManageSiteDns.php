<?php

namespace App\Actions\Dns;

use App\Actions\Mail\ManageMail;
use App\Actions\Mail\ManageMailHosting;
use App\Enums\DnsProvider;
use App\Livewire\Sites\SiteMail;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Site;
use App\Services\Agent\SystemInfo;
use App\Services\Dns\DnsAdvisor;
use App\Services\Dns\DnsException;
use App\Services\Dns\Resolver;
use App\Support\Domains;
use App\Support\PublicAddress;

/**
 * DNS of one site: what it needs (its names on this server, the mail of its
 * domain), whether its zone has it, whether the world sees it, and filling
 * in what is missing in a zone the panel manages. Existing records are
 * never overwritten: a name pointing elsewhere is reported, not changed.
 */
class ManageSiteDns
{
    public function __construct(
        private readonly ManageDnsZones $zones,
        private readonly Resolver $resolver,
    ) {}

    /** The zone a name belongs to: its own, or the closest one above it. */
    public static function zoneFor(string $host): ?DnsZone
    {
        $host = strtolower(rtrim($host, '.'));
        $candidates = [];
        $labels = explode('.', $host);
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        return DnsZone::query()->whereIn('domain', $candidates)->get()
            ->sortByDesc(fn (DnsZone $z) => strlen($z->domain))->first();
    }

    /** The zone a new one for the site would be: its domain without www. */
    public static function suggestedZone(Site|string $site): string
    {
        return (string) preg_replace('/^www\./', '', is_string($site) ? $site : $site->domain);
    }

    /** @return list<string> the site's own name and its extra ones */
    public static function hosts(Site $site): array
    {
        $edge = $site->edge_settings ?? [];

        return array_values(array_unique(array_map('strtolower', [$site->domain, ...($edge['aliases'] ?? []), ...($edge['redirect_hosts'] ?? [])])));
    }

    public static function localAvailable(): bool
    {
        return (bool) rescue(fn () => app(SystemInfo::class)->versions()['dns_local'] ?? false, false, report: false);
    }

    /**
     * What the site needs in DNS: its names on this server (A/AAAA), and for
     * its domain the CAA, SPF, DMARC (DKIM) and, with mail hosting, MX,
     * SRV and the mail service names.
     *
     * @return list<array{name: string, type: string, ttl: int, records: list<string>, note: string}>
     */
    public function expected(Site $site): array
    {
        $domain = SiteMail::mailDomainName($site);
        $out = collect(DnsAdvisor::recommended($domain, $this->dkim($domain)));
        $v4 = config('panel.public_ipv4');
        $v6 = config('panel.public_ipv6');
        foreach (self::hosts($site) as $host) {
            if ($host === $domain) {
                continue; // in the recommended set already
            }
            if ($v4) {
                $out->push(['name' => $host, 'type' => 'A', 'ttl' => 3600, 'records' => [$v4], 'note' => __('Web na tomto serveru (IPv4).')]);
            }
            if ($v6) {
                $out->push(['name' => $host, 'type' => 'AAAA', 'ttl' => 3600, 'records' => [$v6], 'note' => __('Web na tomto serveru (IPv6).')]);
            }
        }

        return $out->unique(fn (array $r) => $r['name'].'|'.$r['type'])->values()->all();
    }

    /** @return array{name: string, value: string}|null */
    private function dkim(string $domain): ?array
    {
        $dkim = rescue(fn () => app(ManageMail::class)->dkim($domain, 'get'), null, report: false);

        return isset($dkim['name'], $dkim['value']) ? ['name' => $dkim['name'], 'value' => $dkim['value']] : null;
    }

    /**
     * Each expected record: in the zone (managed zones), and seen by the
     * world (the names that must point here).
     *
     * @return array{zone: ?DnsZone, domain: string, records: list<array{name: string, relative: string, type: string, ttl: int, records: list<string>, fields: list<array<string, string>>, note: string, in_zone: ?string, public: ?string}>, error: ?string}
     */
    public function status(Site $site): array
    {
        $zone = self::zoneFor($site->domain);
        $have = [];
        $error = null;
        if ($zone !== null) {
            try {
                foreach ($zone->api()->rrsets($zone->domain) as $set) {
                    $have[strtolower(rtrim($set['name'], '.')).'|'.$set['type']] = $set['records'];
                }
            } catch (DnsException $e) {
                $error = $e->getMessage();
            }
        }
        $rows = [];
        foreach ($this->expected($site) as $r) {
            $inZone = null; // no zone here: nothing to compare
            if ($zone !== null && $error === null && self::inZone($r['name'], $zone)) {
                $current = $have[strtolower($r['name']).'|'.$r['type']] ?? null;
                $inZone = match (true) {
                    $current === null => 'missing',
                    self::same($current, $r['records']) => 'ok',
                    default => 'differs',
                };
            }
            $rows[] = $r + ['in_zone' => $inZone, 'public' => $this->publicState($r)];
        }
        $domain = $zone?->domain ?? $this->registrarDomain($site->domain);
        foreach ($rows as &$row) {
            $row['relative'] = self::relativeName($row['name'], $domain);
            $row['fields'] = array_map(fn (string $v) => self::fields($row['type'], $v), $row['records']);
        }
        unset($row);

        return ['zone' => $zone, 'domain' => $domain, 'records' => $rows, 'error' => $error];
    }

    private static function inZone(string $name, DnsZone $zone): bool
    {
        $name = strtolower(rtrim($name, '.'));

        return $name === $zone->domain || str_ends_with($name, '.'.$zone->domain);
    }

    /** @param list<string> $a @param list<string> $b */
    private static function same(array $a, array $b): bool
    {
        // A long TXT comes back as several quoted strings ("…" "…"): one value.
        $norm = fn (array $l) => collect($l)->map(fn ($v) => strtolower(rtrim(trim((string) preg_replace('/"\s+"/', '', (string) $v), '" '), '.')))->sort()->values()->all();

        return $norm($a) === $norm($b);
    }

    /**
     * What public DNS says for an expected record: ok (it has our values),
     * differs (the name has other values), missing; null when it cannot be
     * asked (a name of a local network, no public address configured).
     */
    private function publicState(array $r): ?string
    {
        if (Domains::isInternal($r['name']) || (in_array($r['type'], ['A', 'AAAA'], true) && ! PublicAddress::host($r['name']))) {
            return null;
        }
        $have = match ($r['type']) {
            'A' => $this->resolver->records($r['name'], DNS_A, 'ip'),
            'AAAA' => $this->resolver->records($r['name'], DNS_AAAA, 'ipv6'),
            'CNAME' => $this->resolver->records($r['name'], DNS_CNAME, 'target'),
            'MX' => $this->resolver->records($r['name'], DNS_MX, 'target'),
            'TXT' => $this->resolver->records($r['name'], DNS_TXT, 'txt'),
            'SRV' => $this->resolver->records($r['name'], DNS_SRV, 'target'),
            'CAA' => $this->resolver->records($r['name'], DNS_CAA, 'value'),
            default => null,
        };
        if ($have === null) {
            return null;
        }
        if ($have === []) {
            return 'missing';
        }
        $want = array_map(fn (array $f) => $f['value'], array_map(fn (string $v) => self::fields($r['type'], $v), $r['records']));
        $norm = fn (array $l) => array_map(fn ($v) => strtolower(rtrim(trim((string) $v, '" '), '.')), $l);

        return array_diff($norm($want), $norm($have)) === [] ? 'ok' : 'differs';
    }

    /**
     * One record value split as registrars' forms ask for it: the priority
     * (MX, SRV) and the SRV's weight and port in fields of their own, the
     * value without quotes.
     *
     * @return array{value: string, priority?: string, weight?: string, port?: string}
     */
    public static function fields(string $type, string $value): array
    {
        $value = trim($value);
        if ($type === 'MX' && preg_match('/^(\d+)\s+(\S+)$/', $value, $m)) {
            return ['priority' => $m[1], 'value' => rtrim($m[2], '.')];
        }
        if ($type === 'SRV' && preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)$/', $value, $m)) {
            return ['priority' => $m[1], 'weight' => $m[2], 'port' => $m[3], 'value' => rtrim($m[4], '.')];
        }
        if ($type === 'TXT') {
            return ['value' => (string) preg_replace('/"\s+"/', '', trim($value, '"'))];
        }
        if ($type === 'CNAME') {
            return ['value' => rtrim($value, '.')];
        }

        return ['value' => $value];
    }

    /**
     * The domain as the registrar has it (the zone the world asks): found by
     * its SOA, else the last two labels (shop.example.com → example.com).
     */
    public function registrarDomain(string $host): string
    {
        $labels = explode('.', strtolower(rtrim($host, '.')));
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            if (! Domains::isInternal($candidate) && $this->resolver->records($candidate, DNS_SOA, 'mname') !== []) {
                return $candidate;
            }
        }

        return implode('.', array_slice($labels, -2));
    }

    /** A name as registrars' forms take it: relative to the domain, @ for the domain itself. */
    public static function relativeName(string $name, string $domain): string
    {
        $name = strtolower(rtrim($name, '.'));

        return match (true) {
            $name === $domain => '@',
            str_ends_with($name, '.'.$domain) => substr($name, 0, -strlen($domain) - 1),
            default => $name,
        };
    }

    /**
     * Adds what the site's zone lacks. A name with other values is left as it
     * is (reported); the site's zone must be managed by the panel.
     *
     * @return array{added: list<string>, kept: list<string>}
     *
     * @throws DnsException
     */
    public function fill(Site $site): array
    {
        $zone = self::zoneFor($site->domain);
        if ($zone === null) {
            throw new DnsException(__('Web nemá v panelu DNS zónu.'));
        }
        $api = $zone->api();
        $have = collect($api->rrsets($zone->domain))->mapWithKeys(fn ($s) => [strtolower(rtrim($s['name'], '.')).'|'.$s['type'] => $s['records']]);
        $added = $kept = [];
        foreach ($this->expected($site) as $r) {
            if (! self::inZone($r['name'], $zone)) {
                continue;
            }
            $key = strtolower($r['name']).'|'.$r['type'];
            // A CNAME at a name blocks every other type there (and the other way round).
            $cnameClash = $r['type'] !== 'CNAME' ? $have->has(strtolower($r['name']).'|CNAME') : $have->keys()->contains(fn ($k) => str_starts_with($k, strtolower($r['name']).'|') && $k !== $key);
            if ($have->has($key) || $cnameClash) {
                if (! $have->has($key) || ! self::same($have[$key], $r['records'])) {
                    $kept[] = "{$r['name']} {$r['type']}";
                }

                continue;
            }
            $api->set($zone->domain, $r['name'], $r['type'], $r['ttl'], $r['records']);
            $added[] = "{$r['name']} {$r['type']}";
        }

        return ['added' => $added, 'kept' => $kept];
    }

    /**
     * New extra names of a site: their address records go into the zone the
     * panel manages for them (each name its own zone), when missing there.
     *
     * @param  list<string>  $hosts
     * @return list<string> added "name TYPE"
     */
    public function addHosts(array $hosts): array
    {
        $added = [];
        $ips = array_filter(['A' => config('panel.public_ipv4'), 'AAAA' => config('panel.public_ipv6')]);
        foreach ($hosts as $host) {
            $zone = self::zoneFor($host);
            if ($zone === null || $ips === []) {
                continue;
            }
            $api = $zone->api();
            $have = collect($api->rrsets($zone->domain))->map(fn ($s) => strtolower(rtrim($s['name'], '.')).'|'.$s['type']);
            if ($have->contains(strtolower($host).'|CNAME')) {
                continue;
            }
            foreach ($ips as $type => $ip) {
                if (! $have->contains(strtolower($host).'|'.$type)) {
                    $api->set($zone->domain, $host, $type, 3600, [$ip]);
                    $added[] = "{$host} {$type}";
                }
            }
        }

        return $added;
    }

    /**
     * Sets up DNS for a site that was just created: into the zone that
     * covers it, or a new zone here or in Cloudflare; mail hosting first,
     * so its records come along.
     *
     * @param  array{dns?: string|null, dns_zone?: string|null, cloudflare_token?: string|null, mail?: bool}  $data
     * @return array{zone: ?string, added: list<string>, kept: list<string>, mail: bool}
     *
     * @throws \Throwable (the site stays, the caller reports it)
     */
    public function setUpForNewSite(Site $site, array $data): array
    {
        $mail = false;
        if (($data['mail'] ?? false) && ! MailDomain::query()->where('domain', SiteMail::mailDomainName($site))->exists()) {
            app(ManageMailHosting::class)->addDomain(SiteMail::mailDomainName($site), $site->client);
            $mail = true;
        }
        $mode = $data['dns'] ?? 'none';
        if (in_array($mode, [DnsProvider::Local->value, DnsProvider::Cloudflare->value], true) && self::zoneFor($site->domain) === null) {
            $this->zones->create([
                'domain' => Domains::normalize((string) ($data['dns_zone'] ?? '')) ?: self::suggestedZone($site),
                'provider' => $mode,
                'nameservers' => DnsAdvisor::defaultNameservers(),
                'cloudflare_token' => $data['cloudflare_token'] ?? null,
            ]);
        }
        if ($mode === 'none' || self::zoneFor($site->domain) === null) {
            return ['zone' => null, 'added' => [], 'kept' => [], 'mail' => $mail];
        }

        return ['zone' => self::zoneFor($site->domain)->domain] + $this->fill($site) + ['mail' => $mail];
    }
}
