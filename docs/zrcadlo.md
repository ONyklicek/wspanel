# Zrcadlo (vysoká dostupnost)

Dva servery WSPanel tvoří pár. **Primár** obsluhuje weby. **Zrcadlo** (standby)
drží jeho úplnou kopii a při výpadku převezme provoz. Typická sestava:

- **primár:** Linux (Debian 13) nativně,
- **zrcadlo:** WSPanel na Windows, nejlépe nativně ve WSL2 ([WSL](wsl.md)), jinak
  v Dockeru ([Docker](docker.md)). Na stejném
  Windows serveru běží řadič domény s **Windows DNS**, který slouží jako
  lokální DNS celé sítě.

Oba servery jsou ve stejné síti a vidí na sebe. Stránka v panelu:
**Server → Zrcadlo**.

## Co se kopíruje

Veškerá data teče od zrcadla k primáru. Zrcadlo se připojuje jedním spojením
se vzájemným TLS (port 7443). Primár tak nepotřebuje přístup do sítě
Docker Desktopu a jeho port je otevřený jen pro adresu zrcadla.

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

## Nastavení krok za krokem

1. **Zrcadlo nainstalujte** se stejnou doménou panelu jako primár (WSL:
   `Install-WSPanelWsl.ps1 -PanelDomain …`, adresa zrcadla je pak IP Windows; Docker:
   `WSPANEL_PANEL_DOMAIN`). Musí být prázdné, bez webů. V `docker/.env`
   nechte `WSPANEL_MIRROR_PORT=7443` (port se publikuje v `compose.yaml`).
2. **Na primáru:** Server → Zrcadlo → **Nastavit jako primár**. Vyplňte adresu
   primáru v LAN a adresu zrcadla. U Dockeru na Windows je to IP adresa
   Windows hostitele. Panel ukáže **párovací kód**, který platí hodinu
   a jen jednou.
3. **Na zrcadle:** Server → Zrcadlo → **Připojit jako zrcadlo**. Zadejte adresu
   primáru, kód a adresu, na které primár zrcadlo uvidí (IP Windows
   hostitele). Kód obsahuje otisk certifikátu primáru, takže se zrcadlo
   nespojí s ničím jiným.
4. Počkejte na první synchronizaci. V tabulce **Kopie** musí být všechny vrstvy
   „v pořádku“.
5. **Svědek a automatické přepnutí** (Nastavení, na obou serverech stejně):
   jako svědka zadejte bránu LAN (`192.168.1.1`, ping) nebo řadič domény
   (`192.168.1.5:53`, TCP). Pak zapněte **Automatické přepnutí**
   a nastavte dobu (výchozí 60 s).
6. **Windows DNS** viz níže.

## Windows DNS (Active Directory)

Aktivní server drží A záznamy svých webů (domény, aliasy, přesměrované
domény a jména v „Další jména“) v zónách Windows DNS na své IP.
Po přepnutí tak klienti v LAN do TTL (výchozí 60 s) chodí na nový primár.

Panel se připojuje přes **OpenSSH** (součást Windows) k účtu, který smí
spustit jen skript `wspanel-dns.ps1` (ForceCommand). Skript mění jen záznamy
A/AAAA ve vyjmenovaných zónách, jen u jmen, na která se ho panel zeptá,
a nikdy nemaže jiné záznamy. Jméno, které je CNAME, nechá být.

1. Na každém serveru: Zrcadlo → **Windows DNS**. Vyplňte server (z primáru IP
   řadiče domény, z kontejneru `host.docker.internal`), účet
   `wspanel-dns@firma.local` a zóny. Panel ukáže **veřejný klíč** tohoto serveru.
2. Na Windows jako správce domény (soubory jsou
   v `provision/files/windows/`):
   ```powershell
   .\Install-WSPanelDns.ps1 -Account 'FIRMA\wspanel-dns' -Zones firma.local `
       -PublicKey 'ssh-ed25519 AAAA… wspanel-node-…', 'ssh-ed25519 AAAA… wspanel-node-…'
   ```
   Skript nainstaluje OpenSSH Server a založí účet (ve skupině DnsAdmins,
   přihlášení jen klíčem). Nastaví ForceCommand a vypíše otisk klíče
   hostitele.
3. Na každém serveru: **Ověřit hostitele Windows** (porovnejte otisk) a pak
   **Vyzkoušet Windows DNS**.

> DnsAdmins je na řadiči domény silné oprávnění. Přes SSH účet nesmí nic jiného
> než skript, ale klíče obou serverů chraňte jako přístup k řadiči domény.

## Přístup z internetu

- **Různé veřejné IP serverů:** u obou serverů vyplňte veřejnou IPv4. Aktivní
  server pak každou minutu přepíše A záznamy v zónách **Cloudflare**, které
  míří na druhý server, na sebe (proxy a TTL zůstanou).
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
| **Panel zrcadla nejde otevřít** | `sudo panel-agent -mirror-status`, `sudo panel-agent -mirror-promote` (v Dockeru `docker exec -it wspanel …`) | – |

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
- Zrcadlo v Dockeru Desktop má [omezení Docker varianty](docker.md#omezení-oproti-nativní-instalaci).
  Weby obsluhuje i tak, ale například IP adresy klientů vidí přes proxy
  Docker Desktopu.
- Plovoucí IP (VRRP) se nepoužívá, protože ji Docker Desktop na Windows
  spolehlivě neudrží. Přepíná se přes DNS.
