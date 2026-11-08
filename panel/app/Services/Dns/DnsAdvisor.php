<?php

namespace App\Services\Dns;

use App\Actions\Mail\ManageMail;
use App\Actions\Mail\ManageMailHosting;
use App\Models\MailDomain;

/**
 * What a domain on this server should have in DNS, and whether public DNS
 * already says so.
 */
class DnsAdvisor
{
    /**
     * Recommended RRsets for a zone: the web (A/AAAA), CAA for the
     * certificates and the mail records (SPF, DKIM, DMARC).
     *
     * @param  array{name: string, value: string}|null  $dkim
     * @return list<array{name: string, type: string, ttl: int, records: list<string>, note: string}>
     */
    public static function recommended(string $domain, ?array $dkim = null): array
    {
        $v4 = config('panel.public_ipv4');
        $v6 = config('panel.public_ipv6');
        $out = [];
        if ($v4) {
            $out[] = ['name' => $domain, 'type' => 'A', 'ttl' => 3600, 'records' => [$v4], 'note' => __('Web na tomto serveru (IPv4).')];
        }
        if ($v6) {
            $out[] = ['name' => $domain, 'type' => 'AAAA', 'ttl' => 3600, 'records' => [$v6], 'note' => __('Web na tomto serveru (IPv6).')];
        }
        $out[] = ['name' => $domain, 'type' => 'CAA', 'ttl' => 3600, 'records' => ['0 issue "letsencrypt.org"', '0 issue "zerossl.com"'], 'note' => __('Certifikáty smí vydat jen autority, které používá server.')];
        // Mail hosting: MX to this server, client autodiscovery by SRV (RFC 6186).
        if ($mailDomain = MailDomain::acrossClients()->where('domain', $domain)->first()) {
            $host = ManageMailHosting::mailHost();
            // webmail.<domain>, autoconfig./autodiscover.<domain> are served here.
            foreach ($mailDomain->serviceHosts() as $service) {
                $out[] = ['name' => $service, 'type' => 'CNAME', 'ttl' => 3600, 'records' => [$host.'.'],
                    'note' => str_starts_with($service, 'webmail.') ? __('Webmail domény.') : __('Poštovní programy si samy načtou nastavení.')];
            }
            $out[] = ['name' => $domain, 'type' => 'MX', 'ttl' => 3600, 'records' => ['10 '.$host.'.'], 'note' => __('Pošta pro doménu chodí na tento server.')];
            foreach (['_imaps._tcp' => '993', '_pop3s._tcp' => '995', '_submission._tcp' => '587'] as $srv => $port) {
                $out[] = ['name' => $srv.'.'.$domain, 'type' => 'SRV', 'ttl' => 3600, 'records' => ['0 1 '.$port.' '.$host.'.'], 'note' => __('Poštovní programy samy najdou server.')];
            }
        }
        foreach (ManageMail::dnsRecords($domain, $dkim, null, $v4) as $r) {
            $out[] = ['name' => $r['name'], 'type' => 'TXT', 'ttl' => 3600, 'records' => [$r['value']], 'note' => $r['note']];
        }

        return $out;
    }

    /**
     * Checks public DNS for a domain served here.
     *
     * @param  list<string>  $expectedNs  nameservers the zone should be delegated to (empty = don't check)
     * @return list<array{label: string, ok: bool|null, detail: string}>
     */
    public static function check(string $domain, array $expectedNs = []): array
    {
        $checks = [];
        $a = self::lookup($domain, DNS_A, 'ip');
        $v4 = config('panel.public_ipv4');
        $checks[] = [
            'label' => __('A záznam :domain', ['domain' => $domain]),
            'ok' => $v4 ? in_array($v4, $a, true) : ($a !== [] ? null : false),
            'detail' => $a === [] ? __('doména se nepřekládá na žádnou IPv4 adresu') : implode(', ', $a).($v4 && ! in_array($v4, $a, true) ? __(' (server má :v4)', ['v4' => $v4]) : ''),
        ];
        if ($expectedNs !== []) {
            $ns = array_map(fn ($n) => rtrim(strtolower($n), '.'), self::lookup($domain, DNS_NS, 'target'));
            $want = array_map(fn ($n) => rtrim(strtolower($n), '.'), $expectedNs);
            $checks[] = [
                'label' => __('Nameservery (delegace u registrátora)'),
                'ok' => $ns !== [] && array_diff($want, $ns) === [],
                'detail' => $ns === [] ? 'nenalezeny' : implode(', ', $ns).(array_diff($want, $ns) ? __(' (očekáváno ').implode(', ', $want).')' : ''),
            ];
        }
        $txt = self::lookup($domain, DNS_TXT, 'txt');
        $checks[] = [
            'label' => 'SPF',
            'ok' => collect($txt)->contains(fn ($t) => str_starts_with($t, 'v=spf1')),
            'detail' => collect($txt)->first(fn ($t) => str_starts_with($t, 'v=spf1')) ?? __('chybí TXT záznam v=spf1'),
        ];
        $dmarc = self::lookup('_dmarc.'.$domain, DNS_TXT, 'txt');
        $checks[] = [
            'label' => 'DMARC',
            'ok' => $dmarc !== [],
            'detail' => $dmarc[0] ?? __('chybí TXT záznam _dmarc'),
        ];

        return $checks;
    }

    /** @return list<string> */
    private static function lookup(string $name, int $type, string $field): array
    {
        $records = @dns_get_record($name, $type) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r[$field] ?? null, $records)));
    }

    /**
     * Default nameserver names for local zones: ns1/ns2 under the panel domain.
     *
     * @return list<string>
     */
    public static function defaultNameservers(): array
    {
        $panel = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return ["ns1.{$panel}", "ns2.{$panel}"];
    }
}
