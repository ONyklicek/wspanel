# Zrcadlo (vysoká dostupnost)

Dva servery WSPanel tvoří pár. **Primár** obsluhuje weby. **Zrcadlo** (standby)
drží jeho úplnou kopii a při výpadku převezme provoz. Oba jsou obyčejné
servery s Debianem 13: fyzické, nebo virtuální (na Windows ve virtuálním
stroji [Hyper-V](hyper-v.md)). Oba jsou ve stejné síti a vidí na sebe.
Stránka v panelu: **Server → Zrcadlo**.

Návod pro nejčastější sestavu (Linux server, druhý server jako VM v Hyper-V,
DNS ve Windows a Active Directory) je níže:
[Linux server + VM v Hyper-V + Windows DNS](#návod-linux-server--vm-v-hyper-v--windows-dns).

## Co se kopíruje

Veškerá data teče od zrcadla k primáru. Zrcadlo se připojuje jedním spojením
se vzájemným TLS (port 7443), které je na primáru otevřené jen pro adresu
zrcadla.

| Vrstva | Jak | Čerstvost |
|---|---|---|
| Stav a konfigurace (panel, weby, certifikáty, DKIM, účty) | archiv jako záloha serveru, šifrovaný klíčem pro obnovu primáru | každý cyklus (60 s) a hned po změně na primáru |
| Uživatelé a skupiny webů | se stejnými UID/GID | každý cyklus |
| Soubory webů | rsync přes spojení zrcadla (bez `tmp/` a dat Valkey) | každý cyklus a hned po deployi |
| Pošta (Maildiry) | rsync | každý cyklus |
| MariaDB | GTID replikace, zrcadlo je jen pro čtení | průběžně (sekundy) |
| PostgreSQL | streaming replikace se slotem (hot standby) | průběžně |
| MongoDB, vyhrazené DB servery | výpisy jednotlivých databází | každých 5 minut |
| Valkey / Redis každého webu | replika pod uživatelem webu | průběžně |

Software, který primár používá (DB enginy, verze PHP a Pythonu, Redis,
poštovní hosting), si zrcadlo doinstaluje samo. Herní servery se
nekopírují.

## Návod: Linux server + VM v Hyper-V + Windows DNS

Příklad, ze kterého návod vychází (adresy si nahraďte svými):

| | Jméno | Adresa |
|---|---|---|
| Primár, Debian 13 | `srv1` | `192.168.1.10` |
| Windows Server: řadič domény, Windows DNS, Hyper-V | `dc1` | `192.168.1.5` |
| Zrcadlo, Debian 13 ve VM na `dc1` | `wspanel-vm` | `192.168.1.20` |
| Brána LAN | | `192.168.1.1` |
| Doména AD a zóna DNS | `firma.local` | |
| Panel | `panel.firma.local` | |

### 1. Předpoklady

- Zóna `firma.local` je **uložená v Active Directory** (AD-integrated) a
  klienti v LAN používají jako DNS řadič domény (běžné v doméně, nastavuje
  DHCP).
- Oba servery budou mít **pevné adresy**: nastavené v Debianu, nebo
  rezervované v DHCP.
- Na obou poběží **stejná verze WSPanelu** se **stejnou doménou panelu**
  a stejnými DB enginy.
- V zóně je A záznam `panel.firma.local → 192.168.1.10`. Po spárování ho
  bude spravovat zrcadlo (krok 6).

### 2. Primár

Na `srv1` je WSPanel nainstalovaný běžně ([Instalace](instalace.md)), například:

```bash
curl -fsSL https://github.com/onyklicek/wspanel/releases/latest/download/get.sh | sudo bash -s -- \
  --yes --panel-domain=panel.firma.local --local-certs --db=mariadb,postgres --admin-email=admin@firma.local
```

`--local-certs`: interní doména `.local` nemá certifikát od Let's Encrypt.
Panel a weby dostanou certifikát vlastní CA panelu. Weby, které mají jít
i do internetu, dostanou veřejný certifikát, jakmile mají veřejnou doménu.

### 3. Zrcadlo ve VM

Na `dc1` v PowerShellu jako správce
(`provision/files/windows/New-WSPanelVM.ps1`, viz [Hyper-V](hyper-v.md)):

```powershell
.\New-WSPanelVM.ps1 -Name wspanel-vm -MemoryGB 4 -Cpus 2 -DiskGB 80
```

V konzoli VM nainstalujte Debian 13 (jen „SSH server“ a „standard system
utilities“) s adresou `192.168.1.20`. Pak ve VM nainstalujte WSPanel **se
stejnými volbami jako primár** a žádný web nezakládejte:

```bash
curl -fsSL https://github.com/onyklicek/wspanel/releases/latest/download/get.sh | sudo bash -s -- \
  --yes --panel-domain=panel.firma.local --local-certs --db=mariadb,postgres --admin-email=admin@firma.local
```

Panel zrcadla je na stejné doméně, ale ta zatím míří na primár. Do panelu
zrcadla se proto dostanete přes dočasný záznam v souboru `hosts` svého
počítače (`192.168.1.20 panel.firma.local`). Po spárování ho smažte.

### 4. Spárování

1. **Panel primáru** (`srv1`): Server → Zrcadlo → **Nastavit jako primár**.
   - Adresa tohoto serveru: `192.168.1.10`.
   - Adresa zrcadla: `192.168.1.20`.
   - Panel ukáže **párovací kód**. Platí hodinu a jen jednou.
2. **Panel zrcadla** (`wspanel-vm`, přes `hosts`): Server → Zrcadlo →
   **Připojit jako zrcadlo**.
   - Adresa primáru: `192.168.1.10`.
   - Kód z primáru.
   - Adresa tohoto serveru: `192.168.1.20`.
3. Počkejte na první synchronizaci. Na zrcadle musí být v tabulce **Kopie**
   všechny vrstvy „v pořádku“. Trvá to podle velikosti webů a databází.

### 5. Svědek a automatické přepnutí

Na **obou** serverech Zrcadlo → **Nastavení**:

- **Svědci:** `192.168.1.1` (ping na bránu) a `192.168.1.5:53` (DNS řadiče
  domény přes TCP). Zrcadlo se samo povýší jen tehdy, když primár neodpovídá
  a většina svědků ano. Odříznuté zrcadlo se tak nikdy nepovýší.
- **Automatické přepnutí:** zapnout, doba 60 s.

### 6. Windows DNS

Aktivní server drží A záznamy svých webů v zóně `firma.local` na své IP: na
`192.168.1.10`, po převzetí na `192.168.1.20`. Patří k nim domény webů,
aliasy, přesměrované domény a jména z „Další jména“. Klienti v LAN tak po
přepnutí do TTL (60 s) chodí na nový primár.

1. Na **každém** serveru: Zrcadlo → **Windows DNS**:
   - server: `192.168.1.5`, port 22, účet: `wspanel-dns@firma.local`,
   - zóny: `firma.local`, další jména: `panel.firma.local`,
   - panel ukáže **veřejný klíč** tohoto serveru, oba si zkopírujte.
2. Na `dc1` jako správce domény (`provision/files/windows/`):
   ```powershell
   .\Install-WSPanelDns.ps1 -Account 'FIRMA\wspanel-dns' -Zones firma.local `
       -PublicKey 'ssh-ed25519 AAAA… wspanel-node-…', 'ssh-ed25519 AAAA… wspanel-node-…'
   ```
   Skript udělá tohle:
   - zapne OpenSSH Server ve Windows a založí účet s přihlášením jen klíčem,
   - dá účtu práva **jen na zónu `firma.local`** (zápis do jejího objektu
     v AD a správu DNS přes WMI), ne skupinu DnsAdmins,
   - nastaví, že účet smí přes SSH spustit jen skript `wspanel-dns.ps1`
     (ForceCommand). Ten mění jen záznamy A/AAAA v povolených zónách, jen
     u jmen, na která se ho panel zeptá, a nic jiného nemaže. CNAME nechá
     být,
   - vypíše otisk klíče hostitele.
3. Na každém serveru: **Ověřit hostitele Windows** (porovnejte otisk
   s výpisem skriptu) a **Vyzkoušet Windows DNS**.

> Kdyby delegace práv na zónu ve vaší doméně nestačila („Vyzkoušet Windows DNS“
> hlásí odepřený přístup), spusťte skript znovu s `-UseDnsAdmins`. Člen skupiny
> DnsAdmins ale umí přimět službu DNS načíst knihovnu, tedy spustit kód jako
> SYSTEM na řadiči domény. Klíče obou serverů pak chraňte jako přístup
> k řadiči domény.

### 7. Notifikace

Panel zrcadla má do povýšení vlastní databázi. **Notifikace** (e-mail,
Slack, ntfy…) proto nastavte na obou serverech. Hlášení „Primár neodpovídá“
a chyby kopií posílá zrcadlo.

### 8. Vyzkoušejte to

1. **Plánované přepnutí** (panel primáru, Zrcadlo → Plánované přepnutí):
   zrcadlo převezme provoz bez ztráty dat a `srv1` se stane jeho zrcadlem.
   Ověřte weby z počítače v LAN (`nslookup web.firma.local` vrátí
   `192.168.1.20`). Pak stejným tlačítkem na `wspanel-vm` přepněte zpět.
2. **Výpadek** (v klidné době): vypněte `srv1`. Zrcadlo se po 60 s povýší
   a přepne DNS. Po zapnutí se `srv1` sám odstaví (uvidí novější epochu).
   V jeho panelu zvolte **Připojit zpět jako zrcadlo** a až se dosynchronizuje,
   plánovaně přepněte zpět.

### 9. Provoz

- **Aktualizace:** nejdřív zrcadlo, pak primár. Panel upozorní, když se verze
  liší.
- **Zálohy** běží dál na aktivním serveru (záloha serveru, kopie mimo server).
  Zrcadlo zálohy nenahrazuje: smazaná data smaže i na zrcadle.
- **Řadič domény jako jediný bod:** když vypadne `dc1`, nejede DNS v LAN ani
  zrcadlo. Primár běží dál, ale klienti ho bez DNS nenajdou. Druhý řadič
  domény s DNS to řeší a je v AD doporučený tak jako tak. Server pro
  `Install-WSPanelDns.ps1` je pak kterýkoli z nich, protože zóna v AD se
  replikuje.
- **Kód webů na řadiči domény:** VM v Hyper-V je od Windows izolovaná (vlastní
  jádro, k řadiči domény jen po síti jako jiný stroj v LAN). Lepší je ale
  samostatný Windows hostitel pro VM, zvlášť když weby nejsou vaše.

## Přístup z internetu

- **Různé veřejné IP serverů:** u obou serverů vyplňte veřejnou IPv4 (Zrcadlo →
  Nastavení). Aktivní server pak každou minutu přepíše A záznamy v zónách
  **Cloudflare**, které míří na druhý server, na sebe (proxy a TTL zůstanou).
- **Jedna veřejná IP za routerem** (běžné u firmy): přepnout se musí
  přesměrování portů. Na to slouží **háky**: spustitelné soubory roota
  v `/etc/wspanel/mirror/hooks/promote.d/` se spustí po povýšení s argumenty
  `promote <id uzlu> <IP v LAN> <veřejná IP>`. Například volání API routeru
  MikroTik nebo OPNsense.

## Scénáře

| Co se stane | Co udělá zrcadlo | Co udělá primár |
|---|---|---|
| **Primár vypadne** (server, síť, napájení) | Po nastavené době, pokud odpovídá svědek, se samo povýší: databáze přijmou zápisy, aplikuje stav primáru, spustí weby, přepne DNS a spustí háky | – |
| **Vypadne jen panel nebo agent primáru**, weby běží | Nepřepíná (HTTPS primáru odpovídá), hlásí chybu spojení | Weby běží dál |
| **Zrcadlo je odříznuté** (nevidí primár ani svědka) | Nikdy se samo nepovýší | Běží dál |
| **Primár je odříznutý** (nevidí zrcadlo ani svědka) | Po plné době se povýší | Po polovině doby se **sám odstaví**: weby z edge, zastaví poštu, databáze jen pro čtení. Když se spojení vrátí a zrcadlo nepřevzalo provoz, sám se vrátí |
| **Starý primár naběhne** po povýšení zrcadla | – | Uvidí novější epochu a odstaví se. Pak **Připojit zpět jako zrcadlo** |
| **Údržba primáru** | – | **Plánované přepnutí**: primár přestane přijímat zápisy, zrcadlo ho dožene a převezme provoz **bez ztráty dat**, primár se stane zrcadlem |
| **Panel zrcadla nejde otevřít** | `sudo panel-agent -mirror-status`, `sudo panel-agent -mirror-promote` | – |

**Epocha** je čítač zvyšovaný s každým povýšením. Když se potkají dva
primáry, ten se starší epochou se odstaví. Dva primáry tak nikdy neběží
souběžně.

**Návrat zpět** (Připojit zpět jako zrcadlo): odstavený server se stane
zrcadlem nového primáru. Jeho databáze se znovu naplní, ale co měl jen on
(zápisy po výpadku spojení), se nejdřív uloží do
`/var/backups/wspanel/mirror/`. Soubory, které se při první synchronizaci
přepíšou, se uloží tamtéž. Potom lze plánovaně přepnout zpět.

## Co nepřežije výpadek

- Při **neplánovaném** výpadku se ztratí, co primár zapsal a nestihl předat:
  u MariaDB a PostgreSQL obvykle zlomek sekundy, u souborů a pošty až do
  intervalu synchronizace (60 s). U MongoDB a vyhrazených DB serverů je to
  až 5 minut.
- Pošta, která čekala ve frontě primáru (Postfix), na zrcadle není. Odesílající
  servery ji zkusí doručit znovu.
- Plánované přepnutí nic neztratí.

## Omezení

- Oba servery musí mít **stejnou verzi WSPanelu** (panel na to upozorní).
  Aktualizujte nejdřív zrcadlo, pak primár.
- PostgreSQL musí mít na obou serverech stejnou hlavní verzi (Debian 13 = 17).
- Provoz se přepíná přes DNS (Windows DNS, Cloudflare) a háky, ne plovoucí IP.
  Klienti, kteří si záznam drží déle než TTL, se na nový primár dostanou
  později.
