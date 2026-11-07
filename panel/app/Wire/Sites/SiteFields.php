<?php

namespace App\Wire\Sites;

use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Models\Client;
use App\Services\Agent\SystemInfo;
use App\Support\Domains;
use App\Support\Limits;
use App\Wire\Forms\Options;
use Illuminate\Validation\Rule;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;

/** Form fields of a site, shared by "new site" and "switch mode". */
final class SiteFields
{
    public const TLS_OPTIONS = [
        'auto' => "Let's Encrypt (veřejná doména)",
        'internal' => 'Interní certifikační autorita (lokální síť)',
    ];

    public static function domain(): TextInput
    {
        return TextInput::make('domain')
            ->label('Doména')
            ->placeholder('example.com')
            ->required()
            ->maxLength(253)
            ->dehydrateStateUsing(fn (?string $state) => Domains::normalize($state))
            ->rules(['max:253', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Domains::isValid($value)) {
                    $fail('Neplatná doména.');
                }
            }])
            ->liveOnBlur()
            ->afterStateUpdated(fn ($state, $set) => $set('tls', Domains::isInternal(Domains::normalize($state)) ? 'internal' : 'auto'))
            ->helperText('Veřejná doména musí DNS záznamem mířit na tento server. Pro vnitřní síť použijte např. intranet.firma.lan.');
    }

    public static function tls(): Radio
    {
        return Radio::make('tls')
            ->label('HTTPS certifikát')
            ->options(self::TLS_OPTIONS)
            ->descriptions([
                'auto' => 'Zdarma, obnovuje se sám. Doména musí být veřejná a mířit na server.',
                'internal' => 'Pro domény ve vnitřní síti (.lan, .local, .internal…) a testy. Počítače jednou nainstalují kořenový certifikát (SSL → Kořenový certifikát).',
            ])
            ->default('auto')
            ->required()
            ->rules([Rule::in(array_keys(self::TLS_OPTIONS))]);
    }

    /**
     * Mode, framework and version.
     *
     * @return list<mixed>
     */
    /** Which client owns the site (administrators only; empty = the administrator). */
    public static function client(): Select
    {
        return Select::make('client_id')
            ->label('Klient')
            ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id')->all())
            ->placeholder('žádný (web správce)')
            ->rules([Options::rule()])
            ->helperText('Klient web uvidí ve svém panelu a počítá se mu do limitů balíčku.');
    }

    public static function runtime(?string $currentMode = null): array
    {
        $system = app(SystemInfo::class);
        $client = Limits::forCurrentUser();
        $allowed = $client?->limit('allowed_modes');
        $modes = collect(SiteMode::cases())
            ->reject(fn (SiteMode $m) => $m->value === $currentMode)
            ->filter(fn (SiteMode $m) => ! is_array($allowed) || $allowed === [] || in_array($m->value, $allowed, true))
            ->values();
        $phpOptions = fn () => Limits::filterPhp($client, $system->phpVersionOptions());

        return [
            Radio::make('mode')
                ->label('Režim')
                ->options($modes->mapWithKeys(fn (SiteMode $m) => [$m->value => $m->getLabel()])->all())
                ->descriptions($modes->mapWithKeys(fn (SiteMode $m) => [$m->value => $m->getDescription()])->all())
                ->cards()
                ->default($modes->first()->value)
                ->required()
                ->rules([Rule::in($modes->map(fn (SiteMode $m) => $m->value)->all())])
                ->live()
                ->afterStateUpdated(fn ($state, $set) => $set('framework', array_key_first(Framework::optionsForMode($state)))),
            Select::make('framework')
                ->label('Typ aplikace')
                ->options(fn ($get) => Framework::optionsForMode($get('mode')))
                ->default(array_key_first(Framework::optionsForMode($modes->first()->value)))
                ->required()
                ->rules([fn ($get) => Rule::in(array_keys(Framework::optionsForMode($get('mode'))))])
                ->helperText('Určuje adresáře, .env, workery a postup deploye.'),
            Select::make('php_version')
                ->label('Verze PHP')
                ->options($phpOptions)
                ->default(fn () => array_key_exists((string) $system->defaultPhpVersion(), $phpOptions()) ? $system->defaultPhpVersion() : array_key_first($phpOptions()))
                ->visibleWhen('mode', SiteMode::Classic->value)
                ->requiredIf('mode', SiteMode::Classic->value)
                ->rules([fn () => Rule::in(array_keys($phpOptions()))]),
            Placeholder::make('worker_php')
                ->label('PHP')
                ->content(fn () => 'Worker režim běží na PHP '.($system->workerPhpVersion() ?? '?').' zabudovaném ve FrankenPHP. Laravel potřebuje Octane, Symfony verzi 7.4+.')
                ->visibleWhen('mode', SiteMode::Worker->value),
            Select::make('node_version')
                ->label('Verze Node')
                ->options(fn () => $system->nodeVersionOptions())
                ->default(fn () => array_key_first($system->nodeVersionOptions()))
                ->visibleWhen('mode', SiteMode::Node->value)
                ->requiredIf('mode', SiteMode::Node->value)
                ->rules([fn () => Rule::in(array_keys($system->nodeVersionOptions()))]),
        ];
    }
}
