<?php

namespace App\Wire\Sites;

use App\Actions\Databases\ManageDatabases;
use App\Actions\Sites\ChangeRuntime;
use App\Actions\Sites\CheckSiteHealth;
use App\Actions\Sites\ControlSite;
use App\Actions\Sites\DeleteSite;
use App\Actions\Sites\ManageSsh;
use App\Actions\Sites\ManageTls;
use App\Actions\Sites\ManageWorkers;
use App\Enums\SiteMode;
use App\Enums\SshAccess;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Toast;
use App\Wire\Forms\Options;
use App\Wire\Resources\SiteResource;
use Illuminate\Support\Carbon;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Select;
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
                        ? app(SystemInfo::class)->phpVersionOptions()
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
                    ->rules(['regex:/^[a-z][a-z0-9_]{0,31}$/'])
                    ->validationMessages(['regex' => 'Malá písmena, číslice a podtržítko, začíná písmenem.']),
            ])
            ->action(fn (Site $record, array $data) => self::attempt('Databázi se nepodařilo vytvořit', function () use ($record, $data) {
                app(ManageDatabases::class)->create($data['engine'], $data['name'], $record);
                Toast::success("Databáze {$data['name']} vytvořena", 'Údaje jsou v .env webu.');
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
                    ->rules(['regex:/^[\x20-\x39\x3b-\x7e]+$/'])
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
    public static function more(): array
    {
        return [
            self::restart(), self::enable(), self::disable(),
            self::ssh(), self::tls(), self::createDatabase(), self::addWorker(),
            self::switchMode(), self::setVersion(), self::checkHealth(),
            self::delete(),
        ];
    }
}
