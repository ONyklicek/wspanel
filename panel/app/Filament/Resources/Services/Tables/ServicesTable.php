<?php

namespace App\Filament\Resources\Services\Tables;

use App\Actions\Services\ControlService;
use App\Actions\Sites\ManageWorkers;
use App\Enums\ServiceType;
use App\Models\Service;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('15s')
            ->defaultSort('type')
            ->columns([
                TextColumn::make('name')
                    ->label('Služba')
                    ->state(fn (Service $record) => $record->label())
                    ->description(fn (Service $record) => $record->unit)
                    ->weight('medium'),
                TextColumn::make('type')->label('Typ')->badge()->sortable(),
                TextColumn::make('active_state')
                    ->label('Stav')
                    ->badge()
                    ->formatStateUsing(fn (Service $record) => $record->active_state.($record->sub_state ? " ({$record->sub_state})" : ''))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'failed' => 'danger',
                        'activating', 'deactivating', 'reloading' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('enabled_state')->label('Po startu')->color('gray'),
                TextColumn::make('synced_at')->label('Ověřeno')->since()->color('gray'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Typ')->options(ServiceType::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::control('start', 'Spustit', Heroicon::OutlinedPlay, 'success')
                        ->visible(fn (Service $record) => ! $record->isRunning()),
                    self::control('restart', 'Restartovat', Heroicon::OutlinedArrowPath, 'warning')
                        ->requiresConfirmation()
                        ->modalDescription(fn (Service $record) => self::isPanelUnit($record)
                            ? 'Na této službě běží i panel. Během restartu bude panel na pár sekund nedostupný.'
                            : 'Služba se zastaví a znovu spustí. Probíhající požadavky se přeruší.'),
                    self::control('reload', 'Načíst config', Heroicon::OutlinedArrowPathRoundedSquare, 'info')
                        ->visible(fn (Service $record) => $record->isRunning() && $record->type->supportsReload()),
                    self::control('reset', 'Reset po chybě', Heroicon::OutlinedWrench, 'warning')
                        ->visible(fn (Service $record) => $record->isFailed())
                        ->requiresConfirmation()
                        ->modalDescription('Vymaže chybový stav (i limit pokusů o start) a službu znovu spustí.'),
                    self::control('stop', 'Zastavit', Heroicon::OutlinedStop, 'danger')
                        ->visible(fn (Service $record) => $record->isRunning() && ! self::isPanelUnit($record))
                        ->requiresConfirmation(),
                    Action::make('removeWorker')
                        ->label('Odebrat worker')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->visible(fn (Service $record) => $record->type === ServiceType::Worker && $record->site !== null)
                        ->requiresConfirmation()
                        ->modalDescription('Worker se zastaví a jeho systemd unita se odstraní.')
                        ->action(function (Service $record) {
                            try {
                                app(ManageWorkers::class)->delete($record->site, (string) $record->worker);
                                Notification::make()->title('Worker odebrán')->success()->send();
                            } catch (AgentException $e) {
                                Notification::make()->title('Odebrání selhalo')->body($e->getMessage())->danger()->persistent()->send();
                            }
                        }),
                ]),
            ]);
    }

    private static function control(string $verb, string $label, Heroicon $icon, string $color): Action
    {
        return Action::make($verb)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->action(function (Service $record) use ($verb, $label) {
                try {
                    app(ControlService::class)->handle($record, $verb);
                    Notification::make()->title("{$record->label()}: hotovo ({$label})")->success()->send();
                } catch (AgentException $e) {
                    Notification::make()->title("{$record->label()}: {$label} selhalo")->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    private static function isPanelUnit(Service $record): bool
    {
        return in_array($record->unit, app(SystemInfo::class)->panelUnits(), true);
    }
}
