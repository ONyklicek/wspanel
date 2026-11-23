# Windows DNS pro zrcadlo

Zrcadlo po přepnutí přepíše A záznamy webů ve **Windows DNS** na adresu
nového primáru ([Zrcadlo](zrcadlo.md), krok 6). Panel se k tomu připojuje
přes **OpenSSH na řadiči domény** k vlastnímu účtu, který smí spustit jen
jeden skript: `wspanel-dns.ps1`. Ten mění **jen záznamy A/AAAA** jen ve
vámi povolených zónách a nic jiného nemaže.

Řadič domény se dá připravit skriptem `Install-WSPanelDns.ps1`, nebo ručně
podle této stránky. Výsledek je stejný. Obě cesty jsou navržené tak, aby
**nenarušily AD**.

## Co se na řadiči domény změní

| Změna | Dopad na AD |
|---|---|
| Funkce Windows **OpenSSH Server** a služba `sshd` | žádný; bez restartu, port 22 jen pro oba servery |
| Uživatel **`wspanel-dns`** (jen člen Domain Users) | běžný doménový účet, heslo nikdo nezná |
| Oprávnění účtu na **objektu zóny** v AD (záznamy zóny) | jen vybrané zóny; ne server DNS, ne jiné zóny, ne `_msdcs` |
| Oprávnění účtu ve jmenném prostoru **WMI `root\MicrosoftDNS`** | správa DNS přes PowerShell; ne zabezpečení jmenného prostoru |
| Složka `C:\ProgramData\WSPanel` (skript, zóny, klíče) | žádný |
| Blok `Match User` v `C:\ProgramData\ssh\sshd_config` | jen pro účet `wspanel-dns` |

**Nemění se:** schéma AD, zásady skupiny (GPO), skupiny Domain Admins,
DnsAdmins ani jiné privilegované skupiny, nastavení serveru DNS, ostatní
zóny, replikace. Řadič domény se nerestartuje.

**Nedělejte:**

- nedávejte účet do **DnsAdmins** ani **Domain Admins**, pokud to není
  nutné (viz [Když delegace nestačí](#když-delegace-nestačí)),
- nepřidávejte oprávnění na **celý server DNS** (DNS Manager → server →
  Security). To je skoro totéž co DnsAdmins,
- neotevírejte port 22 celé síti ani do internetu,
- nepovolujte účtu přihlášení heslem.

## Než začnete

- Zóny musí být **uložené v Active Directory** (DNS Manager → zóna →
  Properties → Type: „Active Directory-Integrated“). Jen u nich jde dát
  práva na jednu zónu.
- Na **každém** serveru zrcadla: Zrcadlo → **Windows DNS**. Vyplňte server,
  port 22, účet `wspanel-dns@firma.local` a zóny. Panel pak ukáže
  **veřejný klíč** serveru. Zkopírujte si oba.
- Soubory `wspanel-dns.ps1` a `Install-WSPanelDns.ps1` jsou ve zdrojích
  WSPanelu (GitHub) a v balíčku vydání ve složce
  `provision/files/windows/`. Přeneste je na řadič domény.

## Skriptem

Na řadiči domény v PowerShellu **jako správce domény**:

```powershell
.\Install-WSPanelDns.ps1 -Account 'FIRMA\wspanel-dns' -Zones firma.local `
    -AllowFrom 192.168.1.10, 192.168.1.20 `
    -PublicKey 'ssh-ed25519 AAAA… wspanel-node-…', 'ssh-ed25519 AAAA… wspanel-node-…'
```

Skript provede všechny ruční kroky níže a dá se spustit opakovaně (třeba
s novým klíčem nebo další zónou). Na konci vypíše otisk klíče hostitele.
Pokračujte krokem [Ověření](#ověření).

## Ručně

Příklad: doména `FIRMA` (`firma.local`), řadič domény `dc1` (`192.168.1.5`),
servery `192.168.1.10` a `192.168.1.20`.

### 1. OpenSSH Server

1. Nastavení → Systém → Volitelné funkce → Přidat funkci → **OpenSSH
   Server**. Na Windows Serveru 2025 je už nainstalovaný, jen vypnutý.
   V PowerShellu totéž:
   ```powershell
   Get-WindowsCapability -Online -Name 'OpenSSH.Server*' | Add-WindowsCapability -Online
   ```
2. Služba `sshd` (OpenSSH SSH Server): typ spouštění **Automaticky**,
   spustit:
   ```powershell
   Set-Service sshd -StartupType Automatic; Start-Service sshd
   ```
3. Brána firewall: instalace vytvoří pravidlo „OpenSSH SSH Server (sshd)“
   pro **všechny adresy**. Omezte ho na oba servery (Windows Defender
   Firewall s pokročilým zabezpečením → Příchozí pravidla → pravidlo →
   Obor → Vzdálená IP adresa), nebo:
   ```powershell
   Set-NetFirewallRule -Name 'OpenSSH-Server-In-TCP' -RemoteAddress 192.168.1.10, 192.168.1.20
   ```
   Pokud SSH na řadič používáte i odjinud, přidejte i tu adresu.

### 2. Účet

**Active Directory Users and Computers** → Users → New → User:

- **User logon name:** `wspanel-dns`.
- **Heslo:** dlouhé náhodné (40 znaků), nikam ho nezapisujte. Účet se
  přihlašuje jen klíčem.
- Zaškrtnout **Password never expires**, odškrtnout „User must change
  password at next logon“.
- **Member Of:** jen Domain Users. Nic dalšího.
- Description: `WSPanel mirror: A/AAAA records over SSH (wspanel-dns.ps1 only)`.

### 3. Práva na zónu

**DNS Manager** → Forward Lookup Zones → **`firma.local`** (ne server, ne
`_msdcs.firma.local`) → Properties → **Security** → Add → `wspanel-dns` →
**Advanced** → položka `wspanel-dns` → Edit:

- **Applies to:** This object and all descendant objects.
- **Allow:** Read, Write, Create all child objects, Delete all child
  objects.

Totéž pro každou další zónu, kterou má panel spravovat.

### 4. Práva ve WMI

Rutiny PowerShellu pro DNS jdou přes jmenný prostor WMI serveru DNS.

`wmimgmt.msc` → **WMI Control (Local)** → Properties → **Security** →
Root → **MicrosoftDNS** → Security → Add → `wspanel-dns`:

- **Allow:** Execute Methods, Provider Write, Enable Account, Remote Enable.
- **Nezaškrtávejte** Full Write, Edit Security ani Read Security.
- Advanced → položka `wspanel-dns` → Applies to: **This namespace and
  subnamespaces**.

### 5. Skript, zóny a klíče

1. Složka **`C:\ProgramData\WSPanel`** a do ní:
   - `wspanel-dns.ps1` (z `provision/files/windows/`, beze změny),
   - **`dns.json`** se zónami (malými písmeny):
     ```json
     { "zones": ["firma.local"], "server": "localhost" }
     ```
   - **`authorized_keys`**: veřejné klíče obou serverů, každý na jednom
     řádku. Uložte jako **ANSI/ASCII bez BOM** (v Poznámkovém bloku
     „Uložit jako“ → Kódování: ANSI). S BOM nebo v UTF-16 ho `sshd`
     nepřečte.
2. Oprávnění: OpenSSH přečte soubor s klíči, jen když ho smí měnit
   výhradně SYSTEM a Administrators. V PowerShellu jako správce:
   ```powershell
   $d = 'C:\ProgramData\WSPanel'
   foreach ($f in $d, "$d\authorized_keys", "$d\wspanel-dns.ps1", "$d\dns.json") {
       icacls $f /inheritance:r /grant 'SYSTEM:(OI)(CI)F' /grant 'Administrators:(OI)(CI)F' /grant 'wspanel-dns:(OI)(CI)RX'
   }
   ```

### 6. sshd

Do **`C:\ProgramData\ssh\sshd_config`** (Poznámkový blok spuštěný jako
správce) přidejte **na úplný konec** souboru, za případný blok
`Match Group administrators`:

```
# BEGIN WSPanel mirror DNS
Match User wspanel-dns,firma\wspanel-dns,wspanel-dns@firma.local
    AuthorizedKeysFile __PROGRAMDATA__/WSPanel/authorized_keys
    PasswordAuthentication no
    AllowTcpForwarding no
    AllowAgentForwarding no
    PermitTTY no
    X11Forwarding no
    ForceCommand powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "C:\ProgramData\WSPanel\wspanel-dns.ps1"
# END WSPanel mirror DNS
```

Blok platí jen pro účet `wspanel-dns`. Ostatní uživatelé a správci se
přihlašují jako dřív. `ForceCommand` znamená, že ať účet pošle cokoli,
spustí se jen `wspanel-dns.ps1`. Pak zkontrolujte konfiguraci a restartujte
službu:

```powershell
& 'C:\Windows\System32\OpenSSH\sshd.exe' -t     # nic nevypíše = v pořádku
Restart-Service sshd
```

### 7. Otisk klíče hostitele

```powershell
& 'C:\Windows\System32\OpenSSH\ssh-keygen.exe' -l -f 'C:\ProgramData\ssh\ssh_host_ed25519_key.pub'
```

## Ověření

Na **každém** serveru: Zrcadlo → Windows DNS:

1. **Ověřit hostitele Windows:** panel ukáže otisk klíče řadiče. Musí se
   shodovat s výpisem z kroku 7 (nebo skriptu). Když se liší, nastavení
   smažte: někdo se vydává za řadič domény.
2. **Vyzkoušet Windows DNS:** panel přečte zóny a nic nezmění.

## Když delegace nestačí

Když „Vyzkoušet Windows DNS“ hlásí **odepřený přístup** (Access denied),
nestačí ve vaší doméně práva na zónu a WMI. Pak je jediná cesta skupina
**DnsAdmins**: skript s `-UseDnsAdmins`, nebo ručně přidat `wspanel-dns`
do DnsAdmins (Active Directory Users and Computers → Users → DnsAdmins →
Members).

Člen DnsAdmins ale umí přimět službu DNS načíst knihovnu, tedy spustit kód
jako SYSTEM na řadiči domény. Přes SSH to účet nezmůže (smí jen
`wspanel-dns.ps1`), ale **soukromé klíče obou serverů** (`/var/lib/panel-agent/mirror/windns_ed25519`,
čitelné jen pro root) pak chraňte jako
přístup k řadiči domény.

## Odebrání

Vše se dá vrátit bez dopadu na AD:

1. Z `sshd_config` smažte blok mezi `# BEGIN WSPanel mirror DNS`
   a `# END WSPanel mirror DNS` a `Restart-Service sshd`.
2. Smažte složku `C:\ProgramData\WSPanel`.
3. Smažte účet `wspanel-dns` (s ním zmizí i jeho práva na zónách a ve WMI).
4. Pokud OpenSSH Server nepotřebujete k ničemu jinému: Volitelné funkce →
   OpenSSH Server → Odinstalovat.
5. Na serverech: Zrcadlo → Windows DNS → **Odebrat**.
