# Notifikace

**Účet → Notifikace**: každý uživatel panelu si nastaví vlastní kanály.

| Kanál | Co zadat |
|---|---|
| E-mail | adresa (posílá lokální Postfix jako `wspanel@<server pošty>`) |
| Slack | Incoming Webhook (`https://hooks.slack.com/services/…`) |
| Discord | webhook kanálu (`https://discord.com/api/webhooks/…`) |
| Telegram | token bota od @BotFather a chat ID |

Webhooky smí mířit jen na adresy těchto služeb, panel nepošle požadavek na
libovolnou adresu. Token bota se ukládá šifrovaně. **Zkusit** pošle zkušební
zprávu, u kanálu je vidět poslední chyba.

## Co přichází

- **Nový problém**: web neodpovídá, chyba certifikátu (vypršel, chybí, brzy
  vyprší), spadlá služba. Správce navíc dostává zaplněný disk (90 %+) a agent,
  který neodpovídá.
- **Problém vyřešen**: jen u problému, který byl oznámen.
- **Deploye** (volitelně): dokončené i neúspěšné.

Zprávy chodí jen při změně, ne opakovaně. Problém se oznámí, až trvá dvě
kontroly po sobě (kontrola běží každou minutu), takže jednorázový výpadek
zprávu nespustí. Klient dostává jen zprávy o svých webech, správce o všem.
