<?php

namespace App\Services\Dns;

use App\Actions\Mail\ManageMailHosting;
use App\Models\MailDomain;
use App\Services\Mail\Deliverability;
use App\Support\PublicAddress;

/**
 * DNS a feature needs before it works, asked before it is switched on:
 * a name that should point here (a certificate is issued only then) or the
 * mail host's SPF (forwarded mail passes SPF only then). Answers are kept
 * for the request, since a form asks the same thing several times per render.
 */
class DnsPrerequisites
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    public function __construct(private readonly Resolver $dns) {}

    /**
     * Whether each name resolves to this server; null when it cannot tell
     * (no public address configured, or a name only a local network knows).
     *
     * @param  list<string>  $hosts
     * @return list<array{label: string, ok: bool|null, detail: string, bad?: string}>
     */
    public function pointHere(array $hosts): array
    {
        $v4 = (string) config('panel.public_ipv4');
        $v6 = (string) config('panel.public_ipv6');
        $checks = [];
        foreach (array_values(array_unique(array_map('strtolower', $hosts))) as $host) {
            $ips = [...$this->records($host, DNS_A, 'ip'), ...$this->records($host, DNS_AAAA, 'ipv6')];
            $known = ($v4 !== '' || $v6 !== '') && PublicAddress::host($host);
            $here = ($v4 !== '' && in_array($v4, $ips, true)) || ($v6 !== '' && in_array($v6, $ips, true));
            $checks[] = ['label' => $host, 'ok' => $known ? $here : null, 'bad' => __('nemíří sem'),
                'detail' => $ips === [] ? __('nepřekládá se; přidejte A záznam na :ip', ['ip' => $v4 !== '' ? $v4 : $v6])
                    : implode(', ', $ips).($known && ! $here ? __(' (server má :ip)', ['ip' => $v4 !== '' ? $v4 : $v6]) : '')];
        }

        return $checks;
    }

    /**
     * The mail host's SPF, when part of the mail goes to the given addresses
     * off the server (forwarded with an SRS sender at the mail host).
     *
     * @param  list<string>  $destinations
     * @return list<array{label: string, ok: bool|null, detail: string, bad?: string}>
     */
    public function forwardingSpf(array $destinations): array
    {
        $local = MailDomain::acrossClients()->pluck('domain')->map(fn ($d) => strtolower($d))->all();
        $leaves = collect($destinations)->contains(fn ($a) => ! in_array(strtolower((string) substr((string) strrchr((string) $a, '@'), 1)), $local, true));
        if (! $leaves) {
            return [];
        }
        $host = ManageMailHosting::mailHost();
        $spf = app(Deliverability::class)->hostSpf();

        return [['label' => __('SPF serveru pošty :host', ['host' => $host]), 'ok' => $spf['ok'],
            'detail' => ($spf['record'] ?? __('chybí')).__('; přeposlaná pošta ho potřebuje: :host TXT "v=spf1 a -all"', ['host' => $host])]];
    }

    /**
     * @param  list<array{ok: bool|null}>  $checks
     * @return list<array{label: string, ok: bool|null, detail: string, bad?: string}>
     */
    public static function failing(array $checks): array
    {
        return array_values(array_filter($checks, fn ($c) => $c['ok'] === false));
    }

    /** @return list<string> */
    private function records(string $name, int $type, string $field): array
    {
        return $this->memo["{$name}|{$type}"] ??= $this->dns->records($name, $type, $field);
    }
}
