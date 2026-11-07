<?php

namespace App\Livewire\Databases;

use App\Actions\Databases\ManageDatabases;
use App\Actions\Databases\SyncDatabases;
use App\Models\Database;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Forms\Options;
use App\Wire\Resources\DatabaseResource;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

class ViewDatabase extends ViewPage
{
    protected static ?string $resource = DatabaseResource::class;

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $db = $this->nativeRecord();
        if ($db instanceof Database) {
            rescue(fn () => app(SyncDatabases::class)->syncBackups($db), report: false);
        }
    }

    public function getTitle(): ?string
    {
        $db = $this->nativeRecord();

        return $db instanceof Database ? $db->name : 'Databáze';
    }

    protected function headerActions(): array
    {
        return [
            ...DatabaseResource::toolActions(),
            Action::make('backupNow')
                ->label('Zálohovat teď')
                ->icon('outline:archive-box-arrow-down')
                ->action(function (Database $record) {
                    try {
                        $done = app(ManageDatabases::class)->backupNow($record);
                        Toast::success($done ? 'Záloha hotová' : 'Záloha běží na pozadí');
                    } catch (AgentException $e) {
                        Toast::error('Záloha selhala', $e->getMessage());
                    }
                }),
            Action::make('schedule')
                ->label('Plán záloh')
                ->icon('outline:calendar-days')
                ->color('gray')
                ->fillFormUsing(fn (Database $record) => ['frequency' => $record->backup_frequency, 'keep' => $record->backup_keep])
                ->form([
                    Select::make('frequency')->rules([Options::rule()])->label('Frekvence')->options(['off' => 'Vypnuto', 'daily' => 'Denně (kolem 3:00)', 'weekly' => 'Týdně (v neděli kolem 3:00)'])->required(),
                    TextInput::make('keep')->label('Držet záloh')->integer()->minValue(1)->maxValue(90)->rules(['integer', 'between:1,90'])->required(),
                ])
                ->action(function (Database $record, array $data) {
                    try {
                        app(ManageDatabases::class)->schedule($record, $data['frequency'], (int) $data['keep']);
                        Toast::success('Plán záloh uložen');
                    } catch (AgentException $e) {
                        Toast::error('Plán se nepodařilo uložit', $e->getMessage());
                    }
                }),
            ActionGroup::make([DatabaseResource::resetPasswordAction(), DatabaseResource::dropAction()])->label('Další')->icon('outline:ellipsis-vertical'),
        ];
    }
}
