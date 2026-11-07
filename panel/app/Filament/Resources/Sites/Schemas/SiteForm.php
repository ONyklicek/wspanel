<?php

namespace App\Filament\Resources\Sites\Schemas;

use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Services\Agent\SystemInfo;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SiteForm
{
    /** Same rule as the agent's validator (punycode, lowercase, 2+ labels). */
    public const DOMAIN_REGEX = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Nový web')
                ->schema([
                    TextInput::make('domain')
                        ->label('Doména')
                        ->placeholder('example.com')
                        ->required()
                        ->maxLength(253)
                        ->dehydrateStateUsing(fn (?string $state) => self::normalizeDomain($state))
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            if (! preg_match(self::DOMAIN_REGEX, self::normalizeDomain($value))) {
                                $fail('Neplatná doména.');
                            }
                        })
                        ->unique(ignoreRecord: true)
                        ->helperText('Doména musí DNS záznamem mířit na tento server, jinak nepůjde vydat certifikát.'),
                ]),
            Section::make('Jak web poběží')
                ->columns(2)
                ->schema(self::runtimeFields()),
        ]);
    }

    /**
     * Mode, framework and version fields; shared by "create" and "switch mode".
     *
     * @return array<int, mixed>
     */
    public static function runtimeFields(?string $currentMode = null): array
    {
        $system = app(SystemInfo::class);
        $modes = collect(SiteMode::cases())
            ->reject(fn (SiteMode $m) => $m->value === $currentMode)
            ->mapWithKeys(fn (SiteMode $m) => [$m->value => $m->getLabel()]);

        return [
            Radio::make('mode')
                ->label('Režim')
                ->options($modes->all())
                ->descriptions(collect(SiteMode::cases())->mapWithKeys(fn (SiteMode $m) => [$m->value => $m->getDescription()])->all())
                ->default($modes->keys()->first())
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state) {
                    $set('framework', array_key_first(Framework::optionsForMode($state)));
                })
                ->columnSpanFull(),
            Select::make('framework')
                ->label('Framework')
                ->options(fn (Get $get) => Framework::optionsForMode($get('mode')))
                ->default(fn (Get $get) => array_key_first(Framework::optionsForMode($get('mode') ?? $modes->keys()->first())))
                ->required()
                ->in(fn (Get $get) => array_keys(Framework::optionsForMode($get('mode'))))
                ->selectablePlaceholder(false)
                ->helperText('Určuje adresářovou strukturu, .env, workery a spouštěcí příkaz.'),
            Select::make('php_version')
                ->label('Verze PHP')
                ->options(fn () => $system->phpVersionOptions())
                ->default(fn () => $system->defaultPhpVersion())
                ->visible(fn (Get $get) => $get('mode') === SiteMode::Classic->value)
                ->required(fn (Get $get) => $get('mode') === SiteMode::Classic->value),
            Text::make(fn () => 'Worker režim běží na PHP '.($system->workerPhpVersion() ?? '?').', které je zabudované ve FrankenPHP. Laravel potřebuje Octane (octane:install --server=frankenphp), Symfony verzi 7.4 nebo novější.')
                ->visible(fn (Get $get) => $get('mode') === SiteMode::Worker->value),
            Select::make('node_version')
                ->label('Verze Node')
                ->options(fn () => $system->nodeVersionOptions())
                ->default(fn () => array_key_first($system->nodeVersionOptions()))
                ->visible(fn (Get $get) => $get('mode') === SiteMode::Node->value)
                ->required(fn (Get $get) => $get('mode') === SiteMode::Node->value),
        ];
    }

    /**
     * Agent params for the chosen runtime: only the version field the mode uses.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function runtimeParams(array $data): array
    {
        $params = ['mode' => $data['mode'], 'framework' => $data['framework']];
        if ($data['mode'] === SiteMode::Classic->value) {
            $params['php_version'] = $data['php_version'];
        }
        if ($data['mode'] === SiteMode::Node->value) {
            $params['node_version'] = $data['node_version'];
        }

        return $params;
    }

    public static function normalizeDomain(?string $domain): string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim(explode('/', $domain)[0], '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $domain)) {
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
        }

        return $domain;
    }
}
