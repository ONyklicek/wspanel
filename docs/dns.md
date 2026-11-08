# DNS

Panel umí spravovat DNS zóny **na tomto serveru** (PowerDNS) i **v Cloudflare**.
Zónu nemusíte v panelu mít vůbec. Když zůstane u registrátora, panel aspoň
poradí, jaké záznamy nastavit, a ověří je.

## Která možnost

| | Tento server (PowerDNS) | Cloudflare | Jen u registrátora |
|---|---|---|---|
| Záznamy upravujete | v panelu | v panelu (přes API) | u registrátora |
| Panel doplní záznamy webu a pošty | ano | ano | ne, jen je ukáže |
| Co nastavit u registrátora | nameservery na tento server | nameservery Cloudflare | nic |
| Výpadek serveru | doména neodpovídá ani v DNS | DNS běží dál | DNS běží dál |

Lokální DNS je potřeba zapnout při instalaci (`--dns=powerdns`). Server je
autoritativní jen pro své zóny a nic jiného nepřekládá (žádná rekurze).

## Přidání zóny

**DNS → Přidat zónu:**

- **Doména:** např. `example.com`.
- **Kde je zóna:**
  - **Tento server:** zadejte názvy nameserverů. Výchozí `ns1.<panel>` a
    `ns2.<panel>`, oba míří na tento server.
  - **Cloudflare:** API token. Vytvoříte ho v My Profile → API Tokens →
    Create Token → šablona „Edit zone DNS“, omezený na danou zónu. Panel ho
    ukládá šifrovaně.
- **Doporučené záznamy:** rovnou přidá:
  - A/AAAA na tento server,
  - CAA pro Let's Encrypt a ZeroSSL,
  - SPF a DMARC, a je-li klíč, i DKIM.

## Delegace u registrátora (lokální DNS)

Aby svět ptal tento server, nastavte u registrátora domény nameservery
`ns1.<panel>` a `ns2.<panel>`. Pokud jsou nameservery ve stejné doméně jako
zóna, registrátor bude chtít i jejich IP adresy (glue záznamy). Zadejte IP
serveru.

Pro vyšší spolehlivost je dobré mít druhý (sekundární) DNS server u jiného
poskytovatele. Kvůli tomu je ve výchozím stavu `ns2`.

## Úprava záznamů

Detail zóny → tabulka **Záznamy**: přidat, upravit, smazat. Záznamy se
spravují po skupinách: všechny hodnoty jednoho názvu a typu dohromady.

| Typ | Hodnota (příklad) |
|---|---|
| A | `203.0.113.10` |
| AAAA | `2001:db8::10` |
| CNAME | `example.com` (nelze na kořeni domény) |
| MX | `10 mx1.mailprovider.com` |
| TXT | `v=spf1 ip4:203.0.113.10 ~all` (bez uvozovek, dlouhé hodnoty se rozdělí samy) |
| SRV | `10 5 5060 sip.example.com` |
| CAA | `0 issue "letsencrypt.org"` |
| NS | `ns1.example.net` |

Název: `@` nebo prázdné = doména sama, jinak např. `www`, `mail`, `_dmarc`.

## Kontrola

Detail zóny → **Kontrola DNS** ověří přes veřejné DNS:
- A záznam míří na tento server,
- nameservery jsou delegované (u lokálních zón),
- existuje SPF a DMARC.

Změny se šíří podle TTL, obvykle minuty až hodiny.

## Kontrola před zapnutím

Funkce, které bez DNS nefungují, se před zapnutím na DNS podívají:

| Funkce | Co musí být v DNS |
|---|---|
| Další a přesměrované domény webu (nově přidané) | míří na server (certifikát) |
| HTTPS → přepnutí na automatický certifikát | doména webu a jeho další domény míří na server |
| Webmail a automatické nastavení programů (při zapnutí) | `webmail.`, `autoconfig.`, `autodiscover.` míří na server |
| Přesměrování schránky nebo alias mimo server | SPF serveru pošty (viz [Pošta](posta.md#přeposílání-mimo-server-srs)) |

Když něco chybí, dialog to ukáže a uložit jde až po potvrzení **Zapnout
i tak** (DNS často nastavujete hned potom a šíří se podle TTL). Jména jen pro
vnitřní síť (`.local`, `.test`…) a server bez nastavené veřejné IP se
nekontrolují. Nová poštovní doména kontrolu MX nemá záměrně: schránky se
zakládají dřív, než se MX přepne.
