<?php

namespace App\Support;

/**
 * What the panel knows about server.properties (Java Edition 1.20.5 to 26.x):
 * a label, a type and the allowed values, in groups. The form shows the keys
 * the server's own file has (that is what its version knows); keys it does
 * not know are shown as plain text. The port, RCON, query and the management
 * server are the panel's (the agent refuses them).
 */
final class MinecraftProperties
{
    public const GROUPS = [
        'game' => 'Hra',
        'players' => 'Hráči a přístup',
        'world' => 'Svět',
        'performance' => 'Výkon a síť',
        'pack' => 'Resource pack',
    ];

    /**
     * key => [group, label, type (bool|int|enum|string), options or [min, max], help].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3?: array<int|string, mixed>, 4?: string}>
     */
    public const KNOWN = [
        'motd' => ['game', 'Popis serveru (MOTD)', 'string', [], 'Text v seznamu serverů; barvy přes kódy §.'],
        'difficulty' => ['game', 'Obtížnost', 'enum', ['peaceful' => 'mírumilovná', 'easy' => 'lehká', 'normal' => 'normální', 'hard' => 'těžká']],
        'gamemode' => ['game', 'Herní režim', 'enum', ['survival' => 'přežití', 'creative' => 'tvořivý', 'adventure' => 'dobrodružný', 'spectator' => 'divák']],
        'force-gamemode' => ['game', 'Vynutit herní režim při připojení', 'bool'],
        'hardcore' => ['game', 'Hardcore (po smrti jen divák)', 'bool'],
        'allow-flight' => ['game', 'Povolit létání (módy, pluginy)', 'bool', [], 'Jinak server hráče, kteří létají, vyhodí.'],
        'spawn-protection' => ['game', 'Ochrana spawnu (bloků)', 'int', [0, 1000], '0 = vypnuto; operátoři ji nemají.'],
        'max-players' => ['players', 'Nejvíc hráčů', 'int', [1, 1000]],
        'white-list' => ['players', 'Whitelist (jen povolení hráči)', 'bool'],
        'enforce-whitelist' => ['players', 'Vyhodit hráče, kteří z whitelistu vypadnou', 'bool'],
        'online-mode' => ['players', 'Ověřovat účty u Mojangu (online-mode)', 'bool', [], 'Vypnuté jen za proxy (Velocity, BungeeCord): jinak se kdokoli přihlásí pod jakýmkoli jménem.'],
        'enforce-secure-profile' => ['players', 'Vyžadovat podepsaný chat', 'bool'],
        'prevent-proxy-connections' => ['players', 'Odmítat připojení přes proxy a VPN', 'bool'],
        'player-idle-timeout' => ['players', 'Vyhodit nečinné hráče po (minutách)', 'int', [0, 1440], '0 = nikdy.'],
        'op-permission-level' => ['players', 'Úroveň oprávnění operátorů', 'int', [1, 4]],
        'hide-online-players' => ['players', 'Skrýt jména hráčů v seznamu serverů', 'bool'],
        'accepts-transfers' => ['players', 'Přijímat hráče předané z jiného serveru', 'bool'],
        'log-ips' => ['players', 'Zapisovat IP adresy hráčů do logu', 'bool'],
        'level-name' => ['world', 'Složka světa', 'string', [], 'Změna = nový (nebo jiný) svět.'],
        'level-seed' => ['world', 'Seed', 'string', [], 'Platí jen pro nově generovaný svět.'],
        'level-type' => ['world', 'Typ světa', 'enum', ['minecraft:normal' => 'normální', 'minecraft:flat' => 'plochý', 'minecraft:large_biomes' => 'velké biomy', 'minecraft:amplified' => 'zesílený', 'minecraft:single_biome_surface' => 'jeden biom']],
        'generate-structures' => ['world', 'Generovat stavby (vesnice, chrámy)', 'bool'],
        'generator-settings' => ['world', 'Nastavení generátoru (JSON)', 'string'],
        'max-world-size' => ['world', 'Největší poloměr světa (bloků)', 'int', [1, 29999984]],
        'view-distance' => ['performance', 'Dohled (chunků)', 'int', [3, 32], 'Nejvíc ovlivňuje paměť a procesor.'],
        'simulation-distance' => ['performance', 'Simulace (chunků)', 'int', [3, 32]],
        'entity-broadcast-range-percentage' => ['performance', 'Dosah viditelnosti entit (%)', 'int', [10, 1000]],
        'pause-when-empty-seconds' => ['performance', 'Pozastavit prázdný server po (s)', 'int', [0, 86400], '0 = nepozastavovat.'],
        'max-tick-time' => ['performance', 'Watchdog: nejdelší tick (ms)', 'int', [-1, 600000], '-1 = vypnuto.'],
        'network-compression-threshold' => ['performance', 'Komprimovat pakety od (bajtů)', 'int', [-1, 65535], '-1 = nekomprimovat.'],
        'rate-limit' => ['performance', 'Limit paketů za sekundu na hráče', 'int', [0, 100000], '0 = bez limitu.'],
        'sync-chunk-writes' => ['performance', 'Zapisovat chunky synchronně', 'bool'],
        'region-file-compression' => ['performance', 'Komprese souborů světa', 'enum', ['deflate' => 'deflate', 'lz4' => 'lz4 (rychlejší, větší)', 'none' => 'žádná']],
        'use-native-transport' => ['performance', 'Nativní síťový přenos (Linux)', 'bool'],
        'enable-status' => ['performance', 'Odpovídat v seznamu serverů', 'bool'],
        'resource-pack' => ['pack', 'Adresa resource packu', 'string'],
        'resource-pack-sha1' => ['pack', 'SHA-1 resource packu', 'string'],
        'resource-pack-id' => ['pack', 'ID resource packu (UUID)', 'string'],
        'resource-pack-prompt' => ['pack', 'Text výzvy k resource packu', 'string'],
        'require-resource-pack' => ['pack', 'Vyžadovat resource pack', 'bool'],
    ];

    /** The keys the panel owns (mirrors the agent's list). */
    public static function panelKey(string $key): bool
    {
        return in_array($key, ['server-port', 'enable-rcon', 'rcon.port', 'rcon.password', 'broadcast-rcon-to-ops', 'enable-query', 'query.port'], true)
            || str_starts_with($key, 'management-server-');
    }

    /** null when the value fits the key, else why not. */
    public static function invalid(string $key, string $value): ?string
    {
        if (strlen($value) > 4096 || preg_match('/[\r\n\x00]/', $value)) {
            return __('Jeden řádek, nejvýš 4096 znaků.');
        }
        $spec = self::KNOWN[$key] ?? null;
        if ($spec === null) {
            return null;
        }

        return match ($spec[2]) {
            'bool' => in_array($value, ['true', 'false'], true) ? null : __('Zapnuto nebo vypnuto.'),
            'enum' => array_key_exists($value, $spec[3]) || array_key_exists('minecraft:'.$value, $spec[3]) ? null : __('Jedna z nabízených hodnot.'),
            'int' => preg_match('/^-?\d{1,10}$/', $value) && (int) $value >= $spec[3][0] && (int) $value <= $spec[3][1]
                ? null : __('Celé číslo :min až :max.', ['min' => $spec[3][0], 'max' => $spec[3][1]]),
            default => null,
        };
    }
}
