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
   - **Node SSR:** Nuxt, Next.js nebo vlastní `server.js` jako stále běžící proces.
4. **Typ aplikace:** Laravel, Symfony, Čisté PHP, Nuxt, Next.js nebo Node.
   Podle typu panel připraví adresáře, `.env`, workery a postup deploye.
5. Po uložení se otevře **detail webu**. Web už odpovídá úvodní stránkou
   „Web běží“, která popisuje, jak pokračovat.

Co se při založení stane:
- vytvoří se systémový uživatel `web_<doména>` bez přihlášení,
- vznikne `/srv/sites/<doména>` (struktura níže),
- PHP-FPM pool, nebo proces aplikace, pod tímto uživatelem,
- edge proxy začne pro doménu vydávat certifikát (Let's Encrypt, nebo z interní CA),
- vytvoří se SMTP přihlášení pro odesílání pošty webem.

## HTTPS a certifikáty

Každý web běží jen přes HTTPS. Certifikát se mění na detailu webu: **Další →
HTTPS / certifikát**.

| Volba | Pro koho | Co je potřeba |
|---|---|---|
| **Let's Encrypt** | veřejné domény | doména míří na server a port 80/443 je z internetu dostupný; obnovuje se sám |
| **Interní certifikační autorita** | vnitřní síť, intranet, testy (`intranet.firma.lan`) | počítače, které web otevírají, jednou nainstalují kořenový certifikát serveru |
| **Vlastní certifikát** | firemní CA, zakoupený certifikát | vložíte certifikát (PEM, i s mezilehlými) a soukromý klíč; panel ověří, že k sobě patří a platí pro doménu |

**Kořenový certifikát interní CA** stáhnete v **Síť a pošta → SSL →
Kořenový certifikát**. Instalace:
- **Windows:** dvojklik → Nainstalovat certifikát → Místní počítač → Důvěryhodné
  kořenové certifikační autority (nebo rozeslat přes GPO),
- **macOS:** dvojklik → Klíčenka *Systém* → u certifikátu „Vždy důvěřovat“,
- **Linux:** `/usr/local/share/ca-certificates/` + `update-ca-certificates`,
- **Firefox** má vlastní úložiště: Nastavení → Certifikáty → Importovat.

Soukromý klíč vlastního certifikátu je jen na serveru (čitelný pro proxy) a do
auditu se nezapisuje. Vlastní certifikát hlídá panel na stránce SSL; obnovit ho
musíte sami (nahrát nový).

## Domény a přístup

Detail webu → **Další → Domény a přístup**. Vše zpracuje proxy ještě před
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

Detail webu → **Další → PHP nastavení**. Prázdné pole = výchozí hodnota PHP.

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
  instalaci serveru. Web na ni přepnete v Další → Změnit verzi.
- **FrankenPHP** (worker režim) je jedna binárka s pevnou sadou rozšíření;
  stránka je vypíše. Novou verzi FrankenPHP nainstalujete v Server → Služby.

## Čisté PHP (WordPress, vlastní skripty)

Pro weby bez frameworku zvolte typ **Čisté PHP**. Deploy z Gitu nepotřebujete:

1. Na detailu webu: **Další → SSH / SFTP**, přístup **Jen SFTP**. Vložte veřejný
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
    .env                          # Laravel, Čisté PHP, Node (Symfony: .env.local)
    storage/                      # Laravel: nahrané soubory, cache, logy
    var/log/                      # Symfony: logy
  logs/php-error.log              # PHP chyby
  tmp/                            # dočasné soubory, sessions
```

Kořen webu (document root) je vždy `current/public/` (u Node webů `current/`).
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
- **Otevřít web**,
- **Deploy**,
- **Restartovat**,
- **Další:** vypnout web, změnit režim nebo verzi, ověřit odezvu, databáze,
  SSH/SFTP, přidat worker, smazat.

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
  - **worker a Node:** restart procesu aplikace, pár sekund výpadek.
  - Volitelně se restartují i workery. Hodí se po změně `.env`.

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

## Změna režimu a verze

- **Změnit režim** (např. classic → worker): nový backend naběhne vedle
  starého a proxy se přepne, až je připravený. Když něco selže, web běží dál
  jako dřív.
- **Změnit verzi PHP** (classic): nový FPM pool vedle starého, bez výpadku.
- **Změnit verzi Node:** restart procesu.

## Smazání webu

Detail → Další → **Smazat**, pro potvrzení napište doménu. Odstraní se:
- konfigurace a procesy,
- workery a úlohy,
- systémový uživatel a SMTP přihlášení.

Soubory se nesmažou, přesunou se do `/srv/sites/.trash/` (jen pro roota).
Databáze zůstávají, smažte je zvlášť.
