<?php

namespace App\Filament\Resources\DnsZones;

use App\Enums\DnsProvider;
use App\Filament\Resources\DnsZones\Pages\CreateDnsZone;
use App\Filament\Resources\DnsZones\Pages\ListDnsZones;
use App\Filament\Resources\DnsZones\Pages\ViewDnsZone;
use App\Filament\Resources\Sites\Schemas\SiteForm;
use App\Models\DnsZone;
use App\Models\Site;
use App\Services\Agent\SystemInfo;
use App\Services\Dns\DnsAdvisor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DnsZoneResource extends Resource
{
    protected static ?string $model = DnsZone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;

    protected static ?string $navigationLabel = 'DNS';

    protected static ?string $modelLabel = 'DNS zóna';

    protected static ?string $pluralModelLabel = 'DNS zóny';

    protected static ?string $recordTitleAttribute = 'domain';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        $localAvailable = (bool) (app(SystemInfo::class)->versions()['dns_local'] ?? false);

        return $schema->components([
            Section::make('Doména')->schema([
                TextInput::make('domain')
                    ->label('Doména')
                    ->placeholder('example.com')
                    ->datalist(fn () => Site::query()->pluck('domain')->all())
                    ->required()
                    ->dehydrateStateUsing(fn (?string $state) => SiteForm::normalizeDomain($state))
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (! preg_match(SiteForm::DOMAIN_REGEX, SiteForm::normalizeDomain($value))) {
                            $fail('Neplatná doména.');
                        }
                    })
                    ->unique(),
                Radio::make('provider')
                    ->label('Kde je zóna')
                    ->options(collect(DnsProvider::cases())->mapWithKeys(fn (DnsProvider $p) => [$p->value => $p->getLabel()])->all())
                    ->descriptions(collect(DnsProvider::cases())->mapWithKeys(fn (DnsProvider $p) => [$p->value => $p->getDescription()])->all())
                    ->disableOptionWhen(fn (string $value) => $value === DnsProvider::Local->value && ! $localAvailable)
                    ->default($localAvailable ? DnsProvider::Local->value : DnsProvider::Cloudflare->value)
                    ->required()
                    ->live(),
                TagsInput::make('nameservers')
                    ->label('Nameservery')
                    ->default(DnsAdvisor::defaultNameservers())
                    ->helperText('Tyto názvy zadáte u registrátora. Musí mířit na IP tohoto serveru (u registrátora jako „glue“ záznamy, je-li NS ve stejné doméně).')
                    ->visible(fn (Get $get) => $get('provider') === DnsProvider::Local->value),
                TextInput::make('cloudflare_token')
                    ->label('Cloudflare API token')
                    ->password()
                    ->revealable()
                    ->required(fn (Get $get) => $get('provider') === DnsProvider::Cloudflare->value)
                    ->helperText('My Profile → API Tokens → Create Token → šablona „Edit zone DNS“, omezit na tuto zónu. Uloží se šifrovaně.')
                    ->visible(fn (Get $get) => $get('provider') === DnsProvider::Cloudflare->value),
                Toggle::make('recommended')
                    ->label('Rovnou přidat doporučené záznamy (A/AAAA na tento server, CAA, SPF, DMARC)')
                    ->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('domain')
            ->recordUrl(fn (DnsZone $record) => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('domain')->label('Doména')->searchable()->sortable()->weight('medium'),
                TextColumn::make('provider')->label('Poskytovatel')->badge()->color(fn (DnsZone $record) => $record->provider === DnsProvider::Local ? 'info' : 'warning'),
                TextColumn::make('site.domain')->label('Web')->placeholder('—'),
                TextColumn::make('created_at')->label('Přidáno')->dateTime('j. n. Y'),
            ])
            ->emptyStateDescription('Zónu spravujte na tomto serveru (PowerDNS), nebo připojte existující zónu z Cloudflare.')
            ->recordActions([
                Action::make('open')->label('Záznamy')->icon(Heroicon::OutlinedListBullet)
                    ->url(fn (DnsZone $record) => static::getUrl('view', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDnsZones::route('/'),
            'create' => CreateDnsZone::route('/create'),
            'view' => ViewDnsZone::route('/{record}'),
        ];
    }
}
