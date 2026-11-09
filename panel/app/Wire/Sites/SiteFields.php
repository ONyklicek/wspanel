<?php

namespace App\Wire\Sites;

use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Models\Client;
use App\Services\Agent\SystemInfo;
use App\Support\Domains;
use App\Support\Lang;
use App\Support\Limits;
use App\Wire\Forms\Options;
use Illuminate\Validation\Rule;
use NyonCode\WireCore\Foundation\Schema\Callout;
use NyonCode\WireForms\Components\CheckboxList;
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
            ->label(__('Doména'))
            ->placeholder('example.com')
            ->required()
            ->maxLength(253)
            ->dehydrateStateUsing(fn (?string $state) => Domains::normalize($state))
            ->rules(['max:253', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Domains::isValid($value)) {
                    $fail(__('Neplatná doména.'));
                }
            }])
            ->liveOnBlur()
            ->afterStateUpdated(fn ($state, $set) => $set('tls', Domains::isInternal(Domains::normalize($state)) ? 'internal' : 'auto'))
            ->helperText(__('Veřejná doména musí DNS záznamem mířit na tento server. Pro vnitřní síť použijte např. intranet.firma.lan.'));
    }

    public static function tls(): Radio
    {
        return Radio::make('tls')
            ->label(__('HTTPS certifikát'))
            ->options(Lang::values(self::TLS_OPTIONS))
            ->descriptions([
                'auto' => __('Zdarma, obnovuje se sám. Doména musí být veřejná a mířit na server.'),
                'internal' => __('Pro domény ve vnitřní síti (.lan, .local, .internal…) a testy. Počítače jednou nainstalují kořenový certifikát (SSL → Kořenový certifikát).'),
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
            ->label(__('Klient'))
            ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id')->all())
            ->placeholder(__('žádný (web správce)'))
            ->rules([Options::rule()])
            ->helperText(__('Klient web uvidí ve svém panelu a počítá se mu do limitů balíčku.'));
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
                ->label(__('Režim'))
                ->options($modes->mapWithKeys(fn (SiteMode $m) => [$m->value => $m->getLabel()])->all())
                ->descriptions($modes->mapWithKeys(fn (SiteMode $m) => [$m->value => $m->getDescription()])->all())
                ->cards()
                ->default($modes->first()->value)
                ->required()
                ->rules([Rule::in($modes->map(fn (SiteMode $m) => $m->value)->all())])
                ->live()
                ->afterStateUpdated(fn ($state, $set) => $set('framework', array_key_first(Framework::optionsForMode($state)))),
            Select::make('framework')
                ->label(__('Typ aplikace'))
                ->options(fn ($get) => Framework::optionsForMode($get('mode')))
                ->default(array_key_first(Framework::optionsForMode($modes->first()->value)))
                ->required()
                ->rules([fn ($get) => Rule::in(array_keys(Framework::optionsForMode($get('mode'))))])
                ->helperText(__('Určuje adresáře, .env, workery a postup deploye.')),
            Select::make('php_version')
                ->label(__('Verze PHP'))
                ->options($phpOptions)
                ->default(fn () => array_key_exists((string) $system->defaultPhpVersion(), $phpOptions()) ? $system->defaultPhpVersion() : array_key_first($phpOptions()))
                ->visibleWhen('mode', SiteMode::Classic->value)
                ->requiredIf('mode', SiteMode::Classic->value)
                ->rules([fn () => Rule::in(array_keys($phpOptions()))]),
            Callout::make()->info()->heading(fn () => __('PHP :version ve FrankenPHP', ['version' => $system->workerPhpVersion() ?? '?']))
                ->content(__('Laravel potřebuje Octane, Symfony verzi 7.4+. Fronty, plánovač a další workery běží na systémovém PHP CLI.'))
                ->visible(fn ($get) => $get('mode') === SiteMode::Worker->value),
            Select::make('node_version')
                ->label(__('Verze Node'))
                ->options(fn () => $system->nodeVersionOptions())
                ->default(fn () => array_key_first($system->nodeVersionOptions()))
                ->visibleWhen('mode', SiteMode::Node->value)
                ->requiredIf('mode', SiteMode::Node->value)
                ->rules([fn () => Rule::in(array_keys($system->nodeVersionOptions()))]),
            Callout::make()->warning()->heading(__('Na serveru zatím není Python'))
                ->content(__('Doinstalujete ho v Server → Moduly (Python), pak tu půjde vybrat verzi.'))
                ->visible(fn ($get) => $get('mode') === SiteMode::Python->value && $system->pythonVersionOptions() === []),
            Select::make('python_version')
                ->label(__('Verze Pythonu'))
                ->options(fn () => $system->pythonVersionOptions())
                ->default(fn () => array_key_first($system->pythonVersionOptions()))
                ->visibleWhen('mode', SiteMode::Python->value)
                ->requiredIf('mode', SiteMode::Python->value)
                ->rules([fn () => Rule::in(array_keys($system->pythonVersionOptions()))]),
            self::pythonApp()
                ->visible(fn ($get) => $get('mode') === SiteMode::Python->value && (Framework::tryFrom((string) $get('framework'))?->loadsPythonApp() ?? false)),
        ];
    }

    /** The WSGI/ASGI target of a Django, Flask or FastAPI site. */
    public static function pythonApp(): TextInput
    {
        return TextInput::make('python_app')
            ->label(__('Aplikace (modul:objekt)'))
            ->placeholder(__('podle frameworku: Django ze souboru manage.py, Flask app:app, FastAPI main:app'))
            ->rules(['nullable', 'max:120', 'regex:/^[A-Za-z_][A-Za-z0-9_]*(\\.[A-Za-z_][A-Za-z0-9_]*){0,5}:[A-Za-z_][A-Za-z0-9_]*(\\.[A-Za-z_][A-Za-z0-9_]*){0,3}$/'])
            ->validationMessages(['regex' => __('Ve tvaru modul:objekt, např. mysite.wsgi:application nebo main:app.')])
            ->helperText(__('Co načte gunicorn (Django, Flask) nebo uvicorn (FastAPI). Prázdné = zvyklost frameworku.'));
    }

    /** Workers preselected for a new site: what nearly every app of the kind runs. */
    public const DEFAULT_WORKERS = ['laravel' => ['queue', 'scheduler'], 'symfony' => ['messenger', 'scheduler']];

    /**
     * Workers to set up with a new Laravel or Symfony site, so the queue and
     * the scheduler run from the first deploy without a second trip.
     */
    public static function workers(): CheckboxList
    {
        return CheckboxList::make('workers')
            ->label(__('Workery'))
            ->options(fn ($get) => SiteActions::workerOptions((string) $get('framework')))
            ->default(array_values(array_unique(array_merge(...array_values(self::DEFAULT_WORKERS)))))
            ->visible(fn ($get) => ! (SiteMode::tryFrom((string) $get('mode'))?->isApp() ?? false) && isset(self::DEFAULT_WORKERS[(string) $get('framework')]))
            ->rules([fn ($get) => function (string $attribute, mixed $value, \Closure $fail) {
                $chosen = (array) $value;
                if (in_array('horizon', $chosen, true) && in_array('queue', $chosen, true)) {
                    $fail(__('Horizon fronty zpracovává sám: vyberte buď Horizon, nebo frontu.'));
                }
            }])
            ->helperText(__('Běží jako služby pod uživatelem webu a spustí se po prvním deployi. Horizon a Pulse ingest zapnou Valkey webu. Další přidáte kdykoli u webu.'));
    }

    /**
     * The chosen workers the site's framework actually offers (the checkbox
     * state keeps a choice made under another framework).
     *
     * @return list<string>
     */
    public static function chosenWorkers(array $data): array
    {
        $offered = array_keys(SiteActions::workerOptions((string) ($data['framework'] ?? '')));

        return array_values(array_intersect((array) ($data['workers'] ?? []), $offered));
    }
}
