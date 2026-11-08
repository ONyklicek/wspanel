<?php

namespace App\Livewire\Dns;

use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\SystemInfo;
use App\Services\Dns\DnsException;
use App\Services\Dns\LocalDns;
use App\Support\Toast;
use App\Wire\Resources\DnsZoneResource;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListDnsZones extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = DnsZoneResource::class;

    protected function headerActions(): array
    {
        $lines = fn (?string $v) => array_values(array_filter(preg_split('/[\s,]+/', (string) $v) ?: []));

        return [
            $this->createHeaderAction()?->label(__('Přidat zónu')),
            Action::make('secondary')
                ->label(__('Sekundární DNS'))
                ->icon('outline:server-stack')
                ->color('gray')
                ->visible(fn () => Gate::allows('admin') && (app(SystemInfo::class)->versions()['dns_local'] ?? false))
                ->modalDescription(__('Když DNS běží jen na tomto serveru, jeho výpadek vezme s sebou i weby a poštu všech domén. Sekundární server (jiného poskytovatele nebo druhý stroj) drží kopii zón: smí si je stáhnout (AXFR) a při každé změně dostane NOTIFY. U něj nastavte tento server jako primární.'))
                ->fillFormUsing(fn () => ['ips' => implode("\n", LocalDns::secondary()['ips']), 'nameservers' => implode("\n", LocalDns::secondary()['nameservers'])])
                ->form([
                    Textarea::make('ips')->label(__('IP adresy sekundárních serverů'))->rows(3)->placeholder("198.51.100.7\n2001:db8::53")
                        ->helperText(__('Jen tyto adresy si smí zóny stáhnout. Prázdné = žádný sekundární server.'))
                        ->rules([fn () => function (string $attr, mixed $value, \Closure $fail) use ($lines) {
                            foreach ($lines($value) as $ip) {
                                if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                                    $fail(__(':ip není IP adresa.', ['ip' => $ip]));
                                }
                            }
                        }]),
                    Textarea::make('nameservers')->label(__('Jejich jména (NS)'))->rows(2)->placeholder('ns2.provider.net')
                        ->helperText(__('Nové zóny dostanou tato jména jako nameservery vedle ns1 tohoto serveru. U existujících zón je doplňte do NS záznamů a u registrátora.')),
                ])
                ->action(function (array $data) use ($lines) {
                    abort_unless(Gate::allows('admin'), 403);
                    try {
                        app(LocalDns::class)->setSecondary($lines($data['ips'] ?? ''), $lines($data['nameservers'] ?? ''));
                        Toast::success(__('Sekundární DNS uloženo'));
                    } catch (DnsException $e) {
                        Toast::error(__('Nepodařilo se uložit'), $e->getMessage());
                    }
                }),
        ];
    }
}
