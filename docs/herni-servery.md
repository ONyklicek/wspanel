# Herní servery

Volitelný modul: zapíná se při instalaci (`install.sh --games=yes`) nebo
později v **Server → Moduly**. Do té doby se na server nic neinstaluje. Java
a samotný server hry se stáhnou až s prvním herním serverem.

| Hra | Varianty | Port (výchozí) |
|---|---|---|
| Minecraft Java | Paper (pluginy, lepší výkon), Vanilla (oficiální server), Fabric a Forge (módy); s balíčkem z katalogu Folia (vícevláknový) | 25565/tcp |
| Factorio | stabilní vydání (jen procesory x86-64) | 34197/udp |
| Counter-Strike 2 | balíček z Katalogu WSPanelu; ze Steamu (SteamCMD), jen x86-64, asi 60 GB na disku, nejméně 4 GB paměti | 28015/udp |

Které hry a varianty jdou spustit, určují **herní balíčky**
(**Server → Herní balíčky**): Minecraft a Factorio jsou vestavěné, další
přicházejí z podepsaného katalogu WSPanelu (Folia, Counter-Strike 2) nebo jako vlastní balíček
správce. Jak přidat hru nebo variantu (postup s příkladem), formát, katalog
a API: [Herní balíčky](herni-balicky.md#jak-přidat-hru-nebo-variantu).

## Nový server

**Hosting → Herní servery → Nový server:** název, hra, varianta, verze, port,
paměť a procesor. U Minecraftu je potřeba odsouhlasit jeho licenční podmínky
(EULA), jinak se server nespustí.

- **Hry ze Steamu** (Counter-Strike 2) mají jedinou verzi, „nejnovější“:
  instalace stáhne aktuální build přes SteamCMD (u CS2 kolem 60 GB, podle
  připojení i desítky minut; běží na pozadí a panel mezitím funguje), na
  stránce serveru je pak vidět číslo buildu. **Aktualizovat** stáhne jen
  změněné soubory. Mapu, režim, počet hráčů, heslo a Steam token (GSLT,
  bez něj není server ve veřejném seznamu) nastavíte v záložce
  **Nastavení**; vlastní `server.cfg` a `addons/` (Metamod, pluginy) jsou
  ve složce `data` a patří do záloh.
- **Verze** nabízí vydavatel hry (PaperMC, Mojang, Wube). Minecraft starší
  než 1.20.5 potřebuje Javu 17, kterou Debian 13 nemá, proto v nabídce
  není. Javu 21 nebo 25 (podle verze) panel doinstaluje z Debianu sám.
- **Stažení** se ověřuje otiskem, který zveřejnil vydavatel (SHA-256,
  u oficiálního serveru Mojangu SHA-1). Soubor s jiným otiskem se odmítne.
- **Fabric a Forge** se instalují svým oficiálním instalátorem (z maven
  úložiště Fabricu a Forge, s otiskem SHA-256 zveřejněným vedle něj),
  spuštěným jako uživatel serveru: Fabric s nejnovějším stabilním loaderem,
  Forge s doporučeným sestavením pro danou verzi Minecraftu (jinak
  nejnovějším). Forge si při instalaci stáhne knihovny, trvá to pár minut.
- **Port** se otevře ve firewallu a při smazání serveru se zase zavře
  (pravidlo, které jste pro ten port měli už předtím, panel nechá být).
  Herní servery používají porty 20000 až 40000, kromě 27000–27999 (RCON)
  a portů známých služeb. Prázdné pole znamená výchozí port hry, nebo
  další volný.
- **Paměť** je u Minecraftu velikost haldy Javy (2048 MB stačí na pár
  hráčů, s pluginy a víc hráči 4096 MB a víc). Proces dostane navíc rezervu
  pro samotnou Javu. Procesor 0 znamená bez omezení, 200 = dvě jádra.

Minecraft si napoprvé vygeneruje svět, to trvá minutu až dvě. Hráči se
připojí na adresu, kterou panel ukáže na stránce serveru (doména panelu
a port; výchozí port Minecraftu se nepíše).

## Stránka serveru

Seznam serverů ukazuje u každého živě, jestli běží, kolik hráčů je online
(a kdo) a kolik paměti bere; spustit, zastavit a restartovat jde přímo
z řádku.

- **Stav** (obnovuje se sám, při spouštění a zastavování častěji): běží
  (a jak dlouho), spouští se, zastavuje se, zastaveno, spadl. Minecraft
  a Factorio jsou „spouští se“, dokud nenačtou svět a neodpoví jejich
  konzole. Dále hráči online se jmény, zatížení procesoru (100 % = jedno
  jádro, vedle případného limitu), paměť proti nastavenému limitu, adresa
  pro hráče s tlačítkem pro zkopírování a počet automatických restartů po
  pádu. **Spustit / Zastavit / Restartovat**; zastavený server zůstane
  zastavený i po restartu serveru.
- **Nastavení** (Minecraft): formulář nad `server.properties`, jak ho
  zapsal server dané verze, po skupinách (hra, hráči a přístup, svět,
  výkon a síť, resource pack), s rozsahy hodnot; volby, které panel nezná
  (novější verze), jako text. Port, RCON, query a management server
  spravuje panel. PvP, spawnování příšer a podobně jsou od verze 1.21.9
  herní pravidla: v konzoli např. `gamerule pvp false`. *Uložit a
  restartovat* změny rovnou uplatní.
- **Nastavení** (Factorio): formulář nad `server-settings.json`: název,
  popis, štítky, počet hráčů, viditelnost (veřejný seznam, LAN), ověřování
  hráčů, kdo smí příkazy, pozastavení, automatické ukládání, vyhazování
  nečinných, limity odesílání. Účet factorio.com (jméno a token) pro
  veřejný seznam a módy a heslo ke hře se jen zadávají: panel je nikdy
  nezobrazí. Ostatní volby souboru zůstanou, jak jsou.
- **Hráči** (Minecraft): whitelist (zapnout, přidat, odebrat), operátoři,
  bany hráčů i IP adres, vyhození. Změny jdou do konzole běžícího serveru
  a platí hned; seznamy jsou vidět i u zastaveného serveru.
- **Pluginy a módy → Módy** (Fabric, Forge): jako pluginy, ale jen
  z katalogu Modrinth (Hangar má jen pluginy pro Paper), módy pro daný
  loader a verzi Minecraftu, do `data/mods`; povinné závislosti (třeba
  Fabric API) se nainstalují s módem. Hráči potřebují stejné módy u sebe.
- **Pluginy a módy → Pluginy** (Folia): jako u Paperu, ale jen z Modrinthu
  a jen pluginy, které Folii podporují (loader `folia`); Hangar nabízí
  pluginy pro Paper, které na Folii často nefungují.
- **Pluginy a módy → Pluginy** (Paper): hledání v katalozích **Modrinth** a **Hangar**
  (PaperMC), jen verze pro Minecraft serveru, instalace jedním tlačítkem,
  seznam nainstalovaných (se zdrojem a verzí), *Zkontrolovat aktualizace*,
  aktualizace a odebrání. Soubor se stahuje jen z úložiště katalogu
  (`cdn.modrinth.com`, `hangarcdn.papermc.io`) a ověřuje otiskem, který
  katalog zveřejňuje (SHA-512, SHA-256); plugin, který katalog jen odkazuje
  jinam (bez otisku), panel neinstaluje, nahrát ho jde ručně v Souborech.
  Povinné závislosti z Modrinthu se nainstalují s pluginem, u Hangaru je
  panel vypíše. Přednost má stabilní verze před vývojovou. Pluginy se
  načtou po restartu serveru (tlačítko *Restartovat teď*).
- **Pluginy a módy → Módy** (Factorio): hledání v mod portálu (mods.factorio.com) podle
  názvu i popisu, jen verze pro Factorio serveru, seřazené podle počtu
  stažení; instalace s povinnými závislostmi (ne s volitelnými ani se
  základní hrou a DLC), kontrola a instalace aktualizací (starší verze
  módu se smaže), odebrání. Soubor se ověřuje otiskem z portálu (SHA-1).
  Portál módy stahuje jen s účtem factorio.com: jméno a token v
  Nastavení serveru (token se nikde nezobrazí ani nezapíše do logu).
  Factorio nový mód při startu zapne sám.
- **Soubory:** záložka se správcem souborů nad složkou serveru, jako
  u webů: úpravy konfigurací (`server.properties`, `bukkit.yml`,
  `config/paper-*.yml`, nastavení pluginů a módů), nahrávání pluginů a
  módů, stahování, rozbalování archivů. Pracuje jako uživatel serveru,
  jen v jeho složce. Změny platí po restartu serveru.
- **SFTP** (tlačítko v záhlaví stránky serveru): přenos souborů do složky
  serveru jako jeho uživatel `wsg_<id>`, jen SFTP, nikdy shell. Klíče
  (bezpečnější) a/nebo heslo; vygenerované heslo se ukáže jen jednou,
  panel hesla neukládá. Přihlášení: `sftp -P <port SSH> wsg_<id>@<server>`
  (stránka ho ukáže). Změna přístupu ukončí otevřené SFTP relace, běžící
  server hry ne. U klienta platí limit SSH/SFTP z jeho balíčku.
- **Konzole:** log serveru tak, jak se zapisuje (sleduje se živě, jde
  vypnout), a v něm odeslané příkazy s odpověďmi. Příkazy serveru, např.
  `list`, `say Ahoj`, `op Jméno`, `whitelist add Jméno` u Minecraftu,
  `/players` nebo `/promote Jméno` u Factoria, panel posílá přes RCON;
  časté příkazy jsou připravené pod polem, šipkami ↑ ↓ se vrátíte
  k poslaným. Varování jsou žlutě, chyby červeně, příchody a odchody
  hráčů zeleně.
- **Zálohy:** data serveru (svět, nastavení, pluginy či módy) každou noc
  (mezi 4. a 5. hodinou) a kdykoli tlačítkem *Zálohovat teď*. Běžící
  Minecraft před zálohou uloží svět a po dobu zálohy neukládá. Knihovny a
  cache, které si server vytvoří sám, v záloze nejsou. Na serveru se
  uchovává posledních 10 záloh v `/var/backups/wspanel-games`. Se zapnutými
  **zálohami mimo server** (Server → Zálohy mimo server) se každá záloha
  zkopíruje i tam, podepsaná klíčem serveru; kopii, která zůstala jen mimo
  server, stáhne zpět tlačítko *Stáhnout zpět*. Obnova server na chvíli
  zastaví, současná data odloží stranou a smaže je až po úspěšném rozbalení.
- **Aktualizovat:** Paper stáhne nejnovější sestavení zvolené verze, nebo
  novější verzi. Na starší verzi Minecraftu svět vrátit nejde, proto
  předtím zálohujte. Factorio se aktualizuje na nejnovější stabilní vydání,
  mapa zůstane.
- **Nastavení:** název, paměť a procesor. Běžící server se kvůli nim
  restartuje.

Soubory serveru jsou v `/srv/games/<id>`: `server/` (server hry), `data/`
(svět, `server.properties`, pluginy, módy, nastavení Factoria). Port a RCON
v nastavení hry spravuje panel, ostatní nastavení zůstává na vás.

## Bezpečnost

- Každý server běží jako vlastní systémový uživatel (`wsg_<id>`) v
  omezené systemd službě: zapisovat smí jen do své složky, otevřít smí jen
  svůj port a RCON, má pevný limit paměti (a procesoru, je-li nastavený).
- RCON poslouchá jen lokálně a připojit se k němu smí jen agent panelu
  (root). Pravidlo nftables (`wspanel-games-rcon.service`) odmítne pokus
  kohokoli jiného, například uživatele webu na stejném serveru. Heslo
  RCON panel nikdy neukazuje.
- Server hry pochází jen od jeho vydavatele a ověřuje se otiskem.

## Klienti

Se zapnutým modulem Klienti a balíčky může herní server patřit klientovi.
Klient pak vidí a ovládá jen své servery. Balíček omezuje počet herních
serverů, jejich celkovou paměť a procesor na server; s limitem procesoru
má klientův server vždy kvótu (**Hosting → Klienti → Balíčky**).

## Obnova na nový server

Záloha celého serveru (Server → Zálohy mimo server) nese nastavení herních
serverů, jejich data jsou v jejich vlastních zálohách. `install --restore` na
novém serveru postaví každý herní server znovu: doinstaluje Javu nebo
SteamCMD, stáhne stejnou verzi hry, nahraje nejnovější zálohu jeho dat,
zapíše službu a pravidlo firewallu a spustí ho, pokud se spouštěl se starým
serverem. Server bez zálohy dat začne s novým světem (zpráva o obnově to
uvede).

Zálohy dat se berou z úložiště mimo server (`games/<id>/`), nebo z kopie
`/var/backups/wspanel-games` starého serveru vedle složky z `--backups`
(tj. `…/wspanel` a `…/wspanel-games` vedle sebe). Hra z vlastního balíčku musí
být na novém serveru nainstalovaná, jinak obnova napíše, který balíček chybí.

## Co zatím neumí

- Další hry (Terraria, Valheim, hry přes steamcmd), NeoForge a Quilt pro
  Minecraft.
