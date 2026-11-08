<?php

namespace App\Wire\Resources;

use App\Actions\Dns\ManageDnsZones;
use App\Enums\DnsProvider;
use App\Livewire\Dns\CreateDnsZone;
use App\Livewire\Dns\ListDnsZones;
use App\Livewire\Dns\ViewDnsZone;
use App\Models\DnsZone;
use App\Models\Site;
use App\Services\Agent\SystemInfo;
use App\Services\Dns\DnsAdvisor;
use App\Services\Dns\DnsException;
use App\Support\Domains;
use App\Wire\Forms\Options;
use Illuminate\Validation\ValidationException;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Tags;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WireForms\Contracts\ProvidesResourceForm;
use NyonCode\WireForms\Forms\Form;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Table;

final class DnsZoneResource implements DescribesResource, ProvidesNavigation, ProvidesPages, ProvidesResourceForm, ProvidesResourceTable
{
    use DescribesRecords;

    public static function modelClass(): ?string
    {
        return DnsZone::class;
    }

    public static function key(): string
    {
        return 'dns';
    }

    public static function label(): string
    {
        return __('DNS zóna');
    }

    public static function pluralLabel(): string
    {
        return 'DNS';
    }

    public static function pages(): array
    {
        return ['index' => ListDnsZones::class, 'create' => CreateDnsZone::class, 'view' => ViewDnsZone::class];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make('DNS')->icon('outline:server')->group('network')->sort(10);
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(DnsZone::query()->with('site'))
            ->columns([
                TextColumn::make('domain')->label(__('Doména'))->searchable()->sortable()->weight('medium'),
                BadgeColumn::make('provider')->label(__('Kde je zóna'))
                    ->formatStateUsing(fn ($state) => $state instanceof DnsProvider ? $state->getLabel() : $state)
                    ->colors(['local' => 'info', 'cloudflare' => 'warning']),
                TextColumn::make('site_domain')->label(__('Web'))->state(fn (DnsZone $record) => $record->site?->domain ?? '—'),
                TextColumn::make('created_at')->label(__('Přidáno'))->date('j. n. Y'),
            ])
            ->recordUrl(fn (DnsZone $record) => self::url('view', $record))
            ->emptyState(__('Zatím žádná zóna'), __('Spravujte DNS na tomto serveru (PowerDNS), nebo připojte zónu z Cloudflare.'), 'outline:server')
            ->defaultSort('domain');
    }

    public function form(Form $form): Form
    {
        $local = (bool) (app(SystemInfo::class)->versions()['dns_local'] ?? false);

        return $form
            ->schema([
                Section::make('zone')->label(__('Zóna'))->schema([
                    TextInput::make('domain')->label(__('Doména'))->placeholder('example.com')->required()
                        ->datalist(Site::query()->pluck('domain')->all())
                        ->dehydrateStateUsing(fn (?string $state) => Domains::normalize($state))
                        ->rules([function (string $attribute, mixed $value, \Closure $fail) {
                            if (! Domains::isValid($value)) {
                                $fail(__('Neplatná doména.'));
                            } elseif (DnsZone::acrossClients()->where('domain', Domains::normalize($value))->exists()) {
                                $fail(__('Tato zóna už v panelu je.'));
                            }
                        }]),
                    Radio::make('provider')->rules([Options::rule()])->label(__('Kde je zóna'))
                        ->options(collect(DnsProvider::cases())->mapWithKeys(fn (DnsProvider $p) => [$p->value => $p->getLabel()])->all())
                        ->descriptions(collect(DnsProvider::cases())->mapWithKeys(fn (DnsProvider $p) => [$p->value => $p->getDescription()
                            .($p === DnsProvider::Local && ! $local ? __(' (Není nainstalováno: install.sh --dns=powerdns.)') : '')])->all())
                        ->cards()
                        ->default($local ? DnsProvider::Local->value : DnsProvider::Cloudflare->value)
                        ->required()
                        ->live(),
                    Tags::make('nameservers')->label(__('Nameservery'))
                        ->default(DnsAdvisor::defaultNameservers())
                        ->helperText(__('Tyto názvy zadáte u registrátora. Musí mířit na IP tohoto serveru (u registrátora jako „glue“ záznamy, je-li NS ve stejné doméně).'))
                        ->visibleWhen('provider', DnsProvider::Local->value),
                    TextInput::make('cloudflare_token')->label(__('Cloudflare API token'))->password()->revealable()
                        ->helperText(__('My Profile → API Tokens → Create Token → šablona „Edit zone DNS“, omezit na tuto zónu. Uloží se šifrovaně.'))
                        ->visibleWhen('provider', DnsProvider::Cloudflare->value)
                        ->requiredIf('provider', DnsProvider::Cloudflare->value),
                    Toggle::make('recommended')->label(__('Rovnou přidat doporučené záznamy (A/AAAA na tento server, CAA, SPF, DMARC)'))->default(true),
                ]),
            ])
            ->using(function (array $data): DnsZone {
                if ($data['provider'] === DnsProvider::Local->value && ! (app(SystemInfo::class)->versions()['dns_local'] ?? false)) {
                    throw ValidationException::withMessages(['data.provider' => __('Lokální DNS server není nainstalovaný.')]);
                }
                try {
                    return app(ManageDnsZones::class)->create($data);
                } catch (DnsException $e) {
                    throw ValidationException::withMessages(['data.domain' => $e->getMessage()]);
                }
            })
            ->successMessage(__('Zóna přidána'));
    }
}
