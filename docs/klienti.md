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
