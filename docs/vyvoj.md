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


## Vydání verze

CI je v `.github/workflows/`:

- **CI** (`ci.yml`) při každém push na `main` a pull requestu: agent (gofmt,
  `go vet` pro amd64 i arm64, testy), panel (Pint, build assetů, testy),
  syntaxe skriptů instalace.
- **Release** (`release.yml`) po pushnutí tagu:

  ```
  git tag v1.2.0 && git push origin v1.2.0      # v1.2.0-rc1 = předběžná verze
  ```

  Spustí kontroly z CI, sestaví balíčky pro amd64 a arm64, každý nainstaluje
  na čistý Debian 13 na stejné architektuře (`dev/release-smoke.sh`: instalace
  se všemi moduly, služby, panel, web Laravel s workery, doručení pošty,
  aktualizace s uloženým nastavením) a teprve pak vydá GitHub release:
  balíčky, `SHA256SUMS`, záznam o původu buildu (build provenance) a seznam
  změn od předchozího tagu.

  Před vydáním projdou všechny tři balíčky kontrolou `lintian`
  (`--fail-on error`): `wspanel-agent` (`provision/build-deb.sh`, agent,
  CLI, služba v `/usr/lib/systemd/system`), `wspanel`
  (`provision/build-installer-deb.sh`) a `wspanel-archive-keyring`
  (`provision/build-keyring-deb.sh`, klíč, zdroj apt, automatické
  aktualizace). Společné věci (copyright DEP-5 s MIT, changelog z commitů od
  předchozího tagu, manuálové stránky z `provision/files/man/`, md5sums,
  výjimky lintianu) jsou v `provision/deb-common.sh`.

  Po vydání job **Apt repository** přidá balíčky
  do apt repozitáře na GitHub Pages (větev
  `gh-pages`, složka `apt/`, `provision/apt-repo.sh`): kanál pro každou
  řadu verzí, nejnovějších 5 verzí v kanálu, indexy podepsané klíčem
  z tajné proměnné `APT_SIGNING_KEY`. Servery mu věří podle veřejného klíče
  `provision/files/wspanel-archive-keyring.asc` (otisk
  `51525652EBC466696C479E9DEECED19A06101F1F`); výměna klíče = nový pár,
  nový veřejný klíč v repozitáři i tajná proměnná. Bez výpadku: nejdřív
  vydat verzi, kde `wspanel-archive-keyring.asc` obsahuje **oba** klíče
  (starý první, jím se dál podepisuje), servery ho dostanou přes apt
  (`wspanel-archive-keyring`); v další verzi nový klíč první a jako
  `APT_SIGNING_KEY`, starý pryč. Nejnovější `wspanel-archive-keyring.deb`
  je i na pevné adrese `…/apt/wspanel-archive-keyring.deb` (první krok
  instalace přes apt).

  Ručním spuštěním (Actions → Release → Run workflow, s číslem verze) se
  balíčky sestaví a otestují bez vydání.

Lokálně totéž: `make release-smoke VERSION=1.2.0-rc1` (Docker, trvá 10–15 minut).

Ověření staženého balíčku na serveru:

```
sha256sum -c SHA256SUMS --ignore-missing
gh attestation verify wspanel-1.2.0-linux-amd64.tar.gz --repo <vlastník>/<repozitář>
```
