<?php

namespace App\Wire\Resources;

use App\Actions\Games\ManageGameServers;
use App\Enums\Game;
use App\Livewire\Games\GamePlayers;
use App\Livewire\Games\GameServerFiles;
use App\Livewire\Games\GameServerSettings;
use App\Livewire\Games\ListGameServers;
use App\Livewire\Games\ViewGameServer;
use App\Models\GameServer;
use App\Support\Features;
use App\Support\Limits;
use App\Wire\Forms\Options;
use App\Wire\Sites\SiteFields;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use NyonCode\WireCore\Core\Resources\Concerns\DescribesRecords;
use NyonCode\WireCore\Core\Resources\Contracts\DescribesResource;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Foundation\Routing\RoutePage;
use NyonCode\WireForms\Components\Checkbox;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Resources\Contracts\ProvidesResourceTable;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Table;

final class GameServerResource implements DescribesResource, ProvidesNavigation, ProvidesPages, ProvidesResourceTable
{
    use DescribesRecords;

    public static function modelClass(): ?string
    {
        return GameServer::class;
    }

    public static function key(): string
    {
        return 'games';
    }

    public static function label(): string
    {
        return __('Herní server');
    }

    public static function pluralLabel(): string
    {
        return __('Herní servery');
    }

    public static function pages(): array
    {
        return [
            'index' => RoutePage::make(ListGameServers::class)->permission('games'),
            'view' => RoutePage::make(ViewGameServer::class)->permission('games')->label(__('Přehled'))->icon('outline:squares-2x2')->sort(10),
            'settings' => RoutePage::make(GameServerSettings::class)->uri('{record}/settings')->permission('games')->label(__('Nastavení'))->icon('outline:adjustments-horizontal')->sort(20),
            'players' => RoutePage::make(GamePlayers::class)->uri('{record}/players')->permission('games')->label(__('Hráči'))->icon('outline:user-group')->sort(25),
            'files' => RoutePage::make(GameServerFiles::class)->uri('{record}/files')->permission('games')->label(__('Soubory'))->icon('outline:folder')->sort(30),
        ];
    }

    public static function navigation(): NavigationItem
    {
        return NavigationItem::make()->icon('outline:puzzle-piece')->group('hosting')->sort(40)
            ->visible(fn (): bool => Gate::allows('games'));
    }

    /**
     * The create form: the game, its flavor and version (from the publishers,
     * through the agent), the port, memory and CPU, the owner.
     *
     * @return list<mixed>
     */
    public static function createFields(): array
    {
        $versions = fn ($get) => ($g = Game::tryFrom((string) $get('game'))) && $get('flavor')
            ? app(ManageGameServers::class)->versions($g, (string) $get('flavor')) : [];

        return array_values(array_filter([
            TextInput::make('name')->label(__('Název'))->required()->maxLength(64)
                ->rules(['max:64', 'regex:/^[^"\\\\\x00-\x1f]+$/'])->placeholder('Survival'),
            Select::make('game')->label(__('Hra'))->options(Game::options())->required()->live()
                ->rules([Options::rule()])
                ->afterStateUpdated(function ($state, $set) {
                    $game = Game::tryFrom((string) $state);
                    $set('flavor', $game ? array_key_first($game->flavors()) : null);
                    $set('version', null);
                    $set('port', $game ? app(ManageGameServers::class)->freePort($game) : null);
                }),
            Select::make('flavor')->label(__('Varianta'))->required()->live()->rules([Options::rule()])
                ->options(fn ($get) => Game::tryFrom((string) $get('game'))?->flavors() ?? [])
                ->afterStateUpdated(fn ($set) => $set('version', null)),
            Select::make('version')->label(__('Verze'))->required()->searchable()
                ->rules([fn ($get) => Rule::in($versions($get))])
                ->options(fn ($get) => collect($versions($get))->mapWithKeys(fn ($v) => [$v => $v])->all())
                ->helperText(__('Seznam je od vydavatele hry. Minecraft starší než 1.20.5 potřebuje Javu 17, kterou Debian 13 nemá.')),
            TextInput::make('port')->label(__('Port'))->integer()->minValue(20000)->maxValue(40000)
                ->rules(['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value !== null && $value !== '' && ! ManageGameServers::portAllowed((int) $value)) {
                        $fail(__('Herní servery používají porty 20000 až 40000 (mimo 27000–27999 pro RCON).'));
                    }
                }])
                ->helperText(__('Prázdné = výchozí port hry (nebo další volný). Otevře se ve firewallu.')),
            TextInput::make('memory_mb')->label(__('Paměť (MB)'))->integer()->required()->default(2048)
                ->rules(['required', 'integer', 'between:1024,262144'])
                ->helperText(__('Minecraft: 2048 pro pár hráčů, 4096+ pro víc hráčů nebo pluginy.')),
            TextInput::make('cpu_percent')->label(__('Procesor (% jednoho jádra, 0 = bez omezení)'))->integer()->default(0)
                ->rules(['nullable', 'integer', 'between:0,6400']),
            Limits::forCurrentUser() === null && Features::clients() ? SiteFields::client()->helperText(__('Klient server uvidí ve svém panelu a počítá se mu do limitů balíčku.')) : null,
            Checkbox::make('eula')
                ->label(__('Souhlasím s licenčními podmínkami Minecraftu (EULA, aka.ms/MinecraftEULA)'))
                ->visibleWhen('game', Game::Minecraft->value)
                ->rules(['accepted'])
                ->validationMessages(['accepted' => __('Server Minecraftu se bez souhlasu s EULA nespustí.')]),
        ]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(GameServer::query()->with('client'))
            ->recordUrl(fn (GameServer $record) => self::url('view', $record))
            ->columns([
                TextColumn::make('name')->label(__('Název'))->weight('medium')->searchable()
                    ->description(fn (GameServer $record) => $record->game->label()),
                TextColumn::make('version')->label(__('Verze'))->state(fn (GameServer $record) => $record->versionLabel()),
                TextColumn::make('port')->label(__('Adresa'))->fontFamily('mono')->state(fn (GameServer $record) => $record->address())
                    ->description(fn (GameServer $record) => $record->port.'/'.$record->game->proto()),
                TextColumn::make('memory_mb')->label(__('Paměť'))->state(fn (GameServer $record) => Number::fileSize($record->memory_mb * 1048576)),
                TextColumn::make('client.name')->label(__('Klient'))->placeholder('—')->visible(fn () => Features::clients() && Limits::forCurrentUser() === null),
                BadgeColumn::make('status')->label(__('Stav'))
                    ->formatStateUsing(fn ($state) => ['installing' => __('instaluje se'), 'active' => __('nainstalováno'), 'failed' => __('chyba')][$state] ?? $state)
                    ->colors(['active' => 'success', 'installing' => 'warning', 'failed' => 'danger']),
            ])
            ->emptyState(__('Žádné herní servery'), __('Minecraft nebo Factorio přidáte tlačítkem Nový server. Java a server hry se stáhnou automaticky.'), 'outline:puzzle-piece');
    }
}
