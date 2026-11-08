# Pošta

WSPanel řeší **odchozí poštu z webů** (registrace, objednávky, reset hesla)
a volitelně i **schránky**: příjem pošty, IMAP/POP3, odesílání z poštovních
programů, aliasy, přeposílání, automatické odpovědi a antispam. Schránky se
zapínají při instalaci (`install.sh --mail-hosting=yes`) nebo později
v **Server → Moduly**. Bez nich nechte schránky u poskytovatele (Google
Workspace, Microsoft 365, Seznam…) a nastavte jeho MX záznamy.

## Dva režimy každého webu

| Režim | Kam pošta jde | Kdy |
|---|---|---|
| **Odesílá** | přes Postfix na serveru příjemcům (nebo přes relay) | produkce |
| **Zachytává** | do **Mailpitu**, nic neodejde ven | vývoj, testy, staging, kopie produkce |

Přepnutí: **Pošta** → u webu „Začít odesílat“ / „Zachytávat v Mailpitu“.
Workery a worker/Node weby změnu načtou po restartu webu.

## Jak to aplikace používají (nic nastavovat nemusíte)

Panel zapíše do `.env` webu:

- **Laravel:** `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=25`
  (Mailpit 1025), `MAIL_USERNAME`, `MAIL_PASSWORD`,
  `MAIL_FROM_ADDRESS=noreply@<doména>`.
- **Symfony:** `MAILER_DSN=smtp://<login>:<heslo>@127.0.0.1:25`.
- **Node:** `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`.
- **PHP `mail()`** (WordPress, Čisté PHP) funguje bez nastavení, odesílá jako
  `noreply@<doména>`.

## Mailpit: prohlížení zachycené pošty

**Pošta → Otevřít Mailpit** (adresa `https://<panel>/mailpit/`). Je přístupný
jen po přihlášení do panelu. Ukazuje HTML i textovou podobu, hlavičky, přílohy
a kontrolu odkazů. Uchovává posledních 5 000 zpráv.

## Aby pošta nepadala do spamu

U každé domény, ze které web posílá:

1. **Pošta → Vytvořit DKIM klíč.** Panel ukáže tři TXT záznamy:
   - **SPF** (`v=spf1 ip4:<IP serveru> ~all`): kdo smí posílat poštu za doménu.
     Pokud doména posílá i odjinud (Google, Microsoft…), záznamy spojte do
     jednoho, např. `v=spf1 ip4:203.0.113.10 include:_spf.google.com ~all`.
   - **DKIM** (`wspanel._domainkey.<doména>`): veřejný klíč podpisu.
   - **DMARC** (`_dmarc.<doména>`): co mají příjemci dělat s nepodepsanou poštou.
2. Záznamy vložte do DNS. Je-li zóna v panelu: **DNS → zóna → Doplnit
   doporučené**.
3. U poskytovatele serveru nastavte **reverzní záznam (PTR)** IP adresy na
   doménu panelu.

## Relay (SMTP poskytovatel)

Mnoho poskytovatelů blokuje odchozí port 25 a nová IP adresa nemá reputaci.
Spolehlivější je posílat přes službu jako Mailgun, Amazon SES, Postmark nebo
Brevo:

1. **Pošta → Relay (SMTP poskytovatel):** server, port (obvykle 587),
   uživatel, heslo / API klíč. Spojení je vždy šifrované, heslo zůstane jen na
   serveru.
2. Do SPF přidejte `include:` poskytovatele a u něj ověřte doménu.

Prázdný server = doručovat přímo.

## Bezpečnost odesílání

- Postfix přijímá poštu **jen ze serveru samotného** (port 25 na loopbacku) a
  jen od přihlášených webů.
- **Každý web smí odesílat jen za svou doménu.** Přes SMTP mu to hlídá jeho
  přihlášení, přes `sendmail` jeho systémový uživatel. Web `a.cz` tedy nemůže
  poslat podepsanou poštu jako `b.cz`.
- DKIM se podepisuje podle ověřeného odesílatele, ne podle hlavičky From.
- Zachycené zprávy v Mailpitu se zobrazují v izolovaném rámci, takže HTML z
  e-mailu nemůže jednat jménem přihlášeného správce.

## Log a fronta

**Pošta → Fronta** ukáže zprávy, které čekají na doručení, a proč (server
příjemce neodpovídá, odložil je…). Jde je poslat znovu, nebo smazat jednotlivě
či všechny.

**Pošta → Log pošty:** doručení, odmítnutí a odložené zprávy (Postfix, OpenDKIM).
Zprávy, které nejde doručit, Postfix
zkouší znovu až 5 dní a pak je vrátí odesílateli.


## Schránky (příjem pošty)

**Síť a pošta → Schránky → Přidat doménu**, pak v detailu domény. Doména
musí patřit vašemu webu (jeho doména, alias nebo nadřazená doména) nebo vaší
DNS zóně. Doménu, kterou používá web či zóna jiného vlastníka, nezřídí nikdo
(ani správce: četl by jeho poštu). Při přesunu webu ke klientovi jde jeho
pošta s ním.

- **Nová schránka:** adresa, heslo (prázdné = vygeneruje se a ukáže jednou),
  velikost. Heslo panel odešle na server jen jako hash (SHA512-CRYPT),
  v čitelné podobě ho neuloží nikde.
- **Velikost a stav:** kvóta; zablokovaná schránka se nepřihlásí (přihlášená
  sezení se ukončí), pošta do ní chodí dál.
- **Automatická odpověď:** předmět, text, jednomu odesílateli nejvýš jednou
  za N dní. Platí vedle filtrů, které si schránka nastaví ve webmailu.
- **Webmail:** otevře webmail domény rovnou přihlášený za tuto schránku
  (jednorázový odkaz z panelu, bez hesla).
- **Profil pro iPhone a Mac:** soubor `.mobileconfig`, který nastaví účet
  v Mailu (heslo si zařízení vyžádá).
- **Aliasy a přeposílání:** adresa → jedna či víc adres (i mimo server);
  prázdná adresa = **catch-all** (vše, co nemá vlastní schránku). Schránka
  smí odesílat i jako alias, který na ni doručuje.
- U klientů omezuje počet schránek a jejich součet velikostí balíček.

### Webmail

Každá poštovní doména má vlastní webmail (Roundcube) na
**`https://webmail.<doména>`**, žádný společný. Přihlašuje se jen jménem
schránky: na `webmail.firma.cz` stačí `info` (= `info@firma.cz`). Adresa jiné
domény se tam nepřihlásí. Ve webmailu jsou filtry (Sieve), archiv a tlačítko
**Spam**; přesun zprávy do složky Junk (z webmailu i z jakéhokoli programu)
učí antispam, přesun ven ho učí opak.

Zapíná se a vypíná v detailu domény (**Webmail a nastavení programů**),
nová doména ho má zapnutý. Potřebuje DNS záznam `webmail.<doména>`; certifikát
server získá sám, jakmile na něj DNS ukazuje. Adresy `webmail.`, `autoconfig.`
a `autodiscover.` poštovní domény si nemůže vzít žádný web. Pokud už některou
web má, zůstane ta funkce vypnutá.

Heslo schránky mění v panelu správce nebo klient, webmail změnu hesla nenabízí.

### Poštovní programy

| | Server | Port |
|---|---|---|
| IMAP | `<server pošty>` | 993 (SSL/TLS) |
| POP3 | `<server pošty>` | 995 (SSL/TLS) |
| SMTP | `<server pošty>` | 465 (SSL/TLS) nebo 587 (STARTTLS) |
| Filtry (Sieve) | `<server pošty>` | 4190 |

Přihlašuje se celou e-mailovou adresou. Thunderbird a Outlook si nastavení
najdou samy (`autoconfig.<doména>`, `autodiscover.<doména>`, zapnuté spolu
s webmailem v detailu domény), iPhone a Mac přes stažený profil. Server pošty je doména panelu
(`--panel-domain`); jeho certifikát je stejný jako certifikát panelu a obnovuje se
s ním.

### DNS

**DNS → zóna → Doplnit doporučené** přidá k doméně se schránkami `MX`
(na server pošty), SRV záznamy `_imaps`, `_pop3s`, `_submission`, podle
kterých si poštovní programy server najdou samy, a CNAME `webmail`,
`autoconfig` a `autodiscover` na server pošty. SPF, DKIM a DMARC zůstávají
jako u odchozí pošty.

### Doručitelnost

**Kontrola doručitelnosti** v detailu domény ukáže najednou, co kontrolují
ostatní servery: MX, adresu serveru pošty, reverzní záznam (PTR, nastavuje ho
poskytovatel serveru), SPF, DKIM (porovná klíč v DNS s tím, kterým server
podepisuje), DMARC, tři DNS blacklisty a záznamy webmailu. Pošta odeslaná
ze schránek (programy i webmail) je podepsaná DKIM klíčem domény, který
vznikne s poštovní doménou.

### Antispam

Rspamd hodnotí každou příchozí zprávu: nad 15 bodů ji odmítne, nad 6 ji
označí a Sieve ji přesune do složky **Junk**. Testovací zpráva GTUBE se
odmítá vždy. Odesílání z programů vyžaduje přihlášení a schránka smí
odesílat jen za sebe (a své aliasy); neúspěšná přihlášení hlídá fail2ban.
**Antispam** v detailu domény: vlastní prahy (do Junk od, odmítnout od),
seznam odesílatelů, které přijmout vždy (bez kontroly), a seznam odesílatelů,
které vždy odmítnout. Obojí jako adresa nebo `@doména`. Zprávu GTUBE
odmítne Rspamd vždy, i od povoleného odesílatele.

Statistiky učení drží Rspamd ve vlastní instanci Valkey (jen unix socket).
Jeho rozhraní (učení, statistiky) chce heslo i z localhostu, které má jen
root a doručovací proces.

Port 25 je s příjmem pošty veřejný, ale SMTP přihlášení webů na něm zvenku
nefungují: AUTH se nenabízí a ani úspěšné přihlášení zvenku nic nepřepošle.
Weby dál posílají přes loopback (bez STARTTLS: certifikát patří serveru pošty,
ne adrese 127.0.0.1). Schránky odesílají přes 465/587.
