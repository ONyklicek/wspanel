<?php

namespace App\Enums;

/** Games a game server can run (recipes in the agent, games.go). */
enum Game: string
{
    case Minecraft = 'minecraft';
    case Factorio = 'factorio';

    public function label(): string
    {
        return match ($this) {
            self::Minecraft => 'Minecraft Java',
            self::Factorio => 'Factorio',
        };
    }

    /** @return array<string, string> flavor => label */
    public function flavors(): array
    {
        return match ($this) {
            self::Minecraft => ['paper' => __('Paper (pluginy, výkon)'), 'vanilla' => __('Vanilla (oficiální server)'), 'fabric' => __('Fabric (módy)'), 'forge' => __('Forge (módy)')],
            self::Factorio => ['stable' => __('Stabilní vydání')],
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::Minecraft => 25565,
            self::Factorio => 34197,
        };
    }

    public function proto(): string
    {
        return $this === self::Factorio ? 'udp' : 'tcp';
    }

    public function minMemoryMb(): int
    {
        return 1024;
    }

    /** What a player types to connect (the panel's host name and the port). */
    public function connectHint(string $host, int $port): string
    {
        return match ($this) {
            self::Minecraft => $port === 25565 ? $host : $host.':'.$port,
            self::Factorio => $host.':'.$port,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $g) => [$g->value => $g->label()])->all();
    }
}
