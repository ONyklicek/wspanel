<?php

namespace App\Wire\Resources;

use App\Actions\Games\ManageGameServers;
use App\Enums\Game;
use App\Livewire\Games\GamePlayers;
use App\Livewire\Games\GamePlugins;
use App\Livewire\Games\GameServerFiles;
use App\Livewire\Games\GameServerSettings;
use App\Livewire\Games\ListGameServers;
use App\Livewire\Games\ViewGameServer;
use App\Models\GameServer;
use App\Services\Agent\AgentException;
use App\Support\Features;
use App\Support\Limits;
use App\Support\Toast;
use App\Wire\Actions\Action;
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
            'plugins' => RoutePage::make(GamePlugins::class)->uri('{record}/plugins')->permission('games')->label(__('Pluginy a módy'))->icon('outline:puzzle-piece')->sort(27),
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
                ->afterStateUpdated(function ($state, $set, $get) {
                    $game = Game::tryFrom((string) $state);
                    $set('flavor', $game ? array_key_first($game->flavors()) : null);
                    $set('version', null);
                    $set('port', $game ? app(ManageGameServers::class)->freePort($game) : null);
                    // The game's own minimum (Counter-Strike 2 wants 4 GB).
                    if ($game && (int) $get('memory_mb') < $game->minMemoryMb()) {
                        $set('memory_mb', $game->minMemoryMb());
                    }
                })
                ->helperText(fn ($get) => ($g = Game::tryFrom((string) $get('game'))) && $g->diskGb() > 0
                    ? __('Instalace stáhne :gb GB a potřebuje tolik volného místa; trvá podle rychlosti připojení i desítky minut (běží na pozadí).', ['gb' => $g->diskGb()])
                    : null),
            Select::make('flavor')->label(__('Varianta'))->required()->live()->rules([Options::rule()])
                ->options(fn ($get) => Game::tryFrom((string) $get('game'))?->flavors() ?? [])
                ->afterStateUpdated(fn ($set) => $set('version', null)),
            Select::make('version')->label(__('Verze'))->required()->searchable()
                ->rules([fn ($get) => Rule::in($versions($get))])
                ->options(fn ($get) => collect($versions($get))->mapWithKeys(fn ($v) => [$v => $v === 'latest' ? __('nejnovější (aktualizuje se ze Steamu)') : $v])->all())
                ->helperText(fn ($get) => Game::tryFrom((string) $get('game'))?->is('minecraft')
                    ? __('Seznam je od vydavatele hry. Minecraft starší než 1.20.5 potřebuje Javu 17, kterou Debian 13 nemá.')
                    : __('Seznam je od vydavatele hry.')),
            TextInput::make('port')->label(__('Port'))->integer()->minValue(20000)->maxValue(40000)
                ->rules(['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value !== null && $value !== '' && ! ManageGameServers::portAllowed((int) $value)) {
                        $fail(__('Herní servery používají porty 20000 až 40000 (mimo 27000–27999 pro RCON).'));
                    }
                }])
                ->helperText(__('Prázdné = výchozí port hry (nebo další volný). Otevře se ve firewallu.')),
            TextInput::make('memory_mb')->label(__('Paměť (MB)'))->integer()->required()->default(2048)
                ->rules(['required', 'integer', 'between:512,262144', fn ($get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                    $min = Game::tryFrom((string) $get('game'))?->minMemoryMb() ?? 0;
                    if ((int) $value < $min) {
                        $fail(__('Tahle hra potřebuje aspoň :mb MB.', ['mb' => $min]));
                    }
                }])
                ->helperText(fn ($get) => Game::tryFrom((string) $get('game'))?->is('minecraft')
                    ? __('Minecraft: 2048 pro pár hráčů, 4096+ pro víc hráčů nebo pluginy.')
                    : (($g = Game::tryFrom((string) $get('game'))) ? __('Nejméně :mb MB.', ['mb' => $g->minMemoryMb()]) : null)),
            TextInput::make('cpu_percent')->label(__('Procesor (% jednoho jádra, 0 = bez omezení)'))->integer()->default(0)
                ->rules(['nullable', 'integer', 'between:0,6400']),
            Limits::forCurrentUser() === null && Features::clients() ? SiteFields::client()->helperText(__('Klient server uvidí ve svém panelu a počítá se mu do limitů balíčku.')) : null,
            Checkbox::make('eula')
                ->label(__('Souhlasím s licenčními podmínkami Minecraftu (EULA, aka.ms/MinecraftEULA)'))
                ->visible(fn ($get) => (bool) Game::tryFrom((string) $get('game'))?->is('minecraft'))
                ->rules(['accepted'])
                ->validationMessages(['accepted' => __('Server Minecraftu se bez souhlasu s EULA nespustí.')]),
        ]));
    }

    public function table(Table $table): Table
    {
        // The agent's view of every server, one call per render (ManageGameServers::liveStates()).
        $live = fn (GameServer $record) => app(ManageGameServers::class)->liveState($record);
        $state = fn (GameServer $record) => $record->runState($live($record));

        return $table
            ->stackedOnMobile()
            ->query(GameServer::query()->with('client'))
            ->recordUrl(fn (GameServer $record) => self::url('view', $record))
            ->columns([
                TextColumn::make('name')->label(__('Název'))->weight('medium')->searchable()
                    ->description(fn (GameServer $record) => $record->game->label().' · '.$record->versionLabel()),
                BadgeColumn::make('status')->label(__('Stav'))
                    ->state(fn (GameServer $record) => GameServer::runStateBadge($state($record))['label'])
                    ->colors(collect(['installing', 'failed', 'running', 'starting', 'stopping', 'crashed', 'stopped', 'unknown'])
                        ->mapWithKeys(fn ($s) => [GameServer::runStateBadge($s)['label'] => GameServer::runStateBadge($s)['color']])->all()),
                TextColumn::make('players')->label(__('Hráči'))->placeholder('—')
                    ->state(fn (GameServer $record) => ($p = $live($record)['players'] ?? null) ? $p['online'].(isset($p['max']) ? ' / '.$p['max'] : '') : null)
                    ->description(fn (GameServer $record) => implode(', ', array_slice($live($record)['players']['names'] ?? [], 0, 5)) ?: null),
                TextColumn::make('port')->label(__('Adresa'))->fontFamily('mono')->copyable()->state(fn (GameServer $record) => $record->address())
                    ->description(fn (GameServer $record) => $record->port.'/'.$record->game->proto()),
                TextColumn::make('memory_mb')->label(__('Paměť'))
                    ->state(fn (GameServer $record) => ($live($record)['memory_bytes'] ?? 0) > 0 && in_array($state($record), ['running', 'starting', 'stopping'], true)
                        ? Number::fileSize((int) $live($record)['memory_bytes'], 1).' / '.Number::fileSize($record->memory_mb * 1048576)
                        : Number::fileSize($record->memory_mb * 1048576)),
                TextColumn::make('client.name')->label(__('Klient'))->placeholder('—')->visible(fn () => Features::clients() && Limits::forCurrentUser() === null),
            ])
            ->actions([
                self::control('start', __('Spustit'), 'outline:play', 'success')
                    ->visible(fn (GameServer $record) => in_array($state($record), ['stopped', 'crashed'], true)),
                self::control('restart', __('Restartovat'), 'outline:arrow-path', 'gray')
                    ->visible(fn (GameServer $record) => in_array($state($record), ['running', 'starting'], true))
                    ->requiresConfirmation()
                    ->modalDescription(fn (GameServer $record) => ($n = (int) ($live($record)['players']['online'] ?? 0)) > 0
                        ? __('Server se zastaví a znovu spustí. Hráči (:n) se odpojí.', ['n' => $n])
                        : __('Server se zastaví a znovu spustí.')),
                self::control('stop', __('Zastavit'), 'outline:stop', 'danger')
                    ->visible(fn (GameServer $record) => in_array($state($record), ['running', 'starting'], true))
                    ->requiresConfirmation()
                    ->modalDescription(__('Hráči se odpojí a po restartu serveru se sám nespustí.')),
            ])
            // State lives in the agent, not in the table: every tick asks it again.
            ->poll('5s')
            ->emptyState(__('Žádné herní servery'), __('Minecraft nebo Factorio přidáte tlačítkem Nový server. Java a server hry se stáhnou automaticky.'), 'outline:puzzle-piece');
    }

    private static function control(string $verb, string $label, string $icon, string $color): Action
    {
        return Action::make($verb)->label($label)->icon($icon)->color($color)->onlyIcon()->tooltip($label)
            ->action(function (GameServer $record) use ($verb) {
                try {
                    app(ManageGameServers::class)->control($record, $verb);
                    Toast::success(match ($verb) {
                        'start' => __(':name se spouští', ['name' => $record->name]),
                        'stop' => __(':name zastaven', ['name' => $record->name]),
                        default => __(':name se restartuje', ['name' => $record->name]),
                    });
                } catch (AgentException $e) {
                    Toast::error(__('Akce selhala'), $e->getMessage());
                }
            });
    }
}
