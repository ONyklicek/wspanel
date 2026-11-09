<?php

namespace App\Support;

/** The settings of Factorio's server-settings.json the panel's form changes. */
final class FactorioSettings
{
    /**
     * key => [label, type (string|text|list|int|bool|commands), [min, max] for int, help].
     *
     * @var array<string, array{0: string, 1: string, 2?: array{0: int, 1: int}, 3?: string}>
     */
    public const FIELDS = [
        'name' => ['Název serveru', 'string'],
        'description' => ['Popis', 'text'],
        'tags' => ['Štítky (oddělené čárkou)', 'list'],
        'max_players' => ['Nejvíc hráčů', 'int', [0, 1000], '0 = bez omezení.'],
        'visibility.public' => ['Ve veřejném seznamu serverů', 'bool', [], 'Potřebuje účet factorio.com (jméno a token níže).'],
        'visibility.lan' => ['Viditelný v místní síti (LAN)', 'bool'],
        'require_user_verification' => ['Ověřovat hráče u factorio.com', 'bool', [], 'Vypnuté = připojí se kdokoli pod jakýmkoli jménem.'],
        'allow_commands' => ['Příkazy konzole (Lua)', 'commands'],
        'only_admins_can_pause_the_game' => ['Pozastavit hru smí jen správci', 'bool'],
        'auto_pause' => ['Pozastavit, když nikdo nehraje', 'bool'],
        'auto_pause_when_players_connect' => ['Pozastavit, dokud se hráč připojuje', 'bool'],
        'autosave_interval' => ['Automatické ukládání po (minutách)', 'int', [1, 1440]],
        'autosave_slots' => ['Počet automatických uložení', 'int', [1, 100]],
        'autosave_only_on_server' => ['Ukládat jen na serveru', 'bool'],
        'non_blocking_saving' => ['Ukládat bez zastavení hry (Linux)', 'bool'],
        'afk_autokick_interval' => ['Vyhodit nečinné po (minutách)', 'int', [0, 1440], '0 = nikdy.'],
        'max_upload_in_kilobytes_per_second' => ['Odesílání nejvýš (kB/s)', 'int', [0, 1000000], '0 = bez omezení.'],
        'max_upload_slots' => ['Souběžných odesílání mapy', 'int', [0, 1000], '0 = bez omezení.'],
        'minimum_latency_in_ticks' => ['Nejmenší latence (ticků)', 'int', [0, 1000]],
        'ignore_player_limit_for_returning_players' => ['Vracející se hráče pustit i nad limit', 'bool'],
        'username' => ['Účet factorio.com: jméno', 'string', [], 'Pro veřejný seznam serverů a stahování módů.'],
    ];
}
