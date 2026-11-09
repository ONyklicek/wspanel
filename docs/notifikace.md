# Notifikace

**Účet → Notifikace**: každý uživatel panelu si nastaví vlastní kanály.

| Kanál | Co zadat |
|---|---|
| E-mail | adresa (posílá lokální Postfix jako `wspanel@<server pošty>`) |
| Slack | Incoming Webhook (`https://hooks.slack.com/services/…`) |
| Discord | webhook kanálu (`https://discord.com/api/webhooks/…`) |
| Telegram | token bota od @BotFather a chat ID |
| ntfy | téma (topic) na ntfy.sh, odběr v aplikaci ntfy (Android, iOS); u rezervovaného tématu přístupový token |

Webhooky smí mířit jen na adresy těchto služeb, panel nepošle požadavek na
libovolnou adresu. Token bota se ukládá šifrovaně. **Zkusit** pošle zkušební
zprávu, u kanálu je vidět poslední chyba.

## Co přichází

- **Nový problém**: web neodpovídá, web v chybovém stavu, chyba certifikátu
  (vypršel, chybí, brzy vyprší), spadlá služba, naplánovaná úloha (např.
  záloha), jejíž poslední běh selhal. Správce navíc dostává zaplněný disk
  (90 %+), docházející paměť (95 %+), přetížení (zátěž nad dvojnásobkem
  počtu jader), poštu, která ve frontě čeká přes hodinu (10+ zpráv), a agent,
  který neodpovídá.
- **Problém vyřešen**: jen u problému, který byl oznámen.
- **Deploye** (volitelně): dokončené i neúspěšné.

Zprávy chodí jen při změně, ne opakovaně. Problém se oznámí, až trvá dvě
kontroly po sobě (kontrola běží každou minutu), takže jednorázový výpadek
zprávu nespustí. Klient dostává jen zprávy o svých webech, správce o všem.

## Hlídání serveru zvenku

Když spadne celý server (výpadek u poskytovatele, zaplněný disk, zaseknutý
systém), panel to oznámit nemůže. Za něj to pozná
[Healthchecks.io](https://healthchecks.io) (bezplatný účet stačí):

1. Na healthchecks.io založte kontrolu: *Period* 5 minut, *Grace* třeba 10
   minut, a nastavte, kam má posílat upozornění (e-mail, SMS, Slack…).
2. V panelu **Účet → Notifikace → Hlídání serveru zvenku** (jen správce)
   vložte její *Ping URL* (`https://hc-ping.com/…`).

Panel pak každých pět minut pošle signál, že běží. Při vážném problému
(spadlá služba, nedostupný web, vypršený certifikát…) pošle místo něj
`/fail` se seznamem problémů. Když signály přestanou chodit, ozve se
Healthchecks.io. Stav posledního signálu je vidět na stránce Notifikace.
Panel posílá jen na `hc-ping.com`, nikam jinam.

## Odesílání e-mailů (externí SMTP)

E-maily panelu (notifikace e-mailem, obnova hesla) odchází poštou tohoto
serveru. Když server ven poštu neposílá (poskytovatel blokuje port 25,
chybí reverzní záznam PTR), nastavte v **Účet → Notifikace → Odesílání
e-mailů** externí SMTP server (Gmail, Microsoft 365, SendGrid, Mailgun,
Amazon SES, Seznam…): server, port, šifrování (STARTTLS na 587, SSL/TLS na
465), uživatel, heslo a odesílatel, kterého SMTP server dovolí. Heslo se
uloží šifrovaně klíčem aplikace a už se nezobrazí; při novém uložení bez
hesla zůstane původní. Po uložení odejde zkušební e-mail na vaši adresu,
další kdykoli tlačítkem **Zkušební e-mail**. Nastavení je jen pro správce
a platí pro celý panel; maily webů jdou dál jejich vlastní cestou.

