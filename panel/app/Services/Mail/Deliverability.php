<?php

namespace App\Services\Mail;

use App\Actions\Mail\ManageMail;
use App\Actions\Mail\ManageMailHosting;
use App\Models\MailDomain;
use App\Services\Dns\Resolver;

/**
 * Whether mail of a domain hosted here arrives and is delivered: what other
 * servers check before they accept it (reverse DNS, SPF, DKIM, DMARC,
 * blacklists) and what makes it reach this server (MX), on one page.
 */
class Deliverability
{
    /** DNS blacklists asked about the server's IPv4 (A lookup of the reversed address). */
    public const BLACKLISTS = ['zen.spamhaus.org', 'bl.spamcop.net', 'b.barracudacentral.org'];

    public function __construct(private readonly Resolver $dns) {}

    /** @return list<array{label: string, ok: bool|null, detail: string, bad?: string}> ok null = could not tell */
    public function check(MailDomain $domain): array
    {
        $d = $domain->domain;
        $host = ManageMailHosting::mailHost();
        $v4 = (string) config('panel.public_ipv4');
        $checks = [];

        $mx = array_map(fn ($t) => rtrim(strtolower($t), '.'), $this->dns->records($d, DNS_MX, 'target'));
        $checks[] = ['label' => __('MX (příchozí pošta)'), 'ok' => in_array($host, $mx, true),
            'detail' => $mx === [] ? __('chybí, má ukazovat na :host', ['host' => $host]) : implode(', ', $mx).(in_array($host, $mx, true) ? '' : __(' (má být :host)', ['host' => $host]))];

        $hostIps = $this->dns->records($host, DNS_A, 'ip');
        $checks[] = ['label' => __('Server pošty :host', ['host' => $host]), 'ok' => $v4 !== '' ? in_array($v4, $hostIps, true) : ($hostIps !== [] ? null : false),
            'detail' => $hostIps === [] ? __('nepřekládá se na IPv4 adresu') : implode(', ', $hostIps).($v4 !== '' && ! in_array($v4, $hostIps, true) ? __(' (server má :v4)', ['v4' => $v4]) : '')];

        if ($v4 !== '') {
            $ptr = $this->dns->reverse($v4);
            $checks[] = ['label' => __('Reverzní záznam (PTR) :v4', ['v4' => $v4]), 'ok' => $ptr === $host,
                'detail' => $ptr === null ? __('chybí; nastavuje ho poskytovatel serveru, má být :host', ['host' => $host]) : $ptr.($ptr === $host ? '' : __(' (má být :host)', ['host' => $host]))];
        }

        $spf = collect($this->dns->records($d, DNS_TXT, 'txt'))->first(fn ($t) => str_starts_with($t, 'v=spf1'));
        $spfOk = $spf !== null && ($v4 !== '' && str_contains($spf, 'ip4:'.$v4) || preg_match('/\s(\+?mx|\+?a)(\s|$)/', $spf) || str_contains($spf, 'include:'));
        $checks[] = ['label' => 'SPF', 'ok' => $spf === null ? false : ($spfOk ? true : null),
            'detail' => $spf ?? __('chybí TXT záznam v=spf1 (DNS → Doplnit doporučené)')];

        $key = rescue(fn () => app(ManageMail::class)->dkim($d, 'get'), null, report: false);
        $published = $this->dns->records((string) ($key['name'] ?? 'wspanel._domainkey.'.$d), DNS_TXT, 'txt');
        $want = isset($key['value']) ? preg_replace('/\s+/', '', $key['value']) : null;
        $dkimOk = $want !== null && collect($published)->contains(fn ($t) => preg_replace('/\s+/', '', $t) === $want);
        $checks[] = ['label' => 'DKIM', 'ok' => $dkimOk, 'bad' => $published === [] || $want === null ? __('chybí') : __('nesouhlasí'),
            'detail' => $want === null ? __('server nemá klíč pro doménu') : ($published === [] ? __('klíč není v DNS (DNS → Doplnit doporučené)') : ($dkimOk ? __('klíč v DNS odpovídá serveru') : __('v DNS je jiný klíč, než kterým server podepisuje')))];

        $dmarc = collect($this->dns->records('_dmarc.'.$d, DNS_TXT, 'txt'))->first(fn ($t) => str_starts_with($t, 'v=DMARC1'));
        $checks[] = ['label' => 'DMARC', 'ok' => $dmarc !== null, 'detail' => $dmarc ?? __('chybí TXT záznam _dmarc')];

        if ($v4 !== '' && filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $reversed = implode('.', array_reverse(explode('.', $v4)));
            foreach (self::BLACKLISTS as $bl) {
                $answer = $this->dns->records($reversed.'.'.$bl, DNS_A, 'ip');
                // 127.255.255.x: the list refuses to answer this resolver (e.g. a public one).
                $refused = collect($answer)->contains(fn ($ip) => str_starts_with($ip, '127.255.255.'));
                $checks[] = ['label' => "Blacklist {$bl}", 'ok' => $refused ? null : $answer === [], 'bad' => 'na seznamu',
                    'detail' => $refused ? __('nelze ověřit (seznam neodpovídá tomuto DNS resolveru)') : ($answer === [] ? __('není na seznamu') : 'adresa serveru JE na seznamu ('.implode(', ', $answer).')')];
            }
        }

        foreach ($domain->serviceHosts() as $service) {
            $ips = $this->dns->records($service, DNS_A, 'ip');
            $checks[] = ['label' => $service, 'ok' => $v4 !== '' ? in_array($v4, $ips, true) : ($ips !== [] ? null : false),
                'detail' => $ips === [] ? __('nepřekládá se (DNS → Doplnit doporučené)') : implode(', ', $ips)];
        }

        return $checks;
    }
}
