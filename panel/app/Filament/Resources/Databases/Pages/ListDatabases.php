<?php

namespace App\Filament\Resources\Databases\Pages;

use App\Actions\Databases\ManageDatabases;
use App\Actions\Databases\SyncDatabases;
use App\Filament\Resources\Databases\DatabaseResource;
use App\Models\Database;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

class ListDatabases extends ListRecords
{
    protected static string $resource = DatabaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::createAction(),
            Action::make('sync')
                ->label('Ověřit stav')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function () {
                    try {
                        $n = app(SyncDatabases::class)->handle();
                        Notification::make()->title("Ověřeno {$n} databází")->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Agent není dostupný')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    /** Also used from the sites list (prefilled with the site). */
    public static function createAction(?Site $site = null): Action
    {
        $engines = collect(app(SystemInfo::class)->dbEngines())->mapWithKeys(fn ($e) => [$e->value => $e->getLabel()]);

        return Action::make('createDatabase')
            ->label('Nová databáze')
            ->icon(Heroicon::OutlinedPlus)
            ->modalDescription('Vytvoří databázi a jejího vlastníka se stejným názvem a náhodným heslem. S připojeným webem se údaje zapíší rovnou do jeho .env (Laravel DB_*, Symfony a Node DATABASE_URL) a panel heslo neuvidí.')
            ->fillForm(fn (?Site $record = null) => [
                'engine' => $engines->keys()->first(),
                'site_id' => ($site ?? ($record instanceof Site ? $record : null))?->id,
                'name' => ($site ?? ($record instanceof Site ? $record : null)) ? Database::suggestName(($site ?? $record)->domain) : null,
            ])
            ->schema([
                Select::make('engine')->label('Engine')->options($engines->all())->required()->selectablePlaceholder(false),
                Select::make('site_id')
                    ->label('Web')
                    ->options(fn () => Site::query()->where('status', 'active')->orderBy('domain')->pluck('domain', 'id')->all())
                    ->placeholder('nepřipojovat')
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        if ($state && blank($get('name'))) {
                            $set('name', Database::suggestName(Site::find($state)->domain));
                        }
                    }),
                TextInput::make('name')
                    ->label('Název (i uživatel)')
                    ->required()
                    ->regex('/^[a-z][a-z0-9_]{1,47}$/')
                    ->notIn(['mysql', 'information_schema', 'performance_schema', 'sys', 'root', 'postgres', 'template0', 'template1', 'public', 'panel'])
                    ->helperText('Malá písmena, číslice a podtržítko; začíná písmenem.'),
            ])
            ->action(function (array $data) {
                try {
                    $site = $data['site_id'] ? Site::find($data['site_id']) : null;
                    $result = app(ManageDatabases::class)->create($data['engine'], $data['name'], $site);
                    Notification::make()
                        ->title("Databáze {$data['name']} vytvořena")
                        ->body($result['password']
                            ? "Uživatel {$data['name']}, heslo: {$result['password']} (zobrazeno jen teď)"
                            : "Údaje zapsány do .env webu {$site?->domain}.")
                        ->success()
                        ->persistent()
                        ->send();
                } catch (AgentException $e) {
                    Notification::make()->title('Databázi se nepodařilo vytvořit')->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }
}
