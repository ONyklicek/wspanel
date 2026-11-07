# WSPanel

Vlastní hosting control panel pro jeden server (Debian 13). Produkce běží
nativně přes systemd, bez Dockeru. Docker slouží jen jako vývojový „server“.

```
agent/      Go agent: jediná komponenta s rootem, whitelist operací přes Unix socket
panel/      Laravel 13 + Filament 5, běží jako neprivilegovaný uživatel `panel`, stav v SQLite
provision/  install.sh pro Debian 13 + systemd unity, Caddyfile, PHP tuning
dev/        Debian 13 kontejner se systemd jako PID 1 (náhrada VM na Macu)
```

## Bezpečnostní model v kostce

- Panel nemá root ani sudo. Všechno privilegované posílá agentovi přes
  `/run/panel-agent.sock`. Socket má práva `root:panel 0660` a agent navíc
  ověřuje UID protistrany přes `SO_PEERCRED`.
- Agent zná jen operace z `agent/internal/ops/ops.go`. Parametry ověřuje přes
  enum/regex a neznámé klíče odmítne. Příkazy spouští přes `exec.Command` s
  allowlistem binárek, nikdy přes shell.
- Před každým reloadem ověří config (`php-fpm -t`, `frankenphp validate`).
  Když selže, provedené kroky se vrátí zpět.
- Každá operace se zapíše do `/var/log/panel-agent/audit.log` (JSON lines).
- Každý web má vlastního systémového uživatele, `open_basedir`, FPM pool a
  `disable_functions` (včetně `proc_open` a `putenv`).
- Proxy má dvě vrstvy, protože Caddy `file_server` následuje symlinky i mimo root:
  - `panel-proxy` (edge) drží TLS klíče, ale `/srv` nevidí vůbec
    (`InaccessiblePaths`). Na web tier jen předává požadavky přes Unix socket.
  - `panel-web` obsluhuje statiku a FastCGI. Nemá žádná tajemství a přes ACL
    smí číst jen adresáře `public/`. Symlink, který si web nastrčí, tak vede
    nanejvýš k souborům, které jsou veřejné i tak.
- Workery (Horizon, queue, scheduler, Messenger) běží jako systemd unity pod
  uživatelem webu se sandboxem (`ProtectSystem=strict`, zapisovat smí jen do
  svého webu). Příkaz určuje framework, panel ho zadat nemůže.
- Firewall (UFW): porty SSH, 80 a 443 nejde z panelu zakázat ani smazat.
  Služby, na kterých běží panel, nejde z panelu zastavit.

## Režimy webu

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

## Laravel a Symfony

| Laravel | Symfony |
|---|---|
| Octane = worker režim webu (FrankenPHP) | worker režim přes Symfony Runtime (7.4+) |
| Horizon, fronta (`queue:work`), scheduler (`schedule:work`) | Messenger, scheduler (`messenger:consume scheduler_default`) |
| Reverb (`reverb:start`): edge posílá `/app` a `/apps` na jeho port | |
| Pulse (`pulse:check`, volitelně `pulse:work` při Redis ingestu) | |

Workery běží na stejném PHP jako web (FPM CLI dané verze, nebo `frankenphp php-cli` ve worker režimu).

## Porty aplikací

Node SSR a Reverb dostávají porty 600–999 na `127.0.0.1`. Porty pod 1024 může
bindovat jen proces s `CAP_NET_BIND_SERVICE`, takže si port restartující se
aplikace nemůže zabrat jiný web (PHP, SSH ani jiný Node proces).
- Provisioning nastaví `net.ipv4.ip_unprivileged_port_start=1024`. Kontejnery
  ho často mají na 0.
- Unita aplikace dostane capability a `SocketBindAllow` jen na svůj port.
- Agent si vynucení `SocketBindDeny` (cgroup BPF) ověří sondou. Když vynucené
  není, Node a Reverb odmítne. Výjimka `--allow-unenforced-socket-bind` je jen
  pro Docker Desktop.

## SSH a SFTP

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

## Databáze

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

## SSL, úlohy, monitoring

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

## Deploy

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
- **Rollback** jde na kterýkoli z držených releasů. **`.env`** se edituje
  přímo na stránce Deploy (čte i zapisuje se jako uživatel webu).

## Struktura webu

```
/srv/sites/<doména>/
  current -> releases/<release>     # atomické přepnutí při deployi
  releases/initial/public/index.php # placeholder do prvního deploye
  shared/                           # .env (Laravel) / .env.local (Symfony), storage/ nebo var/
  tmp/  logs/
```

## Vývoj na Macu

```sh
make test          # Go testy agenta + PHPUnit testy panelu
make agent-dry     # agent v dry-run režimu na var/agent.sock (příkazy jen loguje)
cd panel && php artisan serve   # panel proti dry-run agentovi (AGENT_SOCKET v .env)
```

Integrace na skutečném systemd, FPM a Caddy:

```sh
make dev-up                    # Debian 13 kontejner se systemd
make dev-provision             # install.sh uvnitř (DB=mariadb,postgres PHP=8.3,8.4)
make dev-shell                 # shell na "serveru"
docker compose -f dev/compose.yaml exec devserver bash -c \
  'cd /srv/panel/current && runuser -u panel -- php artisan make:filament-user'
# panel: https://panel.localhost:8443 (certifikát z interní CA Caddy)
```

Pro vývoj používej domény `*.localhost`, případně provisioning s `--local-certs`.

## Produkce

```sh
make agent-build ARCH=amd64
# na serveru (Debian 13, root):
./provision/install.sh --db=mariadb --php=8.3,8.4 --node=22,24 \
  --panel-domain=panel.example.com --acme-email=admin@example.com \
  --agent-bin=./build/panel-agent-linux-amd64 --panel-source=./panel
```

`--db`: `none` | `mariadb` | `postgres` | `mariadb,postgres`. Engine půjde
později jen doinstalovat (Fáze 4).

## Stav

- **Fáze 1:** agent, zakládání a mazání classic (PHP-FPM) webů pro Laravel,
  Symfony a obecné PHP.
- **Fáze 2:**
  - služby (`service.start/stop/restart/reload/reset`),
  - workery webů (`worker.create/delete`),
  - firewall (`fw.list/allow/deny/delete`),
  - zrcadlení skutečného stavu do panelu (`panel:sync` každou minutu přes
    `panel-scheduler`) a přehled na dashboardu.
- **Fáze 3:** worker režim (FrankenPHP + Octane / Symfony Runtime), Node SSR přes
  fnm, přepínání režimů a verzí z panelu, kontrola, že web odpovídá, SSH/SFTP,
  Reverb a Pulse.
- **Fáze 4:** databáze se zálohami, phpMyAdmin a Adminer, SSL přehled,
  naplánované úlohy a monitoring.
- **Deploy pipeline:** git a deploy klíč, build v sandboxu pod uživatelem webu,
  atomické přepnutí, restart služeb, health-check, automatický rollback, editor
  `.env`.
