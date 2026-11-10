# Herní balíčky

Co herní server umí spustit, určují **herní balíčky**. Balíček je JSON
manifest, který buď **přidá hru** (Minecraft, Factorio…), nebo **rozšíří
existující hru o varianty** (Folia pro Minecraft). Panel i agent se podle
nainstalovaných balíčků řídí: formulář nového serveru nabízí jejich hry,
varianty a verze, agent podle nich server stáhne, nastaví a spustí.

Balíček je **popis, ne program**. Agent běží jako root, proto manifest
neobsahuje žádné příkazy ani cesty: jen jmenuje mechanismy, které agent
sám obsahuje (engine, zdroj stažení), a jejich parametry. Každé pole agent
ověří dřív, než balíček začne platit; neznámé pole, engine nebo zdroj
balíček odmítne.

- [Odkud se balíčky berou](#odkud-se-balíčky-berou)
- [V panelu](#v-panelu-server--herní-balíčky)
- [Formát manifestu](#formát-manifestu) – úplný popis polí
- [Enginy a zdroje](#enginy-a-zdroje)
- [Pravidla](#pravidla-konflikty-pořadí-odebrání)
- [Katalog WSPanelu a podpis](#katalog-wspanelu-a-podpis)
- [REST API](#rest-api)
- [Příkazová řádka](#příkazová-řádka-wspanel)
- [Operace agenta](#operace-agenta-interní-api)
- [Nový engine nebo zdroj](#nový-engine-nebo-zdroj-pro-vývojáře)

## Odkud se balíčky berou

| Původ | Kde | Kdo ho přidá |
|---|---|---|
| **vestavěný** | v agentovi (`agent/internal/ops/gamepkgs/*.json`) | přichází s WSPanelem: Minecraft Java, Factorio |
| **z katalogu WSPanelu** | `/var/lib/panel-agent/game-packages/<id>.json` | správce v panelu z podepsaného katalogu (Folia…) |
| **vlastní** | `/etc/wspanel/games.d/<id>.json` | správce: nahrání v panelu, přes API, nebo soubor na serveru |

Pořadí je zároveň přednost: když se dva balíčky střetnou (stejné id,
stejná hra, stejná varianta), platí dřívější a pozdější se ukáže mezi
„Balíčky, které neplatí“ i s důvodem.

## V panelu: Server → Herní balíčky

Stránka je jen pro správce a jen se zapnutým modulem Herní servery.

- **Nainstalované:** název, verze, původ (vestavěný, z katalogu, vlastní),
  co balíček přidává nebo rozšiřuje, jeho varianty a kolik serverů ho
  používá. Balíček z katalogu a vlastní jde **odebrat**, dokud ho žádný
  server nepoužívá (pak je tlačítko neaktivní).
- **Balíčky, které neplatí:** soubor s chybou nebo balíček, který se
  střetl s jiným, i s důvodem (třeba `extends minecraft-x, which no package
  adds`).
- **Katalog WSPanelu:** co katalog nabízí; **Nainstalovat**, u novější verze
  **Aktualizovat na …**. Agent instaluje jen z katalogu s platným podpisem.
- **Nahrát vlastní balíček:** vložení manifestu (JSON). Agent ho zkontroluje
  stejně jako každý jiný a uloží do `/etc/wspanel/games.d`.

Po instalaci nebo odebrání formulář nového serveru hned nabízí nové hry
a varianty.

## Formát manifestu

```json
{
  "id": "minecraft-folia",
  "name": "Folia pro Minecraft",
  "version": "1.0.0",
  "description": "Folia od PaperMC: vícevláknový server pro velké světy.",
  "extends": "minecraft",
  "flavors": [
    {
      "id": "folia",
      "label": "Folia (vícevláknový, velké servery)",
      "source": {"type": "papermc", "project": "folia"},
      "addons": {"kind": "plugins", "modrinth_loaders": ["folia"]}
    }
  ]
}
```

Balíček, který přidává hru, má místo `extends` objekt `game`:

```json
{
  "id": "minecraft",
  "name": "Minecraft Java",
  "version": "1.0.0",
  "game": {
    "id": "minecraft",
    "label": "Minecraft Java",
    "engine": "minecraft",
    "proto": "tcp",
    "default_port": 25565,
    "min_memory_mb": 1024,
    "arch": ["amd64", "arm64"]
  },
  "flavors": [
    {"id": "paper", "label": "Paper (pluginy, výkon)", "source": {"type": "papermc", "project": "paper"},
     "addons": {"kind": "plugins", "modrinth_loaders": ["paper", "spigot", "bukkit"], "hangar": true}},
    {"id": "vanilla", "label": "Vanilla (oficiální server)", "source": {"type": "mojang"}}
  ]
}
```

### Balíček

| Pole | Typ | Povinné | Pravidlo |
|---|---|---|---|
| `id` | string | ano | `^[a-z0-9][a-z0-9-]{1,40}$`; jméno souboru je `<id>.json` |
| `name` | string | ano | 1–64 znaků, bez řídicích znaků a `<` `>` |
| `version` | string | ano | `1`, `1.2` nebo `1.2.3` (každá část do 4 číslic); katalog podle ní nabízí aktualizaci |
| `description` | string | ne | do 300 znaků |
| `game` | objekt | buď `game`, nebo `extends` | balíček přidává hru, viz níže |
| `extends` | string | buď `game`, nebo `extends` | id hry, kterou rozšiřuje (`^[a-z][a-z0-9]{1,31}$`); hru musí přidávat jiný balíček |
| `flavors` | pole | ano | 1–20 variant, viz níže |

Neznámé pole manifest odmítne (třeba `"exec"`), stejně jako manifest větší
než 64 KB.

### Hra (`game`)

| Pole | Typ | Pravidlo |
|---|---|---|
| `id` | string | `^[a-z][a-z0-9]{1,31}$`; ukládá se k serveru, neměnit |
| `label` | string | 1–64 znaků; název hry v panelu |
| `engine` | string | `minecraft` nebo `factorio` – jak agent hru spouští, viz [Enginy](#enginy-a-zdroje) |
| `proto` | string | `tcp` nebo `udp` – protokol portu hry (otevírá se ve firewallu) |
| `default_port` | číslo | 1024–65535; výchozí port (panel vezme ten, nebo další volný z rozsahu 20000–40000 mimo 27000–27999) |
| `min_memory_mb` | číslo | 256–65536; nejmenší paměť serveru |
| `arch` | pole | `amd64` a/nebo `arm64`; na jiném procesoru se hra nenabízí |

### Varianta (`flavors[]`)

| Pole | Typ | Pravidlo |
|---|---|---|
| `id` | string | `^[a-z][a-z0-9-]{0,31}$`; v rámci hry jedinečné (i napříč balíčky) |
| `label` | string | 1–64 znaků; název ve formuláři |
| `source.type` | string | zdroj serveru, musí ho umět engine hry, viz [Zdroje](#zdroje) |
| `source.project` | string | jen u `papermc`: projekt PaperMC (`paper`, `folia`), `^[a-z0-9][a-z0-9-]{0,31}$` |
| `addons` | objekt | ne – pluginy nebo módy, které varianta načítá |
| `addons.kind` | string | `plugins` (složka `data/plugins`) nebo `mods` (`data/mods`) |
| `addons.modrinth_loaders` | pole | 1–10 jmen loaderů Modrinthu (`paper`, `folia`, `fabric`…): podle nich se hledá a ověřuje kompatibilita |
| `addons.hangar` | bool | i katalog Hangar (pluginy PaperMC); jen se zdrojem `papermc` |

## Enginy a zdroje

**Engine** je to, co agent o hře ví: jak ji spustit, kde má nastavení, jak
s ní mluví konzole a jak zjistí hráče. Engine je kód agenta, balíček ho jen
jmenuje.

| Engine | Co obstará | Zdroje |
|---|---|---|
| `minecraft` | Java z Debianu (21/25 podle verze, doinstaluje se), `server.properties` (port, RCON), EULA, konzole přes RCON, hráči (`list`), záloha s `save-off`/`save-all`, Nastavení a Hráči v panelu | `papermc`, `mojang`, `fabric`, `forge` |
| `factorio` | headless server, `server-settings.json`, první mapa, konzole přes RCON, hráči, módy z mod portálu | `factorio` |

### Zdroje

Každý zdroj stahuje jen od vydavatele hry a soubor ověřuje otiskem, který
vydavatel zveřejňuje; soubor s jiným otiskem se odmítne.

| Zdroj | Odkud | Ověření | Verze |
|---|---|---|---|
| `papermc` | API PaperMC (`fill.papermc.io/v3/projects/<project>`), nejnovější sestavení verze | SHA-256 z API | z API projektu, od Minecraftu 1.20.5 |
| `mojang` | manifest Mojangu (`piston-meta.mojang.com`), oficiální server | SHA-1 z manifestu | vydání (ne snapshoty), od 1.20.5 |
| `fabric` | oficiální instalátor z mavenu Fabricu, nejnovější stabilní loader | SHA-256 z mavenu | stabilní verze hry |
| `forge` | instalátor z mavenu Forge, doporučené (jinak nejnovější) sestavení | SHA-256 z mavenu | verze s buildem Forge |
| `factorio` | factorio.com, headless pro Linux | SHA-256 ze `sha256sums` | nejnovější stabilní |

Seznam verzí si agent drží hodinu (každý zdroj zvlášť); zdroj, který
neodpovídá, nenabízí nic, dokud neodpoví.

## Pravidla: konflikty, pořadí, odebrání

- Balíček s `extends` platí, jen když hru přidává jiný platný balíček.
- Stejné `id` balíčku, stejná hra nebo stejné `id` varianty ve stejné hře:
  platí dřívější podle pořadí původů (vestavěný, katalog, vlastní); nahrání
  přes panel a API takový balíček rovnou odmítne (409).
- Varianta musí mít zdroj, který umí engine její hry (Folia s
  `factorio` nejde).
- **Odebrání:** vestavěný balíček odebrat nejde. Ostatní ne, dokud ho
  používá nějaký server (jeho hru nebo variantu); balíček s hrou ne, dokud
  ji rozšiřuje jiný balíček.
- **Aktualizace balíčku** (novější verze z katalogu, nebo nové nahrání
  vlastního se stejným `id`) nahradí manifest; běžící servery zůstanou,
  nové nastavení použijí při příští instalaci nebo aktualizaci serveru.
- Server, jehož hru nebo variantu už žádný balíček nepřidává, běží dál,
  jen ho nejde aktualizovat ani založit znovu.

## Katalog WSPanelu a podpis

Manifesty katalogu jsou v repozitáři WSPanelu v `games/catalog/<id>.json`.
Release je složí do jednoho souboru a podepíše klíčem apt repozitáře
(`provision/game-catalog.sh`, krok workflow `apt.yml`):

```
https://onyklicek.github.io/wspanel/games/catalog.json       {"format": 1, "packages": [<manifest>, …]}
https://onyklicek.github.io/wspanel/games/catalog.json.asc   podpis (OpenPGP, odpojený, ASCII)
```

Agent stáhne obojí, podpis ověří proti klíči WSPanelu
(`/usr/share/keyrings/wspanel-archive-keyring.asc`, balíček
`wspanel-archive-keyring`) přes `gpgv` a teprve pak katalog čte. Katalog bez
platného podpisu odmítne celý. Manifest, který tahle verze agenta nezná
(novější formát), z nabídky vypadne. Adresu katalogu a klíč lze změnit
v `/etc/panel-agent/agent.json` (`game_catalog_url`, `archive_keyring`).

**Přidat balíček do katalogu:** pull request s `games/catalog/<id>.json`
(jméno souboru = `id`). Dostupný bude s nejbližším vydáním WSPanelu.

## REST API

Stejné API a tokeny jako ostatní (**Účet → API a CLI**, viz [API a
CLI](api.md)). Herní endpointy existují jen se zapnutým modulem Herní
servery (jinak 404); balíčky jen pro správce (klient dostane 404). Změny
potřebují token, který smí měnit (jinak 403).

| Metoda | Cesta | Kdo | Co dělá |
|---|---|---|---|
| GET | `/api/v1/games` | každý | hry, jejich varianty a verze, které jdou nainstalovat |
| GET | `/api/v1/game-servers` | každý (klient své) | herní servery |
| GET | `/api/v1/game-servers/{id}` | každý (klient své) | jeden server |
| GET | `/api/v1/game-packages` | správce | nainstalované balíčky a ty, které neplatí |
| GET | `/api/v1/game-packages/catalog` | správce | co nabízí katalog WSPanelu |
| POST | `/api/v1/game-packages` | správce, zápis | nahraje vlastní balíček; **tělo požadavku je manifest** |
| POST | `/api/v1/game-packages/{id}/install` | správce, zápis | nainstaluje (nebo aktualizuje) balíček z katalogu |
| DELETE | `/api/v1/game-packages/{id}` | správce, zápis | odebere balíček z katalogu nebo vlastní |

Odpovědi na chybu: `422` neplatný manifest nebo parametr, `409` konflikt
(balíček používají servery, varianta už existuje…), `404` neznámý balíček,
`502` agent neodpovídá. Tělo chyby je `{"error": "…"}` (česky podle jazyka
panelu).

```sh
# hry a varianty s verzemi
curl -H "Authorization: Bearer $TOKEN" https://panel.example.com/api/v1/games

# vlastní balíček ze souboru (tělo = manifest)
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  --data-binary @minecraft-folia.json https://panel.example.com/api/v1/game-packages

# z katalogu
curl -X POST -H "Authorization: Bearer $TOKEN" \
  https://panel.example.com/api/v1/game-packages/minecraft-folia/install

# odebrání
curl -X DELETE -H "Authorization: Bearer $TOKEN" \
  https://panel.example.com/api/v1/game-packages/minecraft-folia
```

Příklad odpovědi `GET /api/v1/games`:

```json
{"data": [{
  "id": "minecraft", "name": "Minecraft Java", "engine": "minecraft", "proto": "tcp",
  "default_port": 25565, "min_memory_mb": 1024, "available": true,
  "flavors": [
    {"id": "paper", "label": "Paper (pluginy, výkon)", "package": "minecraft",
     "addons": {"kind": "plugins", "modrinth_loaders": ["paper", "spigot", "bukkit"], "hangar": true},
     "versions": ["26.3", "1.21.10"]},
    {"id": "folia", "label": "Folia (vícevláknový, velké servery)", "package": "minecraft-folia",
     "addons": {"kind": "plugins", "modrinth_loaders": ["folia"]}, "versions": ["1.21.8"]}
  ]
}]}
```

Příklad položky `GET /api/v1/game-packages`:

```json
{"id": "minecraft-folia", "name": "Folia pro Minecraft", "version": "1.0.0",
 "description": "…", "origin": "catalog", "extends": "minecraft",
 "flavors": [{"id": "folia", "label": "Folia (vícevláknový, velké servery)"}],
 "servers": [9]}
```

`origin` je `builtin`, `catalog` nebo `local`; `servers` jsou id herních
serverů, které balíček používají. U balíčku s hrou je místo `extends`
`game`, `game_label`, `engine` a `arch`. Seznam `problems` má položky
`{"origin", "file", "id", "error"}`. Položka katalogu má navíc `installed`
(nainstalovaná verze, nebo prázdný řetězec).

Formální popis je v [openapi.yaml](openapi.yaml).

## Příkazová řádka (wspanel)

```sh
wspanel games                                  # hry a varianty
wspanel game-servers
wspanel game-packages                          # nainstalované (správce)
wspanel game-catalog                           # co nabízí katalog
wspanel game-package upload minecraft-folia.json
wspanel game-package install minecraft-folia   # z katalogu (i novější verze)
wspanel game-package remove minecraft-folia
```

`--json` vypíše odpověď API, jak přišla.

## Operace agenta (interní API)

Panel mluví s agentem přes Unix socket `/run/panel-agent.sock` (JSON na
řádek: `{"op": "…", "params": {…}}`, odpověď `{"ok": true, "output": …}`
nebo `{"ok": false, "error": {"code", "message"}}`). Herní balíčky:

| Operace | Parametry | Odpověď |
|---|---|---|
| `games.catalog` | – | `{"<hra>": {"<varianta>": ["verze", …]}, "games": {"<hra>": {name, engine, proto, default_port, min_memory_mb, available, package, flavors: [{id, label, package, addons}]}}}` |
| `games.packages` | `catalog` (bool, volitelně) | `{"packages": […], "problems": […], "catalog": […], "catalog_error": "…"}` |
| `games.package_install` | `id` | `{"installed": <balíček>}` |
| `games.package_upload` | `manifest` (string, JSON manifestu) | `{"installed": <balíček>}` |
| `games.package_remove` | `id` | `{"removed": "<id>"}` |

Chybové kódy: `invalid_params` (manifest nebo parametr), `conflict`,
`not_found`. Operace se zapisují do auditu jako každá jiná. Herní servery
samotné (`game.create`, `game.update`…) berou hru a variantu z balíčků;
`game.create` ukládá ke stavu serveru i protokol portu, aby firewall seděl,
i kdyby balíček později zmizel.

## Nový engine nebo zdroj (pro vývojáře)

Hra, kterou umí existující engine a zdroj, je jen manifest. Nová hra, která
potřebuje jiné spouštění (třeba Counter-Strike 2 přes SteamCMD a Source
RCON), potřebuje nový engine nebo zdroj v agentovi:

1. Zdroj: `fetchSourceVersions` (verze) a `resolveGameDownload` (adresa
   a otisk) v `agent/internal/ops/games.go`; stažení vždy ověřené otiskem
   vydavatele (u SteamCMD jeho vlastní kontrolou).
2. Engine: jeho jméno a zdroje, které umí, do `gameEngines`
   (`agent/internal/ops/gamepkgs.go`); spuštění (`gameExecStart`),
   konfigurace (`configureGame`), umístění souborů (`placeDownload`),
   hráči (`gamePlayers`), co se nezálohuje (`gameBackupExcludes`).
3. Testy v `agent/internal/ops/gamepkgs_test.go` (manifest, odmítnutí)
   a `games_test.go` (zdroj proti falešnému vydavateli).
4. Panel se přizpůsobí sám (hry bere z agenta); obrazovky specifické pro
   engine (Nastavení, Hráči) se řídí `Game::is('<engine>')`.
5. Balíček s hrou do `games/catalog/` (nebo jako vestavěný do
   `agent/internal/ops/gamepkgs/`) a dokumentace sem.

Herní servery běží vždy nativně (systemd, vlastní uživatel, sandbox,
limity), ne v kontejneru.
