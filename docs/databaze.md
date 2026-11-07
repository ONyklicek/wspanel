# Databáze

## Založení

**Databáze → Nová databáze** (nebo na detailu webu → Další → Nová databáze):

- **Engine:** MariaDB nebo PostgreSQL (jen ty, které jsou nainstalované; další
  doinstalujete v Databáze → Doinstalovat engine).
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

Pro práci z vlastního počítače (TablePlus, DBeaver…) použijte SSH tunel přes
účet správce serveru (ne přes účet webu, ten tunely nesmí):
```
ssh -L 3306:127.0.0.1:3306 root@server
```

## Zálohy

Detail databáze:
- **Zálohovat teď:** dump na pozadí do `/var/backups/wspanel`.
- **Plán záloh:** denně nebo týdně, a kolik záloh držet.
- **Stáhnout:** záloha jako soubor (`.sql.gz` / PostgreSQL custom formát).
- **Obnovit:** před obnovou se automaticky udělá záloha aktuálního stavu.

Zálohy jsou na stejném serveru. Pro skutečnou ochranu dat je kopírujte i jinam.

## Změna hesla

Řádek databáze → **Nové heslo**. U databáze napojené na web se nové heslo
rovnou zapíše do `.env`. Aplikace v worker/Node režimu a workery je načtou po
restartu webu.

## Smazání

Řádek databáze → **Smazat** (pro potvrzení napište název). Smaže databázi i
jejího uživatele. Zálohy zůstanou.
