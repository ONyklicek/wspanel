# Zrcadlo (vysoká dostupnost)

Dva servery WSPanel tvoří pár. **Primár** obsluhuje weby. **Zrcadlo** (standby)
drží jeho úplnou kopii a při výpadku převezme provoz. Oba jsou obyčejné
servery s Debianem 13: fyzické, nebo virtuální (na Windows ve virtuálním
stroji [Hyper-V](hyper-v.md)). Oba jsou ve stejné síti a vidí na sebe.
Stránka v panelu: **Server → Zrcadlo**.

## Správa: každý server má vlastní panel

Jako u Proxmoxu nebo VMware jsou oddělené dvě vrstvy:

- **Správa serveru:** každý server má vlastní panel na vlastní doméně (např.
  `srv1.firma.local` a `srv2.firma.local`), dostupný vždy, i na zrcadle.
  Spravujete v něm stroj samotný: aktualizace, moduly, služby, síť a zrcadlo.
- **Správa služby:** weby, databáze, pošta, DNS zóny a klienti se spravují
  na serveru, který obsluhuje weby. Zrcadlo drží jeho kopii, kterou při převzetí
  přepíše. Proto na zrcadle (i na odstaveném serveru) agent odmítne **každou
  změnu** webů, databází, pošty, DNS zón, klientů, deploye, cronu a workerů,
  i jejich zálohy: smazání databáze na replice by rozbilo replikaci, zapnutý
  web by spustil plánovač a fronty vedle primáru. Panel zrcadla ukáže
  správcům pruh s odkazem na panel aktivního serveru.

Při převzetí si každý server nechá svou doménu panelu (`APP_URL`, volby
instalace, Caddyfile panelu) a svou certifikační autoritu (`--local-certs`):
převezme jen data a nastavení webů.

Celé zrcadlo je „všechno, nebo nic“: kopírují se všechny weby, pošta
a databáze. Co nemá mít vysokou dostupnost, patří na samostatný server.

### Herní servery patří svému serveru

Herní servery se **nikdy nekopírují a nikdy nepřepisují**: každý server má své,
spravuje je ve svém panelu a běží i na zrcadle nebo na odstaveném serveru.

- Do kopie pro zrcadlo nejde jejich stav, účty (`wsg_*`), jednotky ani data.
- Při převzetí si server ponechá v panelu své herní servery a herní servery
  druhého serveru se v něm neobjeví. Klienta her přiřadí podle linuxové skupiny
  klienta, která je na obou serverech stejná.
- Zálohy her mimo server jdou v zrcadle do `games/<id uzlu>/<id>`, takže se
  zálohy obou serverů nemíchají. Zálohy z doby před spárováním zůstávají
  v `games/<id>`.
- Hráči se k serveru připojují jeho vlastní adresou (ne plovoucí IP): hra
  s přepnutím webů nikam nejde.

### Poštovní server

Když zrcadlo drží i poštu, musí mít oba servery **stejné jméno poštovního
serveru** (`--mail-host=mail.firma.cz` při instalaci) a to jméno musí mířit
na plovoucí IP. Jinak by se po převzetí poštovní jméno a jeho certifikát
rozešly s MX a s nastavením klientů. Stránka Zrcadlo ukazuje rozdíl
ve vrstvě „Nastavení obou serverů“.

## Přepnutí v LAN: plovoucí IP

Když jsou oba servery ve stejné síti, je nejjednodušší **plovoucí IP**:
jedna volná adresa v LAN (např. `192.168.1.30`), kterou drží vždy server,
který obsluhuje weby.

- DNS (Windows DNS, PowerDNS, `hosts`) a přesměrování portů na routeru míří
  **natrvalo na plovoucí IP**. Při přepnutí se nic nepřepisuje, do Active
  Directory se nesahá a nečeká se na TTL.
- O tom, kdo adresu drží, rozhoduje agent zrcadla (epocha, svědci, odstavení),
  žádná druhá volba typu VRRP, která by se s ním mohla rozejít. Adresa se
  přidá k rozhraní serveru jako druhá adresa (vlastní MAC, takže v Hyper-V
  není potřeba MAC spoofing) a oznámí se gratuitním ARP. Přepínače a klienti
  se přesunou během jedné až dvou sekund.
- Odstavený server a zrcadlo ji uvolní hned. Po restartu ji server nemá: vezme
  si ji, jen když je aktivní, a primár, kterému zrcadlo ještě nepotvrdilo, že
  je zrcadlem, jen když na ni v LAN nikdo jiný neodpovídá.
- Nastavení: na **obou** serverech Zrcadlo → **Plovoucí IP**, stejná adresa.
  Panel ji před uložením ověří (ARP): když ji drží jiné zařízení, odmítne ji.
  Vyberte adresu mimo rozsah DHCP.
- Vlastní adresy serverů (`192.168.1.10`, `.20`) zůstávají: zrcadlo se
  spojuje přes ně a panel každého serveru běží na nich.

Kde servery nejsou ve stejné síti, přepíná se DNS (Windows DNS níže,
Cloudflare) a háky.

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
| Soubory webů | rsync přes spojení zrcadla (složky `tmp/` bez obsahu, bez dat Valkey) | každý cyklus a hned po deployi |
| Pošta (Maildiry) | rsync | každý cyklus |
| MariaDB | GTID replikace, zrcadlo je jen pro čtení | průběžně (sekundy) |
| PostgreSQL | streaming replikace se slotem (hot standby) | průběžně |
| MongoDB, vyhrazené DB servery | výpisy jednotlivých databází | každých 5 minut |
| Valkey / Redis každého webu | replika pod uživatelem webu (týž server jako na primáru) | průběžně |

Software, který primár používá (DB enginy, verze PHP a Pythonu, Valkey, Redis,
poštovní hosting), si zrcadlo doinstaluje samo. Herní servery se
nekopírují (viz výše).

Kopie se chrání před chybou na primáru: když ve složce webů nebo pošty
primáru chybí víc než polovina toho, co zrcadlo už má a co stav primáru
uvádí (nepřipojený disk, smazání), synchronizace té vrstvy neproběhne
a zrcadlo nic nesmaže. Vrstva hlásí chybu, dokud se složka nevrátí.

Složka `tmp/` každého webu se kopíruje prázdná, s vlastníkem a právy: PHP-FPM
v ní má `TMPDIR`, nahrávané soubory i sessions, takže je po převzetí hned
k dispozici. Její obsah (rozpracované nahrávání, sessions) se nekopíruje.

**Zápis na zrcadle mimo replikaci.** MariaDB nemá `super_read_only`, takže root
(správce, skript v cronu) může zapisovat i do repliky na zrcadle. Taková
transakce by při další změně na primáru zastavila replikaci. Agent zrcadla ji
pozná (GTID s vlastním `server_id` v `gtid_binlog_state`), ohlásí ji a repliku
znovu naplní z primáru. Co na zrcadle bylo, se předtím uloží do
`/var/backups/wspanel/mirror/mariadb-before-seed-…sql.gz`.

## Návod: Linux server + VM v Hyper-V + Windows DNS

Příklad, ze kterého návod vychází (adresy si nahraďte svými):

| | Jméno | Adresa |
|---|---|---|
| Primár, Debian 13 | `srv1` | `192.168.1.10` |
| Windows Server: řadič domény, Windows DNS, Hyper-V | `dc1` | `192.168.1.5` |
| Zrcadlo, Debian 13 ve VM na `dc1` | `wspanel-vm` | `192.168.1.20` |
| Brána LAN | | `192.168.1.1` |
| Doména AD a zóna DNS | `firma.local` | |
| Panel primáru / zrcadla | `srv1.firma.local` / `wspanel-vm.firma.local` | jejich vlastní adresy |
| Plovoucí IP (weby) | | `192.168.1.30` |

### 1. Předpoklady

- Zóna `firma.local` je **uložená v Active Directory** (AD-integrated) a
  klienti v LAN používají jako DNS řadič domény (běžné v doméně, nastavuje
  DHCP).
- Oba servery budou mít **pevné adresy**: nastavené v Debianu, nebo
  rezervované v DHCP.
- Na obou poběží **stejná verze WSPanelu** a stejné DB enginy. Doména panelu
  je každému serveru vlastní: `srv1.firma.local` a `wspanel-vm.firma.local`
  (A záznamy na jejich vlastní adresy).
- Plovoucí IP `192.168.1.30` je volná (mimo rozsah DHCP). Weby v DNS míří na ni.

### 2. Primár

Na `srv1` je WSPanel nainstalovaný běžně ([Instalace](instalace.md)), například:

```bash
curl -fsSL https://github.com/onyklicek/wspanel/releases/latest/download/get.sh | sudo bash -s -- \
  --yes --panel-domain=srv1.firma.local --local-certs --db=mariadb,postgres --admin-email=admin@firma.local
```

`--local-certs`: interní doména `.local` nemá certifikát od Let's Encrypt.
Panel a weby dostanou certifikát vlastní CA panelu. Weby, které mají jít
i do internetu, dostanou veřejný certifikát, jakmile mají veřejnou doménu.

### 3. Zrcadlo ve VM

Na Windows hostiteli v PowerShellu jako správce
(`provision/files/windows/New-WSPanelVM.ps1`):

```powershell
.\New-WSPanelVM.ps1 -Name wspanel-vm -MemoryGB 4 -Cpus 2 -DiskGB 80 -Path D:\Hyper-V
```

Nebo ručně v Hyper-V Manageru. Oba postupy a co dodržet, když je hostitelem
řadič domény `dc1` (disk, paměť, síť, aby se nenarušilo AD), popisuje
[Hyper-V](hyper-v.md).

V konzoli VM nainstalujte Debian 13 (jen „SSH server“ a „standard system
utilities“) s adresou `192.168.1.20`. Pak ve VM nainstalujte WSPanel **se
stejnými volbami jako primár, jen s vlastní doménou panelu**, a žádný web
nezakládejte:

```bash
curl -fsSL https://github.com/onyklicek/wspanel/releases/latest/download/get.sh | sudo bash -s -- \
  --yes --panel-domain=wspanel-vm.firma.local --local-certs --db=mariadb,postgres --admin-email=admin@firma.local
```

S `--local-certs` má každý server vlastní certifikační autoritu (po převzetí
používá zrcadlo autoritu primáru). Klientům proto rozdejte kořenové
certifikáty obou serverů (GPO → Důvěryhodné kořenové certifikační autority).

### 4. Spárování

1. **Panel primáru** (`srv1`): Server → Zrcadlo → **Nastavit jako primár**.
   - Adresa tohoto serveru: `192.168.1.10`.
   - Adresa zrcadla: `192.168.1.20`.
   - Panel ukáže **párovací kód**. Platí hodinu a jen jednou.
2. **Panel zrcadla** (`https://wspanel-vm.firma.local`): Server → Zrcadlo →
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

### 6. Plovoucí IP

Na **obou** serverech Zrcadlo → **Plovoucí IP**: adresa `192.168.1.30`,
rozhraní podle adresy. Na primáru se adresa hned objeví (Zrcadlo → Přepnutí:
„drží tento server“), na zrcadle „drží druhý server“.

Ve Windows DNS nasměrujte domény webů jednou ručně na `192.168.1.30`
(např. `intranet.firma.local → 192.168.1.30`). Router přesměruje porty 80/443
na `192.168.1.30`. Krok 6b pak nepotřebujete.

### 6b. Windows DNS (místo plovoucí IP)

Jen když plovoucí IP použít nejde (servery v různých sítích). Je-li plovoucí
IP nastavená, panel do Windows DNS zapisuje ji, takže se záznamy nemění.

Aktivní server drží A záznamy svých webů v zóně `firma.local` na své IP: na
`192.168.1.10`, po převzetí na `192.168.1.20`. Patří k nim domény webů,
aliasy, přesměrované domény a jména z „Další jména“. Klienti v LAN tak po
přepnutí do TTL (60 s) chodí na nový primár.

1. Na **každém** serveru: Zrcadlo → **Windows DNS**:
   - server: `192.168.1.5`, port 22, účet: `wspanel-dns@firma.local`,
   - zóny: `firma.local`, další jména: jen to, co má následovat aktivní server
     (panely ne, ty mají každý svou adresu),
   - panel ukáže **veřejný klíč** tohoto serveru, oba si zkopírujte.
2. Na `dc1` jako správce domény (`provision/files/windows/`):
   ```powershell
   .\Install-WSPanelDns.ps1 -Account 'FIRMA\wspanel-dns' -Zones firma.local `
       -AllowFrom 192.168.1.10, 192.168.1.20 `
       -PublicKey 'ssh-ed25519 AAAA… wspanel-node-…', 'ssh-ed25519 AAAA… wspanel-node-…'
   ```
   Nebo ručně podle [Windows DNS](windows-dns.md), kde je i přesný výčet
   toho, co se na řadiči domény změní, a jak to odebrat. Skript udělá tohle:
   - zapne OpenSSH Server ve Windows s portem 22 otevřeným jen pro oba
     servery a založí účet s přihlášením jen klíčem,
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
   Ověřte weby z počítače v LAN: s plovoucí IP běží dál na `192.168.1.30`
   (Zrcadlo → Přepnutí: „drží tento server“ na `wspanel-vm`), s Windows DNS
   `nslookup web.firma.local` vrátí `192.168.1.20`. Pak stejným tlačítkem na `wspanel-vm` přepněte zpět.
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
- **Jedna veřejná IP za routerem s plovoucí IP:** přesměrujte porty jednou
  na plovoucí IP. Při přepnutí se nic nemění.
- **Jedna veřejná IP za routerem bez plovoucí IP:** přepnout se musí
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
| **Primár je odříznutý** (nevidí zrcadlo ani svědka) | Po plné době se povýší | Po polovině doby se **sám odstaví**: uvolní plovoucí IP, weby z edge, zastaví poštu, databáze jen pro čtení. Když se spojení vrátí a zrcadlo nepřevzalo provoz, sám se vrátí |
| **Servery se nevidí, ale oba vidí svědka** (firewall, vadná VLAN, zaseknutý agent i web primáru) | Neodpovídá-li primár, ale jeho stroj v LAN odpovídá na ARP, **automaticky se nepovýší**: hlásí to a převzetí je na správci (Povýšit na primár) | Běží dál |
| **Dvě zařízení drží plovoucí IP** | Server, který adresu převzal naposledy, si ji nechá a znovu ji oznámí | Starší držitel, který na adrese uvidí druhý server, se odstaví. Cizí zařízení na adrese se jen hlásí |
| **Servery mají jinou verzi WSPanelu** | Automaticky se nepovýší, ruční povýšení chce potvrzení | – |
| **Plánované přepnutí nedoběhne** (dohánění selže, agent zrcadla se restartuje) | – | Po 2 minutách, kdy zrcadlo nepřebírá provoz, **sám znovu obsluhuje** weby |
| **Starý primár naběhne** po povýšení zrcadla | – | Brána po startu ho drží odstavený (viz níže), od zrcadla se dozví novější epochu a zůstane odstavený. Pak **Připojit zpět jako zrcadlo** |
| **Primár se restartuje** (pád, údržba), zrcadlo provoz nepřevzalo | – | Po startu neobsluhuje, dokud zrcadlo nepotvrdí, že je zrcadlem (obvykle do sekundy). Pak obsluhuje jako dřív |
| **Výpadek proudu u obou**, vrátí se jen primár | – | Po startu čeká po dobu přepnutí. Když zrcadlo mlčí, svědek odpovídá a plovoucí IP nikdo nedrží, začne obsluhovat |
| **Údržba primáru** | – | **Plánované přepnutí**: primár přestane přijímat zápisy, zrcadlo ho dožene a převezme provoz **bez ztráty dat**, primár se stane zrcadlem |
| **Panel zrcadla nejde otevřít** | `sudo panel-agent -mirror-status`, `sudo panel-agent -mirror-promote` | – |

**Epocha** je čítač zvyšovaný s každým povýšením. Zrcadlo ji uloží hned na
začátku povýšení a primár, který o novější epoše uslyší (i od zrcadla, které
se teprve povyšuje), se odstaví. Těsně předtím, než nový primár začne
obsluhovat, starému primáru odstavení zopakuje. Dva primáry tak neběží
souběžně, ani když se starý primár vrátí uprostřed povýšení.

**Brána po startu.** Primár, který startuje (restart stroje, pád, výpadek
proudu), neví, jestli zrcadlo mezitím nepřevzalo provoz. Proto po startu
stroje nejdřív neobsluhuje: weby nejsou na edge, databáze jsou jen pro čtení,
jednotky webů stojí (stav **odstavený – po startu**). Bránu spustí už edge před
svým startem (`ExecStartPre` služby `panel-proxy`), takže weby z minulého běhu
nenačte, i když agent ještě neběží. Obsluhovat začne, jakmile:

- zrcadlo odpoví jako zrcadlo bez novější epochy (běžný restart, do sekundy), nebo
- zrcadlo mlčí celou dobu přepnutí, svědek odpovídá (je-li nastavený) a
  plovoucí IP nikdo nedrží (výpadek proudu u obou).

Když druhý server mezitím převzal provoz, primár zůstane odstavený. Restart
samotného agenta (aktualizace) bránou neprochází: server obsluhuje dál.
Obsluhující primár navíc každých 5 s hlídá, že jeho MariaDB a PostgreSQL
přijímají zápisy, a jinak je vrátí do zápisu a ohlásí to.

**Návrat zpět** (Připojit zpět jako zrcadlo): odstavený server se stane
zrcadlem nového primáru. Co měl jen on (zápisy po výpadku spojení), se
nejdřív uloží do `/var/backups/wspanel/mirror/rejoin-…`: celý jeho stav
včetně databáze panelu, výpisy MariaDB a soubory a pošta, které první
synchronizace přepíše nebo smaže. Data PostgreSQL zůstanou vedle clusteru
jako `….before-rejoin-<čas>`. Potom lze plánovaně přepnout zpět.

**Smazané weby se nevracejí:** při povýšení se konfigurace webů, které primár
mezitím smazal, přesune do `/var/backups/wspanel/mirror/stale-sites-…`
(soubory webů zůstanou na místě).

## Co nepřežije výpadek

- Při **neplánovaném** výpadku se ztratí, co primár zapsal a nestihl předat:
  u MariaDB a PostgreSQL obvykle zlomek sekundy, u souborů a pošty až do
  intervalu synchronizace (60 s). U MongoDB a vyhrazených DB serverů je to
  až 5 minut.
- Pošta, která čekala ve frontě primáru (Postfix), na zrcadle není. Odesílající
  servery ji zkusí doručit znovu.
- Plánované přepnutí nic neztratí.

## Omezení

- Oba servery musí mít **stejnou verzi WSPanelu** (panel na to upozorní,
  automatické přepnutí do té doby neproběhne). Aktualizujte nejdřív zrcadlo,
  pak primár.
- Automatické přepnutí, jeho doba a plovoucí IP musí být na obou serverech
  stejné: vrstva „Nastavení obou serverů“ ukáže rozdíl.
- **Cloudflare:** aktivní server přepisuje jen A záznamy domén svých webů
  (a „Dalších jmen“), a jen když mu to agent dovolí (stejná kontrola jako
  u Windows DNS). Panel druhého serveru a jiná jména na jeho adrese zůstanou.
- PostgreSQL musí mít na obou serverech stejnou hlavní verzi (Debian 13 = 17).
- Plovoucí IP je jen IPv4 a jen v jedné síti (L2) s oběma servery. Jinak se
  provoz přepíná přes DNS (Windows DNS, Cloudflare) a háky. Klienti, kteří si
  záznam drží déle než TTL, se pak na nový primár dostanou později.
