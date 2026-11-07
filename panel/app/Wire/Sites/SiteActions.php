<?php

namespace App\Wire\Sites;

use App\Actions\Clients\ManageClients;
use App\Actions\Databases\ManageDatabases;
use App\Actions\Databases\ManageDatabaseServers;
use App\Actions\Sites\ChangeRuntime;
use App\Actions\Sites\CheckSiteHealth;
use App\Actions\Sites\ControlSite;
use App\Actions\Sites\DeleteSite;
use App\Actions\Sites\ManageEdge;
use App\Actions\Sites\ManagePhpSettings;
use App\Actions\Sites\ManageSsh;
use App\Actions\Sites\ManageTls;
use App\Actions\Sites\ManageValkey;
use App\Actions\Sites\ManageWebRules;
use App\Actions\Sites\ManageWorkers;
use App\Enums\SiteMode;
use App\Enums\SshAccess;
use App\Models\Client;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Domains;
use App\Support\Limits;
use App\Support\Toast;
use App\Support\WebRules\RulesConverter;
use App\Wire\Forms\Options;
use App\Wire\Resources\SiteResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Foundation\Schema\Grid;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireCore\Foundation\Schema\Tab;
use NyonCode\WireCore\Foundation\Schema\Tabs;
use NyonCode\WireCore\Infolists\Components\TextEntry;
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
    ];

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
            ->label('Otevřít web')
            ->icon('outline:arrow-top-right-on-square')
            ->color('gray')
            ->url(fn (Site $record) => $record->url(), true);
    }

    public static function deploy(): Action
    {
        return Action::make('deploy')
            ->label('Deploy')
            ->icon('outline:rocket-launch')
            ->color('primary')
            ->visible(fn (Site $record) => $record->isOperational() && $record->enabled)
            ->url(fn (Site $record) => SiteResource::url('deploy', $record));
    }

    public static function restart(): Action
    {
        return Action::make('restart')
            ->label('Restartovat')
            ->icon('outline:arrow-path')
            ->color('gray')
            ->visible(fn (Site $record) => $record->isOperational() && $record->enabled)
            ->modalHeading(fn (Site $record) => "Restartovat {$record->domain}?")
            ->modalDescription(fn (Site $record) => $record->mode === SiteMode::Classic
                ? 'PHP-FPM se plynule znovu načte (rozpracované požadavky doběhnou) a vyprázdní se OPcache. Bez výpadku.'
                : 'Proces aplikace se zastaví a znovu spustí. Web bude pár sekund nedostupný.')
            ->form([Toggle::make('workers')->label('Restartovat i workery (fronty, Horizon, Reverb…)')->default(true)])
            ->modalSubmitActionLabel('Restartovat')
            ->action(fn (Site $record, array $data) => self::attempt('Restart selhal', function () use ($record, $data) {
                $done = app(ControlSite::class)->restart($record, (bool) ($data['workers'] ?? false));
                Toast::success("{$record->domain} restartován", implode(', ', $done) ?: null);
            }));
    }

    public static function disable(): Action
    {
        return Action::make('disable')
            ->label('Vypnout web')
            ->icon('outline:power')
            ->color('danger')
            ->visible(fn (Site $record) => $record->isOperational() && $record->enabled)
            ->requiresConfirmation()
            ->modalHeading(fn (Site $record) => "Vypnout {$record->domain}?")
            ->modalDescription('Návštěvníci uvidí stránku „Web je dočasně vypnutý“ (HTTP 503). Zastaví se proces aplikace, workery i naplánované úlohy a po restartu serveru se samy nespustí. Soubory, databáze ani nastavení se nemění.')
            ->modalSubmitActionLabel('Vypnout')
            ->action(fn (Site $record) => self::attempt('Vypnutí selhalo', function () use ($record) {
                app(ControlSite::class)->disable($record);
                Toast::success("{$record->domain} je vypnutý");
            }));
    }

    public static function enable(): Action
    {
        return Action::make('enable')
            ->label('Zapnout web')
            ->icon('outline:play')
            ->color('success')
            ->visible(fn (Site $record) => $record->isOperational() && ! $record->enabled)
            ->action(fn (Site $record) => self::attempt('Zapnutí selhalo', function () use ($record) {
                app(ControlSite::class)->enable($record);
                Toast::success("{$record->domain} je zapnutý");
            }));
    }

    public static function switchMode(): Action
    {
        return Action::make('switchMode')
            ->label('Změnit režim')
            ->icon('outline:arrows-right-left')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "Změnit režim webu {$record->domain}")
            ->modalDescription('Nový backend se připraví vedle starého a proxy se přepne, až bude hotový. Když něco selže, web běží dál jako dosud.')
            ->form(fn (Site $record) => SiteFields::runtime($record->mode->value))
            ->modalWidth('2xl')
            ->action(fn (Site $record, array $data) => self::attempt('Změna režimu selhala', function () use ($record, $data) {
                app(ChangeRuntime::class)->switchMode($record, $data);
                Toast::success("{$record->domain}: režim změněn", $record->runtimeLabel());
            }));
    }

    public static function setVersion(): Action
    {
        return Action::make('setVersion')
            ->label('Změnit verzi')
            ->icon('outline:adjustments-horizontal')
            ->visible(fn (Site $record) => $record->isOperational() && $record->mode !== SiteMode::Worker)
            ->modalHeading(fn (Site $record) => "Změnit verzi pro {$record->domain}")
            ->modalDescription(fn (Site $record) => $record->mode === SiteMode::Classic
                ? 'Nový FPM pool naběhne vedle starého a web se na něj přepne. Bez výpadku.'
                : 'Proces se restartuje s novou verzí (krátký výpadek).')
            ->form(fn (Site $record) => [
                Select::make('version')->rules([Options::rule()])
                    ->label($record->mode === SiteMode::Classic ? 'Verze PHP' : 'Verze Node')
                    ->options(collect($record->mode === SiteMode::Classic
                        ? Limits::filterPhp(Limits::forCurrentUser(), app(SystemInfo::class)->phpVersionOptions())
                        : app(SystemInfo::class)->nodeVersionOptions())
                        ->except($record->mode === SiteMode::Classic ? $record->php_version : $record->node_version)
                        ->all())
                    ->required(),
            ])
            ->action(fn (Site $record, array $data) => self::attempt('Změna verze selhala', function () use ($record, $data) {
                app(ChangeRuntime::class)->setVersion($record, $data['version']);
                Toast::success("{$record->domain}: {$record->runtimeLabel()}");
            }));
    }

    public static function checkHealth(): Action
    {
        return Action::make('checkHealth')
            ->label('Ověřit odezvu')
            ->icon('outline:heart')
            ->visible(fn (Site $record) => $record->isOperational())
            ->action(function (Site $record) {
                app(CheckSiteHealth::class)->handle($record);
                $record->health_ok
                    ? Toast::success("{$record->domain} odpovídá", "HTTP {$record->health_status}")
                    : Toast::error("{$record->domain} neodpovídá", (string) $record->health_error);
            });
    }

    public static function createDatabase(): Action
    {
        return Action::make('createDatabase')
            ->label('Nová databáze')
            ->icon('outline:circle-stack')
            ->visible(fn (Site $record) => $record->isOperational() && app(SystemInfo::class)->dbEngines() !== [])
            ->modalDescription('Údaje (host, jméno, heslo) zapíše server rovnou do .env webu; heslo se nikde nezobrazuje.')
            ->form(fn (Site $record) => [
                Select::make('engine')->rules([Options::rule()])->label('Engine')->required()
                    ->options(collect(app(SystemInfo::class)->dbEngines())->mapWithKeys(fn ($e) => [$e->value => $e->getLabel()])->all())
                    ->default(app(SystemInfo::class)->dbEngines()[0]->value ?? null),
                TextInput::make('name')->label('Název (i uživatel)')->required()->maxLength(32)
                    ->default(substr(preg_replace('/[^a-z0-9]+/', '_', $record->domain), 0, 32))
                    ->rules(['max:32', 'regex:/^[a-z][a-z0-9_]{0,31}$/'])
                    ->validationMessages(['regex' => 'Malá písmena, číslice a podtržítko, začíná písmenem.']),
            ])
            ->action(fn (Site $record, array $data) => self::attempt('Databázi se nepodařilo vytvořit', function () use ($record, $data) {
                $name = app(ManageDatabases::class)->create($data['engine'], $data['name'], $record)['database']->name;
                Toast::success("Databáze {$name} vytvořena", 'Údaje jsou v .env webu.');
            }));
    }

    public static function ssh(): Action
    {
        return Action::make('ssh')
            ->label('SSH / SFTP')
            ->icon('outline:command-line')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "SSH / SFTP pro {$record->domain}")
            ->modalDescription(fn (Site $record) => "Přihlášení jako {$record->system_user} (port ".config('panel.ssh_port').'), jen v rámci tohoto webu. Přesměrování portů je zakázané, opakovaně chybná přihlášení zablokuje fail2ban.')
            ->modalWidth('2xl')
            ->fillFormUsing(fn (Site $record) => ['access' => $record->ssh_access->value, 'keys' => (string) $record->ssh_keys, 'password_mode' => 'keep'])
            ->form(fn (Site $record) => [
                Radio::make('access')->label('Přístup')->options(SshAccess::class)->segmented()->required()->live(),
                Radio::make('password_mode')->rules([Options::rule()])
                    ->label('Heslo')
                    ->options($record->ssh_password
                        ? ['keep' => 'Ponechat současné', 'generate' => 'Vygenerovat nové', 'set' => 'Zadat vlastní', 'remove' => 'Zrušit (jen klíče)']
                        : ['keep' => 'Bez hesla (jen klíče)', 'generate' => 'Vygenerovat heslo', 'set' => 'Zadat vlastní'])
                    ->descriptions(['generate' => 'Ukáže se jednou po uložení, panel hesla neukládá.'])
                    ->required()
                    ->live()
                    ->hiddenWhen('access', SshAccess::Off->value),
                TextInput::make('password')
                    ->label('Nové heslo')
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    ->maxLength(128)
                    ->rules(['min:12', 'max:128', 'regex:/^[\x20-\x39\x3b-\x7e]+$/'])
                    ->validationMessages(['regex' => 'Jen tisknutelné ASCII znaky bez dvojtečky.'])
                    ->helperText('Alespoň 12 znaků.')
                    ->visible(fn ($get) => $get('password_mode') === 'set' && $get('access') !== SshAccess::Off->value)
                    ->requiredIf('password_mode', 'set'),
                Textarea::make('keys')
                    ->label('Veřejné klíče')
                    ->rows(4)
                    ->placeholder('ssh-ed25519 AAAA… jmeno@pocitac')
                    ->helperText('Jeden na řádek. Klíč je bezpečnější než heslo.')
                    ->hiddenWhen('access', SshAccess::Off->value)
                    ->required(fn ($get) => ! in_array($get('password_mode') ?? 'keep', ['generate', 'set'], true)
                        && ! ($record->ssh_password && ($get('password_mode') ?? 'keep') === 'keep'))
                    ->rules([function (string $attribute, mixed $value, \Closure $fail) {
                        if (trim((string) $value) !== '' && ManageSsh::parseKeys((string) $value) === []) {
                            $fail('Nenašel jsem žádný platný klíč.');
                        }
                    }])
                    ->validationMessages(['required' => 'Zadejte veřejný klíč, nebo nastavte heslo.']),            ])
            ->action(function (Site $record, array $data) {
                self::attempt('Nastavení SSH selhalo', function () use ($record, $data) {
                    $generated = app(ManageSsh::class)->handle($record, $data['access'], (string) ($data['keys'] ?? ''), (string) ($data['password_mode'] ?? 'keep'), $data['password'] ?? null);
                    $login = 'sftp -P '.config('panel.ssh_port').' '.$record->sshTarget();
                    $generated !== null
                        ? Toast::secret("SSH pro {$record->domain}: {$record->ssh_access->getLabel()}", "{$login}\nHeslo (zobrazí se jen teď): {$generated}")
                        : Toast::success("SSH pro {$record->domain}: {$record->ssh_access->getLabel()}", $record->ssh_access === SshAccess::Off ? null : $login);
                });
            });
    }

    public static function tls(): Action
    {
        return Action::make('tls')
            ->label('HTTPS / certifikát')
            ->icon('outline:lock-closed')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "HTTPS pro {$record->domain}")
            ->modalWidth('2xl')
            ->fillFormUsing(fn (Site $record) => ['mode' => $record->tls_mode ?? 'auto'])
            ->form([
                Radio::make('mode')->rules([Options::rule()])
                    ->label('Odkud je certifikát')
                    ->options(ManageTls::MODES)
                    ->descriptions([
                        'auto' => 'Veřejná doména mířící na server. Vydává a obnovuje se sám, zdarma.',
                        'internal' => 'Domény ve vnitřní síti a testy. Vydá ho server; počítače jednou nainstalují kořenový certifikát (SSL → Kořenový certifikát).',
                        'custom' => 'Firemní CA nebo zakoupený certifikát. Obnovu hlídáte sami (panel upozorní 14 dní předem).',
                    ])
                    ->required()
                    ->live(),
                Textarea::make('cert')
                    ->label('Certifikát (PEM, včetně mezilehlých)')
                    ->placeholder('-----BEGIN CERTIFICATE-----')
                    ->rows(6)
                    ->visibleWhen('mode', 'custom')
                    ->requiredIf('mode', 'custom'),
                Textarea::make('key')
                    ->label('Privátní klíč (PEM, bez hesla)')
                    ->placeholder('-----BEGIN PRIVATE KEY-----')
                    ->rows(5)
                    ->visibleWhen('mode', 'custom')
                    ->requiredIf('mode', 'custom'),
            ])
            ->action(fn (Site $record, array $data) => self::attempt('Certifikát se nepodařilo nastavit', function () use ($record, $data) {
                $out = app(ManageTls::class)->set($record, $data['mode'], $data['cert'] ?? null, $data['key'] ?? null);
                $cert = $out['certificate'] ?? null;
                Toast::success("{$record->domain}: ".ManageTls::MODES[$data['mode']], $cert
                    ? 'Vydal '.$cert['issuer'].', platí do '.Carbon::parse($cert['not_after'])->format('j. n. Y').'.'
                    : 'Certifikát se vydá během chvíle.');
            }));
    }

    public static function addWorker(): Action
    {
        return Action::make('addWorker')
            ->label('Přidat worker')
            ->icon('outline:cpu-chip')
            ->visible(fn (Site $record) => $record->isOperational()
                && ! $record->framework->isNode()
                && app(SystemInfo::class)->workerTypes($record->framework->value) !== [])
            ->modalDescription('Worker běží jako systemd služba pod uživatelem webu se sandboxem a po pádu se sám restartuje. Před prvním deployem se jen zaregistruje.')
            ->form(fn (Site $record) => [
                Select::make('worker')->rules([Options::rule()])
                    ->label('Typ')
                    ->options(collect(app(SystemInfo::class)->workerTypes($record->framework->value))
                        ->mapWithKeys(fn (string $t) => [$t => $t === 'scheduler'
                            ? ($record->framework->value === 'laravel' ? 'Plánovač (artisan schedule:work)' : 'Plánovač (messenger:consume scheduler_default)')
                            : (self::WORKER_LABELS[$t] ?? $t)])
                        ->all())
                    ->required(),
            ])
            ->action(fn (Site $record, array $data) => self::attempt('Worker se nepodařilo přidat', function () use ($record, $data) {
                $out = app(ManageWorkers::class)->create($record, $data['worker']);
                $body = ($out['started'] ?? false) ? 'Běží.' : 'Zaregistrován, spustí se po prvním deployi.';
                if ($data['worker'] === 'reverb') {
                    $body .= " Do .env: REVERB_HOST={$record->domain}, REVERB_PORT=443, REVERB_SCHEME=https.";
                }
                Toast::success('Worker přidán', $body);
            }));
    }

    public static function delete(): Action
    {
        return Action::make('delete')
            ->label('Smazat web')
            ->icon('outline:trash')
            ->color('danger')
            ->modalHeading(fn (Site $record) => "Smazat web {$record->domain}?")
            ->modalDescription('Odebere procesy, konfiguraci proxy a systémového uživatele. Soubory se přesunou do koše na serveru (/srv/sites/.trash), databáze zůstanou.')
            ->modalIcon('outline:trash', 'danger')
            ->form(fn (Site $record) => [
                TextInput::make('confirm')
                    ->label('Pro potvrzení napište doménu')
                    ->placeholder($record->domain)
                    ->required()
                    ->rules(['in:'.$record->domain])
                    ->validationMessages(['in' => 'Doména nesouhlasí.']),
            ])
            ->modalSubmitActionLabel('Smazat web')
            ->action(function (Site $record) {
                try {
                    app(DeleteSite::class)->handle($record);
                    Toast::success("Web {$record->domain} smazán");
                } catch (AgentException $e) {
                    Toast::error('Smazání selhalo', $e->getMessage());
                }
            })
            ->successRedirect(fn () => SiteResource::url('index'));
    }

    /**
     * The "more" menu: everything but the primary buttons.
     *
     * @return list<Action>
     */
    public static function phpSettings(): Action
    {
        $f = ManagePhpSettings::FIELDS;
        // Form keys cannot hold a dot (it would nest the state): date.timezone -> date__timezone.
        $k = fn (string $key) => str_replace('.', '__', $key);
        $size = fn (string $key) => TextInput::make($k($key))->label($f[$key][0])->placeholder('výchozí '.$f[$key][1])
            ->rules(['nullable', 'regex:/^(-1|[0-9]{1,6}[KMGkmg]?)$/'])
            ->validationMessages(['regex' => 'Velikost jako 256M nebo 1G.']);
        $int = fn (string $key, int $min, int $max) => TextInput::make($k($key))->label($f[$key][0])->placeholder('výchozí '.$f[$key][1])
            ->integer()->rules(['nullable', 'integer', "between:{$min},{$max}"]);
        $choice = fn (string $key, array $options) => Select::make($k($key))->label($f[$key][0])->options($options)
            ->placeholder('výchozí ('.($options[$f[$key][1]] ?? $f[$key][1]).')')->rules([Options::rule()]);
        $onOff = ['on' => 'zapnuto', 'off' => 'vypnuto'];

        return Action::make('phpSettings')
            ->label('PHP nastavení')
            ->icon('outline:adjustments-horizontal')
            ->visible(fn (Site $record) => $record->isOperational() && $record->mode !== SiteMode::Node)
            ->modalHeading(fn (Site $record) => "PHP nastavení: {$record->domain}")
            ->modalDescription(fn (Site $record) => $record->mode === SiteMode::Worker
                ? 'Hodnoty php.ini pro FrankenPHP tohoto webu. Po uložení se aplikace restartuje.'
                : 'Hodnoty php.ini pro PHP-FPM pool tohoto webu (PHP '.$record->php_version.'). Po uložení se PHP plynule znovu načte. Prázdné pole = výchozí hodnota.')
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
                        ->placeholder('výchozí ('.config('app.timezone', 'UTC').')')->rules([Options::rule()]),
                    $choice('display_errors', $onOff)->helperText('Na produkci nechte vypnuté: chyby jsou v logu webu.'),
                    $choice('error_reporting', ['production' => 'produkční (bez deprecated)', 'all' => 'vše (E_ALL)', 'errors' => 'jen fatální chyby']),
                    $choice('allow_url_fopen', $onOff),
                    $choice('short_open_tag', $onOff),
                    $record->mode === SiteMode::Worker ? $int('worker_threads', 1, 64)->helperText('Víc workerů = víc souběžných požadavků i víc paměti.') : null,
                    $record->mode === SiteMode::Worker ? $int('max_requests', 0, 100000)->helperText('Chrání před únikem paměti; 0 = nikdy.') : null,
                ]))),
            ])
            ->modalSubmitActionLabel('Uložit')
            ->action(fn (Site $record, array $data) => self::attempt('PHP nastavení se nepodařilo uložit', function () use ($record, $data) {
                $applied = app(ManagePhpSettings::class)->set($record, collect($data)->mapWithKeys(fn ($v, $key) => [str_replace('__', '.', $key) => $v])->all());
                Toast::success('PHP nastavení uloženo', $applied === [] ? 'Všechny hodnoty jsou výchozí.' : count($applied).' vlastních hodnot.');
            }));
    }

    public static function edge(): Action
    {
        $domainRule = function (string $attribute, mixed $value, \Closure $fail) {
            foreach ((array) $value as $d) {
                if (! Domains::isValid(Domains::normalize((string) $d))) {
                    $fail("{$d} není platná doména.");
                }
            }
        };

        return Action::make('edge')
            ->label('Domény a přístup')
            ->icon('outline:globe-europe-africa')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "Domény a přístup: {$record->domain}")
            ->modalDescription('Zpracuje proxy před aplikací: další domény (každá dostane certifikát), přesměrování, ochranu heslem nebo IP adresou a hlavičky odpovědí.')
            ->modalWidth('4xl')
            ->fillFormUsing(fn (Site $record) => ManageEdge::formData($record))
            ->form(fn (Site $record) => [
                Tabs::make()->schema([
                    Tab::make('domains')->label('Domény')->icon('outline:globe-alt')->schema([
                        Tags::make('aliases')->label('Další domény webu')
                            ->helperText('Web odpovídá i na nich (stejný obsah). Musí mířit na tento server.')
                            ->rules([$domainRule]),
                        Tags::make('redirect_hosts')->label('Domény přesměrované na '.$record->domain)
                            ->helperText('Např. www.'.$record->domain.' nebo stará doména. Přesměrují trvale (308) i s cestou.')
                            ->suggestions(str_starts_with($record->domain, 'www.') ? [substr($record->domain, 4)] : ['www.'.$record->domain])
                            ->rules([$domainRule]),
                    ]),
                    Tab::make('redirects')->label('Přesměrování')->icon('outline:arrow-uturn-right')->schema([
                        Repeater::make('redirects')->label('Pravidla přesměrování')->table()->addButtonLabel('Přidat přesměrování')->schema([
                            TextInput::make('from')->label('Z cesty')->placeholder('/stara-stranka nebo /blog/*')->required()
                                ->rules(['regex:~^/[A-Za-z0-9._\~!$&\'()+,;=:@%/-]{0,200}(/\*)?$~'])
                                ->validationMessages(['regex' => 'Cesta začíná lomítkem; /blog/* přesměruje celou sekci.']),
                            TextInput::make('to')->label('Na')->placeholder('/nova-stranka nebo https://…')->required()
                                ->rules(['regex:~^(/[^\s"{}\\\\]{0,300}|https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[^\s"{}\\\\]{0,300})?)$~'])
                                ->validationMessages(['regex' => 'Cesta na tomto webu nebo adresa https://…']),
                            Select::make('code')->label('Typ')->options([301 => '301 trvalé', 302 => '302 dočasné', 308 => '308 trvalé (zachová metodu)', 307 => '307 dočasné (zachová metodu)'])
                                ->default(301)->required()->rules([Options::rule()]),
                        ]),
                    ]),
                    Tab::make('protection')->label('Ochrana')->icon('outline:lock-closed')->schema([
                        Radio::make('auth_mode')->label('Heslo')->options(['off' => 'Bez hesla', 'site' => 'Celý web', 'path' => 'Jen část webu'])
                            ->segmented()->default('off')->live()->required()->rules([Options::rule()]),
                        Grid::make()->columns(['default' => 1, 'md' => 3])->schema([
                            TextInput::make('auth_path')->label('Cesta')->placeholder('/admin')->visibleWhen('auth_mode', 'path')->requiredIf('auth_mode', 'path')
                                ->rules(['nullable', 'regex:~^/[A-Za-z0-9._\-/]{0,200}$~']),
                            TextInput::make('auth_user')->label('Uživatel')->hiddenWhen('auth_mode', 'off')->requiredIf('auth_mode', ['site', 'path'])
                                ->rules(['nullable', 'regex:/^[A-Za-z0-9._-]{1,32}$/']),
                            TextInput::make('auth_password')->label('Heslo')->password()->revealable()->hiddenWhen('auth_mode', 'off')
                                ->rules(['nullable', 'min:8', 'max:128'])
                                ->helperText(fn () => ($record->edge_settings['auth_user'] ?? null) ? 'Prázdné = ponechat současné.' : null),
                        ]),
                        Textarea::make('allow_ips')->label('Povolené IP adresy')->rows(3)->placeholder("203.0.113.7\n10.8.0.0/24")
                            ->helperText('Prázdné = kdokoli. Ostatní dostanou 403. Jedna adresa nebo síť na řádek.')
                            ->rules([function (string $attribute, mixed $value, \Closure $fail) {
                                foreach (ManageDatabaseServers::parseAllow((string) $value) as $ip) {
                                    if (! ManageDatabaseServers::validAddress($ip)) {
                                        $fail("{$ip} není IP adresa ani síť.");
                                    }
                                }
                            }]),
                        TextInput::make('allow_path')->label('Platí pro cestu')->placeholder('/ = celý web')->default('/')
                            ->rules(['nullable', 'regex:~^/[A-Za-z0-9._\-/]{0,200}$~']),
                    ]),
                    Tab::make('headers')->label('Hlavičky')->icon('outline:shield-check')->schema([
                        Toggle::make('hsts')->label('HSTS (prohlížeč pak web otevírá jen přes HTTPS, rok)'),
                        Toggle::make('security_headers')->label('Bezpečnostní hlavičky (nosniff, SAMEORIGIN, Referrer-Policy, Permissions-Policy)'),
                        Repeater::make('headers')->label('Vlastní hlavičky')->table()->addButtonLabel('Přidat hlavičku')->schema([
                            TextInput::make('name')->label('Název')->placeholder('X-Robots-Tag')->required()->rules(['regex:/^[A-Za-z0-9-]{1,64}$/']),
                            TextInput::make('value')->label('Hodnota')->placeholder('noindex')->required()
                                ->rules(['regex:/^[ !#-\[\]-z|~]{1,500}$/'])->validationMessages(['regex' => 'Bez uvozovek, zpětných lomítek a složených závorek.']),
                        ]),
                    ]),
                ]),
            ])
            ->modalSubmitActionLabel('Uložit')
            ->action(fn (Site $record, array $data) => self::attempt('Nastavení se nepodařilo uložit', function () use ($record, $data) {
                app(ManageEdge::class)->set($record, $data);
                Toast::success('Domény a přístup uloženy', 'Nové domény dostanou certifikát během chvíle.');
            }));
    }

    /**
     * What an application configured by hand needs (WordPress, an installer):
     * SFTP, database, Valkey and outgoing mail, read from the site's env file.
     */
    public static function credentials(): Action
    {
        return Action::make('credentials')
            ->label('Přístupové údaje')
            ->icon('outline:key')
            ->color('gray')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "Přístupové údaje: {$record->domain}")
            ->modalDescription('Pro aplikace, které se nastavují ručně (WordPress, instalátory). Údaje panel čte z .env webu, nikde jinde je neukládá.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Zavřít')
            ->infolist(function (Site $record) {
                try {
                    $c = app(ControlSite::class)->credentials($record);
                } catch (AgentException $e) {
                    return [TextEntry::make('error')->label('Chyba')->state(fn () => $e->getMessage())];
                }
                $entry = fn (string $key, string $label, ?string $value) => TextEntry::make($key)->label($label)->state(fn () => $value ?? '—')->copyable($value !== null);
                $db = $c['database'] ?? null;
                $sections = [
                    Section::make('sftp')->label('SFTP / SSH')->columns(2)->schema([
                        $entry('sftp_host', 'Server', (string) (config('panel.ssh_host') ?: request()->getHost())),
                        $entry('sftp_port', 'Port', (string) config('panel.ssh_port', 22)),
                        $entry('sftp_user', 'Uživatel', $record->system_user),
                        $entry('sftp_auth', 'Přihlášení', $record->ssh_access === SshAccess::Off ? 'vypnuto (Další → SSH / SFTP)' : ($record->ssh_password ? 'klíč nebo heslo' : 'jen klíč')),
                    ]),
                ];
                $sections[] = Section::make('db')->label('Databáze')->columns(2)->schema($db === null
                    ? [TextEntry::make('db_none')->label('')->state(fn () => 'Web zatím nemá databázi v .env (Další → Nová databáze ji vytvoří a zapíše).')]
                    : [
                        $entry('db_host', 'Server (host)', ($db['host'] ?? '127.0.0.1').(($db['port'] ?? '') !== '' ? ':'.$db['port'] : '')),
                        $entry('db_name', 'Databáze', $db['database'] ?? null),
                        $entry('db_user', 'Uživatel', $db['username'] ?? null),
                        $entry('db_password', 'Heslo', $db['password'] ?? null),
                    ]);
                if (isset($c['valkey'])) {
                    $sections[] = Section::make('valkey')->label('Valkey (Redis)')->columns(2)->schema(collect($c['valkey'])
                        ->map(fn ($v, $k) => $entry('valkey_'.strtolower($k), $k, (string) $v))->values()->all());
                }
                if (isset($c['mail'])) {
                    $sections[] = Section::make('mail')->label('Odchozí pošta (SMTP)')->columns(2)->schema(collect($c['mail'])
                        ->map(fn ($v, $k) => $entry('mail_'.strtolower($k), $k, (string) $v))->values()->all());
                }

                return $sections;
            });
    }

    /** The site's own web-server rules, with a converter from nginx / .htaccess. */
    public static function webRules(): Action
    {
        return Action::make('webRules')
            ->label('Pravidla webserveru')
            ->icon('outline:code-bracket')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "Pravidla webserveru: {$record->domain}")
            ->modalDescription('Vlastní pravidla v syntaxi Caddy (přesměrování, přepisy URL, hlavičky, cache, komprese, limit velikosti požadavku). Platí v pořadí, jak jsou napsaná, za přístupovými pravidly panelu a před aplikací. Před nasazením je zkontroluje server; chybná se nepoužijí.')
            ->modalWidth('4xl')
            ->fillFormUsing(fn (Site $record) => ['rules' => $record->web_rules ?? '', 'import' => ''])
            ->form([
                CodeEditor::make('rules')->label('Pravidla (Caddy)')->language('caddyfile')->withLineNumbers()->minHeight(260)->maxLength(16384)
                    ->rules(['nullable', 'string', 'max:16384'])
                    ->helperText('Např.: header /assets/* Cache-Control "public, max-age=31536000" · redir /stary /novy 301 · encode gzip zstd'),
                Textarea::make('import')->label('Převést z nginx nebo .htaccess')->rows(4)
                    ->placeholder("location ~* \\.(css|js)$ { expires 30d; }\nRewriteRule ^blog/(.*)$ /novinky/$1 [R=301,L]")
                    ->helperText('Vložte pravidla a stiskněte Převést: doplní se dolů do pravidel. Co převést nejde, zůstane jako komentář.')
                    ->hintAction(Action::make('convert')->label('Převést')->icon('outline:arrows-right-left')
                        ->action(function ($get, $set) {
                            $converted = app(RulesConverter::class)->convert((string) $get('import'));
                            $set('rules', trim(trim((string) $get('rules'))."\n".$converted));
                            $set('import', '');
                        })),
            ])
            ->action(fn (Site $record, array $data) => self::attempt('Pravidla se nepodařilo uložit', function () use ($record, $data) {
                app(ManageWebRules::class)->set($record, (string) ($data['rules'] ?? ''));
                Toast::success(filled($data['rules'] ?? null) ? 'Pravidla platí' : 'Pravidla odebrána');
            }));
    }

    /** The site's own Valkey (Redis-compatible) server. */
    public static function valkey(): Action
    {
        return Action::make('valkey')
            ->label('Valkey (Redis)')
            ->icon('outline:bolt')
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "Valkey pro {$record->domain}")
            ->modalDescription('Vlastní Valkey server webu (kompatibilní s Redisem): běží pod uživatelem webu na 127.0.0.1 a vlastním portu, chráněný heslem, takže ho žádný jiný web nepoužije. Host, port a heslo se zapíšou do .env (REDIS_HOST, REDIS_PORT, REDIS_PASSWORD).')
            ->fillFormUsing(fn (Site $record) => [
                'enabled' => $record->valkey !== null,
                'maxmemory_mb' => $record->valkey['maxmemory_mb'] ?? 64,
                'policy' => $record->valkey['policy'] ?? 'allkeys-lru',
                'persist' => $record->valkey['persist'] ?? false,
            ])
            ->form([
                Toggle::make('enabled')->label('Zapnout Valkey pro tento web')->live(),
                TextInput::make('maxmemory_mb')->label('Paměť (MB)')->integer()->minValue(8)->maxValue(65536)
                    ->rules(['integer', 'between:8,65536'])->visibleWhen('enabled', true)->requiredIf('enabled', true),
                Select::make('policy')->label('Když se paměť zaplní')->options(ManageValkey::POLICIES)->rules([Options::rule()])
                    ->visibleWhen('enabled', true)->requiredIf('enabled', true),
                Toggle::make('persist')->label('Ukládat data na disk (přežijí restart)')->visibleWhen('enabled', true)
                    ->helperText('Pro fronty a session ano, pro čistou cache není třeba.'),
            ])
            ->action(fn (Site $record, array $data) => self::attempt('Valkey se nepodařilo nastavit', function () use ($record, $data) {
                app(ManageValkey::class)->set($record, $data);
                Toast::success($data['enabled'] ? 'Valkey běží' : 'Valkey vypnut', $data['enabled'] ? 'Připojení je v .env webu.' : null);
            }));
    }

    public static function valkeyFlush(): Action
    {
        return Action::make('valkeyFlush')
            ->label('Vyprázdnit Valkey')
            ->icon('outline:trash')
            ->color('danger')
            ->visible(fn (Site $record) => $record->valkey !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (Site $record) => "Smazat všechny klíče ve Valkey webu {$record->domain}?")
            ->action(fn (Site $record) => self::attempt('Valkey se nepodařilo vyprázdnit', function () use ($record) {
                app(ManageValkey::class)->flush($record);
                Toast::success('Valkey vyprázdněn');
            }));
    }

    public static function valkeyRestart(): Action
    {
        return Action::make('valkeyRestart')
            ->label('Restartovat Valkey')
            ->icon('outline:arrow-path')
            ->visible(fn (Site $record) => $record->valkey !== null)
            ->action(fn (Site $record) => self::attempt('Valkey se nepodařilo restartovat', function () use ($record) {
                app(ManageValkey::class)->restart($record);
                Toast::success('Valkey restartován');
            }));
    }

    /** Move the site (with its databases and DNS zone) to a client or back to the administrator. */
    public static function assignClient(): Action
    {
        return Action::make('assignClient')
            ->label('Přiřadit klientovi')
            ->icon('outline:user-group')
            ->visible(fn () => Gate::allows('admin') && Client::query()->exists())
            ->modalHeading(fn (Site $record) => "Vlastník webu {$record->domain}")
            ->modalDescription('S webem přejdou i jeho databáze a DNS zóna. Limity balíčku se tu nekontrolují.')
            ->fillFormUsing(fn (Site $record) => ['client_id' => $record->client_id])
            ->form([SiteFields::client()])
            ->action(function (Site $record, array $data) {
                abort_unless(Gate::allows('admin'), 403);
                $client = filled($data['client_id'] ?? null) ? Client::findOrFail($data['client_id']) : null;
                try {
                    app(ManageClients::class)->assignSite($record, $client);
                    Toast::success('Vlastník změněn', $client ? "Web teď patří klientovi {$client->name}." : 'Web teď patří správci.');
                } catch (AgentException $e) {
                    Toast::error('Vlastníka se nepodařilo změnit', $e->getMessage());
                }
            });
    }

    public static function more(): array
    {
        return [
            self::credentials(), self::restart(), self::enable(), self::disable(),
            self::ssh(), self::tls(), self::edge(), self::phpSettings(), self::createDatabase(), self::valkey(), self::webRules(), self::addWorker(),
            self::switchMode(), self::setVersion(), self::checkHealth(),
            self::assignClient(), self::delete(),
        ];
    }
}
