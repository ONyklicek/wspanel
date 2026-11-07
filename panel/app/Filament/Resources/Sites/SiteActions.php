<?php

namespace App\Filament\Resources\Sites;

use App\Actions\Sites\ChangeRuntime;
use App\Actions\Sites\CheckSiteHealth;
use App\Actions\Sites\ControlSite;
use App\Actions\Sites\DeleteSite;
use App\Actions\Sites\ManageSsh;
use App\Actions\Sites\ManageTls;
use App\Actions\Sites\ManageWorkers;
use App\Enums\SiteMode;
use App\Enums\SiteStatus;
use App\Enums\SshAccess;
use App\Filament\Resources\Databases\Pages\ListDatabases;
use App\Filament\Resources\Sites\Schemas\SiteForm;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Actions on a site, shared by the sites table and the site detail page.
 */
class SiteActions
{
    /** Primary actions: shown as buttons. */
    public static function open(): Action
    {
        return Action::make('open')
            ->label('Otevřít web')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Site $record) => $record->url(), shouldOpenInNewTab: true);
    }

    public static function view(): Action
    {
        return Action::make('view')
            ->label('Detail')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->url(fn (Site $record) => SiteResource::getUrl('view', ['record' => $record]));
    }

    public static function deploy(): Action
    {
        return Action::make('deploy')
            ->label('Deploy')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->visible(fn (Site $record) => $record->isOperational() && $record->enabled)
            ->url(fn (Site $record) => SiteResource::getUrl('deploy', ['record' => $record]));
    }

    public static function restart(): Action
    {
        return Action::make('restart')
            ->label('Restartovat')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Site $record) => $record->isOperational() && $record->enabled)
            ->modalHeading(fn (Site $record) => "Restartovat {$record->domain}?")
            ->modalDescription(fn (Site $record) => match ($record->mode) {
                SiteMode::Classic => 'PHP-FPM se plynule znovu načte (rozpracované požadavky doběhnou), smaže se tím i OPcache. Bez výpadku.',
                default => 'Proces aplikace se zastaví a znovu spustí. Web bude pár sekund nedostupný.',
            })
            ->schema([
                Toggle::make('workers')->label('Restartovat i workery (fronty, Horizon, Reverb…)')->default(true),
            ])
            ->modalSubmitActionLabel('Restartovat')
            ->action(function (Site $record, array $data) {
                try {
                    $done = app(ControlSite::class)->restart($record, (bool) ($data['workers'] ?? false));
                    Notification::make()->title("{$record->domain} restartován")->body(implode(', ', $done) ?: null)->success()->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Restart selhal')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function disable(): Action
    {
        return Action::make('disable')
            ->label('Vypnout web')
            ->icon(Heroicon::OutlinedPower)
            ->color('danger')
            ->visible(fn (Site $record) => $record->isOperational() && $record->enabled)
            ->requiresConfirmation()
            ->modalHeading(fn (Site $record) => "Vypnout {$record->domain}?")
            ->modalDescription('Návštěvníci uvidí stránku „Web je dočasně vypnutý“ (HTTP 503). Zastaví se proces aplikace, workery i naplánované úlohy a po restartu serveru se samy nespustí. Soubory, databáze ani nastavení se nemění.')
            ->modalSubmitActionLabel('Vypnout')
            ->action(function (Site $record) {
                try {
                    app(ControlSite::class)->disable($record);
                    Notification::make()->title("{$record->domain} je vypnutý")->success()->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Vypnutí selhalo')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function enable(): Action
    {
        return Action::make('enable')
            ->label('Zapnout web')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (Site $record) => $record->isOperational() && ! $record->enabled)
            ->action(function (Site $record) {
                try {
                    app(ControlSite::class)->enable($record);
                    Notification::make()->title("{$record->domain} je zapnutý")->success()->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Zapnutí selhalo')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    /**
     * Secondary actions for a "more" menu.
     *
     * @return list<Action>
     */
    public static function secondary(): array
    {
        return [
            self::restart(),
            self::disable(),
            self::enable(),
            self::switchMode(),
            self::setVersion(),
            self::checkHealth(),
            self::createDatabase(),
            self::ssh(),
            self::tls(),
            self::addWorker(),
            self::delete(),
        ];
    }

    public static function switchMode(): Action
    {
        return Action::make('switchMode')
            ->label('Změnit režim')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (Site $record) => $record->status === SiteStatus::Active)
            ->modalHeading(fn (Site $record) => "Změnit režim webu {$record->domain}")
            ->modalDescription('Nový backend se připraví vedle starého a proxy se přepne až ve chvíli, kdy je hotový. Když něco selže, web dál běží jako dosud. Workery se přenastaví, nepodporované se odeberou.')
            ->schema(fn (Site $record) => SiteForm::runtimeFields($record->mode->value))
            ->action(function (Site $record, array $data) {
                try {
                    app(ChangeRuntime::class)->switchMode($record, $data);
                    Notification::make()->title("{$record->domain}: režim změněn")->body($record->runtimeLabel())->success()->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Změna režimu selhala')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function setVersion(): Action
    {
        return Action::make('setVersion')
            ->label('Změnit verzi')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->visible(fn (Site $record) => $record->status === SiteStatus::Active && $record->mode !== SiteMode::Worker)
            ->modalHeading(fn (Site $record) => "Změnit verzi pro {$record->domain}")
            ->modalDescription(fn (Site $record) => $record->mode === SiteMode::Classic
                ? 'Nový FPM pool naběhne vedle starého, pak se na něj přepne web. Bez výpadku.'
                : 'Node proces se restartuje s novou verzí (krátký výpadek tohoto webu).')
            ->schema(fn (Site $record) => [
                Select::make('version')
                    ->label($record->mode === SiteMode::Classic ? 'Verze PHP' : 'Verze Node')
                    ->options(fn () => collect($record->mode === SiteMode::Classic
                        ? app(SystemInfo::class)->phpVersionOptions()
                        : app(SystemInfo::class)->nodeVersionOptions())
                        ->except($record->mode === SiteMode::Classic ? $record->php_version : $record->node_version)
                        ->all())
                    ->required(),
            ])
            ->action(function (Site $record, array $data) {
                try {
                    app(ChangeRuntime::class)->setVersion($record, $data['version']);
                    Notification::make()->title("{$record->domain}: {$record->runtimeLabel()}")->success()->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Změna verze selhala')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function checkHealth(): Action
    {
        return Action::make('checkHealth')
            ->label('Ověřit, že odpovídá')
            ->icon(Heroicon::OutlinedHeart)
            ->visible(fn (Site $record) => $record->status === SiteStatus::Active)
            ->action(function (Site $record) {
                app(CheckSiteHealth::class)->handle($record);
                $record->health_ok
                    ? Notification::make()->title("{$record->domain} odpovídá ({$record->health_status})")->success()->send()
                    : Notification::make()->title("{$record->domain} neodpovídá")->body($record->health_error)->danger()->send();
            });
    }

    public static function createDatabase(): Action
    {
        return ListDatabases::createAction()
            ->icon(Heroicon::OutlinedCircleStack)
            ->visible(fn (Site $record) => $record->status === SiteStatus::Active && app(SystemInfo::class)->dbEngines() !== []);
    }

    public static function ssh(): Action
    {
        return Action::make('ssh')
            ->label('SSH / SFTP')
            ->icon(Heroicon::OutlinedCommandLine)
            ->visible(fn (Site $record) => $record->status === SiteStatus::Active)
            ->modalHeading(fn (Site $record) => "SSH / SFTP pro {$record->domain}")
            ->modalDescription(fn (Site $record) => "Přihlášení jako {$record->system_user} (port ".config('panel.ssh_port').'), jen v rámci tohoto webu. Přesměrování portů je zakázané. Opakovaně chybná přihlášení zablokuje fail2ban.')
            ->fillForm(fn (Site $record) => ['access' => $record->ssh_access->value, 'keys' => $record->ssh_keys, 'password_mode' => 'keep'])
            ->schema(fn (Site $record) => [
                Radio::make('access')->label('Přístup')->options(SshAccess::class)->required()->live(),
                Radio::make('password_mode')
                    ->label('Heslo')
                    ->options($record->ssh_password
                        ? ['keep' => 'Ponechat současné heslo', 'generate' => 'Vygenerovat nové heslo', 'set' => 'Zadat vlastní heslo', 'remove' => 'Zrušit heslo (jen klíče)']
                        : ['keep' => 'Bez hesla (jen klíče)', 'generate' => 'Vygenerovat heslo', 'set' => 'Zadat vlastní heslo'])
                    ->descriptions(['generate' => 'Ukáže se jednou po uložení. Panel hesla neukládá.'])
                    ->required()
                    ->live()
                    ->hidden(fn (Get $get) => $get('access') === SshAccess::Off->value),
                TextInput::make('password')
                    ->label('Nové heslo')
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    ->maxLength(128)
                    ->regex('/^[\x20-\x39\x3b-\x7e]+$/')
                    ->validationMessages(['regex' => 'Jen tisknutelné ASCII znaky bez dvojtečky.'])
                    ->helperText('Alespoň 12 znaků.')
                    ->required(fn (Get $get) => $get('password_mode') === 'set')
                    ->visible(fn (Get $get) => $get('password_mode') === 'set' && $get('access') !== SshAccess::Off->value),
                Textarea::make('keys')
                    ->label('Veřejné klíče')
                    ->helperText('Jeden na řádek, např. ssh-ed25519 AAAA… jmeno@pocitac. Klíč je bezpečnější než heslo.')
                    ->rows(4)
                    ->required(fn (Get $get) => $get('access') !== SshAccess::Off->value
                        && ! in_array($get('password_mode'), ['generate', 'set'], true)
                        && ! ($record->ssh_password && $get('password_mode') === 'keep'))
                    ->validationMessages(['required' => 'Zadejte klíč, nebo nastavte heslo.']),
            ])
            ->action(function (Site $record, array $data) {
                try {
                    $generated = app(ManageSsh::class)->handle(
                        $record, $data['access'], (string) ($data['keys'] ?? ''),
                        (string) ($data['password_mode'] ?? 'keep'), $data['password'] ?? null,
                    );
                    $port = config('panel.ssh_port');
                    $body = $record->ssh_access === SshAccess::Off ? null : '<code>'.e("sftp -P {$port} {$record->sshTarget()}").'</code>';
                    if ($generated !== null) {
                        $body .= '<br><br>Heslo (zobrazí se jen teď):<br><code style="user-select:all">'.e($generated).'</code>';
                    }
                    $body = $body === null ? null : new HtmlString($body);
                    Notification::make()
                        ->title("SSH pro {$record->domain}: {$record->ssh_access->getLabel()}")
                        ->body($body)
                        ->success()
                        ->persistent($generated !== null)
                        ->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Nastavení SSH selhalo')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function tls(): Action
    {
        return Action::make('tls')
            ->label('HTTPS / certifikát')
            ->icon(Heroicon::OutlinedLockClosed)
            ->visible(fn (Site $record) => $record->isOperational())
            ->modalHeading(fn (Site $record) => "HTTPS pro {$record->domain}")
            ->fillForm(fn (Site $record) => ['mode' => $record->tls_mode ?? 'auto'])
            ->schema([
                Radio::make('mode')
                    ->label('Odkud je certifikát')
                    ->options(ManageTls::MODES)
                    ->descriptions([
                        'auto' => 'Veřejná doména mířící na server. Vydává a obnovuje se sám, zdarma.',
                        'internal' => 'Domény ve vnitřní síti a testy. Certifikát vydá server sám; počítače musí jednou nainstalovat kořenový certifikát (SSL → Kořenový certifikát).',
                        'custom' => 'Certifikát od firemní CA nebo zakoupený. Obnovu hlídáte sami (panel upozorní 14 dní předem).',
                    ])
                    ->required()
                    ->live(),
                Textarea::make('cert')
                    ->label('Certifikát (PEM, včetně mezilehlých)')
                    ->placeholder("-----BEGIN CERTIFICATE-----\n…")
                    ->rows(6)
                    ->extraInputAttributes(['class' => 'font-mono text-xs'])
                    ->required(fn (Get $get) => $get('mode') === 'custom')
                    ->visible(fn (Get $get) => $get('mode') === 'custom'),
                Textarea::make('key')
                    ->label('Privátní klíč (PEM, bez hesla)')
                    ->placeholder("-----BEGIN PRIVATE KEY-----\n…")
                    ->rows(5)
                    ->extraInputAttributes(['class' => 'font-mono text-xs'])
                    ->required(fn (Get $get) => $get('mode') === 'custom')
                    ->visible(fn (Get $get) => $get('mode') === 'custom'),
            ])
            ->action(function (Site $record, array $data) {
                try {
                    $out = app(ManageTls::class)->set($record, $data['mode'], $data['cert'] ?? null, $data['key'] ?? null);
                    $cert = $out['certificate'] ?? null;
                    Notification::make()
                        ->title("{$record->domain}: ".ManageTls::MODES[$data['mode']])
                        ->body($cert ? 'Vydal '.$cert['issuer'].', platí do '.Carbon::parse($cert['not_after'])->format('j. n. Y').'.' : 'Certifikát se vydá během chvíle.')
                        ->success()->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Certifikát se nepodařilo nastavit')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function addWorker(): Action
    {
        return Action::make('addWorker')
            ->label('Přidat worker')
            ->icon(Heroicon::OutlinedCpuChip)
            ->color('gray')
            ->visible(fn (Site $record) => $record->status === SiteStatus::Active
                && ! $record->framework->isNode()
                && app(SystemInfo::class)->workerTypes($record->framework->value) !== [])
            ->modalDescription('Worker běží jako systemd služba pod uživatelem webu, se sandboxem. Před prvním deployem se jen zaregistruje a spustí se, až bude aplikace nasazená.')
            ->schema(fn (Site $record) => [
                Select::make('worker')
                    ->label('Typ')
                    ->options(collect(app(SystemInfo::class)->workerTypes($record->framework->value))
                        ->mapWithKeys(fn (string $t) => [$t => match ($t) {
                            'horizon' => 'Horizon (artisan horizon)',
                            'queue' => 'Fronta (artisan queue:work)',
                            'scheduler' => $record->framework->value === 'laravel'
                                ? 'Plánovač (artisan schedule:work)'
                                : 'Plánovač (messenger:consume scheduler_default)',
                            'messenger' => 'Messenger (messenger:consume async)',
                            'reverb' => 'Reverb – WebSockets (artisan reverb:start)',
                            'pulse' => 'Pulse – kontroly (artisan pulse:check)',
                            'pulsework' => 'Pulse – ingest z Redisu (artisan pulse:work)',
                            default => $t,
                        }]))
                    ->required(),
            ])
            ->action(function (Site $record, array $data) {
                try {
                    $out = app(ManageWorkers::class)->create($record, $data['worker']);
                    $body = ($out['started'] ?? false) ? 'Běží.' : 'Zaregistrován, spustí se po prvním deployi.';
                    if ($data['worker'] === 'reverb') {
                        $body .= " Do .env: REVERB_HOST={$record->domain}, REVERB_PORT=443, REVERB_SCHEME=https (proxy posílá /app a /apps na Reverb).";
                    }
                    Notification::make()->title('Worker přidán')->body($body)->success()->persistent()->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Worker se nepodařilo přidat')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete')
            ->label('Smazat')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalHeading(fn (Site $record) => "Smazat web {$record->domain}?")
            ->modalDescription('Odebere FPM pool, konfiguraci proxy a systémového uživatele. Soubory webu se přesunou do koše na serveru (/srv/sites/.trash), kam má přístup jen root.')
            ->schema(fn (Site $record) => [
                TextInput::make('confirm')
                    ->label('Pro potvrzení napište doménu')
                    ->placeholder($record->domain)
                    ->required()
                    ->in([$record->domain])
                    ->validationMessages(['in' => 'Doména nesouhlasí.']),
            ])
            ->modalSubmitActionLabel('Smazat web')
            ->action(function (Site $record, $livewire) {
                try {
                    app(DeleteSite::class)->handle($record);
                    Notification::make()->title("Web {$record->domain} smazán")->success()->send();
                    $livewire->redirect(SiteResource::getUrl('index'));
                } catch (AgentException $e) {
                    Notification::make()->title('Smazání selhalo')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }
}
