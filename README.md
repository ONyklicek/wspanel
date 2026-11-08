# WSPanel

Vlastní hostingový panel pro jeden server (Debian 13). Produkce běží nativně
přes systemd, bez Dockeru. Docker slouží jen jako vývojový „server“.

**Umí:**
- klienty s balíčky a limity (každý vidí jen své weby, databáze a DNS),
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
panel/      Laravel 13 + WireStack (Livewire 4), běží jako neprivilegovaný uživatel `panel`, stav v SQLite
provision/  install.sh pro Debian 13 + systemd unity, Caddyfile, konfigurace
dev/        Debian 13 kontejner se systemd (náhrada VM na Macu), e2e test, demo
docs/       dokumentace
```

## Dokumentace

- [Přehled](docs/README.md)
- [Instalace](docs/instalace.md)
- [Klienti a balíčky](docs/klienti.md)
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

## Instalace

### Co je potřeba

- Čistý server s **Debianem 13 (trixie)**, architektura **amd64** nebo **arm64**, přístup jako root.
- Alespoň 2 GB RAM a 20 GB disku (s antivirem ClamAV pro poštu 3 GB RAM).
- Doména pro panel s DNS záznamem A (případně AAAA) na server, např. `panel.example.com`.
  Bez domény jde panel provozovat na IP adrese a vlastním portu.
- Otevřené porty 22, 80 a 443; s poštou i 25, 465, 587, 993, 995; s DNS serverem 53 (TCP i UDP).
- Odchozí přístup na internet (balíčky Debianu a PHP, Let's Encrypt, GitHub).

### Instalace jedním příkazem

Na serveru jako root (nebo přes `sudo`):

```sh
curl -fsSL https://github.com/ONyklicek/wspanel/releases/latest/download/get.sh | sudo bash
```

Skript `get.sh`:

1. zjistí nejnovější vydání a architekturu serveru,
2. stáhne balíček vydání a ověří ho proti `SHA256SUMS` téhož vydání (podvržený nebo
   poškozený balíček odmítne),
3. rozbalí ho do `/var/lib/wspanel-installer/<verze>/`,
4. spustí průvodce instalací.

Průvodce se postupně zeptá:

- kde bude panel: na doméně s Let's Encrypt, na IP adrese a portu, na doméně
  s vlastním portem, nebo ve vnitřní síti (vlastní certifikační autorita),
- jestli je server za NATem (veřejná adresa, přesměrované porty),
- na port SSH a jestli úplně zakázat přihlášení roota,
- které databáze (MariaDB, PostgreSQL), verze PHP a Node nainstalovat,
- které moduly zapnout: klienti a balíčky, pošta se schránkami, DNS server, profilování,
- jestli pošta z webů odchází, nebo se zachytává v Mailpitu,
- jestli panel omezit na vybrané adresy,
- na prvního správce: jméno, e-mail a heslo (prázdné = vygeneruje se a ukáže jednou).

Před instalací ukáže souhrn. Instalace trvá 5–15 minut a na konci vypíše adresu
panelu a přihlašovací údaje.

> **Předběžné verze** (`-rc`) odkaz `releases/latest` nenajde, tu je potřeba zadat.
> Dokud nevyjde první ostrá verze (1.2.0), funguje jen tento způsob:
>
> ```sh
> curl -fsSL https://github.com/ONyklicek/wspanel/releases/download/v1.2.0-rc2/get.sh \
>   | sudo env WSPANEL_VERSION=1.2.0-rc2 bash
> ```

### Instalace přes apt

Celý WSPanel jde nainstalovat z apt repozitáře WSPanelu (GitHub Pages,
podepsaný klíčem). Vše v terminálu na čistém Debianu 13, jako uživatel
se `sudo` (nebo jako root bez `sudo`). Bloky jde kopírovat celé.

**1. Nástroje** (minimální Debian je nemusí mít):

```sh
sudo apt update
sudo apt install -y curl gnupg ca-certificates
```

**2. Klíč repozitáře** (stáhnout a ověřit otisk):

```sh
sudo install -d -m 0755 /etc/apt/keyrings
curl -fsSL https://onyklicek.github.io/wspanel/apt/wspanel-archive-keyring.asc \
  | sudo tee /etc/apt/keyrings/wspanel.asc > /dev/null
gpg --show-keys /etc/apt/keyrings/wspanel.asc
```

Výpis musí obsahovat otisk `51525652EBC466696C479E9DEECED19A06101F1F`.
Jiný otisk = klíč nepatří WSPanelu, dál nepokračujte.

**3. Repozitář:**

```sh
CHANNEL=stable   # nejnovější ostrá verze; pre = nejnovější předběžná
printf '%s\n' \
  'Types: deb' \
  'URIs: https://onyklicek.github.io/wspanel/apt' \
  "Suites: $CHANNEL" \
  'Components: main' \
  'Signed-By: /etc/apt/keyrings/wspanel.asc' \
  | sudo tee /etc/apt/sources.list.d/wspanel.sources > /dev/null
sudo apt update
apt policy wspanel             # "Candidate:" ukazuje verzi, která se nainstaluje
```

> Dokud nevyjde první ostrá verze (1.2.0), je v repozitáři jen kanál `pre`:
> použijte `CHANNEL=pre`.

**4. Instalace:**

```sh
sudo apt install wspanel      # balíčky wspanel a wspanel-agent
sudo wspanel-install          # průvodce: adresa panelu, moduly, správce…
```

Bez otázek (automatizace) místo průvodce:

```sh
sudo wspanel-install --yes --panel-domain=panel.example.com --acme-email=admin@example.com \
  --db=mariadb --php=8.3,8.4 --mail-hosting=yes --admin-email=admin@example.com
```

**5. Kontrola:**

```sh
dpkg-query -W wspanel wspanel-agent              # nainstalované verze
grep '^Suites' /etc/apt/sources.list.d/wspanel.sources   # kanál řady, např. 1.2
systemctl status panel-agent --no-pager | head -3
```

#### Instalace ze stažených souborů .deb

Bez přidávání repozitáře (např. konkrétní verze nebo server, který GitHub
Pages nevidí). Soubory jsou u každého vydání na
[Releases](https://github.com/ONyklicek/wspanel/releases); GitHub v jejich
názvech nahrazuje `~` tečkou.

```sh
V=1.2.0-rc3                      # verze z Releases (balíček wspanel je od 1.2.0-rc3)
ARCH=$(dpkg --print-architecture) # amd64 nebo arm64
BASE=https://github.com/ONyklicek/wspanel/releases/download/v$V
cd "$(mktemp -d)"
curl -fLO "$BASE/wspanel_${V/-/.}_all.deb"
curl -fLO "$BASE/wspanel-agent_${V/-/.}_$ARCH.deb"
curl -fLO "$BASE/SHA256SUMS"
sha256sum -c SHA256SUMS --ignore-missing          # oba soubory: OK
sudo apt install ./wspanel_${V/-/.}_all.deb ./wspanel-agent_${V/-/.}_$ARCH.deb
sudo wspanel-install
```

`apt install ./soubor.deb` doinstaluje i závislosti z Debianu. Instalátor pak
apt repozitář WSPanelu přidá sám (kvůli opravám agenta); kdo ho nechce, viz
[Repozitář nepoužívat](#repozitář-nepoužívat).

#### Jak to funguje

- `apt install wspanel` nainstaluje dva balíčky: **`wspanel`** (instalátor
  a panel, `/usr/share/wspanel`) a **`wspanel-agent`** (agent). Na systému zatím
  nic nenastaví.
- `wspanel-install` je tentýž průvodce jako u instalace jedním příkazem (na co
  se ptá, je popsané výše). Bez otázek: `wspanel-install --yes --panel-domain=…`
  se stejnými volbami jako [instalace bez otázek](#instalace-bez-otázek).
- Instalátor pak server přepne z kanálu `stable` na kanál své řady (`1.2`):
  `apt upgrade` a automatické aktualizace přinášejí opravy agenta i balíčku
  `wspanel` jen v rámci řady, kterou máte.

**Opravy v rámci řady (1.2.0 → 1.2.1):**

```sh
sudo apt update && sudo apt upgrade   # nové balíčky wspanel a wspanel-agent
sudo wspanel-install --yes            # použije novou verzi panelu (nastavení zůstane)
```

Agent se novou verzí balíčku restartuje sám; panel a jeho konfiguraci použije
až `wspanel-install` (apt to po aktualizaci připomene).

**Nová verze, i vyšší řada (1.2 → 1.3):**

```sh
sudo wspanel-update          # nejnovější ostrá verze
sudo wspanel-update --pre    # nejnovější předběžná verze
sudo wspanel-update --yes    # bez otázek průvodce
```

`wspanel-update` přepne repozitář na kanál `stable` (`pre`), nainstaluje
nejnovější balíčky a spustí `wspanel-install`, který nabídne aktualizaci se
stávajícím nastavením a server přepne na kanál nové řady. Tento příkaz radí
i panel, když najde novou verzi (**Zabezpečení → Aktualizace**).

Server nainstalovaný jedním příkazem (`get.sh`) přejde na apt kdykoli:
repozitář už má nastavený, stačí `sudo apt install wspanel` a dál používat
`sudo wspanel-update`.

### Instalace bez otázek

Stejné volby jako průvodce se dají předat jako parametry (`--yes` = nic se neptá):

```sh
curl -fsSL https://github.com/ONyklicek/wspanel/releases/latest/download/get.sh | sudo bash -s -- --yes \
  --panel-domain=panel.example.com --acme-email=admin@example.com \
  --db=mariadb --php=8.3,8.4 --mail-hosting=yes --clients=yes \
  --admin-email=admin@example.com
```

Heslo správce se vygeneruje a vypíše na konci. Všechny volby jsou v
[docs/instalace.md](docs/instalace.md#volby-installsh).

### Ruční instalace z vydání

Kdo nechce spouštět skript přímo z internetu, stáhne vydání ze stránky
[Releases](https://github.com/ONyklicek/wspanel/releases), ověří ho a spustí
instalátor sám:

```sh
V=1.2.0-rc2 ARCH=$(dpkg --print-architecture)
curl -fLO https://github.com/ONyklicek/wspanel/releases/download/v$V/wspanel-$V-linux-$ARCH.tar.gz
curl -fLO https://github.com/ONyklicek/wspanel/releases/download/v$V/SHA256SUMS
sha256sum -c SHA256SUMS --ignore-missing          # musí vypsat: OK
gh attestation verify wspanel-$V-linux-$ARCH.tar.gz --repo ONyklicek/wspanel   # volitelně: původ buildu
tar -xzf wspanel-$V-linux-$ARCH.tar.gz && cd wspanel && sudo ./install
```

`gh attestation verify` ověří, že balíček sestavil workflow tohoto repozitáře
na GitHubu (potřebuje [GitHub CLI](https://cli.github.com)).

### Po instalaci

1. Otevřete adresu panelu a přihlaste se účtem správce.
2. Na **Přehledu** je seznam kroků k bezpečnému provozu: dvoufázové přihlášení,
   notifikace, zálohy mimo server, u pošty reverzní záznam (PTR) a SPF.
3. Založte první web (**Weby → Nový web**).

Panel je pro instalaci na vlastní IP adrese nebo ve vnitřní síti podepsaný
vlastní certifikační autoritou: její kořenový certifikát stáhnete v panelu
(**SSL → Kořenový certifikát**) a nainstalujete do počítačů, které panel otevírají.

### Aktualizace

```sh
curl -fsSL https://github.com/ONyklicek/wspanel/releases/latest/download/get.sh | sudo bash
```

Na serveru, kde už WSPanel je, průvodce nabídne **aktualizaci se stávajícím
nastavením** (instalátor si volby pamatuje v `/etc/wspanel/install.flags`). Bez
otázek: `… | sudo bash -s -- --yes`. Účty, weby, data ani nastavení se nemažou.
Panel je během aktualizace pár minut nedostupný, weby jen na několik sekund
(restart proxy a PHP).

O nové verzi panel řekne sám: jednou denně se podívá do vydání na GitHubu
a novější verzi ukáže na **Přehledu** a v **Zabezpečení → Aktualizace** i s tímto
příkazem; správcům pošle jednu notifikaci. Sám nic neinstaluje.

### Balíčky .deb

#### Co obsahují

| Balíček | Obsah | Architektura |
|---|---|---|
| **`wspanel`** | instalátor, provisioning a zdroják panelu (`/usr/share/wspanel`), příkazy `wspanel-install` a `wspanel-update` | `all` |
| **`wspanel-agent`** | agent `/usr/sbin/panel-agent` (jediná část s právy roota) a nástroj `/usr/bin/wspanel` (klient REST API) | `amd64`, `arm64` |

`wspanel` závisí na `wspanel-agent` stejné verze. Samotná instalace balíčků
na systému nic nenastaví: databáze, webserver, poštu a konfiguraci zakládá
`wspanel-install` (nebo `./install` z vydání).

Instalace jedním příkazem nebo z vydání (`get.sh`, `./install`) dá na server
jen `wspanel-agent`, aby opravy agenta chodily přes apt. Kdo chce celý WSPanel
spravovat přes apt, doinstaluje `wspanel` (viz [Instalace přes apt](#instalace-přes-apt)).

#### Jak se dostane na server

Instalace z vydání ho udělá sama:

1. nainstaluje `wspanel-agent` z balíčku vydání (nic nestahuje),
2. přidá apt repozitář WSPanelu:

   | Soubor | Obsah |
   |---|---|
   | `/etc/apt/sources.list.d/wspanel.sources` | repozitář `https://onyklicek.github.io/wspanel/apt`, kanál podle verze |
   | `/etc/apt/keyrings/wspanel.asc` | klíč, kterým jsou indexy repozitáře podepsané |
   | `/etc/apt/apt.conf.d/52wspanel-unattended` | opravy agenta i v automatických aktualizacích |

3. staré cesty `/usr/local/sbin/panel-agent` a `/usr/local/bin/wspanel` nechá
   jako odkazy na nové (používají je služby a časovače založené dřív).

Že je vše na místě:

```sh
dpkg-query -W wspanel-agent          # wspanel-agent  1.2.0~rc2
panel-agent -version                 # 1.2.0-rc2
apt-cache policy wspanel-agent       # odkud se aktualizuje (kanál)
```

#### Kanály

Repozitář má kanál pro každou řadu verzí: `1.2` pro 1.2.0, 1.2.1, 1.2.2…,
`1.2-pre` pro předběžné verze řady 1.2. Server sleduje kanál verze, kterou má
nainstalovanou, takže apt přinese jen **opravy téže řady**. Na vyšší řadu
(1.3) se přechází instalátorem, aby agent a panel byly ze stejné řady.

#### Aktualizace

Ručně (u serveru s balíčkem `wspanel` pak ještě `wspanel-install --yes`):

```sh
sudo apt update
apt list --upgradable 2>/dev/null | grep wspanel-agent   # je k dispozici oprava?
sudo apt install --only-upgrade wspanel-agent           # nainstaluje ji (s balíčkem wspanel: sudo apt upgrade)
```

Automaticky: s **Zabezpečení → Aktualizace → Automatické bezpečnostní
aktualizace: Zapnout** se opravy agenta instalují denně spolu s bezpečnostními
aktualizacemi Debianu.

Balíček po aktualizaci agenta sám restartuje (`panel-agent.service`). Panel je
pár sekund bez agenta, weby běží dál.

#### Odložení oprav

```sh
sudo apt-mark hold wspanel-agent     # apt ho nebude aktualizovat
sudo apt-mark unhold wspanel-agent   # zase ano
```

#### Ruční instalace souboru .deb

Pro server bez přístupu na GitHub Pages, nebo když chcete konkrétní verzi
agenta. Soubory jsou přiložené ke každému vydání na
[Releases](https://github.com/ONyklicek/wspanel/releases)
(`wspanel-agent_<verze>_<arch>.deb`; GitHub v názvu nahrazuje `~` tečkou):

```sh
V=1.2.0-rc2 ARCH=$(dpkg --print-architecture)
curl -fLO https://github.com/ONyklicek/wspanel/releases/download/v$V/wspanel-agent_${V/-/.}_$ARCH.deb
curl -fLO https://github.com/ONyklicek/wspanel/releases/download/v$V/SHA256SUMS
sha256sum -c SHA256SUMS --ignore-missing                 # musí vypsat: OK
sudo apt install ./wspanel-agent_${V/-/.}_$ARCH.deb
```

Na server bez WSPanelu nemá smysl: agent bez konfigurace a služby, které
zakládá instalátor, neběží. Nejvyšší podporovaná je verze agenta stejné řady,
jako je panel (u panelu 1.2.x agent 1.2.x).

#### Repozitář nepoužívat

```sh
sudo rm /etc/apt/sources.list.d/wspanel.sources /etc/apt/apt.conf.d/52wspanel-unattended
```

Agent se pak aktualizuje jen s instalátorem (a další spuštění instalátoru
repozitář zase přidá). Balíček `wspanel-agent` **neodinstalovávejte**:
`apt remove wspanel-agent` odebere agenta a panel přestane fungovat.

#### Ověření klíče repozitáře

```sh
gpg --show-keys /etc/apt/keyrings/wspanel.asc
```

musí ukázat otisk `51525652EBC466696C479E9DEECED19A06101F1F`. Apt přijme jen
indexy podepsané tímto klíčem. Když repozitář není dostupný (výpadek GitHub
Pages), instalace ani aktualizace nespadne, jen ho při `apt update` vynechá.

### Vydání v soukromém repozitáři

Pro fork v soukromém repozitáři potřebuje `get.sh` token. Nejlépe *fine-grained*
token jen pro ten repozitář s právem *Contents: Read-only*:

```sh
gh release download --repo <vlastník>/<repozitář> --pattern get.sh
sudo env WSPANEL_TOKEN=github_pat_… bash get.sh
```

Server si token nikam neukládá. Panel vidí nové verze soukromého repozitáře jen
s tokenem v `PANEL_RELEASE_TOKEN` v `/srv/panel/shared/.env`. Apt repozitář
na GitHub Pages je i u soukromého repozitáře veřejný (obsahuje jen agenta).

### Ze zdrojáků (vývoj)

```sh
make agent-build
# na serveru (Debian 13, root):
./provision/install.sh --panel-domain=panel.example.com --acme-email=admin@example.com \
  --agent-bin=./build/panel-agent-linux-amd64 --panel-source=./panel \
  --db=mariadb,postgres --php=8.3,8.4 --dns=powerdns
```

Instalace ze zdrojáků nemá číslo verze: nehlásí nové verze a agent není balíček.
Vývoj na Macu: `make dev-up dev-provision dev-demo`, pak https://panel.localhost:8443.
Vydání verze: [docs/vyvoj.md](docs/vyvoj.md#vydání-verze).

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

