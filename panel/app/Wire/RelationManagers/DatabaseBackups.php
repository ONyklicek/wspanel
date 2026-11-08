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
                TextColumn::make('taken_at')->label(__('Pořízeno'))->dateTime('j. n. Y H:i')->sortable()
                    ->description(fn (Backup $record) => $record->taken_at?->diffForHumans()),
                TextColumn::make('file')->label(__('Soubor'))->fontFamily('mono')
                    ->description(fn (Backup $record) => $record->isPreRestoreSnapshot() ? __('automatický snímek před obnovou') : null),
                TextColumn::make('size')->label(__('Velikost'))->formatStateUsing(fn ($state) => Number::fileSize((int) $state, 1)),
            ])
            ->actions([
                Action::make('download')->label(__('Stáhnout'))->icon('outline:arrow-down-tray')->color('gray')
                    ->url(fn (Backup $record) => route('backups.download', $record)),
                Action::make('restore')
                    ->label(__('Obnovit'))
                    ->icon('outline:arrow-uturn-left')
                    ->color('warning')
                    ->modalHeading(fn (Backup $record) => __('Obnovit :name ze zálohy?', ['name' => $record->database->name]))
                    ->modalDescription(fn (Backup $record) => __('Současná data přepíše záloha z :i. Předtím se pořídí snímek současného stavu, takže obnovu lze vrátit.', ['i' => $record->taken_at->format('j. n. Y H:i')]))
                    ->form(fn (Backup $record) => [
                        TextInput::make('confirm')->label(__('Pro potvrzení napište název databáze'))->placeholder($record->database->name)->required()
                            ->rules(['in:'.$record->database->name])->validationMessages(['in' => __('Název nesouhlasí.')]),
                    ])
                    ->action(function (Backup $record) {
                        try {
                            $done = app(ManageDatabases::class)->restore($record);
                            Toast::success($done ? __('Databáze obnovena') : __('Obnova běží na pozadí'));
                        } catch (AgentException $e) {
                            Toast::error(__('Obnova selhala'), $e->getMessage());
                        }
                    }),
                Action::make('delete')
                    ->label(__('Smazat'))
                    ->icon('outline:trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Smazat zálohu?'))
                    ->action(function (Backup $record) {
                        try {
                            app(ManageDatabases::class)->deleteBackup($record);
                            Toast::success(__('Záloha smazána'));
                        } catch (AgentException $e) {
                            Toast::error(__('Smazání selhalo'), $e->getMessage());
                        }
                    }),
            ])
            ->emptyState(__('Zatím žádná záloha'), __('Zálohujte tlačítkem Zálohovat teď, nebo zapněte Plán záloh.'), 'outline:archive-box')
            ->defaultSort('taken_at', 'desc');
    }
}
