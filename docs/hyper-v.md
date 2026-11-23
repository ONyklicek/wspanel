# Hyper-V (Windows)

Nejlepší způsob, jak provozovat WSPanel na Windows, je **virtuální stroj
Hyper-V** s Debianem 13. Je to plnohodnotný Linux server s vlastním jádrem,
takže platí vše jako na fyzickém serveru:

- **úplná izolace od Windows:** web ve VM se k Windows nedostane jinak než
  přes síť, jako kterýkoli jiný stroj v LAN,
- **vynucená pravidla portů webů** (cgroup socket-bind) a **diskové kvóty
  klientů**,
- **vlastní IP adresa v LAN:** žádné sdílení portů s Windows (řadič domény
  si nechá 22, 53, 88, 389, 445…) a žádný NAT,
- aktualizace, zálohy a obnova jako na každém serveru.

Na Windows je to jediná podporovaná varianta.

Hyper-V je ve Windows Serveru (role) i ve Windows 10/11 Pro a Enterprise.

## Instalace

1. V PowerShellu spuštěném jako správce:
   ```powershell
   .\New-WSPanelVM.ps1 -Name WSPanel -MemoryGB 4 -Cpus 2 -DiskGB 80
   ```
   Skript je v `provision/files/windows/` a dá se spustit opakovaně. Udělá tohle:
   - zapne Hyper-V (poprvé je potřeba restart a druhé spuštění),
   - vytvoří externí virtuální přepínač na síťové kartě LAN; síť Windows
     při tom na pár sekund vypadne,
   - stáhne Debian 13 netinst a ověří ho proti `SHA256SUMS` Debianu,
   - vytvoří VM generace 2 se Secure Boot, s pevnou pamětí (databáze se
     řídí tím, kolik paměti vidí), se spouštěním s Windows a bez
     automatických checkpointů,
   - VM spustí a otevře její konzoli.
2. **V konzoli nainstalujte Debian.** Z „Software selection“ nechte jen
   **SSH server** a **standard system utilities**. Adresu dejte VM pevnou,
   nebo ji rezervujte v DHCP.
3. Po instalaci vyjměte ISO:
   `Get-VMDvdDrive -VMName WSPanel | Remove-VMDvdDrive`
4. **Ve VM nainstalujte WSPanel** jako na každém serveru ([Instalace](instalace.md)):
   ```bash
   curl -fsSL https://github.com/onyklicek/wspanel/releases/latest/download/get.sh | sudo bash
   ```

## Zrcadlo v Hyper-V

Postupujte podle [Zrcadlo](zrcadlo.md). Adresou zrcadla je **vlastní IP VM**
v LAN, nikoli adresa Windows.

**Windows DNS:** jako server zadejte IP řadiče domény, port 22 a účet
`wspanel-dns@firma.local`. VM je pro řadič domény obyčejný stroj v síti
a k Windows DNS se připojuje přes OpenSSH stejně jako primár.

## Doporučení

- **Checkpointy** nepoužívejte místo záloh. Snímek běžící databáze není
  záloha a vrácení VM na starší checkpoint by se zrcadlem nesouhlasilo
  (epocha, replikace). Zálohujte zálohou serveru a kopiemi mimo server.
- **Hyper-V na řadiči domény** Microsoft podporuje, ale doporučuje, aby řadič
  domény nebyl současně hostitelem virtuálních strojů. Lepší je samostatný
  Windows hostitel.
- Paměť a procesory nastavte podle webů. Pro zrcadlo stačí to samé co má
  primár, protože po převzetí ponese celý provoz.
