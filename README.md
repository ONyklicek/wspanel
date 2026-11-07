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
- Každý web má vlastního systémového uživatele, `open_basedir` a FPM pool.
  Proxy čte jen `releases/` (přes ACL), nikdy `shared/`, kde leží `.env`.

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
./provision/install.sh --db=mariadb --php=8.3,8.4 \
  --panel-domain=panel.example.com --acme-email=admin@example.com \
  --agent-bin=./build/panel-agent-linux-amd64 --panel-source=./panel
```

`--db`: `none` | `mariadb` | `postgres` | `mariadb,postgres`. Engine půjde
později jen doinstalovat (Fáze 4).

## Stav

Fáze 1 je hotová: agent (`site.create/delete`, `service.status/reload/restart`,
`config.validate`, `sys.services/versions`) a panel se seznamem webů a zakládáním
classic (PHP-FPM) webů pro Laravel, Symfony a obecné PHP.
