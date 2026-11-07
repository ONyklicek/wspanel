# Instalace

## Požadavky

- Čistý server s **Debianem 13 (trixie)**, root přístup.
- Doména pro panel (např. `panel.example.com`) s A/AAAA záznamem na server.
- Otevřené porty 22 (SSH), 80 a 443. S lokálním DNS i 53 (TCP i UDP).
- Odchozí přístup na internet: balíčky, Let's Encrypt, GitHub (FrankenPHP,
  Mailpit, fnm).
- Doporučeno: alespoň 2 GB RAM a 20 GB disku.

## Postup

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
| `--panel-domain=` | (povinné) | doména panelu |
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
| `--local-certs` | – | jen pro vývoj: certifikáty z lokální CA místo Let's Encrypt |
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

1. **DNS:** domény webů musí mířit na server (A/AAAA), jinak nepůjde vydat
   certifikát. Viz [DNS](dns.md).
2. **Pošta:** u každé domény, ze které weby posílají e-maily, vytvořte DKIM klíč
   a nastavte SPF/DKIM/DMARC. Viz [Pošta](posta.md). Pokud poskytovatel serveru
   blokuje port 25, nastavte relay.
3. **Reverzní záznam (PTR)** IP adresy nastavte u poskytovatele serveru na
   doménu panelu. Bez něj servery příjemců poštu často odmítají.
4. **Zálohy:** nastavte plán záloh databází (Databáze → web → Plán záloh) a
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
