<?php

namespace App\Support;

/**
 * Czech wording for what the agent reports in English.
 *
 * The agent speaks English on purpose: its audit log and the server's own logs
 * stay greppable and searchable. What reaches the panel's screens goes through
 * here; a message nothing below recognises is shown as it came, so a new agent
 * error is never swallowed.
 */
final class AgentMessages
{
    /** Deploy steps, as the agent names them in "<step> failed". */
    private const STEPS = [
        'Fetch source' => 'Stažení kódu',
        'Link shared files' => 'Sdílené soubory',
        'Composer' => 'Composer',
        'npm install' => 'Instalace npm balíčků',
        'npm run build' => 'Build assetů',
        'Migrations' => 'Migrace databáze',
        'Storage link' => 'Odkaz na storage',
        'Optimize' => 'Optimalizace',
        'Asset map' => 'Asset map',
        'Cache warmup' => 'Příprava cache',
        'Virtualenv' => 'Virtualenv',
        'Python dependencies (uv.lock)' => 'Závislosti Pythonu (uv.lock)',
        'Python dependencies (pyproject.toml)' => 'Závislosti Pythonu (pyproject.toml)',
        'Python dependencies (requirements.txt)' => 'Závislosti Pythonu (requirements.txt)',
        'Static files' => 'Statické soubory',
        'Migrations (Alembic)' => 'Migrace (Alembic)',
    ];

    public static function cs(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }
        // In English the agent's own words are the message.
        if (function_exists('app') && app()->bound('translator') && app()->getLocale() === 'en') {
            return trim($message);
        }
        $m = trim($message);

        foreach (self::rules() as $pattern => $replace) {
            if (preg_match($pattern, $m, $match) === 1) {
                // Only the recognised part: an English tail after it ("; change it…") is dropped.
                return is_callable($replace) ? $replace($match) : preg_replace($pattern, $replace, $match[0]);
            }
        }

        return $m;
    }

    /** @return array<string, string|callable(array<int, string>): string> */
    private static function rules(): array
    {
        $cs = fn (string $s) => self::cs($s);

        return [
            // Game servers
            '/^eula: .*$/' => 'Server Minecraftu se bez souhlasu s EULA nespustí.',
            '/^version: Minecraft before 1\.20\.5 .*$/' => 'Minecraft starší než 1.20.5 potřebuje Javu 17, kterou Debian 13 nemá.',
            '/^version: a world cannot go back to an older Minecraft$/' => 'Svět nejde vrátit na starší verzi Minecraftu.',
            '/^version: Minecraft (\S+) does not exist$/' => 'Minecraft $1 neexistuje.',
            '/^game: (.+) has no server for this processor \((\S+)\)$/' => 'Hra $1 nemá server pro tento procesor ($2).',
            '/^port (\d+) is used by game server (\d+)$/' => 'Port $1 už používá jiný herní server.',
            '/^port (\d+)\/(tcp|udp) is in use$/' => 'Port $1/$2 už na serveru něco používá.',
            '/^port: (\d+) is the panel\'s$/' => 'Port $1 používá panel, zvolte jiný.',
            '/^port: 1025 to 65535$/' => 'Port musí být 1025 až 65535.',
            '/^port: game servers use (\d+) to (\d+).*$/' => 'Herní servery používají porty $1 až $2 (mimo 27000–27999 pro RCON).',
            '/^game server (\d+) does not exist$/' => 'Herní server na serveru neexistuje.',
            '/^(\S+) already has a dedicated (\S+) server \(\d+\)$/' => 'Web $1 už vyhrazený server tohoto enginu má.',
            '/^(\S+) has a dedicated database server \((\S+)\): delete it first$/' => 'Web $1 má vyhrazený databázový server; smažte nejdřív ten (Databáze → Servery).',
            '/^the dedicated server still holds databases \((.+)\): drop them first$/' => 'Ve vyhrazeném serveru jsou ještě databáze ($1); smažte nejdřív je.',
            '/^dedicated database server (\d+) does not exist$/' => 'Vyhrazený databázový server neexistuje.',
            '/^(\S+) is on the site\'s dedicated server: the database tools cannot reach it$/' => 'Databáze $1 je na vyhrazeném serveru webu; nástroje (phpMyAdmin, Adminer) se k ní nedostanou.',
            '/^the dedicated server does not answer: .*$/' => 'Vyhrazený databázový server neodpovídá (podrobnosti v journalu jeho služby).',
            '/^memory_mb: 256 to 262144$/' => 'Paměť: 256 až 262144 MB.',
            '/^game server (\d+) already exists$/' => 'Herní server už na serveru existuje.',
            '/^installing Java (\d+) failed: .*$/s' => 'Instalace Javy $1 se nepodařila.',
            '/^downloading (.+) failed: .*checksum mismatch.*$/s' => 'Stažení $1 se nepodařilo: soubor neodpovídá otisku, který zveřejnil vydavatel. Nic se neinstalovalo.',
            '/^downloading (.+) failed: (.+)$/s' => 'Stažení $1 se nepodařilo: $2',
            '/^factorio (\S+): no published checksum$/' => 'Factorio $1 zatím nemá zveřejněný otisk, zkuste to později.',
            '/^the server does not answer on its console.*$/s' => 'Server na konzoli neodpovídá. Běží?',
            '/^command: one line.*$/' => 'Příkaz musí být jeden řádek, nejvýš 1000 znaků.',
            '/^values: (\S+) is the panel\'s$/' => 'Nastavení $1 spravuje panel (port, konzole RCON).',
            '/^values: (\S+) must be one line.*$/' => 'Nastavení $1 musí být jeden řádek, nejvýš 4096 znaků.',
            '/^name: a Minecraft player name.*$/' => 'Jméno hráče: 3 až 16 písmen, číslic nebo _.',
            '/^name: an IP address$/' => 'Zadejte IP adresu.',
            '/^id: a Minecraft server$/' => 'Jen u serveru Minecraftu.',
            '/^id: plugins need a Paper server.*$/' => 'Pluginy načítá jen Paper, Vanilla žádné.',
            '/^id: a Factorio server$/' => 'Jen u serveru Factoria.',
            '/^vars: (\S+) is the panel\'s.*$/' => 'Proměnnou $1 spravuje panel (databáze, Valkey, pošta, workery).',
            '/^vars: (\S+) must be one line.*$/' => 'Hodnota $1 musí být jeden řádek, nejvýš 8192 znaků.',
            '/^vars: the env file would exceed 64 KiB$/' => 'Soubor .env by byl větší než 64 KiB.',
            '/^auth\.json is not valid JSON$/' => 'Soubor auth.json webu není platný JSON (opravte ho v Souborech).',
            '/^command: not a quick command of this site$/' => 'Tento rychlý příkaz web nemá.',
            '/^version: Forge has no build for Minecraft (\S+)$/' => 'Forge pro Minecraft $1 nemá žádné sestavení.',
            '/^the (fabric|forge) installer failed: (.*)$/s' => fn ($m) => 'Instalátor '.ucfirst($m[1]).' selhal: '.$m[2],
            '/^source: Hangar has Paper plugins only.*$/' => 'Hangar má jen pluginy pro Paper, módy jsou na Modrinthu.',
            '/^mods need a factorio\.com account.*$/' => 'Módy stahuje mod portál jen s účtem factorio.com: zadejte jméno a token v Nastavení serveru.',
            '/^name: (\S+) has no version for Factorio (\S+)$/' => 'Mód $1 nemá verzi pro Factorio $2.',
            '/^name: (\S+) has no file the panel can check$/' => 'Mód $1 nemá soubor s otiskem, panel ho neinstaluje.',
            '/^the mod portal: (.+)$/s' => 'Mod portál neodpovídá: $1',
            '/^values: (\S+) must be a whole number.*$/' => 'Nastavení $1 musí být celé číslo 0 až 1000000.',
            '/^server-settings\.json is not valid JSON.*$/s' => 'Soubor server-settings.json není platný JSON (opravte ho v Souborech).',
            '/^project: (.+) has no version for Minecraft (\S+) on Paper$/' => 'Plugin $1 nemá verzi pro Minecraft $2 (Paper).',
            '/^project: (.+) is downloaded from outside Hangar.*$/' => 'Plugin $1 se stahuje mimo Hangar a bez otisku, panel ho neinstaluje. Nahrát ho můžete ručně v Souborech.',
            '/^project: (.+) comes from (\S+), not the catalogue.*$/' => 'Plugin $1 se stahuje z $2, ne z úložiště katalogu: panel ho neinstaluje.',
            '/^downloading (.+) failed: .*checksum mismatch.*$/s' => 'Stažení $1 se nepodařilo: soubor neodpovídá otisku z katalogu. Nic se neinstalovalo.',
            '/^(Modrinth|Hangar): (.+)$/s' => 'Katalog $1 neodpovídá: $2',
            '/^memory_mb: (\d+) to 262144$/' => 'Paměť musí být $1 až 262144 MB.',

            // Server → Systém, Procesy, Balíčky
            '/^pid: the process has already ended$/' => 'Proces už neběží.',
            '/^pid: this process cannot be ended from the panel$/' => 'Tento proces z panelu ukončit nejde.',
            '/^pid: a kernel thread$/' => 'Vlákno jádra ukončit nejde.',
            '/^pid: the agent or a task it runs.*$/' => 'Proces patří agentovi panelu nebo úloze, kterou právě provádí. Úlohu ukončete v panelu.',
            '/^name: the server or the panel needs (\S+) \(it would be removed too\)$/' => 'Balíček $1 potřebuje server nebo panel a odebral by se také.',
            '/^name: the server or the panel needs (\S+)$/' => 'Balíček $1 potřebuje server nebo panel, odebrat ho nejde.',
            '/^name: (\S+) cannot be (?:install|remov)ed \((.*)\)$/s' => 'Balíček $1 v repozitářích serveru není, nebo s ním apt nemůže pracovat.',
            '/^name: (\S+) is not installed$/' => 'Balíček $1 není nainstalovaný.',
            '/^size_mb: not enough free disk \(the swap file needs (\d+) MB.*$/' => 'Na disku není dost místa: swap potřebuje $1 MB a 1 GB musí zůstat volný.',
            '/^swap could not be switched off.*$/s' => 'Swap se nepodařilo vypnout, nejspíš by se jeho obsah nevešel do volné paměti.',
            '/^swap could not be switched on.*$/s' => 'Swap se nepodařilo zapnout (kontejner nebo souborový systém bez podpory swapových souborů).',
            '/^timezone: unknown time zone (\S+)$/' => 'Časové pásmo $1 neexistuje.',
            '/^hostname: invalid part .*$/' => 'Neplatný název serveru: jen malá písmena, číslice, pomlčky a tečky.',
            '/^path: not a directory$/' => 'Taková složka neexistuje.',
            '/^path: an absolute, clean path$/' => 'Zadejte celou cestu ke složce, např. /var/log.',

            '/^extension (\S+) is needed by (.+); remove those first$/' => 'Rozšíření $1 potřebuje $2. Odeberte nejdřív je (jinak by je apt odebral také).',

            '/^(horizon|pulsework) needs the site\'s Valkey.*$/' => 'Worker $1 potřebuje Valkey webu.',
            '/^worker (\S+) keeps its data in Valkey; remove it first$/' => 'Worker $1 má data ve Valkey: nejdřív ho odeberte, pak Valkey vypněte.',

            '/^the disk did not connect: (.+)$/s' => fn ($m) => 'Disk se nepřipojil: '.self::mountError($m[1]),
            '/^disk (\S+) already exists$/' => 'Disk $1 už existuje, zvolte jiný název složky.',
            '/^disk (\S+) does not exist$/' => 'Disk $1 neexistuje.',
            '/^a site has at most 10 disks$/' => 'Web může mít nejvýš 10 disků.',
            '/^installing (.+) failed: (.+)$/s' => 'Instalace $1 selhala: $2',
            '/^mail hosting is not installed.*$/' => 'Příjem pošty není nainstalovaný (Server → Moduly).',
            '/^domain (\S+) still has mailboxes$/' => 'Doména $1 má ještě schránky.',
            '/^mailbox (\S+) does not exist$/' => 'Schránka $1 neexistuje.',
            '/^mail domain (\S+) is not set up$/' => 'Doména $1 nemá na serveru poštu.',
            '/^(\S+) is an alias$/' => '$1 je alias, nemůže být i schránkou.',
            '/^(\S+) is a mailbox$/' => '$1 je schránka, nemůže být i aliasem.',

            // Site web-server rules (site.directives)
            '/^directives: the web server refused them$/' => 'Webserver pravidla odmítl, platí dál předchozí.',
            '/^directives: the web server could not read them$/' => 'Webserver pravidlům nerozumí, platí dál předchozí.',
            '/^directives: handler "([^"]+)" is not allowed$/' => 'Pravidla vedou na akci „$1“, kterou v pravidlech webu použít nejde.',
            '/^directives: matcher "([^"]+)" is not allowed$/' => 'Matcher „$1“ použít nejde.',
            '/^directives: request header (\S+) is set by the edge$/' => 'Hlavičku $1 nastavuje server sám, měnit ji nejde.',
            '/^directives: placeholder (\S+) is not allowed$/' => 'Zástupný symbol $1 (soubory, proměnné prostředí) použít nejde.',
            '/^directives: line (\d+): directive "([^"]+)" is not allowed.*$/' => 'Řádek $1: direktivu „$2“ v pravidlech webu použít nejde. Povolené: header, request_header, encode, rewrite, uri, redir, respond, error, abort, request_body, vars, map, handle, handle_path, route.',
            '/^directives: line (\d+): matcher "([^"]+)" is not allowed.*$/' => 'Řádek $1: matcher „$2“ použít nejde. Povolené: path, path_regexp, header, header_regexp, method, query, remote_ip, client_ip, protocol, not, host.',
            '/^directives: line (\d+): placeholder (\S+) is not allowed$/' => 'Řádek $1: zástupný symbol $2 (soubory, proměnné prostředí) použít nejde.',
            '/^directives: line (\d+): request header (\S+) is set by the edge$/' => 'Řádek $1: hlavičku $2 nastavuje server sám, měnit ji nejde.',
            '/^directives: line (\d+): matcher names starting with @wsp_ are the panel\'s$/' => 'Řádek $1: názvy matcherů začínající @wsp_ používá panel.',
            '/^directives: line (\d+): (.+)$/' => 'Řádek $1: $2',
            '/^directives: missing }$/' => 'Chybí uzavírací závorka }.',
            '/^directives: at most (\d+) KB$/' => 'Pravidla mohou mít nejvýš $1 KB.',

            // Backups of sites, off-site storage
            '/^the copy\'s signature does not match.*$/s' => 'Podpis kopie nesedí: zálohu nevytvořil tento server, nebo ji někdo v úložišti změnil. Nic se nestáhlo.',
            '/^connecting to the storage failed: (.+)$/s' => fn ($m) => 'Připojení k úložišti selhalo: '.$cs($m[1]),
            '/^the server\'s certificate is not trusted \((.*)\); its SHA-256 fingerprint is ([0-9A-F:]+).*$/s' => 'Certifikát serveru není důvěryhodný. Jeho otisk SHA-256 je $2 – pokud je to opravdu certifikát vašeho serveru, vložte otisk do pole „Otisk certifikátu“.',
            '/^the server does not offer TLS \(FTPS\).*$/s' => 'Server nenabízí šifrování (FTPS). Zapněte ho na serveru, nebo zvolte „Bez šifrování“, jde-li o důvěryhodnou vnitřní síť.',
            '/^FTP 530: .*$/s' => 'FTP server odmítl přihlášení: zkontrolujte uživatele a heslo.',
            '/^FTP 55[0-3]: (.*)$/s' => 'FTP server odmítl zápis nebo čtení (oprávnění či složka): $1',
            '/^FTP (\d+): (.*)$/s' => 'FTP server odpověděl chybou $1: $2',
            '/^NT_STATUS_LOGON_FAILURE.*$/' => 'SMB server odmítl přihlášení: zkontrolujte uživatele, heslo a doménu.',
            '/^NT_STATUS_BAD_NETWORK_NAME.*$/' => 'Sdílená složka na SMB serveru neexistuje.',
            '/^NT_STATUS_ACCESS_DENIED.*$/' => 'SMB server odepřel přístup (oprávnění ke sdílené složce).',
            '/^NT_STATUS_(CONNECTION_REFUSED|HOST_UNREACHABLE|IO_TIMEOUT|NETWORK_UNREACHABLE).*$/' => 'SMB server není dostupný (adresa, port 445, firewall).',
            '/^NT_STATUS_(NOT_SUPPORTED|INVALID_NETWORK_RESPONSE).*$/' => 'SMB server nepodporuje požadované šifrování SMB3; vypněte ho jen pro důvěryhodnou síť.',
            '/^(NT_STATUS_[A-Z_]+)$/' => 'SMB server odpověděl chybou $1.',
            '/^installing smbclient failed.*$/s' => 'Nepodařilo se nainstalovat smbclient (balíček Samba pro SMB).',
            '/^signing_key: .*$/' => 'Klíč pro ověření záloh musí mít 64 šestnáctkových znaků.',
            '/^tls_pin: .*$/' => 'Otisk certifikátu musí být SHA-256 (64 šestnáctkových znaků).',
            '/^the copy is larger than (\d+) GB.*$/' => 'Kopie je větší než $1 GB, nemůže to být záloha tohoto serveru.',
            '/^off-site storage is not set up$/' => 'Úložiště mimo server není nastavené (Server → Zálohy mimo server).',
            '/^the storage refused a test upload: (.+)$/s' => fn ($m) => 'Úložiště odmítlo zkušební nahrání: '.$cs($m[1]),
            '/^upload to (\S+) failed: (.+)$/s' => fn ($m) => 'Nahrání do úložiště '.$m[1].' selhalo: '.$cs($m[2]),
            '/^AccessDenied: .*$/s' => 'Přístup odepřen: zkontrolujte access key, secret key a oprávnění k bucketu.',
            '/^NoSuchBucket: .*$/s' => 'Bucket neexistuje (zkontrolujte název, region a adresování path-style).',
            '/^SignatureDoesNotMatch: .*$/s' => 'Podpis nesedí: zkontrolujte secret key a region.',
            '/^InvalidAccessKeyId: .*$/s' => 'Access key úložiště nezná.',
            '/^(\S+) is already on the server$/' => 'Záloha $1 už na serveru je.',
            '/^file: not a backup of this site or database$/' => 'Tohle není záloha tohoto webu ani databáze.',
            '/^the site holds more than (\d+) GB, too much for a backup$/' => 'Web má víc než $1 GB, na zálohu je to příliš.',
            '/^choose files, databases or both$/' => 'Zvolte soubory, databáze nebo obojí.',
            '/^the backup belongs to another site$/' => 'Záloha patří jinému webu.',
            '/^not a site backup.*$/' => 'Soubor není záloha webu.',
            '/^the backup holds no files$/' => 'Záloha neobsahuje soubory.',
            '/^backup before restore failed, nothing restored: (.+)$/s' => fn ($m) => 'Zálohu před obnovou se nepodařilo vytvořit, nic se neobnovilo: '.$cs($m[1]),
            '/^restore failed, nothing changed \(backup before restore: (\S+)\): (.+)$/s' => fn ($m) => 'Obnova selhala, nic se nezměnilo (záloha před obnovou: '.$m[1].'): '.$cs($m[2]),
            '/^restore of database (\S+) failed \(backup before restore: (\S+)\): (.+)$/s' => fn ($m) => 'Obnova databáze '.$m[1].' selhala (záloha před obnovou: '.$m[2].'): '.$cs($m[3]),
            '/^reading the site\'s files failed: (.+)$/s' => fn ($m) => 'Čtení souborů webu selhalo: '.$cs($m[1]),
            '/^endpoint: https is required.*$/' => 'Adresa úložiště musí být https (http jen na tomto serveru).',
            '/^download failed: (.+)$/s' => fn ($m) => 'Stažení selhalo: '.$cs($m[1]),

            // Deploy
            '/^the new release did not answer; switched back$/' => 'Nová verze neodpovídala, web běží dál na předchozí.',
            '/^(.+) failed$/' => fn ($m) => isset(self::STEPS[$m[1]])
                ? 'Krok „'.self::STEPS[$m[1]].'“ selhal. Podrobnosti jsou v logu deploye.'
                : 'Selhalo: '.$m[1].'.',
            '/^a deploy of (\S+) is (?:already )?running$/' => 'Deploy webu $1 už běží.',
            '/^deploy is not configured for (\S+)/' => 'Deploy webu $1 není nastavený (Deploy → Nastavení).',
            '/^no repository is set for (\S+); it deploys only artifacts from CI/' => 'Web $1 nemá nastavený repozitář, nasazuje se jen z CI. Repozitář doplňte v Deploy → Nastavení.',
            '/^no repository is set for (\S+)$/' => 'Web $1 nemá nastavený repozitář (Deploy → Nastavení).',
            '/^no release before (\S+)$/' => 'Před releasem $1 už žádný starší není.',
            '/^rollback failed: (.+)$/' => fn ($m) => 'Rollback selhal: '.$cs($m[1]),
            '/^git ls-remote failed: (.*)$/' => fn ($m) => 'Repozitář se nepodařilo zkontrolovat: '.self::git($m[1]),
            '/^artifact is larger than 1 GB$/' => 'Balík z CI je větší než 1 GB.',
            '/^artifact expands to more than (\d+) GB$/' => 'Balík z CI se rozbalí na víc než $1 GB.',
            '/^artifact is not gzip/' => 'Balík z CI není .tar.gz.',
            '/^unpacking the artifact failed: (.+)$/' => fn ($m) => 'Balík z CI se nepodařilo rozbalit: '.$cs($m[1]),
            '/^ref: tag, branch or commit$/' => 'Neplatný tag, větev nebo commit.',
            '/^repo: /' => 'Neplatná adresa repozitáře: použijte https://host/cesta, git@host:cesta nebo ssh://git@host/cesta (bez hesla v URL, použijte deploy klíč).',
            '/^branch: invalid name$/' => 'Neplatný název větve.',

            // Sites
            '/^site (\S+) does not exist$/' => 'Web $1 na serveru neexistuje.',
            '/^site (\S+) already exists/' => 'Web $1 už na serveru existuje.',
            '/^system user (\S+) already exists$/' => 'Systémový uživatel $1 už existuje.',
            '/^site (\S+) has no env file$/' => 'Web $1 nemá soubor .env.',
            '/^mode: site already runs in (\S+)/' => 'Web už běží v režimu $1.',
            '/^php-fpm (\S+) config invalid, not reloading: (.+)$/' => 'Konfigurace PHP-FPM $1 je neplatná, nic se nezměnilo: $2',
            '/^(.+) config invalid, not reloading: (.+)$/' => 'Konfigurace $1 je neplatná, nic se nezměnilo: $2',
            '/^unit (\S+) invalid, not installing: (.+)$/' => 'Unita $1 je neplatná, nic se nenainstalovalo: $2',
            '/^switched to (.+), but (.+) failed: (.+)$/' => 'Přepnuto na $1, ale dokončení selhalo: $3',
            '/^no free port in (\d+)-(\d+)$/' => 'Na serveru není volný port v rozsahu $1–$2.',
            '/^reload: not supported for (.+)$/' => 'Službu $1 nejde jen znovu načíst, použijte restart.',
            '/^source: framework log exists only for Laravel and Symfony$/' => 'Log frameworku mají jen weby Laravel a Symfony.',

            // Databases
            '/^(MariaDB|PostgreSQL|mariadb|postgresql) is not installed on this server$/' => fn ($m) => self::engine($m[1]).' není na serveru nainstalovaná.',
            '/^database (\S+) already exists$/' => 'Databáze $1 už existuje.',
            '/^database (\S+) does not exist$/' => 'Databáze $1 neexistuje.',
            '/^writing credentials to (\S+) failed, database removed again/' => 'Údaje se nepodařilo zapsat do .env webu $1, databáze byla zase odstraněna.',
            '/^password changed, but writing it to (\S+) failed/' => 'Heslo je změněné, ale do .env webu $1 se nepodařilo zapsat. Nastavte ho znovu.',
            '/^pre-restore snapshot failed, nothing restored/' => 'Záloha před obnovou selhala, nic se neobnovilo.',
            '/^restore failed \(snapshot before restore: (\S+)\)/' => 'Obnova selhala. Stav před obnovou je uložený v záloze $1.',
            '/^dump (\S+) failed/' => 'Záloha databáze $1 selhala.',
            '/^backup (\S+) not found$/' => 'Záloha $1 neexistuje.',
            '/^tool: phpMyAdmin supports MariaDB only; use Adminer$/' => 'phpMyAdmin umí jen MariaDB, pro PostgreSQL použijte Adminer.',
            '/^(phpmyadmin|adminer) is not installed$/' => fn ($m) => ($m[1] === 'phpmyadmin' ? 'phpMyAdmin' : 'Adminer').' není nainstalovaný.',
            '/^PostgreSQL configuration not found in (.+)$/' => 'Konfigurace PostgreSQL nebyla nalezena ($1).',
            '/^allow: (.+) means everyone; use anywhere$/' => '$1 znamená všechny adresy, zvolte „Kdokoli z internetu“.',
            '/^allow: (.+) is not an IP address or network$/' => '$1 není IP adresa ani síť.',
            '/^creating the TLS certificate failed/' => 'TLS certifikát databáze se nepodařilo vytvořit.',
            '/^allow: MariaDB accepts single IPv6 addresses only, not (\S+)$/' => 'MariaDB umí z IPv6 jen jednotlivé adresy, ne síť $1.',
            '/^the firewall \(ufw\) is not active; an allowlist needs it$/' => 'Firewall (UFW) neběží. Povolené adresy bez něj nastavit nejde.',

            // SSH, mail, DNS, certificates, firewall
            '/^keys: at least one public key or a password is required$/' => 'Zadejte veřejný klíč, nebo nastavte heslo.',
            '/^keys: RSA keys must be at least 2048 bits/' => 'RSA klíč musí mít aspoň 2048 bitů (lepší je ssh-ed25519).',
            '/^keys: each key must start with a supported type/' => 'Klíč musí začínat podporovaným typem (ssh-ed25519, ecdsa-sha2-*, ssh-rsa, sk-*).',
            '/^keys: (?:invalid base64 key data|key data does not match its type)$/' => 'Veřejný klíč je poškozený, zkopírujte ho znovu celý.',
            '/^no DKIM key for (\S+)$/' => 'Doména $1 zatím nemá DKIM klíč.',
            '/^OpenDKIM is not installed/' => 'OpenDKIM není nainstalovaný.',
            '/^local DNS \(PowerDNS\) is not installed on this server/' => 'Lokální DNS (PowerDNS) není na serveru nainstalované (install.sh --dns=powerdns).',
            '/^CNAME is not allowed at the zone apex$/' => 'CNAME nejde použít na kořeni zóny (@).',
            '/^CNAME: exactly one target$/' => 'CNAME může mít jen jeden cíl.',
            '/^the local CA does not exist yet/' => 'Interní certifikační autorita zatím neexistuje. Vznikne s prvním interním certifikátem.',
            '/^cert: does not cover (\S+)$/' => 'Certifikát neplatí pro doménu $1.',
            '/^cert: not valid now \(valid (.+)\)$/' => 'Certifikát teď neplatí (platnost $1).',
            '/^port (\d+) is protected/' => 'Port $1 je chráněný (SSH/HTTP/HTTPS), z panelu ho nejde zavřít.',
            '/^schedule: expected 5 fields/' => 'Rozvrh potřebuje 5 polí: minuta hodina den měsíc den_v_týdnu.',
            '/^schedule: use either day of month or day of week, not both$/' => 'V rozvrhu použijte buď den v měsíci, nebo den v týdnu, ne obojí.',
            '/^kind: artisan needs a Laravel site$/' => 'Příkaz artisan jde jen u webu Laravel.',
            '/^kind: console needs a Symfony site$/' => 'Příkaz bin/console jde jen u webu Symfony.',

            // FrankenPHP update
            '/^checksum mismatch/' => 'Stažená binárka nesedí s kontrolním součtem z GitHubu, nic se nezměnilo.',
            '/^after the update (.+) did not run; the previous binary was restored$/' => 'Po aktualizaci se $1 nespustil, vrátila se předchozí verze.',
            '/^GitHub publishes no sha256 digest/' => 'GitHub k této verzi nezveřejnil kontrolní součet, instalace bez ověření se odmítá.',

            // Low level: commands and HTTP
            '/^(\S+): exit status (\d+)$/' => 'Příkaz $1 skončil chybou (kód $2).',
            '/connection refused/i' => fn () => 'Server spojení odmítl (nic na portu neposlouchá).',
            '/dial unix \S+\.sock: connect: no such file or directory/' => fn () => 'Proces webu neběží (aplikace neotevřela svůj socket).',
            '/(Client\.Timeout|deadline exceeded|i\/o timeout)/' => fn () => 'Vypršel časový limit, web neodpověděl včas.',
            '/no such host/' => fn () => 'Doménu se nepodařilo přeložit (DNS).',
            '/^HTTP (\d{3})$/' => 'Web vrátil HTTP $1.',
        ];
    }

    /** What mount or rclone said, in words: the usual causes first. */
    private static function mountError(string $detail): string
    {
        return match (true) {
            str_contains($detail, 'error(13)'), str_contains($detail, 'Permission denied'), str_contains($detail, '530 '), str_contains($detail, 'unable to authenticate') => 'server odmítl přihlášení (uživatel nebo heslo). Podrobnosti: '.$detail,
            str_contains($detail, 'error(2)'), str_contains($detail, 'No such file'), str_contains($detail, '550 ') => 'sdílená složka nebo cesta neexistuje. Podrobnosti: '.$detail,
            str_contains($detail, 'error(112)'), str_contains($detail, 'error(115)'), str_contains($detail, 'could not connect'), str_contains($detail, 'Host is down'), str_contains($detail, 'connection refused'), str_contains($detail, 'i/o timeout'), str_contains($detail, 'no route to host') => 'server není dostupný (adresa, port nebo firewall). Podrobnosti: '.$detail,
            str_contains($detail, 'error(95)'), str_contains($detail, 'Operation not supported') => 'server tuto verzi SMB nepodporuje, zkuste jinou. Podrobnosti: '.$detail,
            default => $detail,
        };
    }

    private static function engine(string $name): string
    {
        return str_starts_with(strtolower($name), 'maria') ? 'MariaDB' : 'PostgreSQL';
    }

    /** git's own messages, for the usual causes. */
    private static function git(string $detail): string
    {
        return match (true) {
            str_contains($detail, 'Permission denied (publickey)') => 'server nemá do repozitáře přístup. Přidejte deploy klíč do Gitu (Deploy → Další → Deploy klíč).',
            str_contains($detail, 'Repository not found'), str_contains($detail, 'does not appear to be a git repository') => 'repozitář neexistuje nebo k němu server nemá přístup.',
            str_contains($detail, 'Could not resolve host') => 'adresu Git serveru se nepodařilo přeložit (DNS).',
            str_contains($detail, 'Host key verification failed') => 'neznámý klíč Git serveru.',
            default => $detail,
        };
    }
}
