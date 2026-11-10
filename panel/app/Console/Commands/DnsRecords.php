<?php

namespace App\Console\Commands;

use App\Actions\Mail\ManageMailHosting;
use App\Services\Agent\SystemInfo;
use App\Services\Dns\Resolver;
use App\Services\Mail\Deliverability;
use App\Support\PublicAddress;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The DNS records this server needs (the panel's address, the mail server),
 * each checked in public DNS: the end of the installation prints them, so
 * whoever installs knows what to set at the registrar or DNS provider.
 */
#[Signature('panel:dns-records')]
#[Description('The DNS records this server needs, checked in public DNS')]
class DnsRecords extends Command
{
    public function handle(Resolver $dns): int
    {
        $v4 = (string) config('panel.public_ipv4');
        $v6 = (string) config('panel.public_ipv6');
        $v4 = PublicAddress::host($v4) ? $v4 : '';
        $v6 = PublicAddress::host($v6) ? $v6 : '';
        $panel = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $rows = [];

        // An address record for each public name of the server.
        $mail = rescue(fn () => app(ManageMailHosting::class)->installed(), false, report: false);
        $mailHost = strtolower(ManageMailHosting::mailHost());
        foreach (array_unique(array_filter([$panel, $mail ? $mailHost : null])) as $host) {
            if (! PublicAddress::host($host) || filter_var($host, FILTER_VALIDATE_IP)) {
                continue;
            }
            if ($v4 !== '') {
                $rows[] = $this->row($host, 'A', $v4, $dns->records($host, DNS_A, 'ip'));
            }
            if ($v6 !== '') {
                $rows[] = $this->row($host, 'AAAA', $v6, $dns->records($host, DNS_AAAA, 'ipv6'));
            }
        }

        // The mail server: its SPF, and the reverse record at the server's provider.
        if ($mail && PublicAddress::host($mailHost) && $v4 !== '') {
            $spf = collect($dns->records($mailHost, DNS_TXT, 'txt'))->first(fn ($t) => str_starts_with($t, 'v=spf1'));
            $rows[] = ['name' => $mailHost, 'type' => 'TXT', 'value' => 'v=spf1 a -all',
                'ok' => $spf !== null && Deliverability::spfAllowsServer($spf, $v4), 'now' => $spf ?? '—'];
            $ptr = $dns->reverse($v4);
            $rows[] = ['name' => $v4, 'type' => 'PTR', 'value' => $mailHost.'  ('.__('nastavuje poskytovatel serveru').')',
                'ok' => $ptr === $mailHost, 'now' => $ptr ?? '—'];
        }

        if ($rows === []) {
            return self::SUCCESS;
        }
        $this->newLine();
        $this->line('<options=bold>'.__('DNS záznamy pro tento server').'</> '.__('(nastavte je u registrátora nebo poskytovatele DNS):'));
        $this->table([__('Název'), __('Typ'), __('Hodnota'), __('Stav')], array_map(fn (array $r) => [
            $r['name'], $r['type'], $r['value'], $r['ok'] ? '✓ '.__('nastaveno') : '✗ '.__('chybí').($r['now'] !== '—' && $r['now'] !== [] ? ' ('.__('teď').': '.(is_array($r['now']) ? implode(', ', $r['now']) : $r['now']).')' : ''),
        ], $rows));
        if (rescue(fn () => (bool) (app(SystemInfo::class)->versions()['dns_local'] ?? false), false, report: false)) {
            $this->line(__('Vlastní DNS server (PowerDNS): domény, jejichž zóny povedete v panelu, delegujte u registrátora na tento server (NS = :host).', ['host' => $mail ? $mailHost : $panel]));
        }
        $this->line(__('Stav i záznamy pro jednotlivé weby a domény pošty ukazuje panel (záložka DNS u webu, Pošta → domény).'));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $now  what public DNS answers
     * @return array{name: string, type: string, value: string, ok: bool, now: list<string>|string}
     */
    private function row(string $name, string $type, string $want, array $now): array
    {
        return ['name' => $name, 'type' => $type, 'value' => $want, 'ok' => in_array($want, $now, true), 'now' => $now];
    }
}
