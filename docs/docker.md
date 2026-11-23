# Docker (Windows a jiné hostitele bez Debianu)

WSPanel patří nativně na Debian 13 ([Instalace](instalace.md)). Kde to nejde,
typicky **Windows** (Docker Desktop s WSL2), běží celý jako **jeden kontejner**:
uvnitř je kompletní Debian 13 se systemd a instalace v něm proběhne stejně jako
na serveru. Kontejner tedy není „aplikace“, ale **celý server**.

## Rychlý start

Potřeba: Docker Desktop (Windows: backend WSL2) nebo Docker Engine na Linuxu,
alespoň 4 GB RAM pro Docker (Windows: `%UserProfile%\.wslconfig`, `memory=`).

```sh
mkdir wspanel && cd wspanel
curl -fsSLO https://raw.githubusercontent.com/ONyklicek/wspanel/main/docker/compose.yaml
curl -fsSL -o .env https://raw.githubusercontent.com/ONyklicek/wspanel/main/docker/.env.example
# v .env vyplnit aspoň WSPANEL_TAG a WSPANEL_ADMIN_EMAIL
docker compose up -d
docker compose logs -f     # průběh instalace (pár minut), na konci účet a heslo
```

Ve Windows totéž v PowerShellu (`curl.exe` místo `curl`), nebo oba soubory
stáhnout ručně do jedné složky.

Panel pak běží na `https://<WSPANEL_PANEL_DOMAIN>` (s doménou a Let's Encrypt),
bez domény na `https://<adresa stroje>:8443` s vlastní certifikační autoritou
panelu. Heslo prvního účtu je i v kontejneru:
`docker exec wspanel cat /var/log/wspanel-docker-setup.log`. Po přihlášení ho
změňte.

## Návod: Windows krok za krokem

1. **WSL2 a Docker Desktop.** V PowerShellu jako správce `wsl --install`
   a restart. Pak nainstalujte [Docker Desktop](https://www.docker.com/products/docker-desktop/)
   a v *Settings → General* nechte zapnuté *Use the WSL 2 based engine*
   a *Start Docker Desktop when you sign in*. Na serveru bez přihlášeného
   uživatele nastavte automatické přihlášení, jinak Docker po restartu
   Windows nenaběhne.
2. **Paměť pro Docker.** Do `%UserProfile%\.wslconfig` napište:
   ```ini
   [wsl2]
   memory=6GB
   ```
   a v PowerShellu spusťte `wsl --shutdown`. Docker Desktop se sám znovu spustí.
3. **Soubory.** V PowerShellu:
   ```powershell
   mkdir C:\wspanel; cd C:\wspanel
   curl.exe -fsSLO https://raw.githubusercontent.com/ONyklicek/wspanel/main/docker/compose.yaml
   curl.exe -fsSL -o .env https://raw.githubusercontent.com/ONyklicek/wspanel/main/docker/.env.example
   notepad .env
   ```
   V `.env` nastavte aspoň `WSPANEL_TAG` (verze z [vydání](https://github.com/ONyklicek/wspanel/releases)),
   `WSPANEL_ADMIN_EMAIL`, s doménou ještě `WSPANEL_PANEL_DOMAIN`
   a `WSPANEL_ACME_EMAIL` a veřejnou adresu `WSPANEL_PUBLIC_IPV4`.
4. **DNS a porty.** Doména panelu (a domény webů) míří A záznamem na veřejnou
   adresu stroje. Na routeru přesměrujte porty 80, 443 (TCP i UDP), 2222
   a 8443 na tento počítač. Ve Windows je povolte (PowerShell jako správce):
   ```powershell
   New-NetFirewallRule -DisplayName WSPanel -Direction Inbound -Protocol TCP -LocalPort 80,443,2222,8443 -Action Allow
   New-NetFirewallRule -DisplayName "WSPanel HTTP/3" -Direction Inbound -Protocol UDP -LocalPort 443 -Action Allow
   ```
5. **Spuštění.**
   ```powershell
   docker compose up -d
   docker compose logs -f
   ```
   Instalace trvá několik minut. Skončí řádkem `[wspanel] Done. Panel: …`
   a nad ním jsou `Panel account` a `Password`. Sledování logu ukončíte Ctrl+C,
   kontejner běží dál.
6. **Přihlášení.** Otevřete adresu panelu, přihlaste se a změňte heslo. Pak
   nastavte **Server → Zálohy mimo server** (viz [Zálohy](#zálohy-a-přechod-na-nový-kontejner)).

Do kontejneru se dostanete příkazem `docker exec -it wspanel bash` (jako root
na serveru). Zastavení a spuštění: `docker compose stop` / `docker compose start`.

## Nastavení (`.env`)

Platí jen pro **první start** (instalaci). Pak má server vlastní nastavení
v panelu a změny v `.env` už nic nedělají.

| Proměnná | Výchozí | Význam |
|---|---|---|
| `WSPANEL_TAG` | – (povinné) | verze image, např. `1.10.0`; nikdy `latest` |
| `WSPANEL_ADMIN_EMAIL` | – (povinné) | první účet panelu |
| `WSPANEL_PANEL_DOMAIN` | – | doména panelu (A záznam na stroj); prázdná = adresa a port |
| `WSPANEL_ACME_EMAIL` | – | účet Let's Encrypt |
| `WSPANEL_PANEL_PORT` | `8443` | port panelu bez vlastní domény |
| `WSPANEL_DB` | `mariadb` | `none`, `mariadb`, `postgres`, `mariadb,postgres` |
| `WSPANEL_PHP` | `8.4` | verze PHP-FPM, např. `8.3,8.4` |
| `WSPANEL_PUBLIC_HTTPS_PORT` | `443` | port HTTPS na hostiteli |
| `WSPANEL_PUBLIC_SSH_PORT` | `2222` | port SSH/SFTP webů na hostiteli |
| `WSPANEL_PUBLIC_IPV4` | zjistí se | veřejná adresa (za NATem, v Docker Desktopu ji nastavte) |
| `WSPANEL_SSH_KEY` | – | veřejný klíč pro roota v kontejneru |
| `WSPANEL_ARGS` | – | další volby instalace ([Volby](instalace.md#volby-installsh)), např. `--mail-hosting=yes --clients=yes` |
| `WSPANEL_SOCKET_BIND` | `auto` | viz [Omezení](#omezení-oproti-nativní-instalaci) |
| `WSPANEL_RESTORE`, `WSPANEL_RECOVERY_KEY` | – | obnova ze zálohy serveru, viz níže |

Porty, které kontejner vystavuje, jsou v `compose.yaml` (80, 443 TCP i UDP,
2222 → 22, 8443). Pošta a herní servery mají řádky připravené zakomentované.
Ve Windows je povolte i ve Windows Defender Firewallu.

## Aktualizace

**Jen přes panel** (Zabezpečení → Aktualizace), stejně jako na serveru:
aktualizace proběhne uvnitř kontejneru. Image se **nemění**.

> **Pozor:** `docker compose down`, změna `WSPANEL_TAG` nebo čehokoli
> v `compose.yaml`, `docker compose pull && up` ani „Delete“ v Docker Desktopu
> nepoužívejte. Docker by kontejner vytvořil znovu a s ním zmizí všechny weby,
> databáze a nastavení. `docker compose stop`/`start`, `restart` a restart
> Windows jsou v pořádku.

Proto image nemá štítek `latest`. `stable` ukazuje na nejnovější vydání, ale
do `.env` patří vždy konkrétní verze.

## Zálohy a přechod na nový kontejner

Data jsou uvnitř kontejneru (ve Windows v disku WSL2). Nastavte proto
**Server → Zálohy mimo server** (S3, FTP, SMB/NAS) a plánované zálohy webů
([Záloha serveru](weby.md#záloha-serveru-a-obnova-po-havárii)).

Nový kontejner (jiný stroj, nová verze image, havárie) se obnoví ze zálohy
serveru:

1. Starý kontejner zastavte. Do složky `restore/` vedle `compose.yaml`
   položte `server-….wspbak` z úložiště.
2. Do `.env` přidejte
   `WSPANEL_RESTORE=/restore/server-….wspbak` a `WSPANEL_RECOVERY_KEY=<klíč pro obnovu>`.
   Bez úložiště mimo server: celý `/var/backups/wspanel` do `restore/backups`
   a `WSPANEL_ARGS=--backups=/restore/backups`.
3. `docker compose up -d` (u nového kontejneru jiný název projektu nebo složka).
   První start místo instalace obnoví server se stejnými volbami a stáhne
   zálohy webů, databází a pošty.
4. Klíč z `.env` smažte.

## Omezení oproti nativní instalaci

- **Adresy návštěvníků:** Docker Desktop posílá spojení přes vlastní proxy,
  takže kontejner vidí adresu Dockeru místo klienta. Logy, statistiky,
  fail2ban a omezení přístupu k panelu (`--panel-allow`) pak nefungují podle
  adres návštěvníků. Na Linuxu s Docker Engine adresy zůstávají.
- **Porty aplikací:** Docker Desktop nevynucuje cgroup BPF `SocketBindDeny`.
  Image tam (`WSPANEL_SOCKET_BIND=auto`) zapne `--allow-unenforced-socket-bind`:
  Node SSR a Reverb poběží, ale webům nikdo nebrání bindovat cizí porty
  ([Bezpečnost](bezpecnost.md)). Pro weby různých klientů, kteří si nevěří,
  použijte nativní server.
- **Diskové kvóty klientů** na overlay disku kontejneru nejdou, instalace je
  vypne (v logu „Client disk quotas: off …“).
- **Firewall (UFW)** platí jen uvnitř kontejneru; navenek rozhodují porty
  v `compose.yaml` a firewall Windows.
- **Privilegovaný kontejner:** systemd, ACL a nftables to vyžadují. Kontejner
  má proto k hostiteli blízko, jako by byl samotný server. Na stroji s ním
  nepouštějte nic, čemu nevěříte.

## Vlastní image

Ze zdrojáků, pro architekturu tohoto stroje:

```sh
make docker-image VERSION=1.10.0-test ARCH=arm64   # wspanel:1.10.0-test
make docker-smoke VERSION=1.10.0-test ARCH=arm64   # první start, panel, restart
```

V `.env` pak `WSPANEL_IMAGE=wspanel` a `WSPANEL_TAG=1.10.0-test`. Vlastní build
nezná repozitář vydání, takže aktualizace z panelu v něm nefungují.

Vydání (tag `v*`) staví image pro amd64 i arm64 a publikuje ho jako
`ghcr.io/onyklicek/wspanel:<verze>` s ověřením původu
(`gh attestation verify oci://ghcr.io/onyklicek/wspanel:<verze> --repo ONyklicek/wspanel`).
