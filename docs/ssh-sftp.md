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
- Správci serveru (root) se přihlašují podle volby při instalaci: **heslem
  i klíčem** (výchozí v průvodci), nebo **jen klíčem**. Jen klíčem průvodce
  nabídne, až když má root (nebo jiný správce se `sudo`) SSH klíč, jinak si
  ho vyžádá. Klíč hledá tam, kde ho hledá sshd (`AuthorizedKeysFile`, i
  `authorized_keys2`, i klíče s volbami jako `from=` nebo `restrict`).
  Neinteraktivní instalace bez klíče správce nic nepovolí navíc: heslo pro
  roota podrží jen tehdy, když ho server měl už před instalací (VPS
  s heslem od poskytovatele); server, kde root heslem nemohl, zůstane jen
  s klíči a instalace na konci upozorní. Změna: instalace znovu
  s `--ssh-password=yes|no` (a případně `--ssh-key=…`).

### Nejde se přihlásit jako root heslem

Server přijímá od správců jen klíč (`Authentication that can continue:
publickey`). Přihlaste se konzolí VPS u poskytovatele a buď přidejte klíč:

```
mkdir -p /root/.ssh && chmod 700 /root/.ssh
echo 'ssh-ed25519 AAAA… vas-klic' >> /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
```

nebo povolte heslo: `wspanel-install` a v SSH zvolte *Heslem i klíčem*
(neinteraktivně `./install --yes --ssh-password=yes`).
- Změna nastavení ukončí běžící SSH relace webu.
- Co uživatel dělá v shellu, panel neaudituje (agent audituje jen své operace).
