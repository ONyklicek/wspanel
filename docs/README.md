# WSPanel – dokumentace

WSPanel je hostingový panel pro jeden server s Debianem 13. Spravuje weby (PHP,
Laravel, Symfony, Node), databáze, deploy z Gitu, poštu, DNS, SSH/SFTP,
certifikáty a zálohy. Běží nativně přes systemd, bez Dockeru.

## Pro koho je co

| Chci… | Čtěte |
|---|---|
| nainstalovat panel na server | [Instalace](instalace.md) |
| založit web a dostat ho na internet | [Weby](weby.md) |
| nahrát obyčejný PHP web (třeba WordPress) | [Weby → Čisté PHP](weby.md#čisté-php-wordpress-vlastní-skripty) |
| hostovat weby pro klienty s limity | [Klienti a balíčky](klienti.md) |
| nasazovat aplikaci z Gitu | [Deploy](deploy.md) |
| nasazovat přes GitHub Releases / Actions | [Deploy → Release a CI](deploy.md#nasazení-releasů-a-z-ci-github-actions) |
| napojit repozitář z GitHubu jedním kliknutím (jako Zerops) | [GitHub → Návod krok za krokem](github.md#návod-první-deploy-z-githubu-krok-za-krokem) |
| převést existující aplikaci (CI build, Horizon, Reverb, FrankenPHP) | [Nasazení existujícího projektu](nasazeni-projektu.md) |
| databázi a phpMyAdmin / Adminer | [Databáze](databaze.md) |
| přístup k souborům | [SSH a SFTP](ssh-sftp.md) |
| odesílat e-maily z webu, testovat je v Mailpitu | [Pošta](posta.md) |
| dostat zprávu, když web spadne nebo doběhne deploy | [Notifikace](notifikace.md) |
| ovládat panel ze skriptu nebo příkazové řádky | [API a CLI](api.md) |
| spravovat DNS (na serveru nebo v Cloudflare) | [DNS](dns.md) |
| fronty, Horizon, Reverb, plánovač | [Weby → Procesy a workery](weby.md#procesy-a-workery) |
| vidět vytížení CPU, RAM, disku, kdo server zatěžuje | [Vytížení serveru](vytizeni.md) |
| pochopit, jak je to zabezpečené | [Bezpečnost](bezpecnost.md) |
| vyvíjet panel a testovat ho na Macu | [Vývoj](vyvoj.md) |
| něco nefunguje | [Řešení problémů](reseni-problemu.md) |

## Základní pojmy

- **Web** = jedna doména. Má vlastního systémového uživatele `web_<doména>`
  (tečky a pomlčky nahrazené podtržítkem), vlastní adresář
  `/srv/sites/<doména>` a vlastní PHP/Node proces. Weby se navzájem nevidí.
- **Release** = jedna nasazená verze aplikace v `releases/<čas>`. Odkaz
  `current` ukazuje na tu, která právě běží.
- **Shared** = `shared/` se soubory, které přežijí každý deploy: `.env`,
  nahrané soubory (`storage/`), logy.
- **Agent** = jediná část s právy roota. Panel ho žádá o konkrétní operace
  (založ web, restartuj worker…), nic jiného agent neumí.

## Kde co v panelu najdete

| Menu | Co tam je |
|---|---|
| **Přehled** | co vyžaduje pozornost (spadlé služby, neodpovídající weby, certifikáty, chyby deploye, plný disk), zátěž, poslední deploye |
| **Hosting → Klienti** | klienti, jejich balíčky a limity, spotřeba, pozastavení, přihlášení jako klient (jen správce) |
| **Hosting → Weby** | seznam webů; klik na web otevře detail se stavem, procesy, logy a akcemi, záložky Deploy, Soubory a Zálohy |
| **Hosting → Databáze** | databáze, zálohy, phpMyAdmin / Adminer |
| **Hosting → Databáze → Servery** | heslo root / postgres, přístup k databázím z internetu, phpMyAdmin / Adminer přes všechny databáze |
| **Hosting → Naplánované úlohy** | cron úlohy webů |
| **Síť a pošta → DNS** | zóny na tomto serveru nebo v Cloudflare, záznamy, kontrola |
| **Síť a pošta → SSL** | certifikáty a jejich stav, kořenový certifikát interní CA |
| **Síť a pošta → Pošta** | odesílání vs. zachytávání (Mailpit), DKIM, SPF/DMARC, relay, log |
| **Server → Zálohy mimo server** | kopie všech záloh do S3 (Amazon S3, R2, B2, Wasabi, MinIO), na FTP(S) nebo SMB (NAS), podepsané |
| **Server → Vytížení** | CPU, RAM, disky, síť živě i v grafech za 30 dní, vytížení podle webů, procesy |
| **Server → PHP** | verze PHP, rozšíření (přidat/odebrat), doinstalování verze, FrankenPHP |
| **Server → Služby** | všechny systemd služby včetně workerů webů (start/stop/restart) |
| **Server → Firewall** | pravidla UFW a přístup k panelu (z jakých adres) |
| **Server → Audit** | co přesně a kdy agent na serveru udělal |
| **Účet → Integrace** | propojení s GitHubem (tlačítkem nebo tokenem), připojené účty a organizace |
| **Účet → API a CLI** | API tokeny pro skripty a příkaz `wspanel` |
| **Dokumentace** | tato dokumentace |

Vyhledávání (⌘K / Ctrl+K) najde web nebo databázi podle názvu.

**Jazyk panelu:** čeština nebo angličtina, volí si ho každý uživatel sám v menu účtu (vpravo nahoře → *English* / *Čeština*). Nový uživatel dostane výchozí jazyk serveru (`APP_LOCALE`, výchozí `cs`). Tato dokumentace a některá hlášení vygenerovaná serverem (komentáře v pravidlech webserveru) zůstávají česky.
