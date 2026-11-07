<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Actions\Sites\ChangeRuntime;
use App\Actions\Sites\CheckSiteHealth;
use App\Actions\Sites\DeleteSite;
use App\Actions\Sites\ManageWorkers;
use App\Enums\SiteMode;
use App\Enums\SiteStatus;
use App\Filament\Resources\Sites\Schemas\SiteForm;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
                TextColumn::make('system_user')->label('Uživatel')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('doc_root')->label('Doc root')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Založeno')->dateTime('j. n. Y H:i')->sortable(),
            ])
            ->poll('30s')
            ->recordActions([
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
                                        default => $t,
                                    }]))
                                ->required(),
                        ])
                        ->action(function (Site $record, array $data) {
                            try {
                                $out = app(ManageWorkers::class)->create($record, $data['worker']);
                                Notification::make()
                                    ->title('Worker přidán')
                                    ->body(($out['started'] ?? false) ? 'Běží.' : 'Zaregistrován, spustí se po prvním deployi.')
                                    ->success()->send();
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
