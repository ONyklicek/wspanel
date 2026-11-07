<?php

namespace App\Wire\RelationManagers;

use App\Actions\Databases\ManageDatabases;
use App\Models\Backup;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Support\Number;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\RelationManagers\RelationManager;
use NyonCode\WireTable\Table;

class DatabaseBackups extends RelationManager
{
    protected string $relationship = 'backups';

    protected ?string $title = 'Zálohy';

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('taken_at')->label('Pořízeno')->dateTime('j. n. Y H:i')->sortable()
                    ->description(fn (Backup $record) => $record->taken_at?->diffForHumans()),
                TextColumn::make('file')->label('Soubor')->fontFamily('mono')
                    ->description(fn (Backup $record) => $record->isPreRestoreSnapshot() ? 'automatický snímek před obnovou' : null),
                TextColumn::make('size')->label('Velikost')->formatStateUsing(fn ($state) => Number::fileSize((int) $state, 1)),
            ])
            ->actions([
                Action::make('download')->label('Stáhnout')->icon('outline:arrow-down-tray')->color('gray')
                    ->url(fn (Backup $record) => route('backups.download', $record)),
                Action::make('restore')
                    ->label('Obnovit')
                    ->icon('outline:arrow-uturn-left')
                    ->color('warning')
                    ->modalHeading(fn (Backup $record) => "Obnovit {$record->database->name} ze zálohy?")
                    ->modalDescription(fn (Backup $record) => "Současná data přepíše záloha z {$record->taken_at->format('j. n. Y H:i')}. Předtím se pořídí snímek současného stavu, takže obnovu lze vrátit.")
                    ->form(fn (Backup $record) => [
                        TextInput::make('confirm')->label('Pro potvrzení napište název databáze')->placeholder($record->database->name)->required()
                            ->rules(['in:'.$record->database->name])->validationMessages(['in' => 'Název nesouhlasí.']),
                    ])
                    ->action(function (Backup $record) {
                        try {
                            $done = app(ManageDatabases::class)->restore($record);
                            Toast::success($done ? 'Databáze obnovena' : 'Obnova běží na pozadí');
                        } catch (AgentException $e) {
                            Toast::error('Obnova selhala', $e->getMessage());
                        }
                    }),
                Action::make('delete')
                    ->label('Smazat')
                    ->icon('outline:trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Smazat zálohu?')
                    ->action(function (Backup $record) {
                        try {
                            app(ManageDatabases::class)->deleteBackup($record);
                            Toast::success('Záloha smazána');
                        } catch (AgentException $e) {
                            Toast::error('Smazání selhalo', $e->getMessage());
                        }
                    }),
            ])
            ->emptyState('Zatím žádná záloha', 'Zálohujte tlačítkem Zálohovat teď, nebo zapněte Plán záloh.', 'outline:archive-box')
            ->defaultSort('taken_at', 'desc');
    }
}
