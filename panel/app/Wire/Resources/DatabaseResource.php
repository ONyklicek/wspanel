<?php

namespace App\Wire\Resources;

use App\Actions\Databases\ManageDatabases;
use App\Enums\DatabaseEngine;
use App\Livewire\Databases\ListDatabases;
use App\Livewire\Databases\ViewDatabase;
use App\Models\Database;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Toast;
use App\Wire\RelationManagers\DatabaseBackups;
use Illuminate\Support\Number;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireCore\GlobalSearch\Contracts\GloballySearchable;
use NyonCode\WireCore\GlobalSearch\GlobalSearchResult;
use NyonCode\WireCore\Infolists\Components\TextEntry;
use NyonCode\WireCore\Infolists\Contracts\ProvidesResourceInfolist;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Resources\Contracts\ProvidesRelationManagers;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Table;

final class DatabaseResource implements DescribesResource, GloballySearchable, ProvidesNavigation, ProvidesPages, ProvidesRelationManagers, ProvidesResourceInfolist, ProvidesResourceTable
{
    use DescribesRecords;

    public const RESERVED = ['mysql', 'information_schema', 'performance_schema', 'sys', 'root', 'postgres', 'template0', 'template1', 'public', 'panel'];

    public static function modelClass(): ?string
    {
        return Database::class;
    }

    public static function key(): string
    {
        return 'databases';
    }

    public static function label(): string
    {
        return 'Databáze';
    }

    public static function pluralLabel(): string
    {
        return 'Databáze';
    }

    public static function pages(): array
    {
        return ['index' => ListDatabases::class, 'view' => ViewDatabase::class];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make()->icon('outline:circle-stack')->group('hosting')->sort(20);
    }

    public static function globallySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function toGlobalSearchResult(object $record): GlobalSearchResult
    {
        return new GlobalSearchResult(resourceKey: self::key(), recordKey: $record->getKey(), title: $record->name,
            subtitle: $record->engine->getLabel().($record->site ? ' · '.$record->site->domain : ''), icon: 'outline:circle-stack');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Database::query()->with('site')->withCount('backups'))
            ->columns([
                TextColumn::make('name')->label('Název')->searchable()->sortable()->fontFamily('mono')->weight('medium'),
                BadgeColumn::make('engine')->label('Engine')->formatStateUsing(fn ($state) => $state instanceof DatabaseEngine ? $state->getLabel() : $state),
                TextColumn::make('site_domain')->label('Web')->state(fn (Database $record) => $record->site?->domain ?? '—'),
                TextColumn::make('size')->label('Velikost')->sortable()->formatStateUsing(fn ($state) => $state === null ? '—' : Number::fileSize((int) $state, 1)),
                TextColumn::make('backups_count')->label('Zálohy'),
                BadgeColumn::make('backup_frequency')->label('Plán záloh')
                    ->formatStateUsing(fn ($state) => ['daily' => 'denně', 'weekly' => 'týdně'][$state] ?? 'vypnuto')
                    ->colors(['daily' => 'success', 'weekly' => 'success', 'off' => 'gray']),
            ])
            ->recordUrl(fn (Database $record) => self::url('view', $record))
            ->actions([...self::toolActions(), ActionGroup::make([self::resetPasswordAction(), self::dropAction()])])
            ->emptyState('Zatím žádná databáze', 'Vytvořte ji tlačítkem Nová databáze, nebo u webu (Další → Nová databáze).', 'outline:circle-stack')
            ->defaultSort('name');
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('db')->label('Databáze')->icon('outline:circle-stack')->columns(2)->schema([
                TextEntry::make('engine')->label('Engine')->state(fn (Database $record) => $record->engine->getLabel())->badge()->color('gray'),
                TextEntry::make('site')->label('Web')->state(fn (Database $record) => $record->site?->domain ?? 'nepřipojeno'),
                TextEntry::make('size')->label('Velikost')->state(fn (Database $record) => $record->size === null ? '—' : Number::fileSize($record->size, 1)),
                TextEntry::make('backup_frequency')->label('Automatické zálohy')->state(fn (Database $record) => match ($record->backup_frequency) {
                    'daily' => "denně, drží {$record->backup_keep}",
                    'weekly' => "týdně, drží {$record->backup_keep}",
                    default => 'vypnuto',
                }),
                TextEntry::make('connection')->label('Připojení')->copyable()
                    ->state(fn (Database $record) => '127.0.0.1:'.($record->engine === DatabaseEngine::MariaDb ? 3306 : 5432).' · uživatel '.$record->name),
                TextEntry::make('credentials')->label('Údaje')->state(fn (Database $record) => $record->site
                    ? 'zapsané v .env webu '.$record->site->domain
                    : 'heslo panel nedrží; v případě potřeby vygenerujte nové'),
            ]),
        ]);
    }

    public function relationManagers(): array
    {
        return [DatabaseBackups::class];
    }

    /** @return list<Action> phpMyAdmin (MariaDB) and Adminer: a new tab, a temporary login for this one database. */
    public static function toolActions(): array
    {
        $tools = app(SystemInfo::class)->dbTools();

        return [
            Action::make('phpmyadmin')->label('phpMyAdmin')->icon('outline:table-cells')->color('gray')
                ->visible(fn (Database $record) => in_array('phpmyadmin', $tools, true) && $record->engine === DatabaseEngine::MariaDb)
                ->url(fn (Database $record) => route('dbtools.open', [$record, 'phpmyadmin']), true),
            Action::make('adminer')->label('Adminer')->icon('outline:table-cells')->color('gray')
                ->visible(fn () => in_array('adminer', $tools, true))
                ->url(fn (Database $record) => route('dbtools.open', [$record, 'adminer']), true),
        ];
    }

    public static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Nové heslo')
            ->icon('outline:key')
            ->requiresConfirmation()
            ->modalDescription(fn (Database $record) => $record->site
                ? "Vygeneruje se nové heslo a zapíše do .env webu {$record->site->domain}. Proces aplikace a workery ho načtou po restartu webu."
                : 'Vygeneruje se nové heslo a zobrazí se jen jednou.')
            ->action(function (Database $record) {
                try {
                    $password = app(ManageDatabases::class)->resetPassword($record);
                    $password
                        ? Toast::secret('Heslo změněno', "Nové heslo pro {$record->name} (zobrazeno jen teď): {$password}")
                        : Toast::success('Heslo změněno', "Zapsáno do .env webu {$record->site?->domain}.");
                } catch (AgentException $e) {
                    Toast::error('Změna hesla selhala', $e->getMessage());
                }
            });
    }

    public static function dropAction(): Action
    {
        return Action::make('drop')
            ->label('Smazat')
            ->icon('outline:trash')
            ->color('danger')
            ->modalHeading(fn (Database $record) => "Smazat databázi {$record->name}?")
            ->modalDescription('Nevratné: databáze i její uživatel zmizí. Existující zálohy zůstanou na serveru.')
            ->form(fn (Database $record) => [
                TextInput::make('confirm')->label('Pro potvrzení napište název')->placeholder($record->name)->required()
                    ->rules(['in:'.$record->name])->validationMessages(['in' => 'Název nesouhlasí.']),
            ])
            ->action(function (Database $record) {
                try {
                    app(ManageDatabases::class)->drop($record);
                    Toast::success("Databáze {$record->name} smazána");
                } catch (AgentException $e) {
                    Toast::error('Smazání selhalo', $e->getMessage());
                }
            })
            ->successRedirect(fn () => self::url('index'));
    }

    /** "New database", optionally preselecting a site. */
    public static function createAction(): Action
    {
        $engines = collect(app(SystemInfo::class)->dbEngines())->mapWithKeys(fn ($e) => [$e->value => $e->getLabel()]);

        return Action::make('createDatabase')
            ->label('Nová databáze')
            ->icon('outline:plus')
            ->visible($engines->isNotEmpty())
            ->modalDescription('Vytvoří databázi a jejího vlastníka se stejným názvem a náhodným heslem. S připojeným webem se údaje zapíší rovnou do jeho .env a panel heslo neuvidí.')
            ->fillFormUsing(fn () => ['engine' => $engines->keys()->first(), 'site_id' => null, 'name' => null])
            ->form([
                Select::make('engine')->label('Engine')->options($engines->all())->required(),
                Select::make('site_id')
                    ->label('Web')
                    ->options(fn () => Site::query()->where('status', 'active')->orderBy('domain')->pluck('domain', 'id')->all())
                    ->placeholder('nepřipojovat')
                    ->live()
                    ->afterStateUpdated(function ($state, $get, $set) {
                        if ($state && blank($get('name'))) {
                            $set('name', Database::suggestName((string) Site::find($state)?->domain));
                        }
                    }),
                TextInput::make('name')
                    ->label('Název (i uživatel)')
                    ->required()
                    ->rules(['regex:/^[a-z][a-z0-9_]{1,47}$/', 'not_in:'.implode(',', self::RESERVED)])
                    ->validationMessages(['regex' => 'Malá písmena, číslice a podtržítko; začíná písmenem.', 'not_in' => 'Tento název je rezervovaný.'])
                    ->helperText('Malá písmena, číslice a podtržítko; začíná písmenem.'),
            ])
            ->action(function (array $data) {
                try {
                    $site = $data['site_id'] ? Site::find($data['site_id']) : null;
                    $result = app(ManageDatabases::class)->create($data['engine'], $data['name'], $site);
                    $result['password']
                        ? Toast::secret("Databáze {$data['name']} vytvořena", "Uživatel {$data['name']}, heslo (zobrazeno jen teď): {$result['password']}")
                        : Toast::success("Databáze {$data['name']} vytvořena", "Údaje zapsány do .env webu {$site?->domain}.");
                } catch (AgentException $e) {
                    Toast::error('Databázi se nepodařilo vytvořit', $e->getMessage());
                }
            });
    }
}
