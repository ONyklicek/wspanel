# Weby

## Založení webu

**Weby → Nový web**

1. **Doména:** např. `example.com`. Veřejná doména musí mířit na server
   (A/AAAA záznam), jinak se nevydá certifikát. Web na `www.` je samostatná
   doména.
2. **HTTPS certifikát:** viz [HTTPS a certifikáty](#https-a-certifikáty).
   U domén vnitřní sítě (`.lan`, `.local`, `.internal`, `.test`, `.localhost`…)
   panel sám předvyplní interní certifikační autoritu.
3. **Režim:**
   - **Classic (PHP-FPM):** pro většinu webů. Lze zvolit verzi PHP.
   - **Worker (FrankenPHP):** aplikace zůstává v paměti mezi požadavky (Laravel
     Octane, Symfony 7.4+ s Runtime). Rychlejší, ale kód musí být „worker-safe“.
     Laravel potřebuje balíček `laravel/octane` a soubor `public/frankenphp-worker.php`
     (`php artisan octane:install --server=frankenphp`). Workery webu (fronty, Horizon,
     plánovač, Reverb) běží i v tomto režimu na systémovém PHP CLI (verze webu, jinak
     nejnovější nainstalovaná), protože CLI zabudované ve FrankenPHP neumí signály a
     workery by se při deployi nezastavily čistě.
   - **Node SSR:** Nuxt, Next.js nebo vlastní `server.js` jako stále běžící proces.
   - **Python:** Django, Flask nebo FastAPI pod gunicornem (uvicornem u FastAPI),
     případně vlastní `app.py`. Nabízí se, až je na serveru Python
     (viz [Python weby](#python-weby)).
4. **Typ aplikace:** Laravel, Symfony, Čisté PHP, Nuxt, Next.js, Node, Django,
   Flask, FastAPI nebo Python.
   Podle typu panel připraví adresáře, `.env`, workery a postup deploye.
5. **Workery** (jen Laravel a Symfony): co má běžet od prvního deploye.
   Celery pro Python weby přidáte u webu po založení.
   Předvybrané jsou fronta a plánovač (Laravel), resp. Messenger a plánovač
   (Symfony); dál Horizon, Reverb a Pulse. Horizon nahrazuje frontu, obojí
   najednou vybrat nejde. Horizon a Pulse ingest zapnou Valkey webu. Další
   workery přidáte kdykoli u webu (Další → Přidat worker).
6. **DNS a pošta:**
   - **Do zóny …:** panel už spravuje zónu, pod kterou doména patří
     (pro `shop.example.com` třeba `example.com`). Zapíše do ní chybějící
     záznamy webu, existující nemění. Je předvybrané, když taková zóna je.
   - **Nová zóna na tomto serveru / v Cloudflare:** založí zónu (výchozí je
     doména bez `www.`) a rovnou do ní zapíše záznamy.
   - **DNS spravuji jinde:** nic se nezapisuje. Co nastavit u registrátora,
     ukáže záložka **DNS** webu.
   - **Pošta pro doménu** (jen s poštovním modulem): založí poštovní doménu
     a do DNS přidá MX, SPF, DKIM, DMARC a záznamy pro webmail a nastavení
     poštovních programů.
   Když se DNS nepovede (např. neplatný token Cloudflare), web se i tak založí
   a panel napíše, co dokončit v záložce DNS.
7. Po vytvoření se otevře **detail webu**. Web už odpovídá úvodní stránkou
   „Web běží“, která popisuje, jak pokračovat.

### DNS webu

Záložka **DNS** u webu ukáže, co web v DNS potřebuje:
- A/AAAA pro doménu webu a jeho další domény (aliasy a přesměrování),
- CAA, SPF a DMARC domény (DKIM, je-li klíč),
- s poštou MX, SRV a `webmail.`, `autoconfig.`, `autodiscover.`.

U každého záznamu je vidět, jestli ho zóna má (jen u zón spravovaných
panelem: **je / chybí / jiná hodnota**) a jestli adresa webu ve veřejném
DNS míří na tento server. **Doplnit chybějící** zapíše, co v zóně chybí.
Záznam, který tam je s jinou hodnotou, panel nepřepíše, jen ho ukáže. Bez
spravované zóny tlačítko **Spravovat DNS v panelu** založí zónu na tomto
serveru (nebo připojí Cloudflare) a záznamy rovnou doplní.

Nově přidaná další doména webu (Nastavení → Domény) se do zóny spravované
panelem zapíše sama (A/AAAA na tento server).

**DNS u registrátora nebo jiného poskytovatele** (DNS spravuji jinde):
záložka ukáže návod a tabulku tak, jak ji chtějí formuláře registrátorů:
- **Název** vůči doméně (`@` je doména sama, `_dmarc`, `www`…), pod ním celý
  název pro formuláře, které chtějí ten. Doménu u registrátora panel pozná
  podle veřejného DNS (pro `www.firma.cz` je to `firma.cz`).
- **Priorita** u MX a SRV zvlášť, u SRV i váha a port.
- **Hodnota** bez uvozovek (TXT), cíle jako celé názvy. Každou hodnotu
  i název jde zkopírovat.
- **Stav** podle veřejného DNS u všech záznamů (A/AAAA, MX, TXT, CNAME,
  SRV, CAA): je / chybí / jiná hodnota (u adres míří jinam).

Domény vnitřní sítě (`.test`, `.lan`…) se veřejně neověřují.

Co se při založení stane:
- vytvoří se systémový uživatel `web_<doména>` bez přihlášení,
- vznikne `/srv/sites/<doména>` (struktura níže),
- PHP-FPM pool, nebo proces aplikace, pod tímto uživatelem,
- edge proxy začne pro doménu vydávat certifikát (Let's Encrypt, nebo z interní CA),
- vytvoří se SMTP přihlášení pro odesílání pošty webem.

## HTTPS a certifikáty

Každý web běží jen přes HTTPS. Certifikát se mění na detailu webu: **Nastavení →
Domény a HTTPS → HTTPS**.

| Volba | Pro koho | Co je potřeba |
|---|---|---|
| **Let's Encrypt** | veřejné domény | doména míří na server a port 80/443 je z internetu dostupný; obnovuje se sám |
| **Interní certifikační autorita** | vnitřní síť, intranet, testy (`intranet.firma.lan`) | počítače, které web otevírají, jednou nainstalují kořenový certifikát serveru |
| **Vlastní certifikát** | firemní CA, zakoupený certifikát | vložíte certifikát (PEM, i s mezilehlými) a soukromý klíč; panel ověří, že k sobě patří a platí pro doménu |

**Kořenový certifikát interní CA** stáhnete v **Síť → SSL →
Kořenový certifikát**. Instalace:
- **Windows:** dvojklik → Nainstalovat certifikát → Místní počítač → Důvěryhodné
  kořenové certifikační autority (nebo rozeslat přes GPO),
- **macOS:** dvojklik → Klíčenka *Systém* → u certifikátu „Vždy důvěřovat“,
- **Linux:** `/usr/local/share/ca-certificates/` + `update-ca-certificates`,
- **Firefox** má vlastní úložiště: Nastavení → Certifikáty → Importovat.

Soukromý klíč vlastního certifikátu je jen na serveru (čitelný pro proxy) a do
auditu se nezapisuje. Vlastní certifikát hlídá panel na stránce SSL; obnovit ho
musíte sami (nahrát nový).

## Soubory

Detail webu → záložka **Soubory**. Správce souborů pracuje jako systémový
uživatel webu a jen v jeho domovské složce `/srv/sites/<doména>`, nikam jinam
se nedostane (ani přes symlinky nebo `..`).

- Procházení složek, **nahrání** (víc souborů najednou, každý do 512 MB),
  **stažení**, nový soubor a složka, přejmenování a přesun, oprávnění
  (např. 644/755), smazání.
- **Editor kódu** jako v IDE: zvýraznění syntaxe (PHP, JS, CSS, HTML, JSON,
  YAML, Markdown, SQL, `.env`…), čísla řádků, sbalování bloků, hledání a
  nahrazování (Ctrl/⌘ F), víc kurzorů. Tlačítko **Naformátovat** (Shift+Alt+F)
  srovná kód: PHP do stylu PSR-12, dále JS, CSS, HTML, JSON, YAML, Markdown.
- **Upravit .env** otevře sdílený `.env` webu (u Symfony `.env.local`) ve
  složce `shared`. Platí pro všechny releasy; proces aplikace a workery ho
  načtou po restartu webu.
- Když soubor mezitím změnil někdo jiný (deploy, SFTP), uložení se odmítne,
  aby se jeho změna nepřepsala.
- **Zip:** vybrané soubory zabalíte do archivu, `.zip` rozbalíte do složky.
- Upravovat jde soubory do 2 MB a uložit do 900 kB; větší soubory a velké
  přenosy jsou pohodlnější přes SFTP.

Aplikace běží ze složky `current` (odkaz na aktuální release). Ruční úpravy v
`current` přepíše příští deploy; trvalé změny patří do Gitu, data a `.env` do
`shared`.

## Zálohy webu

Detail webu → záložka **Zálohy**. Záloha celého webu je jeden archiv
`<doména>-<čas>.tar.gz` v `/var/backups/wspanel/sites/<doména>/` a obsahuje:

- aktuální release (kód tak, jak právě běží),
- složku `shared` (`.env`, nahrané soubory, `storage`),
- dumpy databází webu (MariaDB i PostgreSQL),
- `manifest.json` s tím, co v archivu je.

Soubory čte agent jako systémový uživatel webu, root je neotevírá.

- **Zálohovat nyní**, s databázemi nebo bez nich. Velký web doběhne na pozadí
  a v seznamu se objeví sám.
- **Plán záloh:** denně nebo týdně (kolem 2:30), kolik posledních záloh
  uchovat a zda s databázemi. Starší zálohy se po každé automatické záloze
  smažou.
- **Stáhnout** archiv (běžný `tar.gz`, jde rozbalit i ručně).
- **Obnovit:** zvolte soubory, databáze nebo obojí a pro potvrzení napište
  doménu. Obnova nic nezničí:
  - nejdřív se uloží záloha současného stavu (*Před obnovou*);
  - soubory se obnoví jako **nový release** a web se na něj přepne;
    původní release zůstává a vrátíte se na něj přes Deploy → Rollback;
  - současná složka `shared` zůstane vedle jako
    `shared.before-restore-<čas>` (smažete ji ve Správci souborů, až ji
    nebudete potřebovat);
  - databáze se přepíšou obsahem zálohy. Databáze, které už na serveru
    nejsou, se vynechají.

Zálohy zůstávají i po smazání webu, maže se jen jejich plán. Ukládají se na
stejný disk jako web; proti ztrátě serveru nastavte [zálohy mimo
server](#zálohy-mimo-server).

### Zálohy mimo server

**Server → Zálohy mimo server** nastaví úložiště mimo server. Pak se tam každá
nová záloha webu i databáze, ruční i plánovaná, hned nahraje.

Typy úložiště:

- **S3**: Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO, Hetzner
  Object Storage…
- **FTP / FTPS**: šifrování explicitním TLS (port 21) nebo implicitním TLS
  (port 990). Nešifrované FTP jde zvolit jen výslovně, pro důvěryhodnou
  vnitřní síť. NAS s vlastním certifikátem: při testu panel ukáže otisk
  certifikátu (SHA-256). Když ho ověříte, vložte ho do pole *Otisk
  certifikátu* a panel bude věřit právě tomuto certifikátu.
- **SMB**: sdílená složka NASu, Windows nebo Hetzner Storage Boxu. Šifrování
  SMB3 je ve výchozím stavu vyžadované. Klienta `smbclient` (Samba) agent
  doinstaluje sám.

Jak kopie fungují:

- leží v `<složka>/sites/<doména>/…` a `<složka>/db/<engine>/<databáze>/…`;
- kopie starší než nastavený počet dní se mažou, nejnovější zůstává vždy;
- zálohy pořízené před obnovou se nenahrávají.

Při uložení panel do úložiště zkusí zapsat, přečíst a smazat zkušební soubor;
uloží se jen funkční nastavení. Hesla a klíče zná jen agent (root, 0600), panel
je zpět nedostane a do auditu se nezapisují. Uložené heslo se znovu použije,
jen když se nemění server, port, účet, šifrování, otisk ani sdílená složka;
jinak ho zadejte znovu.

**Podpis záloh.** Ke každé kopii se ukládá podpis `<soubor>.sig` (HMAC-SHA256
klíčem, který zná jen tento server). Podpis zahrnuje i web či databázi a
název zálohy, takže zálohu jednoho webu nejde vydávat za zálohu jiného. Zpět na server se stáhne jen kopie
s platným podpisem. Kdo má přístup k úložišti, tedy nemůže podstrčit
pozměněnou zálohu, která by se pak obnovila. Tlačítkem **Klíč pro obnovu**
si klíč zobrazíte; uložte si ho mimo server (správce hesel). Na novém
serveru ho vložte do nastavení úložiště a staré zálohy půjde ověřit. Kopii bez
platného podpisu stáhnete jen výslovně (*Přijmout i kopii bez platného
podpisu*).

Obnova webu sahá jen na databáze, které k webu patří v panelu, ať archiv
obsahuje cokoli.

Na záložce **Zálohy** u webu:

- sloupec *Mimo server* ukazuje, co tam je;
- starší zálohu nahrajete tlačítkem **Nahrát mimo server**;
- zálohu, která už na serveru není (*Jen mimo server*), stáhnete akcí
  **Stáhnout na server** a obnovíte ji jako každou jinou.

Když nahrání selže, místní záloha zůstává a plánovaná záloha skončí chybou,
aby to bylo vidět.

Zálohy obsahují `.env` a data webů. Použijte proto úložiště nebo účet jen pro
zálohy, ideálně se šifrováním a verzováním na straně úložiště.

### Záloha serveru a obnova po havárii

Každou noc (a kdykoli tlačítkem **Zálohovat server teď** na stránce *Zálohy
mimo server*) vznikne **záloha serveru**:

- stav panelu (jeho databáze a `.env` s klíčem aplikace) a agenta,
- konfigurace, kterou WSPanel vytvořil: služby a FPM pooly webů, bloky proxy,
  SSH klíče, pošta (domény, schránky, aliasy), DKIM klíče, certifikáty
  a vlastní certifikační autorita, DNS zóny (PowerDNS),
- uživatelé webů a skupiny klientů (s jejich ID) a účty databází (uživatelé
  MariaDB, role PostgreSQL),
- volby instalace.

Je **zašifrovaná** (AES-256-GCM) klíčem pro obnovu a leží
v `/var/backups/wspanel/server` (posledních 7). Vedle ní vznikne záloha pošty
každé domény (`/var/backups/wspanel/mail/<doména>`, poslední 3). S úložištěm
mimo server se obojí nahraje i tam (`server/`, `mail/<doména>/`), podepsané
jako ostatní zálohy. Data webů a databáze jsou v jejich vlastních zálohách:
pro úplnou obnovu nastavte u webů plánované zálohy (s databázemi).

**Obnova na novém serveru** (čistý Debian 13, stejná doména panelu): stáhněte
z úložiště nejnovější `server/server-….wspbak` a spusťte:

```sh
curl -fsSL https://github.com/ONyklicek/wspanel/releases/latest/download/get.sh \
  | sudo bash -s -- --restore=/root/server-20261109T021800Z.wspbak
```

(Z rozbaleného vydání: `sudo ./install --restore=…`, přes apt
`sudo wspanel-install --restore=…`.) Instalátor se zeptá na klíč pro obnovu
(nebo ho vezme z `WSPANEL_RECOVERY_KEY`) a pak:

1. nainstaluje WSPanel se stejnými volbami jako starý server,
2. obnoví ze zálohy serveru uživatele, konfiguraci, panel, DNS a účty databází,
3. z úložiště mimo server stáhne nejnovější zálohu každého webu (soubory i jeho
   databáze), každé další databáze a pošty každé domény (jen s platným
   podpisem) a obnoví je.

Bez úložiště mimo server přeneste celý `/var/backups/wspanel` starého serveru
(třeba z kopie disku) a přidejte `--backups=<složka>`. Průběh a co se obnovit
nepovedlo je v `/var/log/wspanel-restore.log`. Pak přepněte DNS (A/AAAA) na
nový server; certifikáty Let's Encrypt si server obnoví sám.

Záloha se obnovuje jen na **prázdný** server a instalátorem stejné nebo novější
verze. Klíč pro obnovu (*Zálohy mimo server → Klíč pro obnovu*) si uložte mimo
server: bez něj zálohu serveru otevřít nejde.

## Domény a přístup

Detail webu → **Nastavení → Domény a přístup**. Vše zpracuje proxy ještě před
aplikací, takže to funguje pro každý typ webu.

- **Další domény webu:** web odpovídá i na nich (stejný obsah), každá dostane
  vlastní certifikát. Musí mířit na server.
- **Přesměrované domény:** např. `www.example.com` nebo stará doména. Odpovídají
  trvalým přesměrováním (308) na hlavní doménu i s cestou a parametry.
- **Přesměrování cest:** `/stara-stranka` → `/nova-stranka` nebo na jinou adresu,
  `/blog/*` přesměruje celou sekci. Typ 301/302/307/308.
- **Heslo:** na celý web, nebo jen část (`/admin`). Panel si heslo nepamatuje;
  na serveru je jen jeho bcrypt hash. Při další úpravě nechte pole prázdné a
  heslo zůstane.
- **Povolené IP adresy:** jen tyto adresy či sítě otevřou web (nebo zvolenou
  cestu, např. `/wp-admin`), ostatní dostanou 403.
- **Hlavičky:** HSTS, sada bezpečnostních hlaviček a vlastní hlavičky
  (např. `X-Robots-Tag: noindex` pro staging).

Konfiguraci před použitím ověří Caddy; neplatná se nepoužije a zůstane
předchozí stav.

## PHP nastavení webu

Detail webu → **Nastavení → Běh a PHP → PHP nastavení**. Prázdné pole = výchozí hodnota PHP.

| Nastavení | Příklad | Poznámka |
|---|---|---|
| `memory_limit` | `512M` | 16M–8G, `-1` = bez limitu |
| `upload_max_filesize`, `post_max_size` | `64M` | velikost nahrávaných souborů; `post_max_size` nastavte aspoň stejně |
| `max_execution_time`, `max_input_time` | `120` | sekundy |
| `max_input_vars` | `5000` | velké formuláře (WooCommerce, Shopware…) |
| `date.timezone` | `Europe/Prague` | výběr ze seznamu |
| `display_errors`, `error_reporting` | | na produkci nechte chyby skryté, jsou v logu webu |
| `session.gc_maxlifetime`, `allow_url_fopen`, `short_open_tag` | | |

U **worker** webů (FrankenPHP) navíc **počet workerů** (výchozí podle počtu CPU)
a **recyklace workeru po N požadavcích** (výchozí 500, chrání před únikem paměti).

- U klasického webu se hodnoty zapíšou do jeho PHP-FPM poolu jako `php_value`,
  takže aplikace je může dál měnit přes `ini_set()`. Před načtením panel
  konfiguraci ověří (`php-fpm -t`); neplatná se nepoužije.
- U worker webu se zapíšou do `php_ini` jeho instance FrankenPHP a aplikace se
  restartuje.
- Funkce jako `exec()` nebo `proc_open()` zůstávají z bezpečnostních důvodů
  zakázané (viz [Bezpečnost](bezpecnost.md)).

## PHP verze a rozšíření

**Server → PHP** ukazuje nainstalované verze PHP-FPM, které weby je používají,
a jejich rozšíření.

- **Přidat / odebrat rozšíření:** balíčky `php8.x-…` z repozitáře
  packages.sury.org (imagick, swoole, mongodb, xdebug…). Po změně se PHP-FPM
  plynule znovu načte. Odebrané rozšíření přestane fungovat všem webům na té verzi.
- **Doinstalovat verzi PHP** (8.1–8.5) se stejnou sadou rozšíření jako při
  instalaci serveru. Web na ni přepnete v Nastavení → Běh a PHP → Verze.
- **FrankenPHP** (worker režim) je jedna binárka s pevnou sadou rozšíření;
  stránka je vypíše. Novou verzi FrankenPHP nainstalujete v Server → Služby.

## Čisté PHP (WordPress, vlastní skripty)

Pro weby bez frameworku zvolte typ **Čisté PHP**. Deploy z Gitu nepotřebujete:

1. Na detailu webu: **Nastavení → Přístup → SSH / SFTP**, přístup **Jen SFTP**. Vložte veřejný
   klíč, nebo zvolte **Vygenerovat heslo**.
2. Připojte se SFTP klientem (FileZilla, Cyberduck, WinSCP, `sftp`):
   - server: doména panelu nebo IP serveru, port 22,
   - uživatel: `web_<doména>`,
   - heslo nebo klíč.
3. Nahrajte web do složky **`public_html/`**. Úvodní `index.php` přepište.
4. Databáze: **Databáze → Nová databáze**, vyberte web. Přístupové údaje
   zapíše panel do `~/shared/.env` (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`,
   `DB_PASSWORD`). Soubor si stáhněte přes SFTP a údaje dejte do konfigurace
   aplikace (u WordPressu do `wp-config.php`).

Příklad: WordPress
- stáhněte a rozbalte WordPress,
- obsah složky `wordpress/` nahrajte do `public_html/`,
- vytvořte databázi pro web a údaje z `shared/.env` vepište do `wp-config.php`,
- otevřete web a dokončete instalaci.

> PHP běží s `open_basedir` omezeným na adresář webu a bez funkcí pro spouštění
> příkazů (`exec`, `shell_exec`, `proc_open`…). Pluginy, které je vyžadují,
> nebudou fungovat.

## Struktura adresáře

```
/srv/sites/example.com/
  current -> releases/<release>   # běžící verze (deploy ji přepíná atomicky)
  public_html -> current/public   # jen u Čistého PHP: sem nahrávejte přes SFTP
  releases/
    initial/public/index.php      # úvodní stránka do prvního deploye / nahrání
    20261107T140000Z/              # releasy z deployů
  shared/
    .env                          # Laravel, Čisté PHP, Node, Python (Symfony: .env.local)
    storage/                      # Laravel: nahrané soubory, cache, logy
    var/log/                      # Symfony: logy
  logs/php-error.log              # PHP chyby
  tmp/                            # dočasné soubory, sessions
```

Kořen webu (document root) je vždy `current/public/` (u Node a Python webů `current/`).
Soubory mimo `public/` nejsou z webu přístupné. Proxy smí číst jen `public/`.

**Soubory můžete kdykoli upravit** přes SFTP/SSH, panel je nezamyká. Pozor:
s produkční OPcache se změny projeví až po restartu webu. Další deploy změny
v `current` přepíše novým releasem.

## Detail webu

Klik na web v seznamu otevře detail:
- **Web:** adresa, stav (Běží / Neodpovídá / Vypnutý), na čem běží, uživatel,
  cesty.
- **Přístup:** SFTP/SSH příkaz ke zkopírování, databáze, DNS, pošta, poslední
  deploy.
- **Procesy a workery:** živý stav ze systemd, viz níže.
- **Logy:** PHP chyby, log aplikace (`laravel.log`, `prod.log`), výstup
  procesu aplikace.

Akce v hlavičce:
- **Otevřít web**, **Přístupové údaje**, **Deploy**,
- **Další:** restartovat, ověřit odezvu, nová databáze, přidat worker
  a odkaz na Nastavení.

Záložka **Nastavení** ukazuje vše, co je u webu nastavené, po oblastech:
Domény a HTTPS, Přístup (SSH/SFTP, heslo, IP), Běh a PHP (verze, režim, PHP
nastavení), Pravidla webserveru, Výkon a ladění (Valkey, XHProf), Staging
a přesun, Vlastník a Nebezpečná zóna (vypnout, smazat). U každé oblasti je
tlačítko, které otevře její úpravu. Každá úprava má i vlastní adresu
(`…/settings?action=tls`), takže na ni jde poslat odkaz.

## Zapnutí, vypnutí, restart

- **Vypnout web:**
  - návštěvníci uvidí stránku „Web je dočasně vypnutý“ (HTTP 503),
  - zastaví se proces aplikace, workery i naplánované úlohy,
  - po restartu serveru se samy nespustí,
  - soubory, databáze ani nastavení se nemění,
  - deploy je u vypnutého webu zakázaný.
- **Zapnout web:** vše se vrátí do původního stavu. Workery se spustí, pokud
  je aplikace nasazená.
- **Restartovat:**
  - **classic:** PHP-FPM se plynule znovu načte. Rozpracované požadavky doběhnou
    a vyprázdní se OPcache, takže je to bez výpadku.
  - **worker, Node a Python:** restart procesu aplikace, pár sekund výpadek.
  - Volitelně se restartují i workery. Hodí se po změně `.env`.

## Prostředí (proměnné a tajné hodnoty)

Záložka **Prostředí** webu spravuje jeho `.env` (sdílený, `shared/.env`,
u Symfony `shared/.env.local`) jako GitHub své *variables* a *secrets*:

- **Proměnné** jsou vidět i s hodnotou, jdou upravit a smazat.
- **Tajné hodnoty** (hesla, API klíče) jdou jen zapsat: panel je nikdy
  nezobrazí ani neuloží u sebe, nezapíší se ani do auditu. Změníte je
  zadáním nové hodnoty.
- Proměnné, které spravuje panel sám (databáze, Valkey, pošta, workery),
  jsou jen vyjmenované a tady se nemění.
- Co nastavíte v Prostředí, **deploy nepřepíše**, ani když repozitář nebo
  CI přinese vlastní `.env`. Ostatní řádky souboru (komentáře, ručně
  přidané proměnné) zůstanou, jak jsou.
- Aplikace hodnoty uvidí po restartu nebo dalším deployi. Laravel s cache
  konfigurace (deploy ji dělá) potřebuje `php artisan config:cache`
  (záložka Konzole).

### Composer: soukromé balíčky (auth.json)

U PHP webů je v Prostředí i **přístup k soukromým repozitářům Composeru**:
HTTP Basic (Private Packagist, Satis: jméno a heslo nebo token), GitHub
token, GitLab token, Bearer token, vždy pro jednoho hostitele. Agent je
zapíše do `auth.json`, který Composer čte při deployi (`COMPOSER_HOME`
webu) i v SSH (`~/.config/composer/auth.json`), jako uživatel webu s právy
0600. Panel ukáže jen hostitele a jméno, hesla a tokeny už ne.

## Konzole a rychlé příkazy

Záložka **Konzole** spouští příkazy jako uživatel webu ve složce `current`
(aktuální release), ve stejném omezeném prostředí jako kroky deploye
(nástroje webu `php`, `composer`, `npm` na PATH, zapisovat smí jen do svého
webu), nejdéle 30 minut. Výstup se ukazuje průběžně, posledních 30 běhů
zůstane i s výstupem.

- **Rychlé příkazy** podle typu webu: `composer install --no-dev -o`,
  `composer dump-autoload -o`, `composer outdated`, `npm ci`,
  `npm run build`; u Laravelu `artisan migrate --force`, `optimize`,
  `optimize:clear`, `config:cache`, `queue:restart`, `storage:link`,
  `about`; u Symfony `cache:clear`, `doctrine:migrations:migrate`; u
  Djanga `migrate`, `collectstatic`, `check --deploy`. Migrace se před
  spuštěním potvrzují.
- **Vlastní příkaz** (příkazová řádka přes `bash -lc`): správce vždy,
  klient jen s balíčkem, který povoluje SSH se shellem (tentýž přístup by
  měl přes SSH).
- Interaktivní programy (čtou z klávesnice, třeba `tinker` bez
  `--execute`) tu nefungují: na ty je SSH.

## Procesy a workery

Workery jsou dlouho běžící procesy aplikace. Každý běží jako systemd služba pod
uživatelem webu, se sandboxem, a po pádu se sám restartuje.

| Framework | Worker | Příkaz |
|---|---|---|
| Laravel | Fronta | `artisan queue:work` |
| Laravel | Horizon | `artisan horizon` |
| Laravel | Plánovač | `artisan schedule:work` (náhrada cronu `schedule:run`) |
| Laravel | Reverb (WebSockets) | `artisan reverb:start`; proxy posílá `/app` a `/apps` na Reverb |
| Laravel | Pulse | `artisan pulse:check`, volitelně `pulse:work` |
| Laravel | Inertia SSR | `node bootstrap/ssr/ssr.js` (vykreslení stránek na serveru) |
| Symfony | Messenger | `messenger:consume async` |
| Symfony | Plánovač | `messenger:consume scheduler_default` |

- **Přidání:** detail webu → Další → **Přidat worker**. Před prvním deployem se
  worker jen zaregistruje a spustí se po nasazení.
- **Ovládání:** tabulka **Procesy a workery** na detailu webu (stav, jak dlouho
  běží, paměť, počet restartů) a akce **Spustit / Restartovat / Zastavit /
  Reset po chybě / Log**. Přehled workerů všech webů je v menu **Služby**.
- **Stav „padá a restartuje se“** znamená, že proces končí chybou. Otevřete
  jeho **Log**. Nejčastěji chybí `.env` hodnota nebo služba (Redis).
- Po deployi se workery restartují samy, aby běžely s novým kódem.
- Odebrání workeru: **Služby** → worker → **Odebrat worker**.

Reverb: do `.env` dejte `REVERB_HOST=<doména>`, `REVERB_PORT=443`,
`REVERB_SCHEME=https`. Port, na kterém Reverb poslouchá, přiděluje panel.

### Inertia SSR (Laravel s Vue, React nebo Svelte)

Worker **Inertia SSR** spustí SSR server Inertie vedle PHP webu. Laravel se
k němu při každém požadavku obrátí a stránka přijde vykreslená už ze serveru.

- **Port** přidělí panel z chráněného rozsahu, stejně jako u Reverbu. Žádný
  jiný web ho nemůže obsadit a podvrhnout vlastní HTML. Výchozí port Inertie
  (13714) by si weby na jednom serveru přetahovaly.
- Procesu předá `INERTIA_SSR_PORT` a do `.env` zapíše
  `INERTIA_SSR_URL=http://127.0.0.1:<port>`.
- **Deploy** po `npm run build` spustí i `npm run build:ssr` (skript ze
  starter kitů: `vite build && vite build --ssr`). Worker pak běží s bundlem
  `bootstrap/ssr/ssr.js` nového releasu.
- **V aplikaci** jsou potřeba dvě úpravy, aby port převzala od panelu. Panel
  po přidání workeru zkontroluje, jestli je máte, a napíše, co chybí:
  - `config/inertia.php`:
    ```php
    'ssr' => [
        'enabled' => true,
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
    ],
    ```
  - `resources/js/ssr.ts` (nebo `.tsx`):
    ```ts
    createServer((page) => createInertiaApp({ /* … */ }),
        Number(process.env.INERTIA_SSR_PORT) || 13714)
    ```
- Bundle se hledá jako `bootstrap/ssr/ssr.js` (výchozí název u projektů
  s `"type": "module"`, tedy všech starter kitů).

## Laravel: co panel nastaví sám

Panel nastavuje `.env` aplikace tak, aby se servery, které spouští, vždy
shodovaly s aplikací. Nic z toho není třeba psát ručně:

| Akce v panelu | Co se zapíše do `.env` |
|---|---|
| Nová databáze | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Valkey | `REDIS_CLIENT=phpredis`, `REDIS_HOST=127.0.0.1`, `REDIS_PORT`, `REDIS_PASSWORD`; podle volby `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` = `redis` (nezaškrtnuté = `database`) |
| Worker **Reverb** | `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID/KEY/SECRET` (vygenerované jednou, pak se drží), `REVERB_HOST=127.0.0.1` + port Reverbu (aplikace publikuje přímo na serveru), `VITE_REVERB_HOST=<doména>`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https` (prohlížeč přes HTTPS webu) |
| Worker **Horizon** | zapne Valkey webu, pokud běží jinak (nic nemaže, ukládá na disk), `QUEUE_CONNECTION=redis` |
| Worker **Pulse (Redis ingest)** | zapne Valkey, `PULSE_INGEST_DRIVER=redis` |
| Worker **Inertia SSR** | `INERTIA_SSR_URL=http://127.0.0.1:<port>`, `INERTIA_SSR_ENABLED=true` |
| Pošta | `MAIL_*` podle režimu (odesílá / zachytává v Mailpitu) |

- **Reverb za proxy:** prohlížeče se připojují na `wss://<doména>/app/…`, edge
  předá WebSocket Reverbu na `127.0.0.1:<port>` (TLS končí na edge). HTTP API
  Reverbu (`/apps`) zvenku dostupné není: aplikace na něj posílá události
  přímo na serveru. `VITE_REVERB_*` se do frontendu dostanou při
  `npm run build` v deployi.
- Valkey nejde vypnout, dokud web má Horizon nebo Pulse s Redis ingestem.
- Klíče, které panel nezná, nechává beze změny.

## Pravidla webserveru

**Nastavení → Pravidla webserveru**: vlastní pravidla webu v syntaxi Caddy (obdoba
„dalších direktiv nginx“ jinde). Platí v pořadí, jak jsou napsaná, za
přístupovými pravidly panelu (heslo, IP) a před aplikací.

```
encode gzip zstd
header /assets/* Cache-Control "public, max-age=31536000, immutable"
redir /stary-clanek /novy-clanek 301
@api path /api/*
header @api X-Robots-Tag "noindex"
request_body {
	max_size 64MB
}
```

- Povolené direktivy: `header`, `request_header`, `encode`, `rewrite`, `uri`,
  `redir`, `respond`, `error`, `abort`, `request_body`, `vars`, `map`, a bloky
  `handle`, `handle_path`, `route`; matchery `path`, `path_regexp`, `header`,
  `header_regexp`, `method`, `query`, `remote_ip`, `client_ip`, `protocol`,
  `not`, `host`.
- Nejde nic, čím by web sáhl jinam: `reverse_proxy`, soubory (`root`,
  `file_server`, `{file.*}`), proměnné prostředí, `import`, změna hlavičky
  `Host` nebo `X-Forwarded-*`.
- Před nasazením pravidla zkontroluje server. Chybná se nepoužijí a platí dál
  předchozí.
- **Převést z nginx nebo .htaccess:** vložte pravidla a stiskněte Převést.
  Převede se `return`, `rewrite … permanent/redirect`, `rewrite` (přepis),
  `add_header`, `expires`, `gzip`, `client_max_body_size`, `deny all`,
  `location` bloky; z `.htaccess` `Redirect`, `RewriteRule` (přesměrování,
  přepis, `[F]`), `Header set/append/unset`, `Require all denied`. Pravidla
  s `RewriteCond` a vše ostatní zůstane jako komentář k ruční úpravě; front
  controller (`… index.php`) není potřeba, panel ho řeší sám.

## Valkey (Redis)

Každý web si může zapnout **vlastní Valkey server** (plně kompatibilní
s Redisem): **Nastavení → Výkon a ladění → Valkey**.

- Běží pod uživatelem webu na `127.0.0.1` a vlastním portu (každý web má
  jiný) a chrání ho heslo. Port je dostupný jen ze serveru, ne z internetu;
  bez hesla webu se nepřipojí nikdo jiný a `FLUSHALL` v jednom webu nesmaže
  data jinému. Heslo zná jen web (je v jeho `.env`) a panel.
- Nastavíte paměť (MB), co dělat při zaplnění (cache maže staré klíče, fronty
  a data nic nemažou) a jestli se data ukládají na disk (`<web>/valkey/`).
- Připojení se zapíše do `.env`:
  - Laravel / Čisté PHP: `REDIS_CLIENT=phpredis`, `REDIS_HOST=127.0.0.1`,
    `REDIS_PORT`, `REDIS_PASSWORD`,
  - Symfony: `REDIS_URL=redis://:<heslo>@127.0.0.1:<port>`,
  - Node a Python: `REDIS_URL` a `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`.
  Ručně je najdete v **Přístupové údaje** webu.
- Detail webu ukazuje obsazenou paměť a počet klíčů, odtud jde Valkey
  vyprázdnit nebo restartovat.
- U klientů omezuje paměť balíček (**Valkey na web**, 0 = nepovoleno).

## Aplikace jedním klikem

**Weby → Nová aplikace** založí web, jeho databázi a aplikaci:

- **WordPress**: stáhne aktuální verzi z wordpress.org a ověří ji kontrolním
  součtem. Rozbalí ji pod uživatelem webu, `wp-config.php` dá o úroveň výš
  než docroot (návštěvník se k němu nedostane) a doplní přístup k databázi
  (MariaDB) a náhodné klíče. Zbytek (název, účet správce) dokončíte ve WordPressu.
  Aktualizuje se sám, soubory zůstávají upravitelné přes SFTP.
- **Laravel / Symfony**: nasadí oficiální kostru (`laravel/laravel`,
  `symfony/skeleton`) jako běžný deploy, s novým `APP_KEY` / `APP_SECRET`.
  Pak v Deploy → Nastavení zadejte svůj repozitář.

## Přesun webu z jiného hostingu

U čistého PHP webu (třeba WordPress z cPanelu):

1. Založte web a přes SFTP nahrajte do jeho složky `tmp/` zálohu z cPanelu
   (`cpmove-<účet>.tar.gz`, cPanel → Backup → Full Backup) nebo archiv
   souborů webu (`.tar.gz`, se složkou `public_html/` nebo `public/`, nebo
   rovnou obsah webu).
2. **Nastavení → Staging a přesun → Importovat web** a zadejte název souboru.

Soubory se rozbalí do webu pod jeho uživatelem. Každý dump databáze ze zálohy
(`mysql/*.sql`) dostane novou databázi webu (MariaDB) a nahraje se do ní.
Když je v záloze WordPress, jeho `wp-config.php` dostane přístup k nové
databázi. Poštu ze zálohy panel nepřenáší: schránky založte v Pošta →
Schránky a zprávy přeneste poštovním programem nebo `imapsync`.

## Síťové disky (NAS, FTP)

**Nastavení → Síťové disky → Připojit disk** (jen správce). K webu jde připojit:

- **SMB**: sdílenou složku Windows, NAS (Synology, QNAP) nebo Samby, verze SMB 1 až 3.1.1,
- **FTP / FTPS**: FTP server, s explicitním (AUTH TLS) nebo implicitním šifrováním,
- **SFTP**: server přes SSH.

Disk se připojí do `/srv/sites/<doména>/mounts/<název>`. Soubory na něm patří
uživateli webu: aplikace (PHP-FPM i FrankenPHP), workery, naplánované úlohy
i SFTP webu je čtou a zapisují jako vlastní. Jiné weby na server do disku
nevidí. V Laravelu stačí přidat disk do `config/filesystems.php` s `root` na tuto
cestu.

- Heslo zná jen server (v souboru, který čte jen root), panel ho neukládá.
- Disk se připojí znovu i po restartu serveru. Když nejde připojit, panel ukáže
  proč (špatné heslo, nedostupný server, neexistující sdílená složka).
- Když disk připojený není, zápis do jeho složky **selže**. Nic se tiše neuloží
  na lokální disk pod ním.
- FTP a SFTP: zapisovaný soubor se nahraje na server po zavření (rclone
  s místní cache). Velké soubory tedy chvíli trvají.
- Disk **není součástí záloh webu** ani se nepočítá do místa na disku webu.
- Při smazání webu se disk odpojí; soubory na NAS zůstanou.

## Staging

**Nastavení → Staging a přesun → Vytvořit staging** udělá kopii webu na `staging.<doména>`:

- aktuální verzi aplikace (nasazenou jako běžný deploy) a sdílené soubory
  (nahrané soubory, storage) bez `.env`, protože staging má vlastní nastavení,
- kopii každé databáze webu (`stg_<název>`, přístup v `.env` stagingu),
- pošta stagingu se **vždy zachytává v Mailpitu**, takže test nikdy nenapíše
  skutečným zákazníkům,
- hlavičku `X-Robots-Tag: noindex`, aby ho vyhledávače neindexovaly. Heslo
  přidáte v Doménách a přístupu.

Staging potřebuje DNS záznam pro `staging.<doména>`.

**Nasadit staging na produkci** (v menu stagingu): nejdřív se zazálohuje
produkce (soubory i databáze, záloha je pak v Zálohách). Potom se nasadí:

- **soubory** jako běžný deploy: když nová verze neodpoví, vrátí se předchozí,
- **databáze**, jen když ji zaškrtnete: přepíše data produkce.

## Návštěvnost a dostupnost

Záložka **Návštěvnost** u webu ukazuje za 24 hodin, 7 nebo 30 dní:

- požadavky v čase, přenesená data, chyby 5xx, průměrnou odezvu a podíl robotů,
- nejčastější stránky (bez query stringu), odkud lidé přišli (cizí weby)
  a nejaktivnější IP adresy,
- dostupnost v %: kontrola webu každou minutu, rovnou na backend.

Edge zapisuje u každého webu JSON access log
(`/var/log/panel-proxy/access/<doména>.log`, rotuje po 20 MiB, drží 3 soubory).
Panel ho každých 5 minut sečte po hodinách a nic dalšího z něj neukládá.
Návštěvnost se drží 90 dní, kontroly dostupnosti 30 dní.

## Profilování (XHProf + XHGui)

Volitelný modul (`install.sh --xhprof=yes` nebo **Server → Moduly**). U webu
v režimu PHP-FPM ho zapnete v **Nastavení → Výkon a ladění → XHProf** a zvolíte, kolik procent
požadavků měřit (na produkci jen část, měření požadavek zpomalí).
Odinstalovat ho jde tamtéž (**Odinstalovat XHProf**), když ho žádný web nemá
zapnutý.

- Rozšíření XHProf se načte jen do poolu webů, které profilování mají zapnuté,
  jinde PHP neovlivní.
- Profily zůstávají v adresáři webu (`xhgui/`), zapisuje je uživatel webu.
  Proměnné prostředí (hesla v `.env`) se do profilů neukládají.
- **Otevřít XHGui** (Nastavení → Výkon a ladění, přehled webu nebo menu Další) otevře `https://<doména>/_wsp_xhgui/` přes
  jednorázový odkaz z panelu (platí 2 minuty, jednou). Přihlášení pak drží
  cookie jen pro tuto doménu a cestu, 8 hodin. Bez odkazu edge XHGui nepustí.
- XHGui běží na doméně webu, ne panelu, v poolu webu pod jeho uživatelem
  a čte jen jeho profily. Na doménu panelu se tak nedostane nic, co web
  ovládá, a cookie panelu se k webu nedostane.
- V režimu FrankenPHP worker profilování nejde (aplikace zůstává v paměti
  a měřil by se jen její start).

## Změna režimu a verze

- **Změnit režim** (např. classic → worker): nový backend naběhne vedle
  starého a proxy se přepne, až je připravený. Když něco selže, web běží dál
  jako dřív.
- **Změnit verzi PHP** (classic): nový FPM pool vedle starého, bez výpadku.
- **Změnit verzi Node:** restart procesu.
- **Změnit verzi Pythonu:** platí od příštího deploye, protože virtualenv
  releasu je postavený pro konkrétní verzi.

## Python weby

Python není v základní instalaci. Doinstalujete ho v **Server → Moduly →
Nainstalovat Python** (nebo při instalaci `install.sh --python=3.13`). Agent
stáhne [uv](https://docs.astral.sh/uv/) (správce Pythonu od Astralu, ověřený
otiskem sha256) a zvolenou verzi CPythonu do `/opt/uv/python`. Další verze
přidáte stejným tlačítkem; nabízí se 3.12, 3.13 a 3.14.

- **Spuštění:** Django a Flask běží pod gunicornem, FastAPI pod uvicornem,
  vždy z virtualenvu releasu (`current/.venv`), na portu, který přidělí agent.
  Typ **Python** spouští `app.py`, který poslouchá na `$HOST:$PORT` sám.
- **Aplikace (modul:objekt):** co server načte. Prázdné = zvyklost frameworku:
  u Djanga se projekt zjistí z `manage.py` při prvním deployi
  (`mysite.wsgi:application`), Flask `app:app`, FastAPI `main:app`. Změníte
  v Nastavení → **Aplikace Pythonu**.
- **Deploy:** každý release dostane vlastní `.venv`, závislosti instaluje uv
  pod uživatelem webu:
  - `uv.lock`: `uv sync --locked --no-dev`,
  - jinak `pyproject.toml`: `uv sync --no-dev`,
  - jinak `requirements.txt`: `uv pip install -r requirements.txt`.

  Chybí-li v závislostech gunicorn (uvicorn), doinstaluje se. Django pak
  spustí `manage.py migrate` (volba Migrace) a `collectstatic` (volba
  Statické soubory; potřebuje `STATIC_ROOT`, statiku servíruje aplikace, např.
  WhiteNoise). U ostatních se s volbou Migrace spustí `alembic upgrade head`,
  má-li repozitář `alembic.ini`. Přepnutí je bez výpadku: nový release naběhne
  ve druhém slotu a proxy se přepne, až odpovídá.
- **Konfigurace:** `shared/.env` se nalinkuje do releasu jako `.env`. Python ho
  sám nečte: použijte `python-dotenv` nebo `django-environ`.
- **Úlohy:** u Djanga `python manage.py …`, jinak Python skript webu (cesta
  relativně k releasu); běží v `.venv` releasu.
- **SSH:** příkaz `python` je Python z `.venv` běžícího releasu
  (`python manage.py shell`).
- **Celery:** Další → **Přidat worker** → *Celery* (úlohy z fronty) a *Celery
  beat* (plánované úlohy). Běží z `.venv` releasu, jako workery PHP webů: po
  deployi starý worker dokončí rozdělané úlohy se starým kódem a nové bere už
  nový. Beat běží vždy jen jeden a jeho stav (`tmp/celerybeat-schedule`) deploy
  nepřepíše. Aplikace Celery (`-A`) platí pro všechny Celery workery webu:
  u Djanga je to balíček projektu (jeho `celery.py`), u ostatních ji zadáte
  (např. `tasks` nebo `tasks:celery`). Broker (Valkey webu nebo jiný) si
  nastavíte v aplikaci.
- **Aktualizace Pythonu:** Server → Moduly → **Aktualizovat Python** stáhne
  nejnovější opravné vydání (např. 3.13.5 → 3.13.18). Virtualenvy zůstanou,
  protože stojí na odkazu verze (`cpython-3.13-…`), ne na konkrétním vydání.
  Weby na té verzi se restartují na nový interpret a staré sestavení se smaže.
- **Odinstalace verze:** Server → Moduly → **Odinstalovat verzi Pythonu**,
  když na ní neběží žádný web. S poslední verzí odejde i uv.

## Smazání webu

Detail → Nastavení → Nebezpečná zóna → **Smazat web**, pro potvrzení napište doménu. Odstraní se:
- konfigurace a procesy,
- workery a úlohy,
- systémový uživatel a SMTP přihlášení.

Soubory se nesmažou, přesunou se do `/srv/sites/.trash/` (jen pro roota).
Databáze zůstávají, smažte je zvlášť. Zálohy webu také zůstávají, smaže se
jen jejich plán.
