# Weby

## Založení webu

**Weby → Nový web**

1. **Doména:** např. `example.com`. Musí mířit na server (A/AAAA záznam), jinak
   se nevydá certifikát. Web na `www.` je samostatná doména.
2. **Režim:**
   - **Classic (PHP-FPM):** pro většinu webů. Lze zvolit verzi PHP.
   - **Worker (FrankenPHP):** aplikace zůstává v paměti mezi požadavky (Laravel
     Octane, Symfony 7.4+ s Runtime). Rychlejší, ale kód musí být „worker-safe“.
   - **Node SSR:** Nuxt, Next.js nebo vlastní `server.js` jako stále běžící proces.
3. **Typ aplikace:** Laravel, Symfony, Čisté PHP, Nuxt, Next.js nebo Node.
   Podle typu panel připraví adresáře, `.env`, workery a postup deploye.
4. Po uložení se otevře **detail webu**. Web už odpovídá úvodní stránkou
   „Web běží“, která popisuje, jak pokračovat.

Co se při založení stane:
- vytvoří se systémový uživatel `web_<doména>` bez přihlášení,
- vznikne `/srv/sites/<doména>` (struktura níže),
- PHP-FPM pool, nebo proces aplikace, pod tímto uživatelem,
- edge proxy začne pro doménu vydávat certifikát (Let's Encrypt),
- vytvoří se SMTP přihlášení pro odesílání pošty webem.

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
