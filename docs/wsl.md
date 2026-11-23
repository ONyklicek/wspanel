# WSL (Windows bez Dockeru)

Na Windows může WSPanel běžet **nativně ve WSL2**: Debian 13 se systemd, do
kterého `install.sh` nainstaluje stejný WSPanel jako na Linux server. Je to
lehčí varianta pro Windows a pro [zrcadlo](zrcadlo.md). Nejlepší izolaci má
[Hyper-V](hyper-v.md) (vlastní jádro a IP, vynucená pravidla portů). Ten
doporučujeme, kde to jde. [Docker](docker.md) zůstává pro starší Windows.

| | WSL2 (zrcadlená síť) | Docker Desktop |
|---|---|---|
| Síť | adresy Windows hostitele, bez NAT | NAT a proxy Docker Desktopu |
| Adresy návštěvníků (logy, fail2ban, omezení panelu) | skutečné | adresa Dockeru |
| Aktualizace | z panelu, jako na Linuxu | z panelu, kontejner se nikdy neobnovuje |
| Požadavek | Windows 11 22H2+ / Windows Server 2025 | Docker Desktop |

## Instalace

V PowerShellu spuštěném jako správce, pod uživatelem, pod kterým má server
běžet:

```powershell
.\Install-WSPanelWsl.ps1 -PanelDomain panel.firma.local -AdminEmail admin@firma.local
```

Skript je v `provision/files/windows/` a dá se spustit opakovaně. Udělá tohle:

1. Nainstaluje nebo aktualizuje WSL. Pokud bylo potřeba ho nainstalovat,
   restartujte Windows a spusťte skript znovu.
2. Do `%UserProfile%\.wslconfig` zapíše `networkingMode=mirrored`
   (distribuce má adresy Windows), `vmIdleTimeout=-1` (WSL se samo
   nevypne), `hostAddressLoopback=true` a `dnsTunneling=true`.
3. Vytvoří distribuci `WSPanel` (Debian, starší verzi povýší na 13) se
   systemd a rootem jako výchozím uživatelem. Vypne **interop** a
   **připojování disků Windows**. Jinak by kterýkoli proces ve WSL (třeba
   kód webu) mohl spouštět programy Windows pod uživatelem Windows nebo číst
   disk `C:`. Na řadiči domény by to znamenalo celou doménu. `install.sh`
   ve WSL se zapnutým interopem nebo připojenými disky odmítne pokračovat.
4. Zaregistruje naplánovanou úlohu **WSPanel (WSPanel)**, která distribuci
   spustí se startem Windows a drží ji v běhu. Funguje i bez přihlášeného
   uživatele a bez zvýšených oprávnění. Parametrem `-RunAs FIRMA\wspanel-wsl`
   ji spustíte pod vyhrazeným účtem bez práv správce (doporučeno).
   Distribuce pak patří tomu účtu, proto skript spusťte pod ním.
5. Přidá pravidla **Hyper-V firewallu** pro porty 80, 443, SSH webů, 7443
   (zrcadlo) a port panelu. Se zrcadlenou sítí propouští spojení do WSL
   právě tento firewall.
6. Nainstaluje WSPanel přes `get.sh` vydání (ověřený proti SHA256SUMS).

Další parametry:
- `-Db mariadb,postgres`
- `-Php 8.4`
- `-PanelPort 8443` (bez vlastní domény)
- `-AcmeEmail`
- `-Version 1.11.0`
- `-ExtraPorts 25,465,587,993` (pošta)
- `-InstallerArgs '--mail-hosting=yes --redis=yes'`

## Porty sdílené s Windows

Se zrcadlenou sítí poslouchá Linux i Windows na stejných adresách, takže
jeden port nemohou mít oba:

- **SSH/SFTP webů** je ve výchozím stavu na portu **2222** (`-SshPort`).
  Port 22 zůstává OpenSSH serveru Windows, přes který zrcadlo zapisuje do
  Windows DNS.
- Na Windows nesmí poslouchat nic jiného na 80, 443, 7443 ani na portu
  panelu (například IIS).
- Port 53 patří Windows DNS. Vlastní DNS server WSPanelu (`--dns=powerdns`)
  ve WSL neinstalujte.

## Zrcadlo ve WSL

Postupujte podle [Zrcadlo](zrcadlo.md). Ve WSL se zrcadlenou sítí platí:

- Adresa zrcadla je **IP Windows serveru v LAN**, a to na obou stranách
  párování.
- **Windows DNS:** server `127.0.0.1` (nebo IP Windows), port 22, účet
  `wspanel-dns@firma.local`.
- Svědek je třeba brána LAN.

## Omezení

- Jádro WSL nevynucuje cgroup BPF `SocketBindDeny`: web si může otevřít
  i port, který mu nepatří. `install.sh` ve WSL proto bez
  `--allow-unenforced-socket-bind` skončí s vysvětlením. Skript ho předává,
  protože jeho spuštěním s tím souhlasíte. Pro weby klientů, kteří si
  navzájem nevěří, použijte nativní Linux server ([Bezpečnost](bezpecnost.md)).
- Diskové kvóty klientů na disku WSL (VHDX) obvykle nejdou. Instalace je
  vypne a vypíše to do logu.
- Data leží ve VHDX distribuce (`%LocalAppData%\wsl\…`). Zálohujte je jako
  jakýkoli server, tedy zálohou serveru a kopiemi mimo server
  ([Zálohy](docker.md#zálohy-a-přechod-na-nový-kontejner) fungují stejně).
- Automatický start zajišťuje naplánovaná úloha. Když ji smažete,
  distribuce se po restartu Windows nespustí.
