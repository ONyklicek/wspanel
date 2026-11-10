<?php

namespace App\Enums;

use App\Actions\Games\ManageGameServers;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A game a game server can run, as the installed game packages make it (the
 * agent's games.catalog): its engine, port, memory and variants. Not an enum
 * any more: a package adds games and variants (Folia, Counter-Strike 2…)
 * without a release of the panel. Minecraft and Factorio are built in, and
 * known here even while the agent does not answer.
 */
final class Game implements Castable
{
    /** The built-in packages (the agent's gamepkgs/*.json), for when the agent is out of reach. */
    private const BUILTIN = [
        'minecraft' => ['name' => 'Minecraft Java', 'engine' => 'minecraft', 'proto' => 'tcp', 'default_port' => 25565, 'min_memory_mb' => 1024, 'available' => true, 'package' => 'minecraft', 'flavors' => [
            ['id' => 'paper', 'label' => 'Paper (pluginy, výkon)', 'package' => 'minecraft', 'addons' => ['kind' => 'plugins', 'modrinth_loaders' => ['paper', 'spigot', 'bukkit'], 'hangar' => true]],
            ['id' => 'vanilla', 'label' => 'Vanilla (oficiální server)', 'package' => 'minecraft', 'addons' => null],
            ['id' => 'fabric', 'label' => 'Fabric (módy)', 'package' => 'minecraft', 'addons' => ['kind' => 'mods', 'modrinth_loaders' => ['fabric']]],
            ['id' => 'forge', 'label' => 'Forge (módy)', 'package' => 'minecraft', 'addons' => ['kind' => 'mods', 'modrinth_loaders' => ['forge']]],
        ]],
        'factorio' => ['name' => 'Factorio', 'engine' => 'factorio', 'proto' => 'udp', 'default_port' => 34197, 'min_memory_mb' => 1024, 'available' => true, 'package' => 'factorio', 'flavors' => [
            ['id' => 'stable', 'label' => 'Stabilní vydání', 'package' => 'factorio', 'addons' => null],
        ]],
    ];

    /** @var array<string, self>|null */
    private static ?array $all = null;

    /**
     * @param  array<string, mixed>  $def
     */
    private function __construct(public readonly string $value, private readonly array $def) {}

    /** @return array<string, self> every game the packages add, by id */
    public static function all(): array
    {
        if (self::$all === null) {
            $games = (array) (app(ManageGameServers::class)->catalogGames() ?: self::BUILTIN);
            self::$all = [];
            foreach ($games as $id => $def) {
                if (is_string($id) && preg_match('/^[a-z][a-z0-9]{1,31}$/', $id) && is_array($def)) {
                    self::$all[$id] = new self($id, $def);
                }
            }
            ksort(self::$all);
        }

        return self::$all;
    }

    /** A model's game column as a Game. */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(Model $model, string $key, mixed $value, array $attributes): ?Game
            {
                return $value === null ? null : Game::from((string) $value);
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                return $value instanceof Game ? $value->value : ($value === null ? null : (string) $value);
            }
        };
    }

    /** Forgets what the packages made (after installing or removing one). */
    public static function flush(): void
    {
        self::$all = null;
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return array_values(self::all());
    }

    public static function tryFrom(?string $id): ?self
    {
        return $id === null ? null : (self::all()[$id] ?? null);
    }

    /** A game, or (no package adds it any more) its id standing for it. */
    public static function from(string $id): self
    {
        return self::tryFrom($id) ?? new self($id, ['name' => $id, 'engine' => '', 'proto' => 'tcp', 'default_port' => 0, 'min_memory_mb' => 1024, 'available' => false, 'flavors' => []]);
    }

    /** @return array<string, string> games that can run here, id => name */
    public static function options(): array
    {
        return collect(self::all())->filter(fn (self $g) => $g->available())->map(fn (self $g) => $g->label())->all();
    }

    public function label(): string
    {
        return (string) ($this->def['name'] ?? $this->value);
    }

    /** How the agent runs it: minecraft, factorio (what the panel's screens follow). */
    public function engine(): string
    {
        return (string) ($this->def['engine'] ?? '');
    }

    public function is(string $engine): bool
    {
        return $this->engine() === $engine;
    }

    public function available(): bool
    {
        return (bool) ($this->def['available'] ?? false);
    }

    /** @return array<string, string> flavor => label */
    public function flavors(): array
    {
        return collect((array) ($this->def['flavors'] ?? []))->mapWithKeys(fn ($f) => [(string) $f['id'] => (string) $f['label']])->all();
    }

    /**
     * Plugins or mods the variant loads (null: none).
     *
     * @return array{kind: string, modrinth_loaders: list<string>, hangar?: bool}|null
     */
    public function addons(string $flavor): ?array
    {
        $f = collect((array) ($this->def['flavors'] ?? []))->firstWhere('id', $flavor);

        return is_array($f['addons'] ?? null) ? $f['addons'] : null;
    }

    /** The package that adds a variant. */
    public function flavorPackage(string $flavor): ?string
    {
        return collect((array) ($this->def['flavors'] ?? []))->firstWhere('id', $flavor)['package'] ?? null;
    }

    public function defaultPort(): int
    {
        return (int) ($this->def['default_port'] ?? 0);
    }

    public function proto(): string
    {
        return (string) ($this->def['proto'] ?? 'tcp');
    }

    public function minMemoryMb(): int
    {
        return (int) ($this->def['min_memory_mb'] ?? 1024);
    }

    /** Whether the panel can send it commands (a native game may have no console). */
    public function hasConsole(): bool
    {
        return (bool) ($this->def['console'] ?? true);
    }

    /** @return list<string>|null commands its package offers under the console (null: none said) */
    public function consoleCommands(): ?array
    {
        return isset($this->def['commands']) && is_array($this->def['commands']) ? array_values(array_map('strval', $this->def['commands'])) : null;
    }

    /** Free disk an install needs, in GB (0: not said). */
    public function diskGb(): int
    {
        return (int) ($this->def['disk_gb'] ?? 0);
    }

    /** Whether its package declares settings of its own (native games: Nastavení). */
    public function hasOwnSettings(): bool
    {
        return (bool) ($this->def['settings'] ?? false);
    }

    /** What a player types to connect (the panel's host name and the port). */
    public function connectHint(string $host, int $port): string
    {
        return $this->is('minecraft') && $port === 25565 ? $host : $host.':'.$port;
    }
}
