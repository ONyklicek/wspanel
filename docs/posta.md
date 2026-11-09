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

Přepnutí: **Pošta → Odesílání z webů**, u webu „Začít odesílat“ / „Zachytávat v Mailpitu“.
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

**Pošta → Odesílání z webů → Otevřít Mailpit** (adresa `https://<panel>/mailpit/`). Je přístupný
jen po přihlášení do panelu. Ukazuje HTML i textovou podobu, hlavičky, přílohy
a kontrolu odkazů. Uchovává posledních 5 000 zpráv.

## Aby pošta nepadala do spamu

U každé domény, ze které web posílá:

1. **Pošta → Odesílání z webů → Vytvořit DKIM klíč.** Panel ukáže tři TXT záznamy:
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

1. **Pošta → Odesílání z webů → Relay (SMTP poskytovatel):** server, port (obvykle 587),
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

**Pošta → Odesílání z webů → Fronta** ukáže zprávy, které čekají na doručení, a proč (server
příjemce neodpovídá, odložil je…). Jde je poslat znovu, nebo smazat jednotlivě
či všechny.

**Pošta → Odesílání z webů → Log pošty:** doručení, odmítnutí a odložené zprávy (Postfix, OpenDKIM).
Zprávy, které nejde doručit, Postfix
zkouší znovu až 5 dní a pak je vrátí odesílateli.


## Schránky (příjem pošty)

**Pošta → Domény a schránky → Přidat doménu**, pak v detailu domény. Schránky
a aliasy webu jsou i přímo u webu na záložce **Pošta**; tam jde poštu pro
doménu webu i zřídit (u `www.firma.cz` pro `firma.cz`). Doména
musí patřit vašemu webu (jeho doména, alias nebo nadřazená doména) nebo vaší
DNS zóně. Doménu, kterou používá web či zóna jiného vlastníka, nezřídí nikdo
(ani správce: četl by jeho poštu). Při přesunu webu ke klientovi jde jeho
pošta s ním.

- **Nová schránka:** adresa, heslo (prázdné = vygeneruje se a ukáže jednou),
  velikost. Heslo panel odešle na server jen jako hash (SHA512-CRYPT),
  v čitelné podobě ho neuloží nikde. Dokud MX domény nemíří na server pošty
  nebo SPF nepovoluje server, panel to ukáže a schránku založí až po
  potvrzení „Založit i tak“. Úplně to nezakazuje: při převodu pošty odjinud
  se schránky zakládají předem a MX se přepne až po přenosu zpráv. Stav DNS
  ukazuje i sloupec **DNS pro poštu** v seznamu domén.
- **Velikost a stav:** kvóta; zablokovaná schránka se nepřihlásí (přihlášená
  sezení se ukončí), pošta do ní chodí dál.
- **Automatická odpověď:** předmět, text, jednomu odesílateli nejvýš jednou
  za N dní. Platí vedle filtrů, které si schránka nastaví ve webmailu.
- **Webmail:** otevře webmail domény rovnou přihlášený za tuto schránku
  (jednorázový odkaz z panelu, bez hesla).
- **Profil pro iPhone a Mac:** soubor `.mobileconfig`, který nastaví účet
  v Mailu (heslo si zařízení vyžádá).
- **Přesměrování:** příchozí pošta schránky se přepošle na jinou schránku
  nebo jakoukoli adresu; **Ponechat kopii ve schránce** Ano/Ne. Při „Ne“
  zůstane schránka prázdná a neběží ani její automatická odpověď.
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

Heslo si schránka změní sama ve webmailu (**Nastavení → Heslo**, se zadáním
současného hesla, nejméně 10 znaků). Ostatní přihlášení se starým heslem
skončí. Po pěti chybných pokusech počká 15 minut. Při otevření webmailu
z panelu změna hesla není (sezení heslo schránky nezná). Kdo heslo zapomněl,
tomu ho nastaví správce nebo klient v panelu.

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
podepisuje), DMARC, SPF serveru pošty (kvůli přeposílání, viz níže), tři DNS
blacklisty a záznamy webmailu. Pošta odeslaná
ze schránek (programy i webmail) je podepsaná DKIM klíčem domény, který
vznikne s poštovní doménou.

### DMARC reporty

Velké poštovní servery (Gmail, Outlook, Seznam…) posílají jednou denně souhrn:
kolik zpráv s vaší doménou v adrese odesílatele dostaly, ze kterých serverů,
a jestli prošly kontrolou DKIM a SPF. Doporučený DMARC záznam domény s poštou
na tomto serveru je posílá na `dmarc-reports@<doména>`
(`v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@<doména>`).

Tato adresa nepotřebuje schránku: server zprávy předá panelu (každých pět
minut je zpracuje) a výsledek je v detailu domény v sekci **DMARC reporty**:
odesílající servery, počet zpráv, výsledek DKIM a SPF a co s poštou příjemce
udělal. Přehled nad tabulkou shrnuje posledních 30 dní. Filtr *Výsledek DMARC
→ neprošlo* ukáže, kdo posílá poštu jménem domény bez podpisu: buď podvržená
pošta, nebo služba, která za doménu posílá (newsletter, fakturace, helpdesk)
a chybí v SPF nebo nemá DKIM. Reporty starší 180 dní se mažou.

Kdo chce reporty číst sám, založí schránku `dmarc-reports` (ta má přednost).
Doména, jejíž poštu přijímá jiný server, reporty sem dostávat nemůže
(`rua` pak míří na `postmaster@<doména>`).

### Přeposílání mimo server (SRS)

Přesměrování i aliasy si nastavuje vlastník schránky. „Cizí“ je jen
odesílatel zprávy, která přišla zvenku. Příklad: `info@firma.cz` se přeposílá
na `petr@seznam.cz` a přijde mu zpráva od `jan@gmail.com`. Server ji pošle dál
na Seznam, pořád s odesílatelem `jan@gmail.com`. Seznam se podívá do SPF
Gmailu, náš server tam nenajde a zprávu odmítne nebo ji označí jako spam.

Server proto obálkového odesílatele takové zprávy přepíše (SRS, PostSRSd) na
`SRS0=…@<server pošty>`. Nedoručenky na tuto adresu převede zpět původnímu
odesílateli. Hlavička `From` se nemění, příjemce dál vidí `jan@gmail.com`.
Odesílatelé z domén tohoto serveru (weby, poštovní domény) se nepřepisují
nikdy. Přesměrování na schránku na tomto serveru se SRS netýká, doručí se
přímo.

Aby přeposlaná pošta prošla SPF, potřebuje server pošty vlastní SPF záznam,
např. `mail.example.net TXT "v=spf1 a -all"`. Je-li zóna serveru pošty
v panelu, doplní ho **DNS → zóna → Doplnit doporučené**. U externího DNS ho
hlídá kontrola doručitelnosti a přesměrování nebo alias mimo server bez něj
jde uložit jen s potvrzením (viz [Kontrola před zapnutím](dns.md#kontrola-před-zapnutím)).
S relayem (SMTP poskytovatel) rozhoduje poskytovatel: mnozí přeposílání cizí
pošty nedovolí.

V doručených zprávách je proto i u běžné příchozí pošty `Return-Path`
v podobě `SRS0=…`; seznamy antispamu s tím počítají.

### Limity odesílání

Ukradené heslo schránky nebo napadený web rozešle během chvíle tisíce zpráv
a adresa serveru skončí na blacklistech, takže pak nedojde pošta nikomu.
**Schránky → Limity odesílání** (jen správce) určí, kolik zpráv za hodinu smí
odeslat:

- **schránka** (poštovní programy a webmail), výchozí 200,
- **web** přes své SMTP přihlášení (Laravel, Symfony, Node), výchozí 1 000.

0 = bez limitu. Nad limit server zprávu dočasně odmítne (451) a odesílatel ji
zkusí později, nic se neztratí. Limit se dobíjí průběžně během hodiny. Pošta
přes PHP `mail()` limitem neprochází.

### Antispam

Rspamd hodnotí každou příchozí zprávu: nad 15 bodů ji odmítne, nad 6 ji
označí a Sieve ji přesune do složky **Junk**. Testovací zpráva GTUBE se
odmítá vždy. Odesílání z programů vyžaduje přihlášení a schránka smí
odesílat jen za sebe (a své aliasy); neúspěšná přihlášení hlídá fail2ban.
**Antispam** v detailu domény: odesílatelé, jejichž pošta nikdy nepadne do
Junk, a odesílatelé, jejichž pošta se tiše zahodí. Obojí jako adresa nebo
`@doména`. Seznamy platí pro každého příjemce zvlášť (při doručení), takže
seznam jedné domény nerozhoduje o poště pro jinou doménu ve stejné zprávě.
Povolený odesílatel platí, jen když projde SPF: Rspamd zahodí každou hlavičku
`Authentication-Results`, kterou si zpráva přinese, a doplní vlastní. Prahy
jsou společné pro celý server (odmítnout od 15 bodů, Junk od 6).

**Schránky → Ochrana pošty** (jen správce) zapne pro celý server:

- **Greylisting:** podezřelou zprávu (od 4 bodů) od odesílatele, kterého
  server ještě neviděl, poprvé odmítne na 5 minut. Skutečné poštovní servery
  to zkusí znovu, většina spamu ne. Taková pošta pak přijde o pár minut
  později. Běžná pošta s nízkým skóre se nezdržuje.
- **Antivir (ClamAV):** každá příchozí zpráva se kontroluje na viry, nakažená
  se odmítne. ClamAV potřebuje asi 1 GB paměti; při zapnutí se nainstaluje
  a stáhne databázi virů (několik minut), při vypnutí se zastaví.

Statistiky učení drží Rspamd ve vlastní instanci Valkey (jen unix socket).
Jeho rozhraní (učení, statistiky) chce heslo i z localhostu, které má jen
root a doručovací proces.

Port 25 je s příjmem pošty veřejný, ale SMTP přihlášení webů na něm zvenku
nefungují: AUTH se nenabízí a ani úspěšné přihlášení zvenku nic nepřepošle.
Weby dál posílají přes loopback (bez STARTTLS: certifikát patří serveru pošty,
ne adrese 127.0.0.1). Schránky odesílají přes 465/587.
