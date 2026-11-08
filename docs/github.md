# GitHub: napojení repozitáře jedním kliknutím

Podobně jako v Zerops: připojíte účet GitHub, u webu vyberete repozitář a větev
ze seznamu a panel přes GitHub API nastaví všechno ostatní sám. Nic se nekopíruje
ručně (deploy klíč, adresa webhooku, secret) a výsledek každého deploye vidíte
přímo v GitHubu.

| Co panel udělá | Kde to v GitHubu uvidíte |
|---|---|
| přidá **deploy klíč** webu, jen pro čtení | Settings → Deploy keys → „WSPanel *doména*“ |
| nastaví deploy webu na repozitář (`git@github.com:…`) a větev | – |
| založí **webhook** na push (podepsaný secretem) | Settings → Webhooks |
| nebo místo webhooku každou minutu kontroluje repozitář | – |
| na přání uloží **CI token** jako secret `WSPANEL_TOKEN` | Settings → Secrets and variables → Actions |
| každý deploy nahlásí jako **Deployment** se stavem a odkazy | stránka repozitáře → Deployments / Environments |

Ruční cesty (vlastní Git server, GitLab, Bitbucket, webhook zadaný ručně) dál
fungují, viz [Deploy](deploy.md).

## 1. Připojit GitHub tlačítkem

Stejně jako v Zerops: kliknete, na GitHubu vyberete účet nebo organizaci
a repozitáře a vrátíte se do panelu. Nic se nekopíruje.

### Jednou pro celý panel: GitHub aplikace (správce)

Menu účtu (vpravo nahoře) → **API a CLI** → sekce **GitHub** → **Nastavit
připojení tlačítkem** → **Pokračovat na GitHub**.

- Panel pošle GitHubu šablonu (manifest) aplikace s oprávněními níže. Na GitHubu
  jen potvrdíte **Create GitHub App** (název jde změnit, musí být na GitHubu
  jedinečný).
- GitHub vrátí prohlížeč do panelu a panel si od něj převezme klíče aplikace
  (ID, client secret, privátní klíč). Uloží je **šifrovaně**, ručně se nic
  nevyplňuje.
- **Organizace (nepovinné):** vyplňte, když má aplikace patřit organizaci
  místo vašeho účtu.
- Aplikace je **veřejná**, aby ji klienti mohli nainstalovat na své účty. Nic
  jiného, než co je v tabulce oprávnění, neumí, a vlastní webhook nemá.
- Funguje i pro panel ve vnitřní síti (`panel.firma.lan`, `localhost`):
  návraty z GitHubu jdou přes prohlížeč, GitHub panel volat nemusí.

### Každý uživatel: Připojit GitHub

**API a CLI → GitHub → Připojit GitHub**:

1. GitHub otevře instalaci aplikace: vyberete **účet nebo organizaci** a
   **repozitáře** (všechny, nebo jen vybrané).
2. GitHub se zeptá na potvrzení přihlášení (aby panel věděl, že instalace
   patří vám) a vrátí vás do panelu.
3. V tabulce přibude účet s typem *aplikací (tlačítkem)*.

Pro každou organizaci připojte zvlášť (Připojit GitHub → zvolit jinou
organizaci). U organizace, kde nejste vlastník, instalaci schvaluje vlastník.
Panel pak napíše „Nic k připojení“; po schválení klikněte znovu.

**Repozitáře** u účtu otevře nastavení instalace na GitHubu: přidání nebo
odebrání repozitáře, ke kterému má panel přístup.

Bezpečnost tohoto toku:

- Každý návrat z GitHubu musí nést náhodný `state`, který panel poslal ve stejné
  relaci. Jinak ho odmítne (403).
- Instalaci panel připojí, jen když GitHub potvrdí, že k ní má přístup právě
  přihlášený uživatel GitHubu (`GET /user/installations` s jeho tokenem).
  Podstrčené `installation_id` nic nepřipojí.
- Panel neukládá žádný trvalý token: pro každé volání si s privátním klíčem
  aplikace (JWT) vyžádá token instalace platný hodinu, drží ho šifrovaně v cache
  a nechá ho vypršet.
- **Odpojit** u účtu aplikaci z účtu na GitHubu i odinstaluje.

### Oprávnění

Aplikace (i token, pokud ho použijete místo ní) potřebuje tato oprávnění
k repozitářům:

| Oprávnění | Úroveň | Proč |
|---|---|---|
| Administration | Read and write | přidání a odebrání deploy klíče |
| Webhooks | Read and write | založení a odebrání webhooku |
| Deployments | Read and write | hlášení stavu deployů |
| Secrets | Read and write | uložení `WSPANEL_TOKEN` (volitelné) |
| Contents | Read-only | seznam větví |
| Metadata | Read-only | povinné, seznam repozitářů |

## 2. Nebo připojit tokenem

Když GitHub aplikaci nechcete (GitHub Enterprise, zákaz aplikací v organizaci):
**API a CLI → GitHub → Vložit token** (bez nastavené aplikace se tlačítko
jmenuje Připojit GitHub).

GitHub → Settings → Developer settings → **Fine-grained tokens** → Generate new token:

- **Resource owner:** vy nebo organizace, které repozitáře patří,
- **Repository access:** *Only select repositories* a vyberte repozitáře,
- **Repository permissions:** podle tabulky výše,
- **Expiration:** podle vašich pravidel. Po vypršení panel hlásí „GitHub odmítl
  token“ a stačí vložit nový (napojené weby zůstanou).

Panel token ověří (`GET /user`) a uloží šifrovaně. Už se nikde nezobrazí. Funguje
i classic token (`ghp_…`) se scope `repo` a `admin:repo_hook`, ale dává přístup
ke všem repozitářům účtu.

Účet (připojený tlačítkem i tokenem) vidí a používá jen uživatel, který ho
připojil: klient má svůj GitHub, správce svůj.

## 3. Napojit web

Detail webu → **Deploy** → tlačítko **GitHub**:

1. **Účet GitHub**: když jich máte víc.
2. **Repozitář**: repozitáře vybrané při instalaci aplikace, nebo které smí spravovat token (🔒 = privátní).
3. **Větev**: načte se z repozitáře.
4. **Co nasadit**: každý push do větve, nebo nový release / tag (nasadí přesně
   ten tag).
5. **Jak se panel o změně dozví:**
   - **Webhook**: okamžité. Panel musí být z internetu dostupný na své adrese
     (`APP_URL`). Výchozí volba, když panel běží na veřejné doméně.
   - **Kontrola každou minutu**: pro panel ve vnitřní síti, za NATem nebo na
     `*.local`/`*.lan`. Nic nemusí volat dovnitř. Výchozí volba pro neveřejný panel.
6. **Uložit CI token do secretů** (volitelné): pro deploy hotového buildu
   z GitHub Actions. Vytvoří nový CI token (starý přestane platit) a uloží ho
   do repozitáře jako `WSPANEL_TOKEN`, zašifrovaný veřejným klíčem repozitáře.

Ostatní nastavení deploye (migrace, vlastní příkazy, údržbová stránka, počet
releasů) zůstává, jak bylo. Když se kterýkoli krok nepovede (chybí oprávnění,
GitHub nedostupný), panel smaže, co už v repozitáři stihl založit, a web zůstane
nenapojený.

Tlačítko pak ukazuje název repozitáře. Pod ním je **Odpojit**: panel z repozitáře
odebere svůj webhook a deploy klíč, automatický deploy přes webhook vypne
a nastavení deploye nechá.

## Deploye v GitHubu

Každý deploy webu (z tlačítka, webhooku, kontroly repozitáře, z CI i staging →
produkce) se do minuty objeví v GitHubu jako **Deployment** commitu v prostředí
`production`:

- *in progress* → *success*, nebo *failure* (selhal nebo se vrátil zpět),
- **View deployment** vede na web, **log** na stránku Deploy v panelu,
- starší deploye v prostředí GitHub označí jako neaktivní.

Hlásí se jen deploye od napojení. Build nahraný z CI s commitem, který
v repozitáři není (lokální build), se přeskočí. GitHub ho nezná.

## GitHub Actions s hotovým buildem

S uloženým `WSPANEL_TOKEN` stačí ve workflow nahrát balík (vzor najdete
v Deploy → Další → GitHub Actions / CI, včetně doplněné adresy):

```yaml
on:
  release:
    types: [published]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: composer install --no-dev --optimize-autoloader
      - run: npm ci && npm run build
      - run: tar --exclude=.git --exclude=node_modules -czf /tmp/build.tar.gz .
      - run: |
          curl -fsS -H "Authorization: Bearer ${{ secrets.WSPANEL_TOKEN }}" \
            -F artifact=@/tmp/build.tar.gz -F ref=${{ github.ref_name }} -F commit=${{ github.sha }} \
            https://panel.example.com/api/deploy/<id>/artifact
```

V tom případě v okně GitHub nechte „Jak se panel dozví“ na kontrole repozitáře
a v Automatickém deployi ho vypněte. Jinak by se release nasadil dvakrát (jednou
z CI, jednou z gitu). Postup pro skutečný projekt (privátní Composer balíčky,
`.env` z GitHub vars) je v [Nasazení existujícího projektu](nasazeni-projektu.md).

## Bezpečnost

- Klíče GitHub aplikace i vložené tokeny jsou v databázi panelu šifrované
  (`APP_KEY`), ven se nepošlou a v UI se nezobrazí. U aplikace panel pracuje jen
  s hodinovými tokeny instalací.
- Deploy klíč je **jen pro čtení** a patří jen tomuto webu (každý web má svůj).
- Webhook je podepsaný secretem webu a panel přijme každé doručení jen jednou.
- Účet GitHub patří uživateli: klient nepoužije účet správce ani jiného klienta.
- Odpojení účtu odebere webhooky a klíče ze všech jeho napojených repozitářů.

## Problémy

| Hláška | Co s tím |
|---|---|
| GitHub odmítl token | token vypršel nebo byl zrušen; vložte nový (API a CLI → GitHub) |
| „Neplatný nebo prošlý návrat z GitHubu“ | návrat z GitHubu z jiné relace nebo podruhé; klikněte na Připojit GitHub znovu |
| „Nic k připojení“ | instalaci v organizaci musí schválit vlastník, nebo aplikace u účtu není nainstalovaná |
| GitHub aplikace se nepodařila vytvořit | název aplikace je na GitHubu obsazený (změňte ho na stránce GitHubu), nebo kód vypršel (opakujte) |
| Token nemá přístup k repozitáři nebo mu chybí oprávnění | v nastavení tokenu přidejte repozitář do *Repository access* a zkontrolujte tabulku oprávnění výše |
| `key is already in use` | klíč webu už je v repozitáři (přidaný dřív ručně) nebo v jiném repozitáři jako deploy klíč, což GitHub nedovolí; odeberte ho tam (Settings → Deploy keys) a napojte znovu |
| Repozitář chybí v seznamu | u aplikace: Repozitáře → přidat ho do instalace; u tokenu: *Repository access* nebo chybí práva správce. Seznam se obnovuje po 5 minutách |
| Webhook v GitHubu hlásí chyby doručení | panel není z internetu dostupný; přepněte na kontrolu každou minutu (GitHub → Napojit znovu) |
| Deploy se v GitHubu neukazuje | build z CI s commitem, který v repozitáři není, se přeskočí; jinak počkejte minutu (hlásí se při `panel:sync`) |
