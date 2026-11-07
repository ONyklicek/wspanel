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
| nasazovat aplikaci z Gitu | [Deploy](deploy.md) |
| nasazovat přes GitHub Releases / Actions | [Deploy → Release a CI](deploy.md#nasazení-releasů-a-z-ci-github-actions) |
| databázi a phpMyAdmin / Adminer | [Databáze](databaze.md) |
| přístup k souborům | [SSH a SFTP](ssh-sftp.md) |
| odesílat e-maily z webu, testovat je v Mailpitu | [Pošta](posta.md) |
| spravovat DNS (na serveru nebo v Cloudflare) | [DNS](dns.md) |
| fronty, Horizon, Reverb, plánovač | [Weby → Procesy a workery](weby.md#procesy-a-workery) |
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
| **Dashboard** | vytížení serveru, disk, certifikáty, upozornění |
| **Weby** | seznam webů; klik na web otevře detail se stavem, procesy, logy a akcemi |
| **Databáze** | databáze, zálohy, phpMyAdmin / Adminer |
| **DNS** | zóny na tomto serveru nebo v Cloudflare, záznamy, kontrola |
| **Pošta** | odesílání vs. zachytávání (Mailpit), DKIM, SPF/DMARC, relay, log |
| **Služby** | všechny systemd služby včetně workerů webů (start/stop/restart) |
| **Firewall** | pravidla UFW |
| **SSL** | certifikáty a jejich stav |
| **Naplánované úlohy** | cron úlohy webů |
| **Audit** | co přesně a kdy agent na serveru udělal |
| **Nápověda** | tato dokumentace |
