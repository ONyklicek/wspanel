# Herní balíčky

Co herní server umí spustit, určují **herní balíčky**. Balíček je JSON
manifest, který buď **přidá hru** (Minecraft, Factorio, Counter-Strike 2…),
nebo **rozšíří existující hru o varianty** (Folia pro Minecraft). Panel i agent se podle
nainstalovaných balíčků řídí: formulář nového serveru nabízí jejich hry,
varianty a verze, agent podle nich server stáhne, nastaví a spustí.

Balíček je **popis, ne program**. Agent běží jako root, proto manifest
neobsahuje žádné příkazy pro roota: jmenuje mechanismy, které agent sám
obsahuje (engine, zdroj stažení, konzoli, dotaz na hráče), a jejich
parametry. Hra s enginem `native` navíc popíše, který program ze své
složky spustit a s jakými argumenty – ten ale běží jako uživatel hry ve
stejném sandboxu jako každý herní server. Každé pole agent ověří dřív,
než balíček začne platit; neznámé pole, engine nebo zdroj balíček odmítne.

- [Jak přidat hru nebo variantu](#jak-přidat-hru-nebo-variantu) – postup krok za krokem
- [Odkud se balíčky berou](#odkud-se-balíčky-berou)
- [V panelu](#v-panelu-server--herní-balíčky)
- [Formát manifestu](#formát-manifestu) – úplný popis polí
- [Běh hry (`game.run`)](#běh-hry-gamerun-engine-native) – hra, kterou balíček spouští sám (CS2)
- [Enginy a zdroje](#enginy-a-zdroje)
- [Pravidla](#pravidla-konflikty-pořadí-odebrání)
- [Katalog WSPanelu a podpis](#katalog-wspanelu-a-podpis)
- [REST API](#rest-api)
- [Příkazová řádka](#příkazová-řádka-wspanel)
- [Operace agenta](#operace-agenta-interní-api)
- [Nová hra s vlastním enginem](#nová-hra-s-vlastním-enginem-pro-vývojáře)

## Jak přidat hru nebo variantu

### 1. Stačí balíček?

Balíček skládá to, co agent umí: **engine** (jak hru spustit, nastavit,
mluvit s její konzolí) a **zdroj** (odkud a jak ověřeně stáhnout server).
Agent umí enginy `minecraft`, `factorio` a `native` a zdroje `papermc`,
`mojang`, `fabric`, `forge`, `factorio` a `steamcmd`.

| Chci přidat | Jak |
|---|---|
| Variantu Minecraftu z jiného projektu PaperMC (jako Folia) | balíček s `extends` – [krok 2](#2-napište-manifest) |
| Samostatnou hru nad existujícím enginem, např. „Minecraft s módy“ s vlastním portem a víc pamětí | balíček s `game` – [krok 2](#2-napište-manifest) |
| Hru, jejíž dedikovaný server je na Steamu s anonymním přihlášením (Counter-Strike 2, Valheim, Rust, ARK, Garry's Mod…) | balíček s enginem `native` a zdrojem `steamcmd` – [Běh hry](#běh-hry-gamerun-engine-native); hotový je **Counter-Strike 2** v Katalogu WSPanelu |
| Hru, která se stahuje jinak (vlastní web vydavatele, Steam s přihlášením) | balíček nestačí: potřebuje nový zdroj v agentovi – [pro vývojáře](#nová-hra-s-vlastním-enginem-pro-vývojáře) |
| Server z vlastního souboru nebo adresy (vlastní JAR, modpack z webu) | nejde, záměrně: agent stahuje jen od vydavatele hry a ověřuje otisk; soubory serveru ale můžete po instalaci změnit přes **Soubory** nebo SFTP |

Projekt PaperMC musí být herní server (`paper`, `folia`); `velocity`
a `waterfall` jsou proxy a jako herní server nepoběží.

### 2. Napište manifest

Soubor `<id>.json`, kde `<id>` je `id` balíčku. Příklad samostatné hry nad
enginem Minecraftu (agent ho přijme tak, jak je):

```json
{
  "id": "minecraft-mody",
  "name": "Minecraft s módy",
  "version": "1.0.0",
  "description": "Samostatná hra pro módované servery: víc paměti a vlastní port.",
  "game": {
    "id": "mcmody",
    "label": "Minecraft s módy",
    "engine": "minecraft",
    "proto": "tcp",
    "default_port": 25600,
    "min_memory_mb": 4096,
    "arch": ["amd64", "arm64"]
  },
  "flavors": [
    {"id": "fabric", "label": "Fabric", "source": {"type": "fabric"},
     "addons": {"kind": "mods", "modrinth_loaders": ["fabric"]}},
    {"id": "forge", "label": "Forge", "source": {"type": "forge"},
     "addons": {"kind": "mods", "modrinth_loaders": ["forge"]}}
  ]
}
```

Rozšíření existující hry o variantu vypadá jako `minecraft-folia`
v [Formátu manifestu](#formát-manifestu) níže: místo `game` je
`"extends": "minecraft"` a `id` varianty nesmí v té hře už existovat.
Všechna pole a jejich pravidla: [Formát manifestu](#formát-manifestu).

Na co si dát pozor:
- `game.id` se ukládá k serverům: po prvním serveru ho neměňte. Při
  změně čehokoli jiného zvyšte `version`.
- `default_port` volte tak, aby se nepletl jiné hře; panel jinak vezme
  další volný port.
- `addons` uveďte, jen když varianta pluginy nebo módy opravdu načítá: podle
  něj se zobrazí záložka **Pluginy a módy** a hledá v Modrinthu.

### 3. Nahrajte ho

Kteroukoli z cest; agent manifest pokaždé celý zkontroluje:

- **Panel:** **Server → Herní balíčky → Nahrát vlastní balíček**, vložit JSON.
- **API:** `POST /api/v1/game-packages`, tělo = manifest ([REST API](#rest-api)).
- **CLI:** `wspanel game-package upload minecraft-mody.json`.
- **Soubor na serveru:** `/etc/wspanel/games.d/minecraft-mody.json`
  (vlastník root, práva 0644). Agent ho čte při každém dotazu, restart
  netřeba; formulář nového serveru ho nabídne po otevření stránky
  **Herní balíčky** (jinak do 10 minut).

Odmítnutý manifest panel i API vrátí s důvodem (např. `game.engine:
factorio or minecraft`); soubor vložený ručně se ukáže mezi **Balíčky,
které neplatí**.

### 4. Vyzkoušejte

1. **Server → Herní balíčky:** balíček je mezi nainstalovanými, u něj jeho
   hra a varianty.
2. **Herní servery → Nový server:** vyberte novou hru a variantu; seznam
   verzí přichází od vydavatele (prázdný = zdroj neodpovídá nebo pro
   tuto architekturu nic nemá).
3. Po instalaci na stránce serveru stav přejde přes „spouští se“ na
   „běží“, jakmile odpoví konzole; v konzoli zkuste `list`.

### 5. Sdílejte ho (volitelné)

Aby balíček mohli instalovat všichni z **Katalogu WSPanelu**, pošlete pull
request se souborem `games/catalog/<id>.json` do repozitáře WSPanelu.
S nejbližším vydáním se katalog podepíše a panel ho nabídne
([Katalog WSPanelu a podpis](#katalog-wspanelu-a-podpis)).

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
| `engine` | string | `minecraft`, `factorio` nebo `native` – jak agent hru spouští, viz [Enginy](#enginy-a-zdroje) |
| `proto` | string | `tcp` nebo `udp` – protokol portu hry (otevírá se ve firewallu) |
| `default_port` | číslo | 1024–65535; výchozí port (panel vezme ten, nebo další volný z rozsahu 20000–40000 mimo 27000–27999) |
| `min_memory_mb` | číslo | 256–65536; nejmenší paměť serveru |
| `arch` | pole | `amd64` a/nebo `arm64`; na jiném procesoru se hra nenabízí (SteamCMD jen `amd64`) |
| `run` | objekt | jen a vždy s enginem `native`: jak hru spustit, viz [Běh hry](#běh-hry-gamerun-engine-native) |

### Varianta (`flavors[]`)

| Pole | Typ | Pravidlo |
|---|---|---|
| `id` | string | `^[a-z][a-z0-9-]{0,31}$`; v rámci hry jedinečné (i napříč balíčky) |
| `label` | string | 1–64 znaků; název ve formuláři |
| `source.type` | string | zdroj serveru, musí ho umět engine hry, viz [Zdroje](#zdroje) |
| `source.project` | string | jen u `papermc`: projekt PaperMC (`paper`, `folia`), `^[a-z0-9][a-z0-9-]{0,31}$` |
| `source.app_id` | číslo | jen a vždy u `steamcmd`: Steam aplikace dedikovaného serveru (CS2: `730`, Valheim: `896660`) |
| `source.branch` | string | jen u `steamcmd`, volitelně: betaverze Steamu (`^[a-z0-9][a-z0-9-]{0,31}$`), jinak `public` |
| `addons` | objekt | ne – pluginy nebo módy, které varianta načítá |
| `addons.kind` | string | `plugins` (složka `data/plugins`) nebo `mods` (`data/mods`) |
| `addons.modrinth_loaders` | pole | 1–10 jmen loaderů Modrinthu (`paper`, `folia`, `fabric`…): podle nich se hledá a ověřuje kompatibilita |
| `addons.hangar` | bool | i katalog Hangar (pluginy PaperMC); jen se zdrojem `papermc` |

## Běh hry (`game.run`, engine `native`)

S enginem `native` balíček sám popíše, jak hru spustit. Tak přichází
**Counter-Strike 2** ([`games/catalog/cs2.json`](../games/catalog/cs2.json))
a stejně jde přidat jiný dedikovaný server ze Steamu.

```json
"game": {
  "id": "cs2", "label": "Counter-Strike 2", "engine": "native", "proto": "udp",
  "default_port": 28015, "min_memory_mb": 4096, "arch": ["amd64"],
  "run": {
    "command": "game/bin/linuxsteamrt64/cs2",
    "args": ["-dedicated", "-usercon", "-port", "{port}", "-maxplayers", "{setting:max_players}",
             "+map", "{setting:map}", "+hostname", "{name}", "+rcon_password", "{rcon_password}",
             {"when": "gslt", "args": ["+sv_setsteamaccount", "{setting:gslt}"]}],
    "stop_signal": "SIGINT",
    "console": {"protocol": "source-rcon", "port": "game", "commands": ["status", "changelevel "]},
    "query": "a2s",
    "disk_gb": 60,
    "links": {"game/csgo/cfg/server.cfg": "server.cfg", "game/csgo/addons/": "addons/"},
    "settings": [
      {"key": "map", "label": "Mapa", "type": "text", "default": "de_dust2", "pattern": "^[a-z0-9_]{1,40}$"},
      {"key": "max_players", "label": "Počet hráčů", "type": "int", "default": "10", "min": 2, "max": 64},
      {"key": "gslt", "label": "Steam Game Server Login Token", "type": "secret", "pattern": "^[A-F0-9]{32}$"}
    ]
  }
},
"flavors": [{"id": "public", "label": "Stabilní vydání (Steam)", "source": {"type": "steamcmd", "app_id": 730}}]
```

| Pole | Pravidlo |
|---|---|
| `command` | program ve složce `server/` hry (relativní cesta, bez `..`); spouští se jako uživatel hry |
| `args` | nejvýš 64 argumentů; každý jeden řádek do 200 znaků se zástupnými hodnotami (níže). Skupina `{"when": "<nastavení>", "args": [...]}` se použije, jen když má nastavení hodnotu (a u `bool` když je zapnuté) |
| `stop_signal` | `SIGINT`, `SIGTERM` (výchozí) nebo `SIGQUIT`: čím se hra zastaví, aby uložila svět |
| `console` | `{"protocol": "source-rcon", "port": "game" \| "own", "commands": [...]}`. `game`: RCON na TCP portu hry (Source, CS2); `own`: vlastní port, argumenty musí předat `{rcon_port}`. `commands` (nejvýš 12): rychlé příkazy pod konzolí v panelu. Bez `console` hra v panelu konzoli nemá (log ano) |
| `query` | `a2s`: panel se ptá hry protokolem Steamu (A2S) na hráče; hra je „běží“, až odpoví. Bez něj je připravená hned po spuštění |
| `disk_gb` | volné místo, které instalace potřebuje; menší disk instalaci odmítne dřív, než cokoli stáhne |
| `links` | soubory a složky, které hra čte ze své instalace, ale patří k jejím datům: `"cesta/v/server": "cesta/v/data"` (složka s `/` na konci). Agent je přesune do `data/` a nahradí odkazem; v `data/` je zálohy uchovají a aktualizace hry je nepřepíše. Jen věci, které Steam nedodává (vlastní `server.cfg`, `addons/`) |
| `backup.exclude` | cesty v `data/`, které se nezálohují |
| `settings` | nejvýš 30 voleb, které panel ukáže v záložce **Nastavení** a dosadí do argumentů |

**Zástupné hodnoty v argumentech:** `{port}` (port hry), `{rcon_port}`,
`{rcon_password}` (náhodné, zná ho jen agent), `{name}` (název serveru
z panelu), `{memory_mb}`, `{setting:<klíč>}`. Jiné složené závorky manifest
odmítne.

**Volby (`settings[]`):**

| Pole | Pravidlo |
|---|---|
| `key` | `^[a-z][a-z0-9_]{0,31}$` |
| `label`, `help` | text pro panel (do 64 / 300 znaků) |
| `type` | `text`, `int`, `bool`, `select` nebo `secret` (heslo, token: panel ho jen zapíše, nikdy nezobrazí, v auditu je skrytý) |
| `default` | výchozí hodnota (ne u `secret`); musí projít vlastními pravidly |
| `required` | volba nesmí být prázdná |
| `pattern` | u `text` a `secret`: regulární výraz (RE2), který hodnota musí splnit |
| `min`, `max` | u `int` |
| `options` | u `select`: 1–50 položek `{"value", "label"}` |

Hodnota je vždy jeden řádek bez uvozovek a zpětných lomítek (`text` do
128 znaků); do argumentů jde jako jeden argument, nikdy přes shell.

**Bezpečnost:** program běží jako uživatel hry ve stejném sandboxu systemd
jako každý herní server (jen vlastní složka pro zápis, žádná práva navíc,
port jen ten svůj a TCP portu RCON, limity paměti a CPU). Příkazový řádek
hry (heslo RCON, token) ostatní uživatelé serveru nevidí (`ProtectProc`).
RCON je dostupný jen rootovi na `127.0.0.1`; firewall otevře jen port hry
a jen jejím protokolem.

**Porty:** herní servery dostávají porty 20000–40000 kromě 27000–27999
(RCON), proto CS2 začíná na 28015 místo obvyklého 27015 – hráči se
připojují na adresu s portem, kterou ukáže panel.

**Instalace a aktualizace** běží na pozadí a dlouhé stahování nebrzdí zbytek
panelu (agent při něm nedrží svůj zámek). SteamCMD se instaluje do složky
hry pod jejím uživatelem; z Debianu se doinstalují jen 32bitové knihovny
`lib32gcc-s1`, které SteamCMD potřebuje. **Aktualizovat** stáhne ze Steamu
jen změněné soubory.

## Enginy a zdroje

**Engine** je to, co agent o hře ví: jak ji spustit, kde má nastavení, jak
s ní mluví konzole a jak zjistí hráče. Engine je kód agenta, balíček ho jen
jmenuje.

| Engine | Co obstará | Zdroje |
|---|---|---|
| `minecraft` | Java z Debianu (21/25 podle verze, doinstaluje se), `server.properties` (port, RCON), EULA, konzole přes RCON, hráči (`list`), záloha s `save-off`/`save-all`, Nastavení a Hráči v panelu | `papermc`, `mojang`, `fabric`, `forge` |
| `factorio` | headless server, `server-settings.json`, první mapa, konzole přes RCON, hráči, módy z mod portálu | `factorio` |
| `native` | spustí program, který popíše balíček (`game.run`), s argumenty z panelu a z nastavení; konzole přes Source RCON a hráči přes Steam A2S, když je hra má; formulář Nastavení z `settings` | `steamcmd` |

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
| `steamcmd` | Steam přes SteamCMD, anonymní přihlášení, do složky `server/` hry | soubory hry ověřuje SteamCMD proti podepsaným manifestům Steamu; samotný SteamCMD (zavaděč, ~3 MB) se stahuje přes HTTPS od Valve, otisk Valve nezveřejňuje, a aktualizuje se sám | jen `latest`: nejnovější build větve |

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
| `game.options` | `id` | volby hry s enginem `native`: `{"settings": [...], "values": {…}, "secrets": {"<klíč>": true}}` (tajné jen zda jsou nastavené) |
| `game.set_options` | `id`, `values`, `secrets`, `clear`, `restart` | uloží volby; prázdné tajné ponechá, `clear` je odebere; s `restart` restartuje běžící server |

Chybové kódy: `invalid_params` (manifest nebo parametr), `conflict`,
`not_found`. Operace se zapisují do auditu jako každá jiná. Herní servery
samotné (`game.create`, `game.update`…) berou hru a variantu z balíčků;
`game.create` ukládá ke stavu serveru i protokol portu, aby firewall seděl,
i kdyby balíček později zmizel.

## Nová hra s vlastním enginem (pro vývojáře)

Většinu her pokryje engine `native` se zdrojem `steamcmd` bez jediného
řádku kódu. Kód v agentovi potřebuje jen hra, která se **stahuje jinak**
(nový zdroj), nebo potřebuje něco, co `native` neumí: jinou konzoli než
Source RCON, jiný dotaz na hráče než A2S, vygenerovaný konfigurační soubor,
první svět vytvořený zvláštním příkazem. Engine je kód, ne konfigurace:
agent běží jako root a manifest nesmí nic spouštět pod rootem.

**V agentovi** (`agent/internal/ops/`):

1. **Engine a jeho zdroje:** jméno enginu a zdroje, které umí, do
   `gameEngines` v `gamepkgs.go`. Od té chvíle manifest s tímto enginem
   projde kontrolou.
2. **Zdroj** (nový způsob stažení) v `games.go`:
   - `fetchSourceVersions` – seznam verzí od vydavatele,
   - `resolveGameDownload` – adresa a otisk souboru (`sha256:`/`sha1:`);
     stažení bez otisku vydavatele se nepřijímá (u SteamCMD jeho vlastní
     kontrola).
3. **Instalace a běh** v `games.go`:
   - `placeDownload` – kam a jak se stažený soubor rozbalí (`server/`),
   - `configureGame` – konfigurace hry: port, RCON, první mapa nebo svět;
     při každém spuštění vrací port a RCON, které spravuje panel,
   - `gameExecStart` – příkazový řádek služby systemd (jen absolutní cesty,
     žádný shell); sandbox a limity paměti a CPU doplní agent sám.
4. **Konzole a hráči:** příkazy jdou přes `rconExec` (protokol Source RCON;
   jiný protokol = nová funkce vedle něj). `gamePlayers` vrací, kdo hraje
   (`online`, `max`, `names`); engine, který to zjistit neumí, ať vrátí
   `errNoPlayerQuery` – server se pak bere za připravený hned po spuštění.
5. **Zálohy:** `gameBackupExcludes` (co nezálohovat, např. cache)
   a v `gameBackup` případné uložení světa před zálohou (Minecraft:
   `save-off` / `save-all flush`).
6. **Testy:** `gamepkgs_test.go` (manifest s novým enginem projde, chybný
   ne) a `games_test.go` (zdroj proti falešnému vydavateli, `configureGame`,
   `gameExecStart`).

**V panelu** (`panel/`) není potřeba nic, aby hra fungovala: hry,
varianty, porty i verze bere od agenta (`App\Enums\Game`), stav,
konzole, log, zálohy, soubory a SFTP jsou společné všem hrám. Záložky
napsané pro konkrétní hru se řídí enginem (`Game::is('<engine>')`) a u
nového enginu jen řeknou, že pro tu hru nic nemají, dokud jim nepřidáte
větev:

- **Nastavení** (`GameServerSettings`, `games/settings.blade.php`),
- **Hráči** (`GamePlayers`, `games/players.blade.php`),
- **Pluginy a módy** (`GamePlugins`; u Minecraftu podle `addons` varianty),
- návrhy častých příkazů v konzoli (`GameConsole::suggestions()`).

**Nakonec** balíček s hrou do `games/catalog/` (nebo jako vestavěný do
`agent/internal/ops/gamepkgs/` a do `Game::BUILTIN` v panelu) a řádek do
tabulek [Enginy a zdroje](#enginy-a-zdroje).

Herní servery běží vždy nativně (systemd, vlastní uživatel, sandbox,
limity), ne v kontejneru.
