<?php

namespace App\Filament\Resources\CronJobs\Tables;

use App\Actions\ManageCronJobs;
use App\Filament\Resources\CronJobs\Pages\ListCronJobs;
use App\Models\CronJob;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CronJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('site_id')
            ->columns([
                TextColumn::make('site.domain')->label('Web')->sortable(),
                TextColumn::make('command')->label('Příkaz')->state(fn (CronJob $record) => $record->commandLine())->fontFamily('mono'),
                TextColumn::make('schedule')->label('Rozvrh')->fontFamily('mono')->description(fn (CronJob $record) => $record->calendar),
                TextColumn::make('last_result')
                    ->label('Poslední běh')
                    ->badge()
                    ->state(fn (CronJob $record) => $record->last_run && $record->last_run !== 'n/a' ? ($record->last_result === 'success' ? 'OK' : ($record->last_result ?? '?')) : 'zatím neběžela')
                    ->color(fn (CronJob $record) => match (true) {
                        ! $record->last_run || $record->last_run === 'n/a' => 'gray',
                        $record->last_result === 'success' => 'success',
                        default => 'danger',
                    })
                    ->description(fn (CronJob $record) => $record->last_run && $record->last_run !== 'n/a' ? $record->last_run : null),
                TextColumn::make('next_run')->label('Další běh')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('site')->label('Web')->relationship('site', 'domain')])
            ->recordActions([
                Action::make('edit')
                    ->label('Upravit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (CronJob $record) => ['site_id' => $record->site_id, 'kind' => $record->kind, 'command' => implode(' ', $record->args), 'schedule' => $record->schedule])
                    ->schema(fn () => ListCronJobs::taskFields(editing: true))
                    ->action(function (CronJob $record, array $data) {
                        try {
                            app(ManageCronJobs::class)->update($record, $data);
                            Notification::make()->title('Úloha upravena')->success()->send();
                        } catch (AgentException $e) {
                            Notification::make()->title('Úpravu se nepodařilo uložit')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                Action::make('delete')
                    ->label('Smazat')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (CronJob $record) {
                        try {
                            app(ManageCronJobs::class)->delete($record);
                            Notification::make()->title('Úloha smazána')->success()->send();
                        } catch (AgentException $e) {
                            Notification::make()->title('Smazání selhalo')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
