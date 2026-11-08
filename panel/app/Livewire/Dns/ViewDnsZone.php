<?php

namespace App\Livewire\Dns;

use App\Actions\Dns\ManageDnsZones;
use App\Actions\Mail\ManageMail;
use App\Enums\DnsProvider;
use App\Models\DnsZone;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsAdvisor;
use App\Services\Dns\DnsException;
use App\Support\Toast;
use App\Wire\Dns\RecordForm;
use App\Wire\Resources\DnsZoneResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireCore\Infolists\Components\TextEntry;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

class ViewDnsZone extends ViewPage
{
    protected static ?string $resource = DnsZoneResource::class;

    public function zone(): DnsZone
    {
        /** @var DnsZone */
        return $this->nativeRecord();
    }

    public function getTitle(): ?string
    {
        return $this->zone()->domain;
    }

    public function infolist(): Infolist
    {
        $zone = $this->zone();

        return Infolist::make()->record($zone)->livewireComponent($this)->schema([
            Section::make('zone')->label(__('Zóna'))->columns(3)->schema([
                TextEntry::make('provider')->label(__('Kde je zóna'))->state(fn () => $zone->provider->getLabel())->badge()->color('gray'),
                TextEntry::make('site')->label(__('Web'))->state(fn () => $zone->site?->domain ?? '—'),
                TextEntry::make('delegation')->label(__('U registrátora nastavte'))->state(fn () => $zone->provider === DnsProvider::Local
                    ? 'nameservery '.implode(', ', DnsAdvisor::defaultNameservers()).' (IP '.config('panel.public_ipv4').')'
                    : __('nameservery, které ukazuje Cloudflare u zóny')),
            ]),
        ]);
    }

    protected function headerActions(): array
    {
        $zone = $this->zone();

        return [
            Action::make('add')
                ->label(__('Přidat záznam'))
                ->icon('outline:plus')
                ->form(RecordForm::fields($zone->domain))
                ->action(function (array $data) {
                    $zone = $this->zone();
                    try {
                        $name = RecordForm::fqdn($data['name'] ?? '', $zone->domain);
                        $zone->api()->set($zone->domain, $name, $data['type'], (int) $data['ttl'], RecordForm::values($data['records']));
                        Toast::success(__('Uloženo: :name :type', ['name' => $name, 'type' => $data['type']]));
                        $this->dispatch('dns-changed');
                    } catch (DnsException $e) {
                        Toast::error(__('Uložení selhalo'), $e->getMessage());
                    }
                }),
            Action::make('recommended')
                ->label(__('Doplnit doporučené'))
                ->icon('outline:sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Přidá záznamy, které zóna ještě nemá: A/AAAA na tento server, CAA, SPF, DKIM (je-li klíč) a DMARC. Existující záznamy se nemění.'))
                ->action(function () {
                    $zone = $this->zone();
                    try {
                        $dkim = rescue(fn () => app(ManageMail::class)->dkim($zone->domain, 'get'), null, report: false);
                        $added = app(ManageDnsZones::class)->addRecommended($zone, isset($dkim['name']) ? $dkim : null);
                        Toast::success($added === [] ? __('Nic nechybělo') : __('Přidáno: ').implode(', ', $added));
                        $this->dispatch('dns-changed');
                    } catch (DnsException|AgentException $e) {
                        Toast::error(__('Nepodařilo se'), $e->getMessage());
                    }
                }),
            Action::make('check')
                ->label(__('Kontrola DNS'))
                ->icon('outline:shield-check')
                ->color('gray')
                ->modalHeading(fn () => __('Veřejné DNS pro ').$this->zone()->domain)
                ->modalSubmitActionLabel(__('Zavřít'))
                ->form(fn () => [Placeholder::make('result')->hiddenLabel()->html(view('livewire.dns.check', [
                    'checks' => DnsAdvisor::check($this->zone()->domain, $this->zone()->provider === DnsProvider::Local ? DnsAdvisor::defaultNameservers() : []),
                ])->render())])
                ->action(fn () => null),
            ActionGroup::make([
                Action::make('delete')
                    ->label(__('Odebrat zónu'))
                    ->icon('outline:trash')
                    ->color('danger')
                    ->modalHeading(__('Odebrat zónu z panelu?'))
                    ->modalDescription($zone->provider === DnsProvider::Cloudflare ? __('Zóna se jen odpojí od panelu, na Cloudflare zůstane beze změny.') : null)
                    ->form($zone->provider === DnsProvider::Local
                        ? [Toggle::make('remove')->label(__('Smazat zónu i z DNS serveru (doména přestane fungovat, pokud na server míří)'))]
                        : [])
                    ->action(function (array $data) {
                        try {
                            app(ManageDnsZones::class)->delete($this->zone(), (bool) ($data['remove'] ?? false));
                            Toast::success(__('Zóna odebrána'));
                        } catch (DnsException $e) {
                            Toast::error(__('Nepodařilo se'), $e->getMessage());
                        }
                    })
                    ->successRedirect(fn () => DnsZoneResource::url('index')),
            ])->label(__('Další'))->icon('outline:ellipsis-vertical'),
        ];
    }

    public function render(): View
    {
        return view('livewire.dns.view-zone', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => [],
            'infolist' => $this->infolist(),
            'zoneId' => $this->zone()->getKey(),
        ]);
    }
}
