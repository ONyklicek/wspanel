# Pošta

WSPanel řeší **odchozí poštu z webů**: registrace, objednávky, reset hesla,
notifikace. Schránky (příjem pošty, IMAP) server nemá. Ty nechte u
poskytovatele (Google Workspace, Microsoft 365, Seznam, Forpsi…) a nastavte
jeho MX záznamy.

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

**Pošta → Log pošty:** doručení, odmítnutí a odložené zprávy (Postfix, OpenDKIM).
Počet zpráv ve frontě je v části Stav. Zprávy, které nejde doručit, Postfix
zkouší znovu až 5 dní a pak je vrátí odesílateli.
