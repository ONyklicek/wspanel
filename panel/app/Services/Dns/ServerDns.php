<?php

namespace App\Services\Dns;

use App\Actions\Mail\ManageMail;
use App\Actions\Mail\ManageMailHosting;
use App\Enums\DnsProvider;
use App\Models\DnsZone;
use App\Models\Site;
use App\Services\Agent\SystemInfo;
use App\Services\Mail\Deliverability;
use App\Support\PublicAddress;
use Illuminate\Support\Facades\Cache;

/**
 * The records the server itself needs, checked in public DNS: the panel's
 * name (its certificate is issued only then), the mail server's name, its
 * reverse record and SPF, and the names of its own nameservers. One answer
 * for the end of the installation (panel:dns-records), Server → Systém and
 * the alerts, which tell when one of them stops being right.
 *
 * Records a site or a mail domain needs are theirs (the site's DNS tab,
 * Pošta → domény), not this list's.
 */
class ServerDns
{
    /** How long a check is kept: public DNS changes slowly, alerts ask every minute. */
    public const CACHE_SECONDS = 1800;

    private const CACHE_KEY = 'server.dns';

    public function __construct(private readonly Resolver $dns) {}

    /**
     * @return list<array{key: string, name: string, type: string, want: string, now: string, ok: bool, note: string}>
     */
    public function checks(): array
    {
        $v4 = (string) config('panel.public_ipv4');
        $v6 = (string) config('panel.public_ipv6');
        $v4 = PublicAddress::host($v4) ? $v4 : '';
        $v6 = PublicAddress::host($v6) ? $v6 : '';
        if ($v4 === '' && $v6 === '') {
            return []; // a server on a local network: public DNS has nothing to say
        }
        $panel = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $hosting = (bool) rescue(fn () => app(ManageMailHosting::class)->installed(), false, report: false);
        $mailHost = strtolower(ManageMailHosting::mailHost());
        $rows = [];

        // An address record for each public name of the server.
        $names = [$panel => __('Adresa panelu; certifikát se vydá, jen když míří sem.')];
        if ($hosting) {
            $names[$mailHost] ??= __('Server pošty: na něj míří MX domén a připojují se k němu poštovní programy.');
        }
        foreach ($this->ownNameservers($panel) as $ns) {
            $names[$ns] ??= __('Vlastní DNS server: u registrátora i jako glue záznam (IP nameserveru).');
        }
        foreach ($names as $host => $note) {
            if (! PublicAddress::host($host) || filter_var($host, FILTER_VALIDATE_IP)) {
                continue;
            }
            if ($v4 !== '') {
                $rows[] = $this->address($host, 'A', $v4, $this->dns->records($host, DNS_A, 'ip'), $note);
            }
            if ($v6 !== '') {
                $rows[] = $this->address($host, 'AAAA', $v6, $this->dns->records($host, DNS_AAAA, 'ipv6'), $note);
            }
        }

        // The mail server: SPF for forwarded mail, and the reverse record that
        // receiving servers check (unless mail leaves through a relay).
        if ($hosting && PublicAddress::host($mailHost) && $v4 !== '') {
            $spf = collect($this->dns->records($mailHost, DNS_TXT, 'txt'))->first(fn ($t) => str_starts_with((string) $t, 'v=spf1'));
            $rows[] = ['key' => 'spf:'.$mailHost, 'name' => $mailHost, 'type' => 'TXT', 'want' => 'v=spf1 a -all', 'now' => $spf ?? '',
                'ok' => $spf !== null && Deliverability::spfAllowsServer($spf, $v4),
                'note' => __('SPF serveru pošty: přeposlaná pošta odchází z adresy na něm.')];
        }
        if ($v4 !== '' && PublicAddress::host($mailHost) && $this->sendsMail($hosting)) {
            $ptr = $this->dns->reverse($v4);
            $rows[] = ['key' => 'ptr:'.$v4, 'name' => $v4, 'type' => 'PTR', 'want' => $mailHost, 'now' => $ptr ?? '',
                'ok' => $ptr === $mailHost,
                'note' => __('Nastavuje ho poskytovatel serveru (ne DNS zóna). Bez něj velcí poskytovatelé poštu odmítají.')];
        }

        return $rows;
    }

    /** checks(), kept for a while: every page and every alert run reads it. */
    public function cached(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->checks());
    }

    /** Asks public DNS again (after a record was fixed). */
    public function refresh(): array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->cached();
    }

    /** @return list<array{key: string, name: string, type: string, want: string, now: string, ok: bool, note: string}> */
    public function failing(): array
    {
        return array_values(array_filter($this->cached(), fn (array $r) => ! $r['ok']));
    }

    /**
     * One failing record as a sentence (alerts, Vyžaduje pozornost).
     *
     * @param  array{name: string, type: string}  $r
     */
    public static function problem(array $r): string
    {
        return match ($r['type']) {
            'PTR' => __('Reverzní záznam (PTR) :ip nesedí', ['ip' => $r['name']]),
            'TXT' => __('Chybí SPF serveru pošty :host', ['host' => $r['name']]),
            default => __('DNS: :host (:type) nemíří na tento server', ['host' => $r['name'], 'type' => $r['type']]),
        };
    }

    /**
     * What to set, and what public DNS answers now.
     *
     * @param  array{type: string, want: string, now: string, note: string}  $r
     */
    public static function fix(array $r): string
    {
        return __('Má být :want, teď :now.', ['want' => $r['want'], 'now' => $r['now'] !== '' ? $r['now'] : __('nic')]).' '.$r['note'];
    }

    /**
     * The nameservers under the panel's own name that zones here point to,
     * while there is a zone on this server (secondaries elsewhere are theirs).
     *
     * @return list<string>
     */
    private function ownNameservers(string $panel): array
    {
        $local = (bool) rescue(fn () => app(SystemInfo::class)->versions()['dns_local'] ?? false, false, report: false);
        if (! $local || ! DnsZone::query()->where('provider', DnsProvider::Local)->exists()) {
            return [];
        }

        return array_values(array_filter(DnsAdvisor::defaultNameservers(), fn (string $ns) => str_ends_with($ns, '.'.$panel)));
    }

    /** Whether mail leaves from this server's address (not through a relay). */
    private function sendsMail(bool $hosting): bool
    {
        $relay = (string) (rescue(fn () => app(ManageMail::class)->status(), [], report: false)['relay'] ?? '');

        return $relay === '' && ($hosting || Site::acrossClients()->where('mail_mode', 'send')->exists());
    }

    /**
     * @param  list<string>  $now
     * @return array{key: string, name: string, type: string, want: string, now: string, ok: bool, note: string}
     */
    private function address(string $host, string $type, string $want, array $now, string $note): array
    {
        return ['key' => strtolower($type).':'.$host, 'name' => $host, 'type' => $type, 'want' => $want,
            'now' => implode(', ', $now), 'ok' => in_array($want, $now, true), 'note' => $note];
    }
}
