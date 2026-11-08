# Instalace

## Požadavky

- Čistý server s **Debianem 13 (trixie)**, root přístup.
- Doména pro panel (např. `panel.example.com`) s A/AAAA záznamem na server.
- Otevřené porty 22 (SSH), 80 a 443. S lokálním DNS i 53 (TCP i UDP).
- Odchozí přístup na internet: balíčky, Let's Encrypt, GitHub (FrankenPHP,
  Mailpit, fnm).
- Doporučeno: alespoň 2 GB RAM a 20 GB disku.

## Instalace jedním příkazem (doporučeno)

Na čistém Debianu 13 (amd64 nebo arm64):

```
curl -fsSL https://github.com/<vlastník>/<repozitář>/releases/latest/download/get.sh | sudo bash
```

Skript stáhne nejnovější vydání pro architekturu serveru, ověří ho proti
`SHA256SUMS` daného vydání, rozbalí ho do `/var/lib/wspanel-installer/<verze>/`
a spustí průvodce (viz níže). Na serveru, kde už WSPanel je, týž příkaz
aktualizuje: průvodce nabídne ponechat stávající nastavení.

- Konkrétní verze: `… | sudo env WSPANEL_VERSION=1.2.0 bash`
- Bez otázek: `… | sudo bash -s -- --yes --panel-domain=panel.example.com --acme-email=admin@example.com --db=mariadb --admin-email=admin@example.com`
- Vydání v soukromém repozitáři: token typu *fine-grained* jen pro tento
  repozitář s právem *Contents: Read-only* (GitHub → Settings → Developer
  settings → Personal access tokens). Server si ho nikam neukládá.
  ```
  gh release download --repo <vlastník>/<repozitář> --pattern get.sh
  sudo env WSPANEL_TOKEN=github_pat_… bash get.sh
  ```

Kdo nechce spouštět skript přímo z internetu, stáhne `get.sh`, prohlédne ho
a spustí `sudo bash get.sh`, nebo ověří jeho původ:
`gh attestation verify get.sh --repo <vlastník>/<repozitář>`.

## Instalace z balíčku

Jeden archiv obsahuje instalátor, provisioning, zdroják panelu, agenta
a příkaz `wspanel`:

```
make bundle VERSION=1.0.0 ARCH=amd64     # dist/wspanel-1.0.0-linux-amd64.tar.gz (+ .sha256)
```

Na serveru jako root:

```
tar -xzf wspanel-1.0.0-linux-amd64.tar.gz && cd wspanel && ./install
```

Průvodce (textové okno, šipky a mezerník) se postupně zeptá:

- kde bude panel: na doméně (Let's Encrypt), na IP adrese s portem, na doméně
  s vlastním portem, nebo ve vnitřní síti bez internetu (např. `panel.firma.lan`;
  panel i weby pak dostanou certifikáty od vlastní certifikační autority serveru),
- jestli je server za NATem: veřejná IP adresa a přesměrované porty HTTPS a SSH
  (panel podle nich doporučuje DNS záznamy a ukazuje adresy pro připojení),
- na port SSH a jestli úplně zakázat přihlášení roota (jinak root jen s klíčem),
- které databázové servery, verze PHP a Node instalovat,
- které moduly zapnout (klienti a balíčky, pošta se schránkami a webmailem,
  DNS server, profilování XHProf),
- jestli pošta z webů odchází, nebo se zachytává v Mailpitu,
- jestli omezit panel na vybrané adresy,
- na prvního správce: jméno, e-mail a heslo (prázdné = vygeneruje se
  a ukáže jednou).

Na konci ukáže souhrn a teprve potom instaluje.

Bez otázek (automatizace) se stejné volby zadávají jako parametry, viz
[Volby](#volby-installsh):

```
./install --yes --panel-domain=panel.example.com --acme-email=admin@example.com \
  --db=mariadb --php=8.3,8.4 --mail-hosting=yes --admin-email=admin@example.com
```

Aktualizace: rozbalte novější balíček a spusťte `./install`. Na nainstalovaném
serveru průvodce nabídne aktualizaci se stávajícím nastavením (instalátor si
volby pamatuje v `/etc/wspanel/install.flags`), nebo projití otázek znovu.
Bez otázek stačí `./install --yes`: bez dalších voleb použije ty uložené.
Účty, weby, data ani nastavení se nemažou a správce se znovu nezakládá.

Opravy agenta přicházejí i přes apt: instalace z vydání nainstaluje agenta
jako balíček `wspanel-agent` a přidá apt repozitář WSPanelu
(`/etc/apt/sources.list.d/wspanel.sources`, podepsaný klíčem
`/etc/apt/keyrings/wspanel.asc`). Repozitář má kanál pro každou řadu verzí
(`1.2` pro 1.2.x, `1.2-pre` pro předběžné verze), takže `apt upgrade`
a automatické aktualizace (Zabezpečení → Aktualizace) přinesou jen opravy
agenta téže řady. Na vyšší řadu (1.3) se přechází instalátorem, s panelem
stejné verze. Když repozitář není dostupný, instalace ani aktualizace
nespadne, jen ho vynechá.

O nové verzi panel řekne sám: jednou denně se podívá do vydání na GitHubu
(repozitář, ze kterého byl nainstalován) a novější verzi ukáže na Přehledu
a v **Zabezpečení → Aktualizace** i s příkazem k aktualizaci; správcům pošle
jednu notifikaci (kanály s událostí „Nový problém“). Panel sám nic
neinstaluje. Vydání v soukromém repozitáři vidí jen s tokenem
`PANEL_RELEASE_TOKEN` v `/srv/panel/shared/.env`.

## Postup ze zdrojáků

1. Na svém počítači sestavte agenta (Go 1.25+):
   ```
   make agent-build        # build/panel-agent-linux-amd64 a -arm64
   ```
2. Zkopírujte na server repozitář (nebo jen `provision/`, `panel/` a binárku agenta).
3. Na serveru jako root spusťte instalaci:
   ```
   ./provision/install.sh \
     --panel-domain=panel.example.com \
     --acme-email=admin@example.com \
     --agent-bin=./build/panel-agent-linux-amd64 \
     --panel-source=./panel \
     --db=mariadb,postgres \
     --php=8.3,8.4 \
     --dns=powerdns
   ```
4. Vytvořte účet správce:
   ```
   cd /srv/panel/current && runuser -u panel -- php artisan wire:user
   ```
5. Otevřete `https://panel.example.com` a přihlaste se.

Instalace je **idempotentní**: stejným příkazem panel i aktualizujete. Spusťte
ho znovu s novou verzí agenta a panelu. Data, weby ani nastavení se nemažou.

## Volby `install.sh`

| Volba | Výchozí | Význam |
|---|---|---|
| `--panel-domain=` | (povinné bez `--panel-port`) | doména panelu |
| `--panel-port=` | `443` | port panelu (např. `8443`); viz Adresa panelu níže |
| `--clients=` | `no` | `yes` = klienti a balíčky (hostování pro víc klientů); později Server → Moduly |
| `--mail-hosting=` | `no` | `yes` = schránky, webmail a antispam (pošta pro domény); později Server → Moduly |
| `--xhprof=` | `no` | `yes` = XHProf + XHGui, profilování zapínané u každého webu; později Server → Moduly |
| `--acme-email=` | `admin@<panel>` | e-mail pro Let's Encrypt |
| `--agent-bin=` | (povinné) | sestavená binárka agenta |
| `--panel-source=` | – | adresář `panel/` (bez něj se nainstaluje jen systém) |
| `--db=` | `none` | `mariadb`, `postgres`, `mariadb,postgres` nebo `none`; další engine jde doinstalovat z panelu |
| `--php=` | `8.4` | verze PHP-FPM pro classic weby, např. `8.2,8.3,8.4` |
| `--node=` | `22,24` | major verze Node pro SSR weby (`none` = jen jedna pro build panelu) |
| `--dns=` | `none` | `powerdns` = server je autoritativní DNS pro zóny z panelu |
| `--mail=` | `send` | výchozí režim pošty nových webů: `send` (odesílat) nebo `capture` (zachytávat v Mailpitu) |
| `--ssh-port=` | `22` | port SSH |
| `--no-root-ssh` | – | zakáže přihlášení roota přes SSH (nejdřív si ověřte jiný účet s klíčem) |
| `--public-ipv4=` / `--public-ipv6=` | zjistí se | veřejné adresy pro navrhované DNS záznamy (za NATem zadejte ručně) |
| `--public-https-port=` / `--public-ssh-port=` | 443 / 22 | porty, jak je vidí klienti (jen pro přesměrování portů, např. dev kontejner) |
| `--panel-allow=` | veřejný | panel jen z těchto adres/sítí, např. `10.8.0.0/24,203.0.113.7`; `any` = zase veřejný (viz [Bezpečnost](bezpecnost.md#panel-jen-z-vnitřní-sítě)) |
| `--local-certs` | – | certifikáty od vlastní certifikační autority serveru místo Let's Encrypt (panel i weby ve vnitřní síti, vývoj) |
| `--allow-unenforced-socket-bind` | – | jen Docker Desktop (viz [Bezpečnost](bezpecnost.md)) |

## Co instalace nastaví

- **PHP-FPM** zvolených verzí a **FrankenPHP** (edge proxy, web tier, worker
  režim). Binárky z GitHubu se ověřují otiskem sha256.
- **MariaDB / PostgreSQL** podle `--db`, **Valkey** (Redis) pro cache a fronty.
- **Node** přes fnm v `/opt/fnm`, **Composer**, **Git**.
- **Postfix** (jen odchozí, poslouchá na loopbacku), **OpenDKIM** a **Mailpit**.
- **PowerDNS** (s `--dns=powerdns`).
- **phpMyAdmin** a **Adminer** na `https://<panel>/dbtools/`.
- **SSH**: klíče a hesla webů spravuje panel, fail2ban hlídá pokusy o přihlášení.
- **UFW** firewall: povolí SSH, 80, 443 (a 53 s DNS).
- Systemd služby `panel-agent`, `panel-proxy`, `panel-web`, `panel-scheduler`.

## Po instalaci

1. **Účet administrátora:**
   ```bash
   cd /srv/panel/current && runuser -u panel -- php artisan wire:user
   ```
   Zeptá se na jméno, e-mail a heslo. Registrace z webu je vypnutá, další účty
   zakládejte stejně. Pak se přihlaste na `https://<panel>`.
2. **DNS:** domény webů musí mířit na server (A/AAAA), jinak nepůjde vydat
   certifikát. Viz [DNS](dns.md).
3. **Pošta:** u každé domény, ze které weby posílají e-maily, vytvořte DKIM klíč
   a nastavte SPF/DKIM/DMARC. Viz [Pošta](posta.md). Pokud poskytovatel serveru
   blokuje port 25, nastavte relay.
4. **Reverzní záznam (PTR)** IP adresy nastavte u poskytovatele serveru na
   doménu panelu. Bez něj servery příjemců poštu často odmítají.
5. **Zálohy:** nastavte plán záloh databází (Databáze → web → Plán záloh) a
   zálohujte i `/srv/sites` (např. rsync/borg na jiný stroj).

## Kde co na serveru je

| Cesta | Obsah |
|---|---|
| `/srv/sites/<doména>` | weby |
| `/srv/panel` | panel (Laravel), stav v SQLite `shared/database.sqlite` |
| `/etc/panel-agent/agent.json` | konfigurace agenta (root, 0600) |
| `/var/log/panel-agent/audit.log` | audit všech operací |
| `/var/log/panel-agent/deploys/` | logy deployů |
| `/var/backups/wspanel` | zálohy databází |
| `/etc/panel-proxy`, `/etc/panel-web` | konfigurace proxy (generuje agent) |


## Adresa panelu

Panel nemusí mít vlastní subdoménu:

| Jak | Volby | Panel je na |
|---|---|---|
| vlastní subdoména | `--panel-domain=panel.example.com` | `https://panel.example.com` (Let's Encrypt) |
| port domény webu | `--panel-domain=example.com --panel-port=8443` | `https://example.com:8443`; web `example.com` dál běží na 443 |
| jen port, bez domény | `--panel-port=8443` | `https://<IP serveru>:8443` s certifikátem vlastní CA panelu (prohlížeč ji nezná, dokud si nenainstalujete kořenový certifikát: SSL → Kořenový certifikát) |

Instalace port panelu otevře ve firewallu a chrání ho proti zablokování.

**Pozor u sdílené domény:** prohlížeče posílají cookies podle domény, ne
podle portu. Web na stejné doméně (`example.com` na 443) tak dostává
i přihlašovací cookie panelu. Proto na doméně panelu smí běžet jen web
správce (klient ji nezaloží ani jako alias) a má to být web, kterému
věříte. Pro hostování cizích webů dejte panelu vlastní subdoménu nebo IP.
Edge předává panel web tieru pod interním jménem, takže panel nikdy
nekoliduje s webem téže domény; odkazy panel skládá z `APP_URL`.
