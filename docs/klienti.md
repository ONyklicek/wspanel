# Klienti a balíčky

Panel umí hostovat weby pro víc **klientů**. Klient se přihlásí do stejného
panelu, ale vidí a spravuje jen své weby, databáze, DNS zóny a naplánované
úlohy. Server (firewall, služby, PHP, pošta, zálohy mimo server, vytížení,
audit, účty panelu) spravuje jen **správce**.

## Balíček

**Hosting → Klienti → Balíčky.** Balíček je sada limitů, kterou dáte klientům:

| Limit | Co omezuje |
|---|---|
| Weby, Databáze, Naplánované úlohy, Workery | kolik jich klient smí mít |
| Místo na disku (MB) | součet velikostí webů klienta |
| Velikost databází (MB) | součet velikostí databází klienta |
| SSH / SFTP | vypnuto, jen SFTP, nebo SSH + SFTP |
| Režimy webu, Verze PHP | co klient uvidí při zakládání webu a změně verze |
| Aliasy domén | kolik dalších domén (aliasů a přesměrovaných domén) mají weby klienta dohromady |
| Poštovní schránky, Místo pro poštu | připraveno pro poštu (zatím se nevynucuje) |

Prázdné pole znamená **neomezeno**. Změna balíčku platí **hned** pro všechny
jeho klienty, nic se nekopíruje.

## Klient

**Hosting → Klienti → Nový klient:** kontakt, balíček a první přihlášení
(e-mail a heslo). Další přihlášení přidáte v detailu klienta (**Přidat
přihlášení**).

Detail klienta ukazuje **spotřebu proti limitům** a u každého limitu, odkud
pochází: z balíčku, nebo z výjimky.

- **Limity klienta:** výjimka jen pro tohoto klienta. Prázdné = podle balíčku,
  číslo nebo `∞` (neomezeno) balíček přebije.
- **Pozastavit:** běžící weby klienta se vypnou (návštěvníci uvidí 503) a
  klient je hned odhlášen. **Obnovit** zapne přesně ty weby, které běžely.
- **Přihlásit se jako klient:** uvidíte panel jeho očima, se stejnými právy.
  Zpět tlačítkem **Vrátit se ke správě** v pruhu nahoře.
- **Smazat klienta** jde, až nevlastní žádný web, databázi ani DNS zónu.

## Weby a databáze klientů

- Klient zakládá weby sám, panel hlídá limity balíčku. Správce může web
  založit pro klienta (pole **Vlastník**) nebo existující web přiřadit
  (**Další → Přiřadit klientovi**). S webem přejdou jeho databáze a DNS zóna.
- Správce limity klienta překročit smí (vědomě); klient ne.
- Databáze klienta dostane předponu `c<číslo klienta>_` (např. `c12_shop`),
  takže se názvy klientů nikdy nepotkají.
- Doména je jedinečná přes všechny klienty: obsazenou doménu nezaloží nikdo
  další.

## Na serveru: skupina a kvóta klienta

- Každý klient má na serveru Linuxovou skupinu `wsc_<číslo>`. Je to primární
  skupina uživatelů všech jeho webů, takže soubory webů klienta patří jeho
  skupině. Weby zůstávají izolované i mezi sebou: skupina nemá do domova
  webu přístup (ACL `g::---`), slouží jen kvótě.
- **Místo na disku** vynucuje jádro jako kvótu této skupiny: zápis přes limit
  selže hned, bez přesčasu. Detail klienta ukáže, jestli kvótu vynucuje jádro,
  nebo jen měří panel.
- Instalace zapne kvóty sama na ext4 (`/srv` nebo kořenový disk): nejraději
  vestavěnou funkci ext4 `quota`, jinak kvótové soubory. Na XFS je potřeba
  přidat `grpquota` do `/etc/fstab` a restartovat. Na souborových systémech
  bez kvót (např. overlay v Dockeru) panel místo jen měří a nové weby nad
  limit nedovolí založit.
- Skupiny `wspanel-ssh` a `wspanel-sftp` mají kvótu jeden blok, takže soubory
  nejde „schovat“ před kvótou změnou skupiny.
- **Souběžné PHP procesy na web** (`pm.max_children`) se berou z balíčku,
  výchozí je 10.
- Přiřazení webu jinému klientovi přesune soubory i běžící procesy do skupiny
  nového klienta (u velkých webů to chvíli trvá).
