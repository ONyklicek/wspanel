<?php

namespace App\Wire\Sites;

use App\Actions\Clients\ManageClients;
use App\Actions\Databases\ManageDatabases;
use App\Actions\Databases\ManageDatabaseServers;
use App\Actions\Sites\ChangeRuntime;
use App\Actions\Sites\CheckSiteHealth;
use App\Actions\Sites\ControlSite;
use App\Actions\Sites\DeleteSite;
use App\Actions\Sites\ImportSite;
use App\Actions\Sites\ManageEdge;
use App\Actions\Sites\ManagePhpSettings;
use App\Actions\Sites\ManageSiteMounts;
use App\Actions\Sites\ManageSsh;
use App\Actions\Sites\ManageStaging;
use App\Actions\Sites\ManageTls;
use App\Actions\Sites\ManageValkey;
use App\Actions\Sites\ManageWebRules;
use App\Actions\Sites\ManageWorkers;
use App\Actions\Sites\ManageXhprof;
use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Enums\SshAccess;
use App\Models\Client;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Services\Dns\DnsPrerequisites;
use App\Support\Domains;
use App\Support\Lang;
use App\Support\Limits;
use App\Support\Toast;
use App\Support\WebRules\RulesConverter;
use App\Wire\Forms\DnsGate;
use App\Wire\Forms\Options;
use App\Wire\Resources\DatabaseResource;
use App\Wire\Resources\SiteResource;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Foundation\Schema\Grid;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireCore\Foundation\Schema\Tab;
use NyonCode\WireCore\Foundation\Schema\Tabs;
use NyonCode\WireCore\Infolists\Components\TextEntry;
use NyonCode\WireForms\Components\CheckboxList;
use NyonCode\WireForms\Components\CodeEditor;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Repeater;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\Tags;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;

/**
 * Everything one can do with a site, shared by the list (row menu) and the
 * site's own pages (header). Logic lives in App\Actions; these are the buttons.
 */
final class SiteActions
{
    public const WORKER_LABELS = [
        'horizon' => 'Horizon (artisan horizon)',
        'queue' => 'Fronta (artisan queue:work)',
        'scheduler' => 'Plánovač',
        'messenger' => 'Messenger (messenger:consume async)',
        'reverb' => 'Reverb – WebSockets (artisan reverb:start)',
        'pulse' => 'Pulse – kontroly (artisan pulse:check)',
        'pulsework' => 'Pulse – ingest z Redisu (artisan pulse:work)',
        'ssr' => 'Inertia SSR – vykreslení na serveru (node bootstrap/ssr/ssr.js)',
        'celery' => 'Celery – worker (úlohy z fronty)',
        'celerybeat' => 'Celery beat (plánovač úloh)',
    ];

    /**
     * The workers a framework can run, labelled (the scheduler differs).
     *
     * @return array<string, string>
     */
    public static function workerOptions(string $framework): array
    {
        return collect(app(SystemInfo::class)->workerTypes($framework))
            ->mapWithKeys(fn (string $t) => [$t => $t === 'scheduler'
                ? ($framework === 'laravel' ? __('Plánovač (artisan schedule:work)') : __('Plánovač (messenger:consume scheduler_default)'))
                : __(self::WORKER_LABELS[$t] ?? $t)])
            ->all();
    }

    /** Runs an agent-backed step and turns its failure into a toast. */
    private static function attempt(string $failure, callable $fn): void
    {
        try {
            $fn();
        } catch (AgentException $e) {
            Toast::error($failure, $e->getMessage());
        }
    }

    public static function open(): Action
    {
        return Action::make('open')
            ->label(__('Otevřít web'))
            ->icon('outline:arrow-top-right-on-square')
            ->color('gray')
            ->url(fn (Site $record) => $record->url(), true);
    }

    public static function deploy(): Action
    {
        return Action::make('deploy')
            ->label(__('Deploy'))
            ->icon('outline:rocket-launch')
            ->color('primary')
            ->visible(fn (Site $record) => $record->isOperational() && $record->enabled)
            ->url(fn (Site $record) => SiteResource::url('deploy', $record));
    }

    public static function restart(): Action
    {
        return Action::make('restart')
            ->label(__('Restartovat'))
            ->icon('outline:arrow-path')
            ->color('gray')
            ->visible(fn (Site $record) => $record->isOperational() && $record->enabled)
            ->modalHeading(fn (Site $record) => "Restartovat {$record->domain}?")
            ->modalDescription(fn (Site $record) => $record->mode === SiteMode::Classic
                ? __('PHP-FPM se plynule znovu načte (rozpracované požadavky doběhnou) a vyprázdní se OPcache. Bez výpadku.')
                : __('Proces aplikace se zastaví a znovu spustí. Web bude pár sekund nedostupný.'))
            ->form([Toggle::make('workers')->label(__('Restartovat i workery (fronty, Horizon, Reverb…)'))->default(true)])
            ->modalSubmitActionLabel(__('Restartovat'))
            ->action(fn (Site $record, array $data) => self::attempt(__('Restart selhal'), function () use ($record, $data) {
                $done = app(ControlSite::class)->restart($record, (bool) ($data['workers'] ?? false));
                Toast::success(__(':domain restartován', ['domain' => $record->domain]), implode(', ', $done) ?: null);
            }));
    }

    public static function disable(): Action
    {
        return Action::make('disable')
            ->label(__('Vypnout web'))
            ->icon('outline:power')
            ->color('danger')
            ->visible(fn (Site $record) => $record->isOperational() && $record->enabled)
            ->requiresConfirmation()
            ->modalHeading(fn (Site $record) => "Vypnout {$record->domain}?")
            ->modalDescription(__('Návštěvníci uvidí stránku „Web je dočasně vypnutý“ (HTTP 503). Zastaví se proces aplikace, workery i naplánované úlohy a po restartu serveru se samy nespustí. Soubory, databáze ani nastavení se nemění.'))
            ->modalSubmitActionLabel(__('Vypnout'))
            ->action(fn (Site $record) => self::attempt(__('Vypnutí selhalo'), function () use ($record) {
                app(ControlSite::class)->disable($record);
                Toast::success(__(':domain je vypnutý', ['domain' => $record->domain]));
            }));
    }

    public static function enable(): Action
    {
        return Action::make('enable')
            ->label(__('Zapnout web'))
            ->icon('outline:play')
            ->color('success')
            ->visible(fn (Site $record) => $record->isOperational() && ! $record->enabled)
            ->action(fn (Site $record) => self::attempt(__('Zapnutí selhalo'), function () use ($record) {
                app(ControlSite::class)->enable($record);
                Toast::success(__(':domain je zapnutý', ['domain' => $record->domain]));
            }));
    }

    public static function switchMode(): Action
    {
        return Action::make('switchMode')
            ->label(__('Změnit režim'))
            ->icon('outline:arrows-right-left')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => __('Změnit režim webu :domain', ['domain' => $record->domain]))
            ->modalDescription(__('Nový backend se připraví vedle starého a proxy se přepne, až bude hotový. Když něco selže, web běží dál jako dosud.'))
            ->form(fn (Site $record) => SiteFields::runtime($record->mode->value))
            ->modalWidth('2xl')
            ->action(fn (Site $record, array $data) => self::attempt(__('Změna režimu selhala'), function () use ($record, $data) {
                app(ChangeRuntime::class)->switchMode($record, $data);
                Toast::success(__(':domain: režim změněn', ['domain' => $record->domain]), $record->runtimeLabel());
            }));
    }

    public static function setVersion(): Action
    {
        return Action::make('setVersion')
            ->label(__('Změnit verzi'))
            ->icon('outline:adjustments-horizontal')
            ->visible(fn (Site $record) => $record->isOperational() && $record->mode !== SiteMode::Worker)
            ->modalHeading(fn (Site $record) => __('Změnit verzi pro :domain', ['domain' => $record->domain]))
            ->modalDescription(fn (Site $record) => match ($record->mode) {
                SiteMode::Classic => __('Nový FPM pool naběhne vedle starého a web se na něj přepne. Bez výpadku.'),
                SiteMode::Python => __('Virtualenv se staví pro konkrétní verzi Pythonu, takže nová verze platí od příštího deploye. Do té doby web běží beze změny.'),
                default => __('Proces se restartuje s novou verzí (krátký výpadek).'),
            })
            ->form(fn (Site $record) => [
                Select::make('version')->rules([Options::rule()])
                    ->label(match ($record->mode) {
                        SiteMode::Classic => __('Verze PHP'),
                        SiteMode::Python => __('Verze Pythonu'),
                        default => __('Verze Node'),
                    })
                    ->options(collect(match ($record->mode) {
                        SiteMode::Classic => Limits::filterPhp(Limits::forCurrentUser(), app(SystemInfo::class)->phpVersionOptions()),
                        SiteMode::Python => app(SystemInfo::class)->pythonVersionOptions(),
                        default => app(SystemInfo::class)->nodeVersionOptions(),
                    })
                        ->except(match ($record->mode) {
                            SiteMode::Classic => $record->php_version,
                            SiteMode::Python => $record->python_version,
                            default => $record->node_version,
                        })
                        ->all())
                    ->required(),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Změna verze selhala'), function () use ($record, $data) {
                app(ChangeRuntime::class)->setVersion($record, $data['version']);
                Toast::success("{$record->domain}: {$record->runtimeLabel()}");
            }));
    }

    public static function pythonApp(): Action
    {
        return Action::make('pythonApp')
            ->label(__('Aplikace Pythonu'))
            ->icon('outline:command-line')
            ->visible(fn (Site $record) => $record->isOperational() && $record->mode === SiteMode::Python && $record->framework->loadsPythonApp())
            ->modalHeading(fn (Site $record) => __('Aplikace Pythonu: :domain', ['domain' => $record->domain]))
            ->modalDescription(__('Po uložení se aplikace restartuje s novým modulem.'))
            ->fillFormUsing(fn (Site $record) => ['python_app' => $record->python_app])
            ->form([SiteFields::pythonApp()])
            ->action(fn (Site $record, array $data) => self::attempt(__('Změna se nepovedla'), function () use ($record, $data) {
                $out = app(AgentClient::class)->call('site.python_app', ['domain' => $record->domain, 'app' => (string) ($data['python_app'] ?? '')]);
                $record->update(['python_app' => ($data['python_app'] ?? '') ?: null]);
                Toast::success("{$record->domain}: ".$out['app']);
            }));
    }

    public static function checkHealth(): Action
    {
        return Action::make('checkHealth')
            ->label(__('Ověřit odezvu'))
            ->icon('outline:heart')
            ->visible(fn (Site $record) => $record->isOperational())
            ->action(function (Site $record) {
                app(CheckSiteHealth::class)->handle($record);
                $record->health_ok
                    ? Toast::success(__(':domain odpovídá', ['domain' => $record->domain]), "HTTP {$record->health_status}")
                    : Toast::error(__(':domain neodpovídá', ['domain' => $record->domain]), (string) $record->health_error);
            });
    }

    public static function createDatabase(): Action
    {
        return Action::make('createDatabase')
            ->label(__('Nová databáze'))
            ->icon('outline:circle-stack')
            ->visible(fn (Site $record) => $record->isOperational() && app(SystemInfo::class)->dbEngines() !== [])
            ->modalDescription(__('Údaje (host, jméno, heslo) zapíše server rovnou do .env webu; heslo se nikde nezobrazuje.'))
            ->form(fn (Site $record) => [
                Select::make('engine')->rules([Options::rule()])->label(__('Engine'))->required()
                    ->options(collect(app(SystemInfo::class)->dbEngines())->mapWithKeys(fn ($e) => [$e->value => $e->getLabel()])->all())
                    ->default(app(SystemInfo::class)->dbEngines()[0]->value ?? null)->live(),
                TextInput::make('name')->label(__('Název (i uživatel)'))->required()->maxLength(32)
                    ->default(substr(preg_replace('/[^a-z0-9]+/', '_', $record->domain), 0, 32))
                    ->rules(['max:32', 'regex:/^[a-z][a-z0-9_]{0,31}$/'])
                    ->validationMessages(['regex' => __('Malá písmena, číslice a podtržítko, začíná písmenem.')]),
                Toggle::make('dedicated')->default(true)
                    ->label(__('Na vyhrazeném serveru webu'))
                    ->helperText(__('Web má vlastní databázový server: jiné weby se k němu nedostanou.'))
                    ->visible(fn ($get) => DatabaseResource::siteInstance($record->id, $get('engine')) !== null),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Databázi se nepodařilo vytvořit'), function () use ($record, $data) {
                $instance = ($data['dedicated'] ?? false) ? DatabaseResource::siteInstance($record->id, $data['engine'])['id'] ?? null : null;
                $name = app(ManageDatabases::class)->create($data['engine'], $data['name'], $record, $instance)['database']->name;
                Toast::success(__('Databáze :name vytvořena', ['name' => $name]), __('Údaje jsou v .env webu.'));
            }));
    }

    public static function ssh(): Action
    {
        return Action::make('ssh')
            ->label('SSH / SFTP')
            ->icon('outline:command-line')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "SSH / SFTP pro {$record->domain}")
            ->modalDescription(fn (Site $record) => __('Přihlášení jako :system_user (port ', ['system_user' => $record->system_user]).config('panel.ssh_port').__('), jen v rámci tohoto webu. Přesměrování portů je zakázané, opakovaně chybná přihlášení zablokuje fail2ban.'))
            ->modalWidth('2xl')
            ->fillFormUsing(fn (Site $record) => ['access' => $record->ssh_access->value, 'keys' => (string) $record->ssh_keys, 'password_mode' => 'keep'])
            ->form(fn (Site $record) => [
                Radio::make('access')->label(__('Přístup'))->options(SshAccess::class)->segmented()->required()->live(),
                Radio::make('password_mode')->rules([Options::rule()])
                    ->label(__('Heslo'))
                    ->options($record->ssh_password
                        ? ['keep' => __('Ponechat současné'), 'generate' => __('Vygenerovat nové'), 'set' => __('Zadat vlastní'), 'remove' => __('Zrušit (jen klíče)')]
                        : ['keep' => __('Bez hesla (jen klíče)'), 'generate' => __('Vygenerovat heslo'), 'set' => __('Zadat vlastní')])
                    ->descriptions(['generate' => __('Ukáže se jednou po uložení, panel hesla neukládá.')])
                    ->required()
                    ->live()
                    ->hiddenWhen('access', SshAccess::Off->value),
                TextInput::make('password')
                    ->label(__('Nové heslo'))
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    ->maxLength(128)
                    ->rules(['min:12', 'max:128', 'regex:/^[\x20-\x39\x3b-\x7e]+$/'])
                    ->validationMessages(['regex' => __('Jen tisknutelné ASCII znaky bez dvojtečky.')])
                    ->helperText(__('Alespoň 12 znaků.'))
                    ->visible(fn ($get) => $get('password_mode') === 'set' && $get('access') !== SshAccess::Off->value)
                    ->requiredIf('password_mode', 'set'),
                Textarea::make('keys')
                    ->label(__('Veřejné klíče'))
                    ->rows(4)
                    ->placeholder(__('ssh-ed25519 AAAA… jmeno@pocitac'))
                    ->helperText(__('Jeden na řádek. Klíč je bezpečnější než heslo.'))
                    ->hiddenWhen('access', SshAccess::Off->value)
                    ->required(fn ($get) => ! in_array($get('password_mode') ?? 'keep', ['generate', 'set'], true)
                        && ! ($record->ssh_password && ($get('password_mode') ?? 'keep') === 'keep'))
                    ->rules([function (string $attribute, mixed $value, Closure $fail) {
                        if (trim((string) $value) !== '' && ManageSsh::parseKeys((string) $value) === []) {
                            $fail(__('Nenašel jsem žádný platný klíč.'));
                        }
                    }])
                    ->validationMessages(['required' => __('Zadejte veřejný klíč, nebo nastavte heslo.')]),            ])
            ->action(function (Site $record, array $data) {
                self::attempt(__('Nastavení SSH selhalo'), function () use ($record, $data) {
                    $generated = app(ManageSsh::class)->handle($record, $data['access'], (string) ($data['keys'] ?? ''), (string) ($data['password_mode'] ?? 'keep'), $data['password'] ?? null);
                    $login = 'sftp -P '.config('panel.ssh_port').' '.$record->sshTarget();
                    $generated !== null
                        ? Toast::secret("SSH pro {$record->domain}: {$record->ssh_access->getLabel()}", __(':login
Heslo (zobrazí se jen teď): :generated', ['login' => $login, 'generated' => $generated]))
                        : Toast::success("SSH pro {$record->domain}: {$record->ssh_access->getLabel()}", $record->ssh_access === SshAccess::Off ? null : $login);
                });
            });
    }

    public static function tls(): Action
    {
        return Action::make('tls')
            ->label(__('HTTPS / certifikát'))
            ->icon('outline:lock-closed')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "HTTPS pro {$record->domain}")
            ->modalWidth('2xl')
            ->fillFormUsing(fn (Site $record) => ['mode' => $record->tls_mode ?? 'auto'])
            ->form(fn (Site $record) => [
                Radio::make('mode')->rules([Options::rule()])
                    ->label(__('Odkud je certifikát'))
                    ->options(Lang::values(ManageTls::MODES))
                    ->descriptions([
                        'auto' => __('Veřejná doména mířící na server. Vydává a obnovuje se sám, zdarma.'),
                        'internal' => __('Domény ve vnitřní síti a testy. Vydá ho server; počítače jednou nainstalují kořenový certifikát (SSL → Kořenový certifikát).'),
                        'custom' => __('Firemní CA nebo zakoupený certifikát. Obnovu hlídáte sami (panel upozorní 14 dní předem).'),
                    ])
                    ->required()
                    ->live(),
                // Switching to a public certificate: it is issued only for names pointing here.
                ...DnsGate::fields(fn (DnsPrerequisites $dns, callable $get) => $get('mode') === 'auto' && ($record->tls_mode ?? 'auto') !== 'auto'
                    ? $dns->pointHere([$record->domain, ...($record->edge_settings['aliases'] ?? []), ...($record->edge_settings['redirect_hosts'] ?? [])])
                    : []),
                Textarea::make('cert')
                    ->label(__('Certifikát (PEM, včetně mezilehlých)'))
                    ->placeholder('-----BEGIN CERTIFICATE-----')
                    ->rows(6)
                    ->visibleWhen('mode', 'custom')
                    ->requiredIf('mode', 'custom'),
                Textarea::make('key')
                    ->label(__('Privátní klíč (PEM, bez hesla)'))
                    ->placeholder('-----BEGIN PRIVATE KEY-----')
                    ->rows(5)
                    ->visibleWhen('mode', 'custom')
                    ->requiredIf('mode', 'custom'),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Certifikát se nepodařilo nastavit'), function () use ($record, $data) {
                $out = app(ManageTls::class)->set($record, $data['mode'], $data['cert'] ?? null, $data['key'] ?? null);
                $cert = $out['certificate'] ?? null;
                Toast::success("{$record->domain}: ".__(ManageTls::MODES[$data['mode']]), $cert
                    ? 'Vydal '.$cert['issuer'].__(', platí do ').Carbon::parse($cert['not_after'])->format('j. n. Y').'.'
                    : __('Certifikát se vydá během chvíle.'));
            }));
    }

    public static function addWorker(): Action
    {
        return Action::make('addWorker')
            ->label(__('Přidat worker'))
            ->icon('outline:cpu-chip')
            ->visible(fn (Site $record) => $record->isOperational()
                && $record->mode !== SiteMode::Node
                && app(SystemInfo::class)->workerTypes($record->framework->value) !== [])
            ->modalDescription(__('Worker běží jako systemd služba pod uživatelem webu se sandboxem a po pádu se sám restartuje. Před prvním deployem se jen zaregistruje.'))
            ->form(fn (Site $record) => [
                Select::make('worker')->rules([Options::rule()])
                    ->label(__('Typ'))
                    ->options(self::workerOptions($record->framework->value))
                    ->live()
                    ->required(),
                TextInput::make('app')
                    ->label(__('Aplikace Celery (-A)'))
                    ->placeholder($record->framework === Framework::Django ? __('balíček projektu (z Django projektu)') : 'tasks:celery')
                    ->helperText(__('Modul nebo modul:objekt s instancí Celery, např. tasks, myapp.celery nebo tasks:celery. Platí pro všechny Celery workery webu. U Djanga lze nechat prázdné: použije se balíček projektu (jeho celery.py).'))
                    ->rules(['nullable', 'max:120', 'regex:/^[A-Za-z_][A-Za-z0-9_]*(\\.[A-Za-z_][A-Za-z0-9_]*){0,5}(:[A-Za-z_][A-Za-z0-9_]*)?$/'])
                    ->visible(fn ($get) => in_array($get('worker'), ManageWorkers::CELERY, true))
                    ->required($record->framework !== Framework::Django), // Django: its project package
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Worker se nepodařilo přidat'), function () use ($record, $data) {
                $out = app(ManageWorkers::class)->create($record, $data['worker'], $data['app'] ?? null);
                $body = ($out['started'] ?? false) ? __('Běží.') : __('Zaregistrován, spustí se po prvním deployi.');
                $body .= match ($data['worker']) {
                    'reverb' => __(' Panel nastavil .env: klíče aplikace, publikování přímo na serveru a VITE_REVERB_* pro prohlížeč (po deployi je sestaví npm run build).'),
                    'horizon' => __(' Fronty běží ve Valkey webu (QUEUE_CONNECTION=redis).'),
                    'pulsework' => __(' Pulse sbírá data přes Valkey webu (PULSE_INGEST_DRIVER=redis).'),
                    'ssr' => ' '.__('Port :port, v .env INERTIA_SSR_URL; deploy sestaví i SSR bundle (npm run build:ssr).', ['port' => $out['port'] ?? '?']),
                    'celery', 'celerybeat' => ' '.__('Aplikace Celery: :app. Broker (Valkey webu nebo jiný) si nastavte v aplikaci.', ['app' => $out['app'] ?? '']),
                    default => '',
                };
                Toast::success(__('Worker přidán'), $body);
                if (($out['hints'] ?? []) !== []) {
                    Toast::warning(__('Aplikace musí převzít port od panelu'), implode("\n", array_map(fn (string $h) => self::ssrHint($h), $out['hints'])));
                }
            }));
    }

    /** What the app still needs for Inertia SSR, in Czech. */
    public static function ssrHint(string $hint): string
    {
        [$file] = explode(':', $hint, 2);

        return match (true) {
            str_starts_with($hint, 'config/inertia.php') => __(':file: ssr.url nastavte na env(\'INERTIA_SSR_URL\', \'http://127.0.0.1:13714\').', ['file' => $file]),
            str_contains($hint, 'no SSR entry') => __('Chybí resources/js/ssr.ts (vstup SSR serveru Inertia).'),
            default => __(':file: v createServer předejte port Number(process.env.INERTIA_SSR_PORT) || 13714.', ['file' => $file]),
        };
    }

    public static function delete(): Action
    {
        return Action::make('delete')
            ->label(__('Smazat web'))
            ->icon('outline:trash')
            ->color('danger')
            ->modalHeading(fn (Site $record) => "Smazat web {$record->domain}?")
            ->modalDescription(__('Odebere procesy, konfiguraci proxy a systémového uživatele. Soubory se přesunou do koše na serveru (/srv/sites/.trash), databáze zůstanou.'))
            ->modalIcon('outline:trash', 'danger')
            ->form(fn (Site $record) => [
                TextInput::make('confirm')
                    ->label(__('Pro potvrzení napište doménu'))
                    ->placeholder($record->domain)
                    ->required()
                    ->rules(['in:'.$record->domain])
                    ->validationMessages(['in' => __('Doména nesouhlasí.')]),
            ])
            ->modalSubmitActionLabel(__('Smazat web'))
            ->action(function (Site $record) {
                try {
                    app(DeleteSite::class)->handle($record);
                    Toast::success(__('Web :domain smazán', ['domain' => $record->domain]));
                } catch (AgentException $e) {
                    Toast::error(__('Smazání selhalo'), $e->getMessage());
                }
            })
            ->successRedirect(fn () => SiteResource::url('index'));
    }

    public static function phpSettings(): Action
    {
        $f = ManagePhpSettings::FIELDS;
        // Form keys cannot hold a dot (it would nest the state): date.timezone -> date__timezone.
        $k = fn (string $key) => str_replace('.', '__', $key);
        $size = fn (string $key) => TextInput::make($k($key))->label(__($f[$key][0]))->placeholder(__('výchozí ').__($f[$key][1]))
            ->rules(['nullable', 'regex:/^(-1|[0-9]{1,6}[KMGkmg]?)$/'])
            ->validationMessages(['regex' => __('Velikost jako 256M nebo 1G.')]);
        $int = fn (string $key, int $min, int $max) => TextInput::make($k($key))->label(__($f[$key][0]))->placeholder(__('výchozí ').__($f[$key][1]))
            ->integer()->rules(['nullable', 'integer', "between:{$min},{$max}"]);
        $choice = fn (string $key, array $options) => Select::make($k($key))->label(__($f[$key][0]))->options($options)
            ->placeholder(__('výchozí (').($options[$f[$key][1]] ?? $f[$key][1]).')')->rules([Options::rule()]);
        $onOff = ['on' => __('zapnuto'), 'off' => __('vypnuto')];

        return Action::make('phpSettings')
            ->label(__('PHP nastavení'))
            ->icon('outline:adjustments-horizontal')
            ->visible(fn (Site $record) => $record->isOperational() && ! $record->mode->isApp())
            ->modalHeading(fn (Site $record) => __('PHP nastavení: :domain', ['domain' => $record->domain]))
            ->modalDescription(fn (Site $record) => $record->mode === SiteMode::Worker
                ? __('Hodnoty php.ini pro FrankenPHP tohoto webu. Po uložení se aplikace restartuje.')
                : __('Hodnoty php.ini pro PHP-FPM pool tohoto webu (PHP :version). Po uložení se PHP plynule znovu načte. Prázdné pole = výchozí hodnota.', ['version' => $record->php_version]))
            ->modalWidth('3xl')
            ->fillFormUsing(fn (Site $record) => collect($record->php_settings ?? [])->mapWithKeys(fn ($v, $key) => [str_replace('.', '__', $key) => $v])->all())
            ->form(fn (Site $record) => [
                Grid::make()->columns(['default' => 1, 'md' => 2])->schema(array_values(array_filter([
                    $size('memory_limit'),
                    $size('upload_max_filesize'),
                    $size('post_max_size'),
                    $int('max_execution_time', 0, 3600),
                    $int('max_input_time', -1, 3600),
                    $int('max_input_vars', 100, 100000),
                    $int('session.gc_maxlifetime', 60, 2592000),
                    Select::make('date__timezone')->label($f['date.timezone'][0])->searchable()
                        ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->placeholder(__('výchozí (').config('app.timezone', 'UTC').')')->rules([Options::rule()]),
                    $choice('display_errors', $onOff)->helperText(__('Na produkci nechte vypnuté: chyby jsou v logu webu.')),
                    $choice('error_reporting', ['production' => __('produkční (bez deprecated)'), 'all' => __('vše (E_ALL)'), 'errors' => __('jen fatální chyby')]),
                    $choice('allow_url_fopen', $onOff),
                    $choice('short_open_tag', $onOff),
                    $record->mode === SiteMode::Worker ? $int('worker_threads', 1, 64)->helperText(__('Víc workerů = víc souběžných požadavků i víc paměti.')) : null,
                    $record->mode === SiteMode::Worker ? $int('max_requests', 0, 100000)->helperText(__('Chrání před únikem paměti; 0 = nikdy.')) : null,
                ]))),
            ])
            ->modalSubmitActionLabel(__('Uložit'))
            ->action(fn (Site $record, array $data) => self::attempt(__('PHP nastavení se nepodařilo uložit'), function () use ($record, $data) {
                $applied = app(ManagePhpSettings::class)->set($record, collect($data)->mapWithKeys(fn ($v, $key) => [str_replace('__', '.', $key) => $v])->all());
                Toast::success(__('PHP nastavení uloženo'), $applied === [] ? __('Všechny hodnoty jsou výchozí.') : count($applied).__(' vlastních hodnot.'));
            }));
    }

    public static function edge(): Action
    {
        $domainRule = function (string $attribute, mixed $value, Closure $fail) {
            foreach ((array) $value as $d) {
                if (! Domains::isValid(Domains::normalize((string) $d))) {
                    $fail("{$d} není platná doména.");
                }
            }
        };

        return Action::make('edge')
            ->label(__('Domény a přístup'))
            ->icon('outline:globe-europe-africa')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => __('Domény a přístup: :domain', ['domain' => $record->domain]))
            ->modalDescription(__('Zpracuje proxy před aplikací: další domény (každá dostane certifikát), přesměrování, ochranu heslem nebo IP adresou a hlavičky odpovědí.'))
            ->modalWidth('4xl')
            ->fillFormUsing(fn (Site $record) => ManageEdge::formData($record))
            ->form(fn (Site $record) => [
                Tabs::make()->schema([
                    Tab::make('domains')->label(__('Domény'))->icon('outline:globe-alt')->schema([
                        Tags::make('aliases')->label(__('Další domény webu'))
                            ->helperText(__('Web odpovídá i na nich (stejný obsah). Musí mířit na tento server.'))
                            ->rules([$domainRule])->live(),
                        Tags::make('redirect_hosts')->label(__('Domény přesměrované na ').$record->domain)
                            ->helperText(__('Např. www.').$record->domain.__(' nebo stará doména. Přesměrují trvale (308) i s cestou.'))
                            ->suggestions(str_starts_with($record->domain, 'www.') ? [substr($record->domain, 4)] : ['www.'.$record->domain])
                            ->rules([$domainRule])->live(),
                        // Only domains added now: each gets a certificate once it points here.
                        ...DnsGate::fields(fn (DnsPrerequisites $dns, callable $get) => $dns->pointHere(array_values(array_diff(
                            [...(array) $get('aliases'), ...(array) $get('redirect_hosts')],
                            [...($record->edge_settings['aliases'] ?? []), ...($record->edge_settings['redirect_hosts'] ?? [])],
                        )))),
                    ]),
                    Tab::make('redirects')->label(__('Přesměrování'))->icon('outline:arrow-uturn-right')->schema([
                        Repeater::make('redirects')->label(__('Pravidla přesměrování'))->table()->addButtonLabel(__('Přidat přesměrování'))->schema([
                            TextInput::make('from')->label(__('Z cesty'))->placeholder(__('/stara-stranka nebo /blog/*'))->required()
                                ->rules(['regex:~^/[A-Za-z0-9._\~!$&\'()+,;=:@%/-]{0,200}(/\*)?$~'])
                                ->validationMessages(['regex' => __('Cesta začíná lomítkem; /blog/* přesměruje celou sekci.')]),
                            TextInput::make('to')->label(__('Na'))->placeholder(__('/nova-stranka nebo https://…'))->required()
                                ->rules(['regex:~^(/[^\s"{}\\\\]{0,300}|https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[^\s"{}\\\\]{0,300})?)$~'])
                                ->validationMessages(['regex' => 'Cesta na tomto webu nebo adresa https://…']),
                            Select::make('code')->label(__('Typ'))->options([301 => __('301 trvalé'), 302 => __('302 dočasné'), 308 => __('308 trvalé (zachová metodu)'), 307 => __('307 dočasné (zachová metodu)')])
                                ->default(301)->required()->rules([Options::rule()]),
                        ]),
                    ]),
                    Tab::make('protection')->label(__('Ochrana'))->icon('outline:lock-closed')->schema([
                        Radio::make('auth_mode')->label(__('Heslo'))->options(['off' => __('Bez hesla'), 'site' => __('Celý web'), 'path' => __('Jen část webu')])
                            ->segmented()->default('off')->live()->required()->rules([Options::rule()]),
                        Grid::make()->columns(['default' => 1, 'md' => 3])->schema([
                            TextInput::make('auth_path')->label(__('Cesta'))->placeholder('/admin')->visibleWhen('auth_mode', 'path')->requiredIf('auth_mode', 'path')
                                ->rules(['nullable', 'regex:~^/[A-Za-z0-9._\-/]{0,200}$~']),
                            TextInput::make('auth_user')->label(__('Uživatel'))->hiddenWhen('auth_mode', 'off')->requiredIf('auth_mode', ['site', 'path'])
                                ->rules(['nullable', 'regex:/^[A-Za-z0-9._-]{1,32}$/']),
                            TextInput::make('auth_password')->label(__('Heslo'))->password()->revealable()->hiddenWhen('auth_mode', 'off')
                                ->rules(['nullable', 'min:8', 'max:128'])
                                ->helperText(fn () => ($record->edge_settings['auth_user'] ?? null) ? __('Prázdné = ponechat současné.') : null),
                        ]),
                        Textarea::make('allow_ips')->label(__('Povolené IP adresy'))->rows(3)->placeholder("203.0.113.7\n10.8.0.0/24")
                            ->helperText(__('Prázdné = kdokoli. Ostatní dostanou 403. Jedna adresa nebo síť na řádek.'))
                            ->rules([function (string $attribute, mixed $value, Closure $fail) {
                                foreach (ManageDatabaseServers::parseAllow((string) $value) as $ip) {
                                    if (! ManageDatabaseServers::validAddress($ip)) {
                                        $fail(__(':ip není IP adresa ani síť.', ['ip' => $ip]));
                                    }
                                }
                            }]),
                        TextInput::make('allow_path')->label(__('Platí pro cestu'))->placeholder(__('/ = celý web'))->default('/')
                            ->rules(['nullable', 'regex:~^/[A-Za-z0-9._\-/]{0,200}$~']),
                    ]),
                    Tab::make('headers')->label(__('Hlavičky'))->icon('outline:shield-check')->schema([
                        Toggle::make('hsts')->label(__('HSTS (prohlížeč pak web otevírá jen přes HTTPS, rok)')),
                        Toggle::make('security_headers')->label(__('Bezpečnostní hlavičky (nosniff, SAMEORIGIN, Referrer-Policy, Permissions-Policy)')),
                        Repeater::make('headers')->label(__('Vlastní hlavičky'))->table()->addButtonLabel(__('Přidat hlavičku'))->schema([
                            TextInput::make('name')->label(__('Název'))->placeholder(__('X-Robots-Tag'))->required()->rules(['regex:/^[A-Za-z0-9-]{1,64}$/']),
                            TextInput::make('value')->label(__('Hodnota'))->placeholder('noindex')->required()
                                ->rules(['regex:/^[ !#-\[\]-z|~]{1,500}$/'])->validationMessages(['regex' => __('Bez uvozovek, zpětných lomítek a složených závorek.')]),
                        ]),
                    ]),
                ]),
            ])
            ->modalSubmitActionLabel(__('Uložit'))
            ->action(fn (Site $record, array $data) => self::attempt(__('Nastavení se nepodařilo uložit'), function () use ($record, $data) {
                app(ManageEdge::class)->set($record, $data);
                Toast::success(__('Domény a přístup uloženy'), __('Nové domény dostanou certifikát během chvíle.'));
            }));
    }

    /**
     * What an application configured by hand needs (WordPress, an installer):
     * SFTP, database, Valkey and outgoing mail, read from the site's env file.
     */
    public static function credentials(): Action
    {
        return Action::make('credentials')
            ->label(__('Přístupové údaje'))
            ->icon('outline:key')
            ->color('gray')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => __('Přístupové údaje: :domain', ['domain' => $record->domain]))
            ->modalDescription(__('Pro aplikace, které se nastavují ručně (WordPress, instalátory). Údaje panel čte z .env webu, nikde jinde je neukládá.'))
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Zavřít'))
            ->infolist(function (Site $record) {
                try {
                    $c = app(ControlSite::class)->credentials($record);
                } catch (AgentException $e) {
                    return [TextEntry::make('error')->label(__('Chyba'))->state(fn () => $e->getMessage())];
                }
                $entry = fn (string $key, string $label, ?string $value) => TextEntry::make($key)->label($label)->state(fn () => $value ?? '—')->copyable($value !== null);
                $db = $c['database'] ?? null;
                $sections = [
                    Section::make('sftp')->label('SFTP / SSH')->columns(2)->schema([
                        $entry('sftp_host', 'Server', (string) (config('panel.ssh_host') ?: request()->getHost())),
                        $entry('sftp_port', 'Port', (string) config('panel.ssh_port', 22)),
                        $entry('sftp_user', __('Uživatel'), $record->system_user),
                        $entry('sftp_auth', __('Přihlášení'), $record->ssh_access === SshAccess::Off ? __('vypnuto (Nastavení → Přístup)') : ($record->ssh_password ? __('klíč nebo heslo') : __('jen klíč'))),
                    ]),
                ];
                $sections[] = Section::make('db')->label(__('Databáze'))->columns(2)->schema($db === null
                    ? [TextEntry::make('db_none')->label('')->state(fn () => __('Web zatím nemá databázi v .env (Další → Nová databáze ji vytvoří a zapíše).'))]
                    : [
                        $entry('db_host', __('Server (host)'), ($db['host'] ?? '127.0.0.1').(($db['port'] ?? '') !== '' ? ':'.$db['port'] : '')),
                        $entry('db_name', __('Databáze'), $db['database'] ?? null),
                        $entry('db_user', __('Uživatel'), $db['username'] ?? null),
                        $entry('db_password', __('Heslo'), $db['password'] ?? null),
                    ]);
                if (isset($c['valkey'])) {
                    $sections[] = Section::make('valkey')->label(__('Valkey (Redis)'))->columns(2)->schema(collect($c['valkey'])
                        ->map(fn ($v, $k) => $entry('valkey_'.strtolower($k), $k, (string) $v))->values()->all());
                }
                if (isset($c['mail'])) {
                    $sections[] = Section::make('mail')->label(__('Odchozí pošta (SMTP)'))->columns(2)->schema(collect($c['mail'])
                        ->map(fn ($v, $k) => $entry('mail_'.strtolower($k), $k, (string) $v))->values()->all());
                }

                return $sections;
            });
    }

    /** The site's own web-server rules, with a converter from nginx / .htaccess. */
    public static function webRules(): Action
    {
        return Action::make('webRules')
            ->label(__('Pravidla webserveru'))
            ->icon('outline:code-bracket')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "Pravidla webserveru: {$record->domain}")
            ->modalDescription(__('Vlastní pravidla v syntaxi Caddy (přesměrování, přepisy URL, hlavičky, cache, komprese, limit velikosti požadavku). Platí v pořadí, jak jsou napsaná, za přístupovými pravidly panelu a před aplikací. Před nasazením je zkontroluje server; chybná se nepoužijí.'))
            ->modalWidth('4xl')
            ->fillFormUsing(fn (Site $record) => ['rules' => $record->web_rules ?? '', 'import' => ''])
            ->form([
                CodeEditor::make('rules')->label(__('Pravidla (Caddy)'))->language('caddyfile')->withLineNumbers()->minHeight(260)->maxLength(16384)
                    ->rules(['nullable', 'string', 'max:16384'])
                    ->helperText(__('Např.: header /assets/* Cache-Control "public, max-age=31536000" · redir /stary /novy 301 · encode gzip zstd')),
                Textarea::make('import')->label(__('Převést z nginx nebo .htaccess'))->rows(4)
                    ->placeholder("location ~* \\.(css|js)$ { expires 30d; }\nRewriteRule ^blog/(.*)$ /novinky/$1 [R=301,L]")
                    ->helperText(__('Vložte pravidla a stiskněte Převést: doplní se dolů do pravidel. Co převést nejde, zůstane jako komentář.'))
                    ->hintAction(Action::make('convert')->label(__('Převést'))->icon('outline:arrows-right-left')
                        ->action(function ($get, $set) {
                            $converted = app(RulesConverter::class)->convert((string) $get('import'));
                            $set('rules', trim(trim((string) $get('rules'))."\n".$converted));
                            $set('import', '');
                        })),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Pravidla se nepodařilo uložit'), function () use ($record, $data) {
                app(ManageWebRules::class)->set($record, (string) ($data['rules'] ?? ''));
                Toast::success(filled($data['rules'] ?? null) ? __('Pravidla platí') : __('Pravidla odebrána'));
            }));
    }

    /** The site's own Valkey (Redis-compatible) server. */
    public static function valkey(): Action
    {
        return Action::make('valkey')
            ->label(__('Valkey (Redis)'))
            ->icon('outline:bolt')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "Valkey pro {$record->domain}")
            ->modalDescription(__('Vlastní Valkey server webu (kompatibilní s Redisem): běží pod uživatelem webu na 127.0.0.1 a vlastním portu, chráněný heslem, takže ho žádný jiný web nepoužije. Host, port a heslo se zapíšou do .env (REDIS_HOST, REDIS_PORT, REDIS_PASSWORD).'))
            ->fillFormUsing(fn (Site $record) => [
                'enabled' => $record->valkey !== null,
                'maxmemory_mb' => $record->valkey['maxmemory_mb'] ?? 64,
                'policy' => $record->valkey['policy'] ?? 'allkeys-lru',
                'persist' => $record->valkey['persist'] ?? false,
                'use' => $record->valkey['use'] ?? [],
            ])
            // The record from the form's closure: a field's own `fn (Site $record)`
            // is handed an empty model when the dialog opens from the Settings tab.
            ->form(fn (Site $record) => [
                Toggle::make('enabled')->label(__('Zapnout Valkey pro tento web'))->live(),
                TextInput::make('maxmemory_mb')->label(__('Paměť (MB)'))->integer()->minValue(8)->maxValue(65536)
                    ->rules(['integer', 'between:8,65536'])->visibleWhen('enabled', true)->requiredIf('enabled', true),
                Select::make('policy')->label(__('Když se paměť zaplní'))->options(Lang::values(ManageValkey::POLICIES))->rules([Options::rule()])
                    ->visibleWhen('enabled', true)->requiredIf('enabled', true),
                Toggle::make('persist')->label(__('Ukládat data na disk (přežijí restart)'))->visibleWhen('enabled', true)
                    ->helperText(__('Pro fronty a session ano, pro čistou cache není třeba.')),
                CheckboxList::make('use')->label(__('Aplikace v něm má'))
                    ->options(ManageValkey::uses($record))
                    ->visible(fn ($get) => $get('enabled') && ManageValkey::uses($record) !== [])
                    ->helperText(__('Panel nastaví ovladače v .env; nezaškrtnuté vrátí na výchozí (database). Fronty a session nemají být v paměti, která maže klíče: zvolte „nic nemaže“.')),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Valkey se nepodařilo nastavit'), function () use ($record, $data) {
                app(ManageValkey::class)->set($record, $data);
                Toast::success($data['enabled'] ? __('Valkey běží') : __('Valkey vypnut'), $data['enabled'] ? __('Připojení je v .env webu.') : null);
            }));
    }

    /** Moving a site in: a cPanel backup or an archive of its files. */
    public static function import(): Action
    {
        return Action::make('import')
            ->label(__('Importovat web (cPanel, archiv)'))
            ->icon('outline:arrow-down-on-square')
            ->visible(fn (Site $record) => $record->isOperational() && $record->framework->value === 'php')
            ->modalDescription(__('Nahrajte přes SFTP do složky tmp webu zálohu z cPanelu (cpmove-….tar.gz) nebo archiv souborů webu (.tar.gz). Soubory se rozbalí do webu, každý dump databáze do nové databáze webu (MariaDB). WordPress dostane v wp-config.php přístup k nové databázi.'))
            ->form([
                TextInput::make('file')->label(__('Soubor v tmp/'))->placeholder('cpmove-jan.tar.gz')->required()
                    ->rules(['regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(tar\.gz|tgz)$/'])->validationMessages(['regex' => __('Název souboru .tar.gz ve složce tmp webu.')]),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Import se nepodařil'), function () use ($record, $data) {
                $out = app(ImportSite::class)->import($record, $data['file']);
                Toast::success(__('Web importován'), ($out['databases'] ?? []) ? __('Databáze: ').implode(', ', $out['databases']).(isset($out['wp_config']) ? '; wp-config.php upraven.' : '') : __('Bez databází.'));
            }));
    }

    /** A staging copy of the site (staging.<domain>), and back to production. */
    public static function staging(): Action
    {
        return Action::make('staging')
            ->label(fn (Site $record) => $record->staging_of ? __('Nasadit staging na produkci') : __('Vytvořit staging'))
            ->icon('outline:beaker')
            ->visible(fn (Site $record) => $record->isOperational() && ($record->staging_of !== null || ! Site::query()->where('staging_of', $record->id)->exists()))
            ->modalHeading(fn (Site $record) => $record->staging_of ? 'Staging '.$record->domain.' → produkce' : 'Staging pro '.$record->domain)
            ->modalDescription(fn (Site $record) => $record->staging_of
                ? __('Nejdřív se zazálohuje produkce (soubory i databáze). Soubory se nasadí jako běžný deploy: když nová verze neodpoví, vrátí se předchozí. Databáze produkce se přepíše databází stagingu.')
                : 'Kopie webu na '.ManageStaging::stagingDomain($record).__(': aktuální verze, sdílené soubory (bez .env) a kopie databází. Pošta stagingu se vždy zachytává v Mailpitu, vyhledávače ho neindexují. Potřebuje DNS záznam pro tu adresu.'))
            ->form(fn (Site $record) => $record->staging_of ? [
                Toggle::make('files')->label(__('Soubory (aktuální verze aplikace)'))->default(true),
                Toggle::make('databases')->label(__('Databáze (přepíše data produkce)'))->default(false),
            ] : [])
            ->action(fn (Site $record, array $data) => self::attempt(__('Staging se nepodařil'), function () use ($record, $data) {
                if ($record->staging_of) {
                    app(ManageStaging::class)->push($record, (bool) ($data['files'] ?? false), (bool) ($data['databases'] ?? false));
                    Toast::success(__('Staging je na produkci'), __('Záloha produkce z doby před nasazením je v Zálohách.'));
                } else {
                    $staging = app(ManageStaging::class)->create($record);
                    Toast::success(__('Staging běží'), $staging->url());
                }
            }));
    }

    private static function homeOf(Site $site): string
    {
        return rtrim((string) ($site->home ?: '/srv/sites/'.$site->domain), '/');
    }

    /** Connects a network disk (SMB, FTP, SFTP) into the site's mounts/ (administrators). */
    public static function mountAdd(): Action
    {
        return Action::make('mountAdd')
            ->label(__('Připojit disk'))
            ->icon('outline:server-stack')
            ->visible(fn (Site $record) => Gate::allows('admin') && $record->isOperational())
            ->modalHeading(fn (Site $record) => __('Síťový disk pro :domain', ['domain' => $record->domain]))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel(__('Připojit'))
            ->modalDescription(__('Disk se připojí do složky mounts/<název> v adresáři webu. Soubory na něm patří uživateli webu: aplikace, workery i SFTP je čtou a zapisují jako vlastní. Do záloh webu se nezahrnuje.'))
            ->form(fn (Site $record) => [
                Radio::make('type')->label(__('Druh'))->options(Lang::values(ManageSiteMounts::TYPES))->default('smb')->required()->inline()->live()
                    ->rules([Options::rule(ManageSiteMounts::TYPES)]),
                Grid::make()->columns(['default' => 1, 'sm' => 3])->schema([
                    TextInput::make('host')->label(__('Server'))->placeholder('nas.firma.lan')->required()->maxLength(253)->columnSpan(2)
                        ->rules(['regex:/^[A-Za-z0-9][A-Za-z0-9.\-]{0,252}$|^\[?[0-9A-Fa-f:.]{2,45}\]?$/'])
                        ->validationMessages(['regex' => __('Název serveru nebo IP adresa.')]),
                    TextInput::make('port')->label(__('Port'))->integer()->placeholder(__('výchozí'))->rules(['nullable', 'integer', 'between:1,65535']),
                ]),
                Grid::make()->columns(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('share')->label(__('Sdílená složka'))->placeholder('Data')->maxLength(80)
                        ->visibleWhen('type', 'smb')->requiredIf('type', 'smb'),
                    TextInput::make('path')->label(__('Složka na disku'))->placeholder('web/uploads')->maxLength(500)
                        ->helperText(__('Prázdné = celý disk.')),
                ]),
                Grid::make()->columns(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('user')->label(__('Uživatel'))->required()->maxLength(128)->autocomplete('off'),
                    TextInput::make('password')->label(__('Heslo'))->password()->revealable()->maxLength(256)->autocomplete('new-password')
                        ->helperText(__('Panel ho neukládá, zná ho jen server.')),
                ]),
                Grid::make()->columns(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('domain')->label(__('Doména / pracovní skupina'))->placeholder('FIRMA')->maxLength(128)->visibleWhen('type', 'smb'),
                    Select::make('version')->label(__('Verze SMB'))->options(Lang::values(ManageSiteMounts::SMB_VERSIONS))->default('3.0')
                        ->visibleWhen('type', 'smb')->rules([Options::rule(ManageSiteMounts::SMB_VERSIONS)])->live(),
                    Toggle::make('encrypt')->label(__('Šifrovat přenos (SMB 3)'))->default(true)->visibleWhen('type', 'smb')
                        ->helperText(__('Nutné, když disk není ve stejné síti. Starší NAS šifrování neumí: pak vypněte, nebo připojte přes SFTP.'))
                        ->rules([fn ($get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                            if ($value && $get('type') === 'smb' && ! str_starts_with((string) $get('version'), '3')) {
                                $fail(__('Šifrování umí jen SMB 3.'));
                            }
                        }]),
                    Select::make('tls')->label(__('Šifrování'))->options(Lang::values(ManageSiteMounts::FTP_TLS))->default('explicit')
                        ->visibleWhen('type', 'ftp')->rules([Options::rule(ManageSiteMounts::FTP_TLS)]),
                ]),
                Grid::make()->columns(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('name')->label(__('Název složky'))->default('nas')->required()->maxLength(32)
                        ->rules(['regex:/^[a-z0-9][a-z0-9_-]{0,31}$/'])->validationMessages(['regex' => __('Malá písmena, číslice, - a _.')])
                        ->helperText(fn ($get) => __('Cesta pro aplikaci: :path', ['path' => self::homeOf($record).'/mounts/'.($get('name') ?: 'nas')])),
                    Toggle::make('read_only')->label(__('Jen pro čtení')),
                ]),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Disk se nepodařilo připojit'), function () use ($record, $data) {
                $mount = collect($data)->only(['name', 'type', 'host', 'share', 'path', 'user', 'domain', 'version', 'tls'])
                    ->map(fn ($v) => is_string($v) ? trim($v) : $v)->filter(fn ($v) => $v !== null && $v !== '')->all()
                    + ['port' => (int) ($data['port'] ?? 0), 'read_only' => (bool) ($data['read_only'] ?? false),
                        'encrypt' => ($data['type'] ?? '') === 'smb' && (bool) ($data['encrypt'] ?? false)];
                $out = app(ManageSiteMounts::class)->add($record, $mount, (string) ($data['password'] ?? ''));
                match (true) {
                    $out === null => Toast::success(__('Disk se připojuje'), __('Instalují se nástroje pro připojení, stav uvidíte za chvíli v Nastavení.')),
                    ($out['not_visible_to'] ?? []) !== [] => Toast::warning(__('Disk připojen, některé procesy ho ještě nevidí'),
                        __('Běžely už před připojením: :units. Do disku zapíšou až po restartu (do té doby zápis selže, nic se neuloží jinam).', ['units' => implode(', ', $out['not_visible_to'])])),
                    default => Toast::success(__('Disk připojen'), self::homeOf($record).'/mounts/'.$mount['name']
                        .(($out['host_keys'] ?? []) !== [] ? ' · '.__('klíč serveru (ověřte u správce serveru): :keys', ['keys' => implode(', ', $out['host_keys'])]) : '')),
                };
            }));
    }

    public static function mountRemove(): Action
    {
        return Action::make('mountRemove')
            ->label(__('Odpojit disk'))
            ->icon('outline:x-circle')
            ->color('gray')
            ->visible(fn (Site $record) => Gate::allows('admin') && app(ManageSiteMounts::class)->list($record) !== [])
            ->modalDescription(__('Disk se odpojí a panel zapomene přihlašovací údaje. Soubory na disku zůstanou.'))
            ->form(fn (Site $record) => [
                Select::make('name')->label(__('Disk'))->required()
                    ->options(collect(app(ManageSiteMounts::class)->list($record))->mapWithKeys(fn ($m) => [$m['mount']['name'] => $m['mount']['name'].' · '.$m['mount']['host']])->all())
                    ->rules([Options::rule()]),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Disk se nepodařilo odpojit'), function () use ($record, $data) {
                app(ManageSiteMounts::class)->remove($record, $data['name']);
                Toast::success(__('Disk odpojen'));
            }));
    }

    public static function mountRemount(): Action
    {
        return Action::make('mountRemount')
            ->label(__('Připojit znovu'))
            ->icon('outline:arrow-path')
            ->color('gray')
            ->visible(fn (Site $record) => Gate::allows('admin') && app(ManageSiteMounts::class)->list($record) !== [])
            ->form(fn (Site $record) => [
                Select::make('name')->label(__('Disk'))->required()
                    ->options(collect(app(ManageSiteMounts::class)->list($record))->mapWithKeys(fn ($m) => [$m['mount']['name'] => $m['mount']['name'].' · '.$m['mount']['host']])->all())
                    ->rules([Options::rule()]),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Disk se nepřipojil'), function () use ($record, $data) {
                app(ManageSiteMounts::class)->remount($record, $data['name']);
                Toast::success(__('Disk připojen'));
            }));
    }

    /** XHProf profiling into the site's own XHGui (classic sites). */
    public static function xhprof(): Action
    {
        return Action::make('xhprof')
            ->label(__('Profilování (XHProf)'))
            ->icon('outline:chart-bar')
            ->visible(fn (Site $record) => $record->isOperational() && $record->mode === SiteMode::Classic && app(ManageXhprof::class)->installed())
            ->modalHeading(fn (Site $record) => __('Profilování :domain', ['domain' => $record->domain]))
            ->modalDescription(__('XHProf změří, kolik času a paměti zabraly jednotlivé funkce, a výsledky ukáže XHGui. Profily zůstávají v adresáři webu a vidí je jen ten, kdo vidí web. Profilování zpomaluje měřené požadavky, na produkci měřte jen část.'))
            ->fillFormUsing(fn (Site $record) => ['enabled' => $record->xhprof !== null, 'ratio' => $record->xhprof['ratio'] ?? 100])
            ->form([
                Toggle::make('enabled')->label(__('Profilovat požadavky'))->live(),
                TextInput::make('ratio')->label(__('Kolik požadavků měřit (%)'))->integer()->visibleWhen('enabled', true)->requiredIf('enabled', true)
                    ->rules(['nullable', 'integer', 'between:1,100']),
            ])
            ->action(fn (Site $record, array $data) => self::attempt(__('Profilování se nepodařilo nastavit'), function () use ($record, $data) {
                app(ManageXhprof::class)->set($record, $data['enabled'] ? (int) $data['ratio'] : null);
                Toast::success($data['enabled'] ? __('Profilování zapnuto') : __('Profilování vypnuto'), $data['enabled']
                    ? __('Otevřete web, ať vzniknou první měření. Výsledky: tlačítko Otevřít XHGui v Nastavení → Výkon a ladění, na přehledu webu nebo v menu Další.') : null);
            }));
    }

    /** The site's XHGui (profiles), signed in through the panel; while profiling is on. */
    public static function openXhgui(): Action
    {
        return Action::make('openXhgui')
            ->label(__('Otevřít XHGui'))
            ->icon('outline:chart-bar')
            ->color('gray')
            ->visible(fn (Site $record) => $record->xhprof !== null && $record->mode === SiteMode::Classic)
            ->url(fn (Site $record) => ManageXhprof::viewerUrl($record), openInNewTab: true);
    }

    public static function valkeyFlush(): Action
    {
        return Action::make('valkeyFlush')
            ->label(__('Vyprázdnit Valkey'))
            ->icon('outline:trash')
            ->color('gray')
            ->modalIcon('outline:trash', 'danger')
            ->visible(fn (Site $record) => $record->valkey !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (Site $record) => __('Smazat všechny klíče ve Valkey webu :domain?', ['domain' => $record->domain]))
            ->action(fn (Site $record) => self::attempt(__('Valkey se nepodařilo vyprázdnit'), function () use ($record) {
                app(ManageValkey::class)->flush($record);
                Toast::success(__('Valkey vyprázdněn'));
            }));
    }

    public static function valkeyRestart(): Action
    {
        return Action::make('valkeyRestart')
            ->label(__('Restartovat Valkey'))
            ->icon('outline:arrow-path')
            ->color('gray')
            ->visible(fn (Site $record) => $record->valkey !== null)
            ->action(fn (Site $record) => self::attempt(__('Valkey se nepodařilo restartovat'), function () use ($record) {
                app(ManageValkey::class)->restart($record);
                Toast::success(__('Valkey restartován'));
            }));
    }

    /** Move the site (with its databases and DNS zone) to a client or back to the administrator. */
    public static function assignClient(): Action
    {
        return Action::make('assignClient')
            ->label(__('Přiřadit klientovi'))
            ->icon('outline:user-group')
            ->visible(fn () => Gate::allows('clients') && Client::query()->exists())
            ->modalHeading(fn (Site $record) => __('Vlastník webu :domain', ['domain' => $record->domain]))
            ->modalDescription(__('S webem přejdou i jeho databáze a DNS zóna. Limity balíčku se tu nekontrolují.'))
            ->fillFormUsing(fn (Site $record) => ['client_id' => $record->client_id])
            ->form([SiteFields::client()])
            ->action(function (Site $record, array $data) {
                abort_unless(Gate::allows('admin'), 403);
                $client = filled($data['client_id'] ?? null) ? Client::findOrFail($data['client_id']) : null;
                try {
                    app(ManageClients::class)->assignSite($record, $client);
                    Toast::success(__('Vlastník změněn'), $client ? __('Web teď patří klientovi :name.', ['name' => $client->name]) : __('Web teď patří správci.'));
                } catch (AgentException $e) {
                    Toast::error(__('Vlastníka se nepodařilo změnit'), $e->getMessage());
                }
            });
    }

    /**
     * The quick menu (site detail, list rows): what is done often and changes
     * no setting, plus the way to the settings.
     *
     * @return list<Action>
     */
    public static function quick(): array
    {
        return [self::restart(), self::checkHealth(), self::createDatabase(), self::addWorker(), self::openXhgui(), self::settingsLink()];
    }

    /**
     * The dialogs on the site's Nastavení tab (each opened from its section).
     *
     * @return list<Action>
     */
    public static function settings(): array
    {
        return [
            self::edge(), self::tls(), self::ssh(), self::credentials(),
            self::setVersion(), self::switchMode(), self::pythonApp(), self::phpSettings(), self::webRules(),
            self::valkey(), self::xhprof(), self::mountAdd(), self::mountRemove(), self::mountRemount(), self::staging(), self::import(),
            self::assignClient(), self::disable(), self::enable(), self::delete(),
        ];
    }

    public static function settingsLink(): Action
    {
        return Action::make('settings')
            ->label(__('Nastavení'))
            ->icon('outline:cog-6-tooth')
            ->url(fn (Site $record) => SiteResource::url('settings', $record));
    }
}
