# API a příkazová řádka

**Účet → API a CLI**: osobní tokeny. Token jedná za svého uživatele: klient
vidí a mění jen své weby, správce všechno. Token je buď **jen ke čtení**, nebo
smí i **měnit** (deploy, restart). Platí 30 či 90 dní, rok, nebo bez omezení.
Ukáže se jen při vytvoření, panel si ukládá jen jeho otisk (SHA-256).
Token suspendovaného klienta neplatí.

Kde je přístup k panelu omezený na vybrané adresy (Firewall → Přístup
k panelu), platí to i pro API.

## REST API v1

`https://<panel>/api/v1`, hlavička `Authorization: Bearer wspa_…`, odpovědi
v JSON. Limit 120 požadavků za minutu. Formální popis je v
[openapi.yaml](openapi.yaml).

| Metoda | Cesta | Co dělá |
|---|---|---|
| GET | `/me` | kdo je přihlášený (role, klient) |
| GET | `/sites` | weby |
| GET | `/sites/{doména}` | detail webu (aliasy, databáze, Valkey, poslední kontrola) |
| GET | `/sites/{doména}/deploys` | historie deployů |
| POST | `/sites/{doména}/deploy` | spustí deploy, volitelně `{"ref": "v1.2.3"}`; vrací `release` |
| POST | `/sites/{doména}/restart` | restart aplikace a workerů |
| GET | `/databases` | databáze |
| GET | `/mail/domains` | poštovní domény (počet schránek, webmail) |
| GET | `/dns/zones` | DNS zóny |

Cizí web vrací 404 stejně jako neexistující. Token jen ke čtení dostane
u POST odpověď 403.

```sh
curl -H "Authorization: Bearer $TOKEN" https://panel.example.com/api/v1/sites
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"ref":"v1.4.0"}' https://panel.example.com/api/v1/sites/example.com/deploy
```

## Příkaz wspanel

Binárky pro Linux a macOS sestaví `make cli-build` (`build/wspanel-<os>-<arch>`).
Na serveru je nainstalovaný jako `/usr/local/bin/wspanel`.

```sh
wspanel login https://panel.example.com wspa_…   # uloží do ~/.config/wspanel/config.json (0600)
wspanel sites
wspanel site example.com
wspanel deploy example.com v1.4.0 --wait         # počká na výsledek
wspanel deploys example.com
wspanel restart example.com
wspanel databases | wspanel mail | wspanel dns
wspanel sites --json                             # odpověď API, jak přišla
```

Proměnné `WSPANEL_URL` a `WSPANEL_TOKEN` mají přednost před uloženým
přihlášením, hodí se třeba v CI.
