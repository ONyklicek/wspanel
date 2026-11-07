# WSPanel

Vlastní hostingový panel pro jeden server (Debian 13). Produkce běží nativně
přes systemd, bez Dockeru. Docker slouží jen jako vývojový „server“.

**Umí:**
- weby: Čisté PHP, Laravel, Symfony, Nuxt/Next/Node v režimech PHP-FPM,
  FrankenPHP worker a Node SSR,
- deploy z Gitu, releasů a GitHub Actions,
- workery (fronty, Horizon, Reverb, Pulse, Messenger, plánovač),
- MariaDB a PostgreSQL se zálohami, phpMyAdmin a Adminer,
- SSH/SFTP s klíči i hesly,
- odchozí poštu s DKIM a Mailpit,
- DNS (vlastní PowerDNS i Cloudflare),
- SSL (Let's Encrypt), firewall, naplánované úlohy a audit,
- monitoring vytížení (CPU, RAM, disky, síť, weby, procesy) s historií 30 dní.

```
agent/      Go agent: jediná komponenta s rootem, whitelist operací přes Unix socket
panel/      Laravel 13 + Filament 5, běží jako neprivilegovaný uživatel `panel`, stav v SQLite
provision/  install.sh pro Debian 13 + systemd unity, Caddyfile, konfigurace
dev/        Debian 13 kontejner se systemd (náhrada VM na Macu), e2e test, demo
docs/       dokumentace
```

## Dokumentace

- [Přehled](docs/README.md)
- [Instalace](docs/instalace.md)
- [Weby](docs/weby.md)
- [Deploy](docs/deploy.md)
- [Databáze](docs/databaze.md)
- [SSH a SFTP](docs/ssh-sftp.md)
- [Pošta](docs/posta.md)
- [DNS](docs/dns.md)
- [Vytížení serveru](docs/vytizeni.md)
- [Bezpečnost](docs/bezpecnost.md)
- [Vývoj a testování](docs/vyvoj.md)
- [Řešení problémů](docs/reseni-problemu.md)

## Rychlý start

```sh
make agent-build
# na serveru (Debian 13, root):
./provision/install.sh --panel-domain=panel.example.com --acme-email=admin@example.com \
  --agent-bin=./build/panel-agent-linux-amd64 --panel-source=./panel \
  --db=mariadb,postgres --php=8.3,8.4 --dns=powerdns
```

Vývoj na Macu: `make dev-up dev-provision dev-demo`, pak https://panel.localhost:8443.

## Technické detaily

### Režimy webu

| Režim | Backend | Verze | Cesta požadavku |
|---|---|---|---|
| Classic | PHP-FPM pool pod uživatelem webu | 8.2–8.4 (FPM balíčky) | edge → `panel-web` → FPM socket |
| Worker | vlastní FrankenPHP instance pod uživatelem webu (Laravel Octane, Symfony 7.4+) | PHP zabudované ve FrankenPHP | edge → `/run/wspanel-sites/<user>/http.sock` |
| Node SSR | Node proces (Nuxt, Next.js, `server.js`) | major verze z fnm (`/opt/fnm`) | edge → `127.0.0.1:<port>` (port přiděluje agent) |

- **Změna režimu** (`site.switch_mode`): nový backend se postaví vedle starého a edge se
  přepne až ve chvíli, kdy je hotový. Při chybě web běží dál jako dřív.
  Workery se přenastaví na nové PHP, nepodporované se odeberou.
- **Změna verze** (`site.set_version`):
  - classic: nový FPM pool vedle starého, bez výpadku,
  - node: restart procesu,
  - worker: verzi nejde zvolit, je daná buildem FrankenPHP.
- **Kontrola, že web odpovídá** (`site.health`): HTTP dotaz přímo na backend (mimo
  edge). Panel ho spouští každou minutu.
- **Worker a Node web obsluhuje statiku sám pod svým uživatelem.** Symlink tak
  nedosáhne dál, než kam smí uživatel webu.

### Laravel a Symfony

| Laravel | Symfony |
|---|---|
| Octane = worker režim webu (FrankenPHP) | worker režim přes Symfony Runtime (7.4+) |
| Horizon, fronta (`queue:work`), scheduler (`schedule:work`) | Messenger, scheduler (`messenger:consume scheduler_default`) |
| Reverb (`reverb:start`): edge posílá `/app` a `/apps` na jeho port | |
| Pulse (`pulse:check`, volitelně `pulse:work` při Redis ingestu) | |

Workery běží na stejném PHP jako web (FPM CLI dané verze, nebo `frankenphp php-cli` ve worker režimu).

### Porty aplikací

Node SSR a Reverb dostávají porty 600–999 na `127.0.0.1`. Porty pod 1024 může
bindovat jen proces s `CAP_NET_BIND_SERVICE`, takže si port restartující se
aplikace nemůže zabrat jiný web (PHP, SSH ani jiný Node proces).
- Provisioning nastaví `net.ipv4.ip_unprivileged_port_start=1024`. Kontejnery
  ho často mají na 0.
- Unita aplikace dostane capability a `SocketBindAllow` jen na svůj port.
- Agent si vynucení `SocketBindDeny` (cgroup BPF) ověří sondou. Když vynucené
  není, Node a Reverb odmítne. Výjimka `--allow-unenforced-socket-bind` je jen
  pro Docker Desktop.

### SSH a SFTP

- Přihlášení jen klíčem jako uživatel webu (`web_<doména>`). Úroveň přístupu se
  volí v panelu: vypnuto, jen SFTP, nebo SSH + SFTP.
- Klíče leží v `/etc/ssh/authorized_keys/<user>` (root, 0644). Uživatel webu
  si nemůže přidat vlastní. Každý klíč má `restrict`; shell dostane navíc jen pty.
- Port forwarding, agent a X11 jsou zakázané: přes SSH se nedostanete na
  lokální služby (databáze, backendy jiných webů).
- V shellu jsou `php` a `node`/`npm` ve verzi webu (`/etc/panel-sites/bin/<user>`
  přes `/etc/profile.d` a `pam_env`), k tomu `composer`, `git`, `mc` a `nano`.
- fail2ban hlídá SSH. Root se může přihlásit jen klíčem; `--no-root-ssh` ho zakáže úplně.
- Přímé SSH obchází audit panelu, proto panel u webu zobrazuje, že je zapnuté.

### Databáze

- **Enginy:** MariaDB a PostgreSQL, volitelné při instalaci (`--db=`). Později
  je jde z panelu doinstalovat (`engine.install`), odebrat ne.
- **Vznik databáze:** každá databáze má vlastníka se stejným jménem a náhodným
  heslem.
  - S připojeným webem agent zapíše údaje přímo do jeho `.env` (Laravel `DB_*`,
    Symfony a Node `DATABASE_URL`) a heslo neopustí agenta.
  - Admin hesla neexistují: root jde přes `unix_socket`, `postgres` přes peer.
- **Izolace:**
  - MariaDB `GRANT` escapuje `_` a `%`, takže práva neplatí pro podobně
    pojmenovanou databázi jiného webu.
  - PostgreSQL odebírá `CONNECT` pro `PUBLIC`.
- **Zálohy** v `/var/backups/wspanel` (root:panel 0640):
  - spuštění ručně, nebo plánovaně přes systemd timer s rotací,
  - dump a obnova běží jako joby na pozadí,
  - před obnovou se pořídí snímek,
  - obnova PostgreSQL běží s právy vlastníka (`--role`), MariaDB s `--sandbox`.
- **phpMyAdmin 5.2.3 a Adminer 6.2.0** na `/dbtools/` domény panelu:
  - vlastní pool `dbtools`,
  - přihlášení jen jednorázovým tokenem od agenta, s dočasným uživatelem
    omezeným na jednu databázi a 30 minut,
  - Adminer má server pevně na 127.0.0.1 (CVE-2021-21311).

### SSL, úlohy, monitoring

- **SSL:** certifikáty vydává a obnovuje edge proxy (Caddy) sama. Panel ukazuje
  stav, vydavatele a poslední chybu z logu. Před vydáním umí zkontrolovat, že
  DNS míří na server a port 443 je otevřený.
- **Naplánované úlohy:**
  - systemd timery pod uživatelem webu se sandboxem,
  - příkazy jen `artisan …`, `bin/console …` nebo PHP skript webu, argumenty bez
    shellových znaků,
  - cron výraz se převede na `OnCalendar`.
- **Dashboard:**
  - zátěž, paměť a nejplnější disk,
  - stav certifikátů,
  - upozornění na novou verzi FrankenPHP (binárka se neaktualizuje sama a má v
    sobě OpenSSL i PHP).

### Deploy

V panelu: web → **Deploy**.
- **Nastavení:** repozitář (SSH s deploy klíčem, nebo veřejné https), větev,
  migrace, assety, automatický rollback a počet držených releasů.
- **Deploy klíč** (ed25519) se vytvoří v `~/.ssh` uživatele webu. Veřejnou část
  přidejte k repozitáři jen pro čtení.
- **Kód z repozitáře nikdy neběží jako root.** Každý krok (git, composer, npm,
  artisan/console) spouští agent jako dočasnou systemd unitu pod uživatelem
  webu se sandboxem. Výstup jde do logu, který může zapsat jen root, a panel ho
  čte živě.
- **Postup:**
  1. `git clone` do `releases/<UTC čas>`,
  2. propojení `shared/` (`.env`, `storage` nebo `var/log`),
  3. composer,
  4. npm install a build,
  5. Laravel: `migrate`, `storage:link`, `optimize`;
     Symfony: Doctrine migrace, `asset-map:compile`, `cache:warmup`,
  6. atomické přepnutí `current`,
  7. reload FPM, nebo restart aplikace (worker režim, Node), a restart workerů
     (Horizon, Reverb, Pulse, Messenger…),
  8. kontrola, že web odpovídá; když ne, návrat na předchozí release,
  9. smazání starých releasů.
- **Když build selže,** `current` se nepřepne a nový release se smaže. Web
  nemá výpadek.
- **Webhook** (Deploy → Webhook): push do nastavené větve spustí deploy.
  - URL obsahuje náhodné id, ne doménu. Tajemství je v databázi šifrované.
  - Podporované podpisy:
    - GitHub a Bitbucket: HMAC-SHA256 (`X-Hub-Signature-256`, Bitbucket i `X-Hub-Signature`; SHA-1 ne),
    - Gitea a Forgejo: `X-Gitea-Signature` / `X-Forgejo-Signature`,
    - GitLab: `X-Gitlab-Token`.
  - Bez platného podpisu se nic nespustí. Jiné větve a `ping` se jen zapíšou.
  - Push během běžícího deploye se zařadí. Scheduler ho do minuty spustí jako
    jeden deploy nejnovějšího stavu.
  - Limit je 30 požadavků za minutu a 1 MB na tělo. Každé doručení vidíte na
    stránce Deploy. Tlačítko „Nové URL a secret“ okamžitě zneplatní ty staré.
- **Rollback** jde na kterýkoli z držených releasů. **`.env`** se edituje
  přímo na stránce Deploy (čte i zapisuje se jako uživatel webu).

### Provoz

- **Audit (menu Audit):** posledních 500 operací agenta s filtrem podle oblasti.
  Citlivé parametry (obsah `.env`, hesla, tokeny) agent do logu nezapisuje,
  jen délku a otisk.
- **Produkční OPcache (Deploy → Nastavení, classic weby):** PHP nekontroluje
  změny souborů. Kód se načte při deployi (reload FPM). Ruční úpravy přes
  SFTP se pak projeví až po reloadu.
- **Aktualizace FrankenPHP (Služby → Aktualizovat):**
  1. Agent stáhne release z GitHubu a ověří ho otiskem sha256, který GitHub
     k binárce zveřejňuje.
  2. Novou binárkou zkontroluje všechny konfigurace.
  3. Vymění binárku (stará zůstane jako `.prev`) a restartuje proxy, web tier
     a worker weby. Všechny weby jsou pár sekund nedostupné.
  4. Když něco nenaběhne, vrátí původní binárku.

### Struktura webu

```
/srv/sites/<doména>/
  current -> releases/<release>     # atomické přepnutí při deployi
  releases/initial/public/index.php # placeholder do prvního deploye
  shared/                           # .env (Laravel) / .env.local (Symfony), storage/ nebo var/
  tmp/  logs/
```

