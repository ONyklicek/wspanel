<?php

namespace App\Filament\Resources\Databases\Tables;

use App\Actions\Databases\ManageDatabases;
use App\Enums\DatabaseEngine;
use App\Models\Database;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class DatabasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Název')->searchable()->sortable()->fontFamily('mono')->weight('medium'),
                TextColumn::make('engine')->label('Engine')->badge(),
                TextColumn::make('site.domain')->label('Web')->placeholder('—'),
                TextColumn::make('size')->label('Velikost')->sortable()
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : Number::fileSize($state, 1)),
                TextColumn::make('backups_count')->label('Zálohy')->counts('backups'),
                TextColumn::make('backup_frequency')->label('Plán')->badge()
                    ->formatStateUsing(fn (string $state) => ['daily' => 'denně', 'weekly' => 'týdně'][$state] ?? 'vypnuto')
                    ->color(fn (string $state) => $state === 'off' ? 'gray' : 'success'),
            ])
            ->recordActions([
                ...self::toolActions(),
                ViewAction::make()->label('Zálohy'),
                ActionGroup::make([
                    self::resetPasswordAction(),
                    Action::make('drop')
                        ->label('Smazat')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->modalHeading(fn (Database $record) => "Smazat databázi {$record->name}?")
                        ->modalDescription('Nevratné: databáze i její uživatel zmizí. Existující zálohy zůstanou na serveru.')
                        ->schema(fn (Database $record) => [
                            TextInput::make('confirm')->label('Pro potvrzení napište název')->placeholder($record->name)->required()->in([$record->name]),
                        ])
                        ->action(function (Database $record) {
                            try {
                                app(ManageDatabases::class)->drop($record);
                                Notification::make()->title("Databáze {$record->name} smazána")->success()->send();
                            } catch (AgentException $e) {
                                Notification::make()->title('Smazání selhalo')->body($e->getMessage())->danger()->persistent()->send();
                            }
                        }),
                ]),
            ]);
    }

    /**
     * "phpMyAdmin" (MariaDB only) and "Adminer" buttons. Each opens a new tab
     * with temporary credentials for this one database only.
     *
     * @return list<Action>
     */
    public static function toolActions(): array
    {
        $tools = app(SystemInfo::class)->dbTools();

        return [
            Action::make('phpmyadmin')
                ->label('phpMyAdmin')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->visible(fn (Database $record) => in_array('phpmyadmin', $tools, true) && $record->engine === DatabaseEngine::MariaDb)
                ->url(fn (Database $record) => route('filament.admin.dbtools.open', [$record, 'phpmyadmin']), shouldOpenInNewTab: true),
            Action::make('adminer')
                ->label('Adminer')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->visible(fn () => in_array('adminer', $tools, true))
                ->url(fn (Database $record) => route('filament.admin.dbtools.open', [$record, 'adminer']), shouldOpenInNewTab: true),
        ];
    }

    public static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Nové heslo')
            ->icon(Heroicon::OutlinedKey)
            ->requiresConfirmation()
            ->modalDescription(fn (Database $record) => $record->site
                ? "Vygeneruje se nové heslo a zapíše do .env webu {$record->site->domain}. Aplikace ho načte po restartu/deployi (worker režim a workery restartujte)."
                : 'Vygeneruje se nové heslo a zobrazí se jen jednou.')
            ->action(function (Database $record) {
                try {
                    $password = app(ManageDatabases::class)->resetPassword($record);
                    Notification::make()
                        ->title('Heslo změněno')
                        ->body($password ? "Nové heslo pro {$record->name}: {$password} (zobrazeno jen teď)" : "Zapsáno do .env webu {$record->site?->domain}.")
                        ->success()
                        ->persistent()
                        ->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Změna hesla selhala')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }
}
