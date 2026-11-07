<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Actions\Sites\ChangeRuntime;
use App\Actions\Sites\CheckSiteHealth;
use App\Actions\Sites\DeleteSite;
use App\Actions\Sites\ManageSsh;
use App\Actions\Sites\ManageWorkers;
use App\Enums\SiteMode;
use App\Enums\SiteStatus;
use App\Enums\SshAccess;
use App\Filament\Resources\Databases\Pages\ListDatabases;
use App\Filament\Resources\Sites\Schemas\SiteForm;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('domain')
            ->columns([
                TextColumn::make('domain')
                    ->label('Doména')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->url(fn (Site $record) => 'https://'.$record->domain, shouldOpenInNewTab: true),
                TextColumn::make('framework')->label('Framework')->badge(),
                TextColumn::make('mode')->label('Režim'),
                TextColumn::make('runtime')
                    ->label('Runtime')
                    ->state(fn (Site $record) => $record->runtimeLabel())
                    ->description(fn (Site $record) => $record->port ? "127.0.0.1:{$record->port}" : null),
                TextColumn::make('status')
                    ->label('Stav')
                    ->badge()
                    ->tooltip(fn (Site $record) => $record->last_error),
                TextColumn::make('health_ok')
                    ->label('Odpovídá')
                    ->badge()
                    ->state(fn (Site $record) => match (true) {
                        $record->health_checked_at === null => 'neověřeno',
                        $record->health_ok => 'ano'.($record->health_status ? " ({$record->health_status})" : ''),
                        default => 'ne'.($record->health_status ? " ({$record->health_status})" : ''),
                    })
                    ->color(fn (Site $record) => $record->health_checked_at === null ? 'gray' : ($record->health_ok ? 'success' : 'danger'))
                    ->tooltip(fn (Site $record) => $record->health_error
                        ?? ($record->health_checked_at ? 'ověřeno '.$record->health_checked_at->diffForHumans() : null)),
                TextColumn::make('ssh_access')
                    ->label('SSH')
                    ->badge()
                    ->tooltip(fn (Site $record) => $record->ssh_access === SshAccess::Off ? null
                        : 'sftp://'.$record->system_user.'@'.request()->getHost()),
                TextColumn::make('system_user')->label('Uživatel')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('doc_root')->label('Doc root')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Založeno')->dateTime('j. n. Y H:i')->sortable(),
            ])
            ->poll('30s')
            ->recordActions([
                Action::make('deploy')
                    ->label('Deploy')
                    ->icon(Heroicon::OutlinedRocketLaunch)
                    ->visible(fn (Site $record) => $record->status === SiteStatus::Active)
                    ->url(fn (Site $record) => SiteResource::getUrl('deploy', ['record' => $record])),
                ActionGroup::make([
                    Action::make('switchMode')
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
                        }),
                    Action::make('setVersion')
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
                        }),
                    Action::make('checkHealth')
                        ->label('Ověřit, že odpovídá')
                        ->icon(Heroicon::OutlinedHeart)
                        ->visible(fn (Site $record) => $record->status === SiteStatus::Active)
                        ->action(function (Site $record) {
                            app(CheckSiteHealth::class)->handle($record);
                            $record->health_ok
                                ? Notification::make()->title("{$record->domain} odpovídá ({$record->health_status})")->success()->send()
                                : Notification::make()->title("{$record->domain} neodpovídá")->body($record->health_error)->danger()->send();
                        }),
                    ListDatabases::createAction()
                        ->icon(Heroicon::OutlinedCircleStack)
                        ->visible(fn (Site $record) => $record->status === SiteStatus::Active && app(SystemInfo::class)->dbEngines() !== []),
                    Action::make('ssh')
                        ->label('SSH / SFTP')
                        ->icon(Heroicon::OutlinedCommandLine)
                        ->visible(fn (Site $record) => $record->status === SiteStatus::Active)
                        ->modalHeading(fn (Site $record) => "SSH / SFTP pro {$record->domain}")
                        ->modalDescription(fn (Site $record) => "Přihlášení jen klíčem jako {$record->system_user}, v rámci tohoto webu. Přesměrování portů je zakázané. Přímé SSH obchází audit panelu.")
                        ->fillForm(fn (Site $record) => ['access' => $record->ssh_access->value, 'keys' => $record->ssh_keys])
                        ->schema([
                            Radio::make('access')->label('Přístup')->options(SshAccess::class)->required()->live(),
                            Textarea::make('keys')
                                ->label('Veřejné klíče')
                                ->helperText('Jeden na řádek, např. ssh-ed25519 AAAA… jmeno@pocitac. Hesla jsou vypnutá.')
                                ->rows(4)
                                ->required(fn (Get $get) => ! in_array($get('access'), [SshAccess::Off, SshAccess::Off->value], true)),
                        ])
                        ->action(function (Site $record, array $data) {
                            try {
                                app(ManageSsh::class)->handle($record, $data['access'], (string) ($data['keys'] ?? ''));
                                Notification::make()
                                    ->title("SSH pro {$record->domain}: {$record->ssh_access->getLabel()}")
                                    ->body($record->ssh_access === SshAccess::Off ? null : "ssh {$record->system_user}@".request()->getHost())
                                    ->success()->send();
                            } catch (AgentException $e) {
                                Notification::make()->title('Nastavení SSH selhalo')->body($e->getMessage())->danger()->persistent()->send();
                            }
                        }),
                    Action::make('addWorker')
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
                        }),
                    Action::make('delete')
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
                        ->action(function (Site $record) {
                            try {
                                app(DeleteSite::class)->handle($record);
                                Notification::make()->title("Web {$record->domain} smazán")->success()->send();
                            } catch (AgentException $e) {
                                Notification::make()->title('Smazání selhalo')->body($e->getMessage())->danger()->persistent()->send();
                            }
                        }),
                ]),
            ]);
    }
}
