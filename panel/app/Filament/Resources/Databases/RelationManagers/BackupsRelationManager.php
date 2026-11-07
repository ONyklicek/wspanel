<?php

namespace App\Filament\Resources\Databases\RelationManagers;

use App\Actions\Databases\ManageDatabases;
use App\Models\Backup;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class BackupsRelationManager extends RelationManager
{
    protected static string $relationship = 'backups';

    protected static ?string $title = 'Zálohy';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file')
            ->defaultSort('taken_at', 'desc')
            ->columns([
                TextColumn::make('taken_at')->label('Pořízeno')->dateTime('j. n. Y H:i')->description(fn (Backup $record) => $record->taken_at->diffForHumans()),
                TextColumn::make('file')->label('Soubor')->fontFamily('mono')
                    ->badge(fn (Backup $record) => $record->isPreRestoreSnapshot())
                    ->color(fn (Backup $record) => $record->isPreRestoreSnapshot() ? 'warning' : null)
                    ->tooltip(fn (Backup $record) => $record->isPreRestoreSnapshot() ? 'Automatický snímek před obnovou' : null),
                TextColumn::make('size')->label('Velikost')->formatStateUsing(fn (int $state) => Number::fileSize($state, 1)),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Stáhnout')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (Backup $record) => route('filament.admin.backups.download', $record)),
                Action::make('restore')
                    ->label('Obnovit')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->modalHeading(fn (Backup $record) => "Obnovit {$record->database->name} ze zálohy?")
                    ->modalDescription(fn (Backup $record) => "Současná data přepíše záloha z {$record->taken_at->format('j. n. Y H:i')}. Předtím se automaticky pořídí snímek současného stavu, takže obnovu lze vrátit.")
                    ->schema(fn (Backup $record) => [
                        TextInput::make('confirm')->label('Pro potvrzení napište název databáze')->placeholder($record->database->name)->required()->in([$record->database->name]),
                    ])
                    ->action(function (Backup $record) {
                        try {
                            $done = app(ManageDatabases::class)->restore($record);
                            Notification::make()->title($done ? 'Databáze obnovena' : 'Obnova běží na pozadí')->success()->send();
                        } catch (AgentException $e) {
                            Notification::make()->title('Obnova selhala')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                Action::make('delete')
                    ->label('Smazat')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Backup $record) {
                        try {
                            app(ManageDatabases::class)->deleteBackup($record);
                            Notification::make()->title('Záloha smazána')->success()->send();
                        } catch (AgentException $e) {
                            Notification::make()->title('Smazání selhalo')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
