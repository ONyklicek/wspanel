<?php

namespace App\Wire\Resources;

use App\Actions\Databases\ManageDatabaseInstances;
use App\Actions\Databases\ManageDatabases;
use App\Enums\DatabaseEngine;
use App\Livewire\Databases\ListDatabases;
use App\Livewire\Databases\ViewDatabase;
use App\Models\Database;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Toast;
use App\Wire\Forms\Options;
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
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Resources\Contracts\ProvidesRelationManagers;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
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
        return __('Databáze');
    }

    public static function pluralLabel(): string
    {
        return __('Databáze');
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
            ->stackedOnMobile()
            ->query(Database::query()->with('site')->withCount('backups'))
            ->columns([
                TextColumn::make('name')->label(__('Databáze'))->searchable()->sortable()->fontFamily('mono')->weight('medium')
                    ->description(fn (Database $record) => $record->engine->getLabel().' · '.($record->site?->domain ?? __('bez webu')).($record->isDedicated() ? ' · '.__('vyhrazený server') : '')),
                TextColumn::make('size')->label(__('Velikost'))->sortable()->formatStateUsing(fn ($state) => $state === null ? '—' : Number::fileSize((int) $state, 1)),
                TextColumn::make('backups_count')->label(__('Zálohy'))
                    ->formatStateUsing(fn ($state) => (int) $state === 0 ? __('žádná') : $state.' '.((int) $state === 1 ? __('záloha') : ((int) $state < 5 ? __('zálohy') : __('záloh'))))
                    ->description(fn (Database $record) => ['daily' => __('automaticky denně'), 'weekly' => __('automaticky týdně')][$record->backup_frequency] ?? __('automatické zálohy vypnuté')),
            ])
            ->recordUrl(fn (Database $record) => self::url('view', $record))
            ->actions([self::openAction(), ActionGroup::make([...self::toolActions(), self::resetPasswordAction(), self::dropAction()])])
            ->emptyState(__('Zatím žádná databáze'), __('Vytvořte ji tlačítkem Nová databáze, nebo u webu (Další → Nová databáze).'), 'outline:circle-stack')
            ->defaultSort('name');
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('db')->label(__('Databáze'))->icon('outline:circle-stack')->columns(2)->schema([
                TextEntry::make('engine')->label(__('Engine'))->state(fn (Database $record) => $record->engine->getLabel())->badge()->color('gray'),
                TextEntry::make('site')->label(__('Web'))->state(fn (Database $record) => $record->site?->domain ?? __('nepřipojeno')),
                TextEntry::make('size')->label(__('Velikost'))->state(fn (Database $record) => $record->size === null ? '—' : Number::fileSize($record->size, 1)),
                TextEntry::make('backup_frequency')->label(__('Automatické zálohy'))->state(fn (Database $record) => match ($record->backup_frequency) {
                    'daily' => __('denně, drží :backup_keep', ['backup_keep' => $record->backup_keep]),
                    'weekly' => __('týdně, drží :backup_keep', ['backup_keep' => $record->backup_keep]),
                    default => 'vypnuto',
                }),
                TextEntry::make('connection')->label(__('Připojení'))->copyable()
                    ->state(fn (Database $record) => self::connection($record).__(' · uživatel ').$record->name),
                TextEntry::make('credentials')->label(__('Údaje'))->state(fn (Database $record) => $record->site
                    ? __('zapsané v .env webu ').$record->site->domain
                    : __('heslo panel nedrží; v případě potřeby vygenerujte nové')),
            ]),
        ]);
    }

    /** Where the site connects: 127.0.0.1, or its dedicated server's socket. */
    public static function connection(Database $record): string
    {
        if ($record->isDedicated()) {
            $i = collect(rescue(fn () => app(ManageDatabaseInstances::class)->list(), [], report: false))->firstWhere('id', $record->instance);

            return $i === null ? __('vyhrazený server webu') : ($i['socket'] ?? null ?: $i['host'].':'.$i['port']);
        }

        return '127.0.0.1:'.match ($record->engine) {
            DatabaseEngine::MariaDb => 3306,
            DatabaseEngine::MongoDb => 27017,
            default => 5432,
        };
    }

    public function relationManagers(): array
    {
        return [DatabaseBackups::class];
    }

    /** The one button of a row: phpMyAdmin for MariaDB, Adminer for PostgreSQL (or whichever is installed). */
    public static function openAction(): Action
    {
        $tools = app(SystemInfo::class)->dbTools();
        $tool = fn (Database $record) => $record->engine === DatabaseEngine::MariaDb && in_array('phpmyadmin', $tools, true) ? 'phpmyadmin' : 'adminer';

        return Action::make('open')->label(__('Otevřít'))->icon('outline:arrow-top-right-on-square')->color('gray')
            ->tooltip(fn (Database $record) => $tool($record) === 'phpmyadmin' ? 'V phpMyAdminu' : 'V Admineru')
            ->visible(fn (Database $record) => $record->engine->hasTools() && ! $record->isDedicated() && in_array($tool($record), $tools, true))
            ->url(fn (Database $record) => route('dbtools.open', [$record, $tool($record)]), true);
    }

    /** @return list<Action> phpMyAdmin (MariaDB) and Adminer: a new tab, a temporary login for this one database. */
    public static function toolActions(): array
    {
        $tools = app(SystemInfo::class)->dbTools();

        return [
            Action::make('phpmyadmin')->label('phpMyAdmin')->icon('outline:table-cells')->color('gray')
                ->visible(fn (Database $record) => ! $record->isDedicated() && in_array('phpmyadmin', $tools, true) && $record->engine === DatabaseEngine::MariaDb)
                ->url(fn (Database $record) => route('dbtools.open', [$record, 'phpmyadmin']), true),
            Action::make('adminer')->label(__('Adminer'))->icon('outline:table-cells')->color('gray')
                ->visible(fn (Database $record) => $record->engine->hasTools() && ! $record->isDedicated() && in_array('adminer', $tools, true))
                ->url(fn (Database $record) => route('dbtools.open', [$record, 'adminer']), true),
        ];
    }

    public static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label(__('Nové heslo'))
            ->icon('outline:key')
            ->requiresConfirmation()
            ->modalDescription(fn (Database $record) => $record->site
                ? __('Vygeneruje se nové heslo a zapíše do .env webu :domain. Proces aplikace a workery ho načtou po restartu webu.', ['domain' => $record->site->domain])
                : __('Vygeneruje se nové heslo a zobrazí se jen jednou.'))
            ->action(function (Database $record) {
                try {
                    $password = app(ManageDatabases::class)->resetPassword($record);
                    $password
                        ? Toast::secret(__('Heslo změněno'), __('Nové heslo pro :name (zobrazeno jen teď): :password', ['name' => $record->name, 'password' => $password]))
                        : Toast::success(__('Heslo změněno'), __('Zapsáno do .env webu :domain.', ['domain' => $record->site?->domain]));
                } catch (AgentException $e) {
                    Toast::error(__('Změna hesla selhala'), $e->getMessage());
                }
            });
    }

    public static function dropAction(): Action
    {
        return Action::make('drop')
            ->label(__('Smazat'))
            ->icon('outline:trash')
            ->color('danger')
            ->modalHeading(fn (Database $record) => __('Smazat databázi :name?', ['name' => $record->name]))
            ->modalDescription(__('Nevratné: databáze i její uživatel zmizí. Existující zálohy zůstanou na serveru.'))
            ->form(fn (Database $record) => [
                TextInput::make('confirm')->label(__('Pro potvrzení napište název'))->placeholder($record->name)->required()
                    ->rules(['in:'.$record->name])->validationMessages(['in' => __('Název nesouhlasí.')]),
            ])
            ->action(function (Database $record) {
                try {
                    app(ManageDatabases::class)->drop($record);
                    Toast::success(__('Databáze :name smazána', ['name' => $record->name]));
                } catch (AgentException $e) {
                    Toast::error(__('Smazání selhalo'), $e->getMessage());
                }
            })
            ->successRedirect(fn () => self::url('index'));
    }

    /** "New database", optionally preselecting a site. */
    /** The dedicated server of the chosen site and engine, if it has one. */
    public static function siteInstance(mixed $siteId, mixed $engine): ?array
    {
        $site = $siteId ? Site::find($siteId) : null;

        return $site !== null && is_string($engine) ? app(ManageDatabaseInstances::class)->forSite($site, $engine) : null;
    }

    public static function createAction(): Action
    {
        $engines = collect(app(SystemInfo::class)->dbEngines())->mapWithKeys(fn ($e) => [$e->value => $e->getLabel()]);

        return Action::make('createDatabase')
            ->label(__('Nová databáze'))
            ->icon('outline:plus')
            ->visible($engines->isNotEmpty())
            ->modalDescription(__('Vytvoří databázi a jejího vlastníka se stejným názvem a náhodným heslem. S připojeným webem se údaje zapíší rovnou do jeho .env a panel heslo neuvidí.'))
            ->fillFormUsing(fn () => ['engine' => $engines->keys()->first(), 'site_id' => null, 'name' => null, 'dedicated' => true])
            ->form([
                Select::make('engine')->rules([Options::rule()])->label(__('Engine'))->options($engines->all())->required()->live(),
                Select::make('site_id')->rules([Options::rule()])
                    ->label(__('Web'))
                    ->options(fn () => Site::query()->where('status', 'active')->orderBy('domain')->pluck('domain', 'id')->all())
                    ->placeholder(__('nepřipojovat'))
                    ->live()
                    ->afterStateUpdated(function ($state, $get, $set) {
                        if ($state && blank($get('name'))) {
                            $set('name', Database::suggestName((string) Site::find($state)?->domain));
                        }
                    }),
                TextInput::make('name')
                    ->label(__('Název (i uživatel)'))
                    ->required()
                    ->rules(['regex:/^[a-z][a-z0-9_]{1,47}$/', 'not_in:'.implode(',', self::RESERVED)])
                    ->validationMessages(['regex' => __('Malá písmena, číslice a podtržítko; začíná písmenem.'), 'not_in' => __('Tento název je rezervovaný.')])
                    ->helperText(__('Malá písmena, číslice a podtržítko; začíná písmenem.')),
                Toggle::make('dedicated')
                    ->label(__('Na vyhrazeném serveru webu'))
                    ->helperText(__('Web má vlastní databázový server: jiné weby se k němu nedostanou.'))
                    ->visible(fn ($get) => self::siteInstance($get('site_id'), $get('engine')) !== null),
            ])
            ->action(function (array $data) {
                try {
                    $site = $data['site_id'] ? Site::find($data['site_id']) : null;
                    $instance = ($data['dedicated'] ?? false) ? self::siteInstance($data['site_id'], $data['engine'])['id'] ?? null : null;
                    $result = app(ManageDatabases::class)->create($data['engine'], $data['name'], $site, $instance);
                    $name = $result['database']->name;
                    $result['password']
                        ? Toast::secret(__('Databáze :name vytvořena', ['name' => $name]), __('Uživatel :name, heslo (zobrazeno jen teď): :password', ['name' => $name, 'password' => $result['password']]))
                        : Toast::success(__('Databáze :name vytvořena', ['name' => $name]), __('Údaje zapsány do .env webu :domain.', ['domain' => $site?->domain]));
                } catch (AgentException $e) {
                    Toast::error(__('Databázi se nepodařilo vytvořit'), $e->getMessage());
                }
            });
    }
}
