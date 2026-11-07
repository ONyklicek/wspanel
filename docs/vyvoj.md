# Vývoj a testování

## Na Macu bez serveru

```sh
make test          # Go testy agenta + PHPUnit testy panelu
make agent-dry     # agent v dry-run režimu na var/agent.sock (příkazy jen loguje)
cd panel && php artisan serve   # panel proti dry-run agentovi (AGENT_SOCKET v .env)
```

## Celý server v Dockeru

Debian 13 kontejner se systemd jako PID 1. Chová se jako skutečný server
(systemd, PHP-FPM, FrankenPHP, MariaDB, PostgreSQL, Postfix, PowerDNS…). Docker
slouží jen k vývoji, produkce běží nativně.

```sh
make dev-up          # spustí kontejner
make dev-provision   # install.sh uvnitř (DB=mariadb,postgres PHP=8.3,8.4, --dns=powerdns, --mail=capture)
docker compose -f dev/compose.yaml exec devserver bash -c \
  'cd /srv/panel/current && runuser -u panel -- php artisan make:filament-user'
make dev-demo        # skutečné aplikace: Laravel 13, Symfony Demo, WordPress přes SFTP
make dev-e2e         # end-to-end test celého stacku (126 kontrol)
make dev-shell       # shell na „serveru“
make dev-down        # smaže kontejner i data
```

| Co | Adresa |
|---|---|
| Panel | https://panel.localhost:8443 |
| Weby | https://<doména>.localhost:8443 (prohlížeč posílá `*.localhost` na váš počítač) |
| Mailpit | https://panel.localhost:8443/mailpit/ |
| SSH/SFTP | `sftp -P 2222 web_<doména>@localhost` |

Certifikáty vydává interní CA Caddy. Prohlížeč jednou zobrazí varování.

## Struktura repozitáře

```
agent/      Go agent: jediná komponenta s rootem, whitelist operací přes Unix socket
panel/      Laravel 13 + Filament 5, běží jako neprivilegovaný uživatel panel, stav v SQLite
provision/  install.sh pro Debian 13 + systemd unity, Caddyfile, konfigurace
dev/        dev kontejner, e2e test, demo aplikace
docs/       tato dokumentace (v panelu v menu Nápověda)
```

## Přidání operace agenta

1. Handler v `agent/internal/ops/*.go`. Parametry dekódovat přes `decode()`
   (neznámé klíče se odmítnou) a validovat. Příkazy jen z allowlistu binárek
   (`cmd/panel-agent/main.go`), nikdy přes shell.
2. Registrace v `Registry()` v `ops.go` (`Mutating: true` pro změny).
3. Test v `*_test.go` s `DryRun` executorem.
4. Volání z panelu přes `AgentClient::call()` v akci v `panel/app/Actions`.
5. Případně kontrola v `dev/e2e.sh`.
