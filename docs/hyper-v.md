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

VM se dá vytvořit skriptem, nebo ručně v Hyper-V Manageru. Výsledek je
stejný. Když je hostitelem **řadič domény**, přečtěte si nejdřív
[Hyper-V na řadiči domény](#hyper-v-na-řadiči-domény).

## Instalace skriptem

1. V PowerShellu spuštěném jako správce:
   ```powershell
   .\New-WSPanelVM.ps1 -Name WSPanel -MemoryGB 4 -Cpus 2 -DiskGB 80
   ```
   Skript je v `provision/files/windows/` a dá se spustit opakovaně. Udělá tohle:
   - zapne Hyper-V (poprvé je potřeba restart a druhé spuštění),
   - vytvoří externí virtuální přepínač na síťové kartě LAN; síť Windows
     při tom na pár sekund vypadne. S `-NetAdapter 'Ethernet 2' -Dedicated`
     použije samostatnou síťovou kartu jen pro VM a síti Windows se nedotkne,
   - stáhne Debian 13 netinst a ověří ho proti `SHA256SUMS` Debianu,
   - vytvoří VM generace 2 se Secure Boot, s pevnou pamětí (databáze se
     řídí tím, kolik paměti vidí), se spouštěním s Windows a bez
     automatických checkpointů. S `-Path D:\Hyper-V` ji uloží jinam než na
     výchozí disk,
   - varuje, když by disk VM ležel na stejném disku jako databáze AD,
   - VM spustí a otevře její konzoli.
2. Pokračujte [instalací Debianu](#instalace-debianu-a-wspanelu).

## Ruční instalace (Hyper-V Manager)

### 1. Role Hyper-V

- **Windows Server:** Server Manager → Add Roles and Features → **Hyper-V**.
  Při průvodci virtuální přepínač ani migraci **nevytvářejte**, uděláte je
  v dalším kroku.
- **Windows 10/11:** Ovládací panely → Programy → Zapnout nebo vypnout
  funkce systému Windows → **Hyper-V**.

Následuje restart.

### 2. Virtuální přepínač

Hyper-V Manager → **Virtual Switch Manager** → New virtual network switch →
**External**:

- **Name:** `WSPanel LAN`.
- **External network:** síťová karta do LAN.
- **Allow management operating system to share this network adapter:**
  - **vypnuto**, když má VM samostatnou síťovou kartu (doporučeno). Síť
    Windows se tím nezmění vůbec.
  - **zapnuto**, když má počítač jen jednu kartu. Síť Windows na pár sekund
    vypadne a její adresa se přesune na nový adaptér „vEthernet (WSPanel
    LAN)“. Na řadiči domény potom proveďte
    [kontrolu po vytvoření přepínače](#kontrola-po-vytvoření-přepínače).

### 3. Virtuální stroj

Hyper-V Manager → New → **Virtual Machine**:

| Krok průvodce | Nastavení |
|---|---|
| Name and Location | `WSPanel`; umístění na datovém disku (např. `D:\Hyper-V`), ne na disku s databází AD |
| Generation | **Generation 2** |
| Memory | 4096 MB (podle webů), **Use Dynamic Memory vypnuto** |
| Networking | `WSPanel LAN` |
| Virtual Hard Disk | nový, 80 GB (podle webů) |
| Installation Options | Install from bootable image: Debian 13 netinst (`debian-13.*-amd64-netinst.iso` z debian.org) |

Pak v **Settings** VM:

- **Security:** Enable Secure Boot, šablona **Microsoft UEFI Certificate
  Authority**. Se šablonou „Microsoft Windows“ Debian nenastartuje.
- **Processor:** 2 a více.
- **Checkpoints:** Production checkpoints a **vypnout** „Use automatic
  checkpoints“. Checkpoint běžící databáze není záloha (viz
  [Doporučení](#doporučení)).
- **Automatic Start Action:** Always start, **startup delay 30–60 s**, aby
  se napřed rozběhla síť, AD a DNS hostitele.
- **Automatic Stop Action:** **Shut down the guest operating system**.
  Databáze se tak při vypnutí Windows ukončí čistě.
- **Integration Services:** vše zapnuté.

Pak VM spusťte (Start) a otevřete konzoli (Connect).

## Instalace Debianu a WSPanelu

1. **V konzoli nainstalujte Debian.**
   - Síť: **pevná adresa** mimo rozsah DHCP (nebo rezervace v DHCP),
     brána, a jako DNS server **IP řadiče domény**, aby VM znala vaše
     interní zóny.
   - Doména (domain name): vaše doména, třeba `firma.local`. VM se do domény
     **nepřipojuje**, jde jen o příponu jmen.
   - Ze „Software selection“ nechte jen **SSH server** a **standard system
     utilities**.
2. Po instalaci vyjměte ISO:
   `Get-VMDvdDrive -VMName WSPanel | Remove-VMDvdDrive`
3. **Ve VM nainstalujte WSPanel** jako na každém serveru ([Instalace](instalace.md)):
   ```bash
   curl -fsSL https://github.com/onyklicek/wspanel/releases/latest/download/get.sh | sudo bash
   ```
4. Volitelně: v DNS Manageru založte pro VM záznam A (`wspanel-vm.firma.local`).

## Hyper-V na řadiči domény

Microsoft takovou kombinaci podporuje, ale doporučuje, aby řadič domény
nebyl současně hostitelem VM. Lepší je samostatný Windows hostitel. Když to
jinak nejde, dodržte tohle a AD ani DNS to nenaruší.

### Co se v AD nemění

VM **nepřipojujte do domény** a nedávejte jí roli DNS, DHCP ani řadiče
domény. Pro AD je to obyčejný stroj v síti: nezakládá se pro ni účet
počítače, nemění se schéma, zásady skupiny (GPO) ani replikace.

### Před instalací

- **Restart:** instalace role Hyper-V restartuje řadič domény. Naplánujte ji
  mimo pracovní dobu. Když je řadič domény jediný, bude po dobu restartu
  v LAN nedostupné přihlašování i DNS.
- **Záloha:** udělejte zálohu stavu systému řadiče domény (Windows Server
  Backup → System State), ať je k čemu se vrátit.
- **Druhý řadič domény** (s DNS) je doporučený tak jako tak. Restarty
  a výpadky prvního pak klienti nepoznají.

### Disk

Databáze AD (`NTDS.dit`), SYSVOL a logy leží obvykle na `C:`. Když disk VM
roste (dynamically expanding) a zaplní `C:`, **AD se zastaví**. Proto:

- VM ukládejte na **jiný disk** (např. `D:\Hyper-V`) a nastavte to i jako
  výchozí: Hyper-V Settings → Virtual Hard Disks / Virtual Machines,
- nebo použijte disk VM pevné velikosti (fixed size), který už neroste.

Kde je databáze AD, ukáže:
`Get-ItemProperty HKLM:\SYSTEM\CurrentControlSet\Services\NTDS\Parameters | Select 'DSA Database file'`.

### Paměť

Pevná paměť VM se odečte od hostitele. Řadiči domény nechte **alespoň 4 GB**
volné paměti, jinak bude AD a DNS pomalé. Součet paměti VM tedy musí být
o 4 GB menší než paměť hostitele.

### Síť

- Nejbezpečnější je **samostatná síťová karta pro VM** s vypnutým „Allow
  management operating system to share this network adapter“. Síť řadiče
  domény zůstane netknutá.
- Při jediné kartě vytvořte přepínač mimo pracovní dobu a pak udělejte
  kontrolu níže.
- Na kartě řadiče domény neměňte nic jiného: adresu, DNS, VLAN ani teaming
  (NIC teaming na kartě přepínače nahradí Hyper-V „Switch Embedded
  Teaming“, to je samostatná změna, kterou dělejte jen s plánem).

### Kontrola po vytvoření přepínače

Jen při sdílené kartě, v PowerShellu jako správce:

```powershell
ipconfig /all            # vEthernet (WSPanel LAN): stejná pevná IP, maska, brána a DNS jako dřív
ipconfig /registerdns    # řadič domény znovu zaregistruje své záznamy
dcdiag /test:dns /v      # DNS řadiče domény v pořádku
nltest /dsgetdc:firma.local
```

V **DNS Manageru** → server → Properties → **Interfaces**: když je
nastaveno „Only the following IP addresses“, zkontrolujte, že je IP
serveru zaškrtnutá (po přesunu na nový adaptér může chybět). Pak z jiného
počítače v LAN: `nslookup firma.local 192.168.1.5`.

Kdyby něco nesedělo, přepínač smažte (Virtual Switch Manager → Remove).
Adresa se vrátí na původní kartu.

### Antivir

Microsoft Defender na Windows Serveru po instalaci role Hyper-V sám vyloučí
soubory VM ze skenování. **Jiný antivirus** nastavte podle Microsoftu:
vyloučit složky VM a disků (`*.vhdx`, `*.avhdx`) a procesy `vmms.exe`
a `vmwp.exe`. Skenování disku běžící VM ji zpomaluje a může ji poškodit.

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
- Paměť a procesory nastavte podle webů. Pro zrcadlo stačí to samé co má
  primár, protože po převzetí ponese celý provoz.
