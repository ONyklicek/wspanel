<?php

namespace App\Filament\Resources\Databases\Pages;

use App\Actions\Databases\ManageDatabases;
use App\Actions\Databases\SyncDatabases;
use App\Filament\Resources\Databases\DatabaseResource;
use App\Filament\Resources\Databases\Tables\DatabasesTable;
use App\Models\Database;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDatabase extends ViewRecord
{
    protected static string $resource = DatabaseResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        rescue(fn () => app(SyncDatabases::class)->syncBackups($this->getRecord()), report: false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backupNow')
                ->label('Zálohovat teď')
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->action(function (Database $record) {
                    try {
                        $done = app(ManageDatabases::class)->backupNow($record);
                        Notification::make()->title($done ? 'Záloha hotová' : 'Záloha běží na pozadí')->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Záloha selhala')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('schedule')
                ->label('Plán záloh')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->fillForm(fn (Database $record) => ['frequency' => $record->backup_frequency, 'keep' => $record->backup_keep])
                ->schema([
                    Select::make('frequency')->label('Frekvence')->options(['off' => 'Vypnuto', 'daily' => 'Denně (kolem 3:00)', 'weekly' => 'Týdně (v neděli kolem 3:00)'])->required(),
                    TextInput::make('keep')->label('Držet záloh')->integer()->minValue(1)->maxValue(90)->required(),
                ])
                ->action(function (Database $record, array $data) {
                    try {
                        app(ManageDatabases::class)->schedule($record, $data['frequency'], (int) $data['keep']);
                        Notification::make()->title('Plán záloh uložen')->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Plán se nepodařilo uložit')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            DatabasesTable::resetPasswordAction()->color('gray'),
        ];
    }
}
