<?php

namespace App\Console\Commands;

use App\Services\Dns\ServerDns;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The DNS records this server needs (the panel's address, the mail server,
 * its own nameservers), each checked in public DNS now: the end of the
 * installation prints them, and an administrator runs it after changing a
 * record. The same checks are kept in Server → Systém and raise an alert
 * when a record stops being right. Exits 1 while one is missing.
 */
#[Signature('panel:dns-records')]
#[Description('The DNS records this server needs, checked in public DNS')]
class DnsRecords extends Command
{
    public function handle(ServerDns $dns): int
    {
        $rows = $dns->refresh();
        if ($rows === []) {
            return self::SUCCESS;
        }
        $this->newLine();
        $this->line('<options=bold>'.__('DNS záznamy pro tento server').'</> '.__('(nastavte je u registrátora nebo poskytovatele DNS):'));
        $this->table([__('Název'), __('Typ'), __('Hodnota'), __('Stav')], array_map(fn (array $r) => [
            $r['name'], $r['type'], $r['want'].($r['type'] === 'PTR' ? '  ('.__('nastavuje poskytovatel serveru').')' : ''),
            $r['ok'] ? '✓ '.__('nastaveno') : '✗ '.__('chybí').($r['now'] !== '' ? ' ('.__('teď').': '.$r['now'].')' : ''),
        ], $rows));
        if (collect($rows)->contains('type', 'A') && collect($rows)->contains(fn ($r) => str_starts_with($r['name'], 'ns1.'))) {
            $this->line(__('Nameservery ns1/ns2 leží v doméně, kterou samy obsluhují: u registrátora k nim zadejte i IP adresu (glue záznam).'));
        }
        $this->line(__('Panel záznamy hlídá dál (Server → Systém) a upozorní, když některý přestane platit. Záznamy webů a domén pošty: záložka DNS u webu, Pošta → domény.'));

        return collect($rows)->every('ok') ? self::SUCCESS : self::FAILURE;
    }
}
