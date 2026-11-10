<?php

namespace App\Livewire\Sites;

use App\Actions\Dns\ManageDnsZones;
use App\Actions\Dns\ManageSiteDns;
use App\Enums\DnsProvider;
use App\Livewire\Sites\Concerns\IsSiteTab;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsAdvisor;
use App\Services\Dns\DnsException;
use App\Support\Domains;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Forms\Options;
use App\Wire\Resources\DnsZoneResource;
use App\Wire\Resources\SiteResource;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** What the site needs in DNS, whether it is there, and filling it in (a tab of the site). */
class SiteDns extends ViewPage
{
    use IsSiteTab;

    protected static ?string $resource = SiteResource::class;

    protected function tabLabel(): string
    {
        return 'DNS';
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    public function site(): Site
    {
        /** @var Site */
        return $this->nativeRecord();
    }

    public function fillDns(): void
    {
        try {
            $out = app(ManageSiteDns::class)->fill($this->site());
            Toast::success($out['added'] === [] ? __('Nic nechybělo') : __('Přidáno: :list', ['list' => implode(', ', $out['added'])]),
                $out['kept'] === [] ? null : __('Ponecháno (míří jinam, změňte v zóně): :list', ['list' => implode(', ', $out['kept'])]));
        } catch (DnsException|AgentException $e) {
            Toast::error(__('DNS se nepodařilo doplnit'), $e->getMessage());
        }
    }

    protected function headerActions(): array
    {
        $local = Gate::allows('admin') && ManageSiteDns::localAvailable();

        return [
            Action::make('zone')
                ->label(__('Zóna v panelu'))
                ->icon('outline:server')
                ->color('gray')
                ->visible(fn () => ManageSiteDns::zoneFor($this->site()->domain) !== null)
                ->url(fn () => DnsZoneResource::url('view', ManageSiteDns::zoneFor($this->site()->domain))),
            Action::make('createZone')
                ->label(__('Spravovat DNS v panelu'))
                ->icon('outline:plus')
                ->visible(fn () => ManageSiteDns::zoneFor($this->site()->domain) === null)
                ->modalDescription(__('Založí zónu na tomto serveru, nebo připojí zónu z Cloudflare, a rovnou do ní zapíše záznamy webu (a pošty, je-li zřízená).'))
                ->fillFormUsing(fn () => ['provider' => $local ? DnsProvider::Local->value : DnsProvider::Cloudflare->value, 'domain' => ManageSiteDns::suggestedZone($this->site())])
                ->form([
                    TextInput::make('domain')->label(__('Zóna'))->required()
                        ->helperText(__('Doména webu bez www; pro shop.example.com obvykle example.com.'))
                        ->dehydrateStateUsing(fn (?string $state) => Domains::normalize($state))
                        ->rules([function (string $attribute, mixed $value, \Closure $fail) {
                            $site = $this->site()->domain;
                            if (! Domains::isValid($value) || ($site !== $value && ! str_ends_with($site, '.'.$value))) {
                                $fail(__('Zóna musí být doména webu, nebo doména nad ní.'));
                            }
                        }]),
                    Radio::make('provider')->label(__('Kde'))->required()->live()
                        ->options(array_filter([
                            DnsProvider::Local->value => $local ? __('Na tomto serveru (PowerDNS)') : null,
                            DnsProvider::Cloudflare->value => 'Cloudflare',
                        ]))
                        ->descriptions([
                            DnsProvider::Local->value => __('U registrátora pak nastavte nameservery :ns.', ['ns' => implode(', ', DnsAdvisor::defaultNameservers())]),
                            DnsProvider::Cloudflare->value => __('Zónu musíte mít v Cloudflare přidanou; panel do ní zapíše záznamy přes API.'),
                        ])
                        ->rules([Options::rule()]),
                    TextInput::make('cloudflare_token')->label(__('Cloudflare API token'))->password()->revealable()
                        ->helperText(__('My Profile → API Tokens → Create Token → šablona „Edit zone DNS“, omezit na tuto zónu. Uloží se šifrovaně.'))
                        ->visibleWhen('provider', DnsProvider::Cloudflare->value)
                        ->requiredIf('provider', DnsProvider::Cloudflare->value),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManageDnsZones::class)->create($data + ['nameservers' => DnsAdvisor::defaultNameservers()]);
                        $this->fillDns();
                    } catch (DnsException|AgentException $e) {
                        Toast::error(__('Zónu se nepodařilo přidat'), $e->getMessage());
                    }
                }),
        ];
    }

    /** When public DNS was last asked, shown beside the button. */
    public ?string $checkedAt = null;

    /** Asks the domain's nameservers again and says what came back. */
    public function recheck(): void
    {
        $records = app(ManageSiteDns::class)->status($this->site())['records'];
        $this->checkedAt = now()->format('H:i:s');
        $pending = collect($records)->whereIn('public', ['missing', 'differs']);
        $pending->isEmpty()
            ? Toast::success(__('DNS je nastavené'), __('Všechny záznamy webu už jsou v DNS domény.'))
            : Toast::warning(__('V DNS ještě chybí :n', ['n' => $pending->count()]), $pending->map(fn (array $r) => $r['type'].' '.$r['name'])->unique()->implode(', '));
    }

    public function render(): View
    {
        $status = app(ManageSiteDns::class)->status($this->site());

        return view('livewire.sites.dns', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($this->site()),
            'site' => $this->site(),
            'zone' => $status['zone'],
            'domain' => $status['domain'],
            'records' => $status['records'],
            'zoneError' => $status['error'],
            'nameservers' => DnsAdvisor::defaultNameservers(),
        ]);
    }
}
