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
  'cd /srv/panel/current && runuser -u panel -- php artisan wire:user'
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
panel/      Laravel 13 + WireStack (Livewire 4), běží jako neprivilegovaný uživatel panel, stav v SQLite
provision/  install.sh pro Debian 13 + systemd unity, Caddyfile, konfigurace
dev/        dev kontejner, e2e test, demo aplikace
docs/       tato dokumentace (v panelu v menu Dokumentace)
```

## Přidání operace agenta

1. Handler v `agent/internal/ops/*.go`. Parametry dekódovat přes `decode()`
   (neznámé klíče se odmítnou) a validovat. Příkazy jen z allowlistu binárek
   (`cmd/panel-agent/main.go`), nikdy přes shell.
2. Registrace v `Registry()` v `ops.go` (`Mutating: true` pro změny).
3. Test v `*_test.go` s `DryRun` executorem.
4. Volání z panelu přes `AgentClient::call()` v akci v `panel/app/Actions`.
5. Případně kontrola v `dev/e2e.sh`.

## Překlady

Texty panelu se píšou česky a obalují do `__('…')`; český text je zároveň klíčem. Anglický překlad je v `panel/lang/en.json` (klíč = česká věta). Při nové větě ji přidejte i tam, jinak se v angličtině zobrazí česky. Hodnoty se proměnnými pište s placeholdery (`__('Web :domain smazán', ['domain' => $d])`), ne skládáním řetězců. Popisky v konstantách (`const`, výchozí hodnoty vlastností) se překládají až při použití: `__($label)` nebo `App\Support\Lang::values($pole)`. Chybové hlášky agenta (anglicky) převádí do češtiny `AgentMessages`; v angličtině se ukazují v originále.

## Simulace deploye skutečného projektu

`dev/simulate-project-deploy.sh <cesta k projektu> [volby]` postaví na Macu
balík z libovolného Laravel projektu stejně jako GitHub Action, v dev panelu
připraví web a nasadí ho přes CI API. S `--franken` pak i v režimu FrankenPHP.
Postup, volby a co kontroluje: [Nasazení existujícího projektu → Simulace](nasazeni-projektu.md#simulace-na-vlastním-projektu).

Dev kontejner má `ip_unprivileged_port_start=0` (Docker), takže tam projde
i to, co by na skutečném Debianu neprošlo (nízké porty pro uživatele webu).
Instalace ho nastavuje na 1024.

**Zapomenuté heslo do dev panelu:**

```bash
docker compose -f dev/compose.yaml exec devserver bash -c 'cd /srv/panel/current && runuser -u panel -- php artisan tinker \
  --execute "App\\Models\\User::where(\"email\", \"admin@dev.local\")->sole()->forceFill([\"password\" => Hash::make(\"nove-heslo\")])->save();"'
```

## Simulace release flow (GitHub Release → Actions → panel)

```
docker compose -f dev/compose.yaml exec devserver /opt/wspanel/dev/simulate-release-deploy.sh
```

Skript odehraje celý postup týmu proti dev serveru:

1. V panelu založí web `intranet.local` (Laravel, interní certifikát),
   databázi a CI token.
2. Jako „runner“ vytvoří skutečnou aplikaci Laravel 13 s Octane.
3. Pro release `v1.0.0` sestaví build (composer, npm), nahraje ho na
   `/api/deploy/<id>/artifact` a čeká na stav, jako Action z Deploy → GitHub
   Actions.

Ověří, že běží v PHP-FPM, s migrovanou databází, frontou a plánovačem. Potom
přepne web do režimu FrankenPHP (worker) a release `v1.1.0` nasadí stejně.

