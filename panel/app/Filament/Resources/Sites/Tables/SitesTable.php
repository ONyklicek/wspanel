<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Actions\Sites\DeleteSite;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
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
                TextColumn::make('php_version')->label('PHP')->prefix('PHP '),
                TextColumn::make('status')
                    ->label('Stav')
                    ->badge()
                    ->tooltip(fn (Site $record) => $record->last_error),
                TextColumn::make('system_user')->label('Uživatel')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('doc_root')->label('Doc root')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Založeno')->dateTime('j. n. Y H:i')->sortable(),
            ])
            ->recordActions([
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
            ]);
    }
}
