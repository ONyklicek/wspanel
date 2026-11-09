# Databáze

## Založení

**Databáze → Nová databáze** (nebo na detailu webu → Další → Nová databáze):

- **Engine:** MariaDB nebo PostgreSQL (jen ty, které jsou nainstalované; další
  doinstalujete v Databáze → Servery → Doinstalovat engine).
- **Název:** stejný název má i uživatel databáze.
- **Web:** když databázi přiřadíte webu, panel zapíše přístupové údaje přímo do
  jeho `.env` a heslo nikde neukáže:
  - Laravel, Čisté PHP: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
  - Symfony: `DATABASE_URL` (včetně `serverVersion`),
  - Node: `DATABASE_URL`.
- Bez webu se heslo zobrazí **jednou** po založení.

Databáze poslouchají jen na `127.0.0.1`, zvenku nejsou dostupné.

## phpMyAdmin a Adminer

V řádku databáze: **phpMyAdmin** (jen MariaDB) nebo **Adminer** (obojí).

- Otevře se v nové záložce, rovnou přihlášené.
- Přihlášení je dočasný uživatel s právy **jen na tuto databázi**, platí
  30 minut.
- Odkaz je jednorázový. Přihlašovací formulář nástrojů je vypnutý, ručně se
  přihlásit nejde.

**Všechny databáze najednou:** Databáze → **Servery** → *phpMyAdmin / Adminer ·
všechny databáze*. Dočasný uživatel má práva superuživatele (správa uživatelů,
všechny databáze), platí 30 minut a pak se sám smaže.

## Databázové servery, root a přístup z internetu

Databáze → **Servery** ukazuje každý engine (MariaDB, PostgreSQL): verzi, port,
kdo se smí připojit a stav superuživatele.

### Heslo superuživatele (root / postgres)

Po instalaci nemá `root` (MariaDB) ani `postgres` (PostgreSQL) heslo: přihlásit
se jde jen přímo na serveru. **Nastavit heslo** vygeneruje silné heslo (zobrazí
se jednou), nebo zadáte vlastní (16–128 znaků, bez uvozovek, zpětného lomítka a
mezer). Heslo jde kdykoli změnit nebo zrušit.

- Panel heslo neukládá. Agent se k databázím připojuje dál přes socket, takže
  správa serveru na hesle nezávisí a jeho únik ji nerozbije.
- Superuživatel smí všechno: zakládat a mazat databáze i uživatele. Heslo
  chraňte stejně jako přístup na server.

### Přístup z internetu

Tlačítko **Přístup z internetu**:

| Volba | Co se stane |
|---|---|
| **Jen tento server** (výchozí) | port 3306 / 5432 je zavřený, server poslouchá jen lokálně |
| **Vybrané adresy** | firewall otevře port jen pro zadané IP adresy a sítě (kancelář, VPN, CI) |
| **Kdokoli z internetu** | port je otevřený všem; panel to ukazuje jako upozornění na Přehledu |

V obou otevřených režimech:
- připojení jde **jen šifrovaně (TLS)**; nešifrované přihlášení po síti server
  odmítne,
- přihlásit se mohou **vlastníci databází** (stejné jméno a heslo jako v `.env`
  webu) a **root / postgres**, pokud má heslo,
- opakovaně chybná přihlášení blokuje **fail2ban** (5 pokusů za 10 minut →
  hodina),
- weby na serveru se připojují dál lokálně (`127.0.0.1`), změna je neovlivní.

Připojení z klienta (adresa je doména panelu):
```
mariadb -h panel.example.com -u root -p --ssl
psql "host=panel.example.com user=postgres dbname=postgres sslmode=require"
```
MariaDB používá vlastní certifikát (self-signed); jeho otisk SHA-256 je na
stránce Servery. Klienti jako DBeaver nebo TablePlus: zapněte SSL, ověření
certifikátu podle otisku, nebo „require“ bez ověření.

### Bez otevření do internetu: SSH tunel

Pro práci z vlastního počítače (TablePlus, DBeaver…) bez otevření portu
použijte SSH tunel přes účet správce serveru (ne přes účet webu, ten tunely
nesmí):
```
ssh -L 3306:127.0.0.1:3306 root@server
```
a v klientovi server `127.0.0.1`. Superuživatel pak potřebuje heslo (viz výše).

## Vyhrazený server pro jeden web

Web, který potřebuje víc oddělení (citlivá data, zákaznický systém), může mít
vlastní MariaDB nebo PostgreSQL: **Databáze → Servery → Vyhrazený server pro
web**. Vyberete web, engine, paměť a CPU. Server se založí na pozadí.

- Běží pod vlastním systémovým uživatelem (`wsd_<číslo>`). Data má ve
  `/var/lib/wspanel-db/<číslo>`, kam nikdo jiný nevidí.
- Nemá síť. Poslouchá jen na socketu ve `/run/wspanel-db/<číslo>` a do toho
  adresáře smí jen uživatel daného webu. Ostatní weby se k němu nedostanou
  vůbec, ani se špatným heslem.
- Paměť a CPU hlídá systemd (`MemoryMax`, `CPUQuota`). Podle paměti se nastaví
  i cache serveru. Limity jdou změnit tlačítkem **Limity**, server se pak
  restartuje.
- Databázi v něm založíte jako obvykle (Databáze → Nová databáze, nebo u webu).
  Stačí zapnout **Na vyhrazeném serveru webu**. Do `.env` se zapíše připojení:
  - Laravel: `DB_SOCKET` (MariaDB), u PostgreSQL adresář socketu v `DB_HOST`;
  - Symfony a Django: `DATABASE_URL` se socketem;
  - WordPress: `DB_HOST` ve tvaru `localhost:/cesta/k/socketu`.
- Zálohy, obnova a kopie do stagingu fungují stejně jako u sdílených serverů.
- phpMyAdmin ani Adminer se k vyhrazenému serveru nedostanou (je bez sítě).
  Pracujte s ním přes SSH jako uživatel webu, např.
  `mariadb --socket=/run/wspanel-db/1/mysqld.sock -u <databáze> -p`.
- Smazat jde, až v něm nejsou žádné databáze. Web s vyhrazeným serverem nejde
  smazat, dokud nesmažete server.
- Záloha celého serveru jeho databáze obsahuje. Při obnově na nový server se
  vrátí do sdíleného serveru a report na to upozorní. Vyhrazený server pak
  založte znovu.

## Zálohy

Detail databáze:
- **Zálohovat teď:** dump na pozadí do `/var/backups/wspanel`.
- **Plán záloh:** denně nebo týdně, a kolik záloh držet.
- **Stáhnout:** záloha jako soubor (`.sql.gz` / PostgreSQL custom formát).
- **Obnovit:** před obnovou se automaticky udělá záloha aktuálního stavu.

Zálohy jsou na stejném serveru. Kopie mimo server zapnete v **Server → Zálohy
mimo server** (viz [Weby → Zálohy mimo server](weby.md#zálohy-mimo-server));
pak se tam nahraje i každý nový dump databáze.

## Změna hesla

Řádek databáze → **Nové heslo**. U databáze napojené na web se nové heslo
rovnou zapíše do `.env`. Aplikace v worker/Node režimu a workery je načtou po
restartu webu.

## Smazání

Řádek databáze → **Smazat** (pro potvrzení napište název). Smaže databázi i
jejího uživatele. Zálohy zůstanou.
