# SSH a SFTP

Každý web má vlastního uživatele `web_<doména>` (např. `web_example_com`).
Přes SSH/SFTP se přihlašuje jako on a vidí jen svůj web.

## Zapnutí

Detail webu → **Nastavení → Přístup → SSH / SFTP**:

- **Přístup:**
  - **Vypnuto:** žádné přihlášení (výchozí).
  - **Jen SFTP:** přenos souborů, bez shellu. Pro nahrávání webů a správu souborů.
  - **SSH + SFTP:** shell s `php` a `node` ve verzi webu, `composer`, `npm`,
    `git`, `mc`, `nano`.
- **Heslo:**
  - **Vygenerovat heslo:** zobrazí se jen jednou, panel ho neukládá,
  - **Zadat vlastní:** alespoň 12 znaků,
  - **Zrušit heslo:** zůstane jen přihlášení klíčem.
- **Veřejné klíče:** jeden na řádek (`ssh-ed25519 AAAA… jmeno@pocitac`). Klíč je
  bezpečnější než heslo. Lze mít klíč i heslo zároveň.

## Připojení

Přesný příkaz je na detailu webu v části **Přístup**:

```
sftp -P 22 web_example_com@panel.example.com
ssh  -p 22 web_example_com@panel.example.com
```

Grafické klienty (FileZilla, Cyberduck, WinSCP) nastavte takto:
- protokol SFTP, port 22,
- uživatel `web_<doména>`,
- heslo nebo soubor s klíčem.

Po přihlášení jste v adresáři webu:
- `public_html/` (Čisté PHP), nebo `current/public/`: veřejné soubory,
- `shared/.env`: konfigurace a přístupové údaje,
- `logs/`: PHP chyby.

## Omezení (záměrně)

- Žádné přesměrování portů ani tunely. Přes účet webu se nedostanete k
  databázím ani k jiným webům.
- Klíče spravuje panel v `/etc/ssh/authorized_keys/<uživatel>`. Uživatel si
  sám klíč nepřidá.
- Opakované chybné přihlášení zablokuje IP adresu (fail2ban, 5 pokusů →
  1 hodina).
- Root se heslem nepřihlásí nikdy.
- Změna nastavení ukončí běžící SSH relace webu.
- Co uživatel dělá v shellu, panel neaudituje (agent audituje jen své operace).
