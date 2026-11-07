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
  fnm, přepínání režimů a verzí z panelu, kontrola, že web odpovídá.
