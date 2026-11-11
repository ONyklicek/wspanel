# Deploy

Deploy nasadí novou verzi aplikace bez výpadku. Nová verze se připraví vedle
běžící (`releases/<čas>`) a až když je hotová a odpovídá, web se na ni přepne.

```
Git / CI ──► releases/20261107T140000Z ──► build ──► přepnutí current ──► kontrola
                                                         │                    │
                                                  web běží dál na        když neodpovídá:
                                                  staré verzi            návrat na předchozí
```

> Repozitář na GitHubu jde napojit i jedním kliknutím: panel přes GitHub API
> sám přidá deploy klíč a webhook a deploye hlásí zpět do GitHubu. Viz
> [GitHub](github.md). Převod existující aplikace krok za krokem je
> v [Nasazení existujícího projektu](nasazeni-projektu.md).

## Tři způsoby, jak dostat kód na server

| Způsob | Kdy | Build běží |
|---|---|---|
| **Git + tlačítko / webhook** | běžné weby: push do `main` nasadí | na serveru (composer, npm) |
| **Release / tag** | nasazujete jen vydané verze (`v1.4.0`) | na serveru |
| **CI artefakt** | build a testy v GitHub Actions, na server jde hotový balík | v CI |

U **Čistého PHP** deploy nepotřebujete, stačí nahrát soubory přes SFTP (viz
[Weby](weby.md#čisté-php-wordpress-vlastní-skripty)).

## První nastavení (Git)

Detail webu → **Deploy**:

1. **Nastavení:**
   - **Repozitář:** `git@github.com:firma/app.git` (privátní, přes deploy klíč)
     nebo `https://github.com/firma/app.git` (veřejný),
   - **Větev:** např. `main`,
   - **Migrace:** `artisan migrate --force`, nebo Doctrine migrace,
   - **Assety:** `npm ci && npm run build`, pokud je v repozitáři `package.json`,
   - **Automatický rollback:** když nová verze neodpovídá, vrátí se předchozí,
   - **Držet releasů:** kolik starších verzí nechat pro rychlý rollback.
2. **Deploy klíč** (u privátního repozitáře): zkopírujte veřejný klíč a přidejte
   ho do repozitáře jako *deploy key* jen pro čtení. GitHub: Settings → Deploy
   keys.
3. **.env:** doplňte konfiguraci aplikace. Údaje databáze a pošty tam panel
   zapíše sám, `APP_KEY` (Laravel) / `APP_SECRET` (Symfony) vygeneruje první
   deploy a chybějící klíče doplní z `.env.example` (viz níže).
4. **Deploy:** sledujte živý log. Hotovo, když log skončí řádkem `✓ deployed`.

## Co přesně deploy udělá

Stránka Deploy ukazuje kroky pro konkrétní web. Obecně:

1. **Stažení kódu:** `git clone` větve (nebo tagu či commitu) do
   `releases/<čas>`. U CI artefaktu se místo toho rozbalí balík.
2. **Sdílené soubory:** do releasu se nalinkují `.env` a `storage/` (Laravel),
   resp. `.env.local` a `var/log` (Symfony) ze `shared/`.
3. **Composer:** `composer install --no-dev --optimize-autoloader` (pokud je
   `composer.json`).
4. **Assety:** `npm ci && npm run build` (pokud je zapnuto a je `package.json`).
5. **Framework:**
   - Laravel: `migrate --force`, `storage:link`, `optimize`. Když repozitář
     verzuje `public/storage/.gitignore` (složka místo odkazu), deploy ji nahradí
     odkazem na `storage/app/public`,
   - Symfony: Doctrine migrace, `asset-map:compile`, `cache:warmup` (prod).
6. **Přepnutí:** `current` se atomicky přepne na nový release. PHP-FPM se
   plynule znovu načte, nebo se restartuje proces aplikace. Restartují se i
   workery.
7. **Kontrola:** panel pošle na web požadavek. Při chybě 5xx a zapnutém
   automatickém rollbacku vrátí předchozí release.
8. **Úklid:** smažou se releasy nad nastavený počet.

**Když kterýkoli krok selže,** web se nepřepne a běží dál na staré verzi.
V logu je přesně vidět, který příkaz a s jakým výstupem selhal.

**Bezpečnost:** každý krok běží pod uživatelem webu v izolované systemd unitě
(bez roota, zapisovat smí jen do svého webu), s limitem 30 minut.

## Automatický deploy

Deploy → **Automatický deploy** → Zapnuto. Zvolte, co deploy spustí:

| Spouštěč | Jak to funguje | Panel musí být z internetu dostupný |
|---|---|---|
| **Push do větve** (webhook) | Git hosting pošle webhook, nasadí se nejnovější commit větve | ano (jen adresa webhooku) |
| **Release nebo tag** (webhook) | nasadí se přesně vydaný tag (`v1.4.0`), push do větví se ignoruje | ano (jen adresa webhooku) |
| **Kontrolovat větev** | server se jednou za minutu podívá do repozitáře (`git ls-remote`) a nasadí nový commit | **ne** |
| **Kontrolovat tagy** | jako výše, nasadí nejnovější tag podle čísla verze (`v1.10.0` > `v1.9.3`) | **ne** |

Kontrola repozitáře se hodí, když panel není veřejný (viz
[Bezpečnost](bezpecnost.md#panel-jen-z-vnitřní-sítě)) nebo je server za NATem.
Server se do repozitáře přihlašuje stejně jako při deployi (deploy klíč nebo
veřejné `https://`). Výsledek poslední kontroly, případně chybu, ukazuje okno
Automatický deploy.

- Nasadí se jen změna od poslední kontroly, a jen když ji poslední deploy ještě
  nenasadil. Ruční rollback se tedy nepřepíše a commit, jehož build selhal, se
  nezkouší každou minutu znovu (nasadí se až další commit, nebo ručně).
- Když zrovna běží deploy, nový stav se zařadí a nasadí po jeho skončení.

### Webhook

Nastavení u Git hostingu (URL a secret zkopírujte z okna Automatický deploy).
Tlačítko **Nové URL a secret** vydá nové údaje, staré okamžitě přestanou platit:

| Hosting | Kde | Události |
|---|---|---|
| GitHub | Settings → Webhooks, Content type `application/json` | „Just the push event“, nebo pro releasy „Releases“ |
| Gitea / Forgejo | Settings → Webhooks → Gitea | Push, nebo Release |
| GitLab | Settings → Webhooks, Secret token = secret | Push events, Tag push events nebo Releases events |
| Bitbucket | Repository settings → Webhooks | Repository push |

- Bez platného podpisu se nic nespustí. Každé doručení (i odmítnuté) je vidět
  na stránce Deploy.
- Push během běžícího deploye se zařadí a nasadí se po jeho skončení
  (nejnovější stav).
- Stejný tag se nasadí jen jednou. GitHub při vydání releasu posílá release i
  push tagu.

## Nasazení releasů a z CI (GitHub Actions)

Pro týmy, které vydávají verze přes **GitHub Releases** a mají build a testy v
**GitHub Actions**. Deploy → **GitHub Actions / CI**:

1. **Vytvořit CI token.** Zobrazí se jen jednou, panel ukládá jen jeho otisk.
   Je oddělený od secretu webhooku a jde kdykoli zrušit nebo vyměnit.
2. V GitHubu: Settings → Secrets and variables → Actions → New secret
   `WSPANEL_TOKEN` = token.
3. Do repozitáře přidejte workflow z okna (`.github/workflows/deploy.yml`).
   Panel nabízí dvě varianty s doplněnou adresou:

**A) Release → server nasadí tag.** Action jen řekne panelu „nasaď v1.4.0“ a
počká na výsledek. Build proběhne na serveru.

```yaml
on:
  release:
    types: [published]
# … krok volá:
# POST https://panel.example.com/api/deploy/<id>   {"ref": "v1.4.0"}
```

**B) Build v CI → na server jde hotový balík.** Composer a npm proběhnou v
Actions (s cache, testy…), na server se nahraje `tar.gz` (max. 512 MB). Server
nic nestahuje ani nebuildí. Jen balík rozbalí, nalinkuje `shared/`, spustí
migrace a optimalizaci a přepne web.

```yaml
- run: composer install --no-dev --optimize-autoloader && npm ci && npm run build
- run: tar --exclude=.git --exclude=node_modules -czf /tmp/build.tar.gz .
# POST https://panel.example.com/api/deploy/<id>/artifact  (multipart: artifact, ref, commit)
```

Varianta B nepotřebuje v panelu repozitář: v Deploy → Nastavení nechte pole
**Repozitář** prázdné a nastavte jen migrace, vlastní příkazy a údržbovou
stránku. Tlačítko Deploy a automatický deploy z Gitu pak nejsou dostupné, web
se nasazuje jen z CI. Balík balte na Linuxu, nebo na macOS s
`COPYFILE_DISABLE=1 tar …`, jinak v něm budou soubory `._*`.

V obou variantách workflow čte stav deploye a **selže, když deploy selže nebo
se vrátil zpět**. V GitHubu tak hned vidíte, že release se nenasadil.

### API pro CI

Všechny požadavky mají hlavičku `Authorization: Bearer <CI token>`.

| Požadavek | Význam | Odpověď |
|---|---|---|
| `POST /api/deploy/{id}` s JSON `{"ref": "v1.4.0"}` | nasadí tag, větev nebo commit (bez `ref` nastavenou větev) | `202 {"release", "status_url"}` |
| `POST /api/deploy/{id}/artifact` (multipart `artifact`, volitelně `ref`, `commit`) | nasadí hotový balík `.tar.gz` | `202 {"release", "status_url"}` |
| `GET /api/deploy/{id}/{release}` | stav deploye | `{"status": "running" \| "success" \| "failed" \| "rolled_back", "error", "url"}` |

- `409`: už běží jiný deploy. Ve workflow použijte `concurrency: deploy`.
- `404`: špatné id nebo token.
- Rozbalený balík může mít nejvýš 4 GB. Rozbaluje se pod uživatelem webu, takže
  nemůže zapisovat mimo web.

## Rollback

Deploy → **Rollback** → vyberte release. Web se okamžitě přepne zpět, bez
nového buildu. Databázové migrace se nevracejí. Pokud je potřeba, vraťte je
ručně (`artisan migrate:rollback`).

## .env

Deploy → **.env** upraví sdílený soubor (`shared/.env`, u Symfony
`shared/.env.local`), společný všem releasům. Hodnoty `DB_*`, `MAIL_*` a
`MAILER_DSN` spravuje panel. Změny jiných hodnot se projeví:
- classic weby: hned (Laravel s `optimize` až po deployi nebo restartu),
- worker/Node weby a workery: po **Restartovat**.

## Typické problémy

- **„Composer failed“:** v logu je výstup composeru. Často chybí PHP rozšíření,
  nebo neprošel skript (`post-install-cmd`).
- **„the new release does not answer: 500“:** aplikace na nové verzi padá. Log
  aplikace najdete na detailu webu → Logy (Laravel) a PHP chyby. Nejčastěji
  chybí `APP_KEY` nebo jiná hodnota v `.env`.
- **„Permission denied (publickey)“ při stažení:** deploy klíč není u
  repozitáře.
- **Deploy nelze spustit, web je vypnutý:** nejdřív ho zapněte.
- Další (CI build, `.env`, `public/storage`, FrankenPHP workery):
  [Nasazení existujícího projektu → Problémy](nasazeni-projektu.md#problémy-na-které-jsme-narazili).


## .env z .env.example

Má-li repozitář `.env.example`, každý deploy z něj do `shared/.env` doplní
klíče, které tam ještě nejsou. První deploy tak začne z toho, co aplikace
čeká, a klíč, který přidá nová verze, přijde se svou výchozí hodnotou.

- Nic, co v `shared/.env` už je, se nepřepíše (ani prázdná hodnota): úpravy
  v panelu i hodnoty z CI vyhrávají.
- Klíče panelu (databáze, pošta, Valkey, Reverb) zapisuje panel.
- Prázdné hodnoty se nepřebírají: prázdná hodnota by přebila výchozí hodnotu
  v konfiguraci aplikace, chybějící ne.
- Vývojové hodnoty se nahradí produkčními: `APP_ENV=production`,
  `APP_DEBUG=false`, `APP_URL=https://<doména>`, `NODE_ENV=production`,
  `DEBUG=False`, `FLASK_DEBUG=0`.
- Klíče, které vypadají jako tajemství (název obsahuje `KEY`, `SECRET`,
  `TOKEN`, `PASSWORD`, `PASS`, `SALT`, `PRIVATE`, `CREDENTIAL` nebo `DSN`), se
  nepřebírají nikdy: hodnota v repozitáři je veřejná (`REVERB_APP_SECRET=my-secret`).
  `APP_KEY` / `APP_SECRET` vygeneruje deploy, ostatní log deploye vypíše
  k nastavení v `.env` webu.
- Řádky se kopírují, jak jsou napsané, takže `"${APP_NAME}"` dál funguje.
  Doplněné klíče jsou v `.env` pod komentářem `# From .env.example` a log
  deploye je vypíše.
- Symfony to nepotřebuje: jeho `.env` v repozitáři je šablona sama
  a `shared/.env.local` ji jen doplňuje.

## .env z CI (GitHub Secrets)

Když release přinese vlastní `.env` (typicky ho sestaví GitHub Actions z
`vars` a `secrets` a pošle v artefaktu), panel ho při deployi **sloučí** do
`shared/.env`, který je pak nalinkovaný do releasu:

- hodnoty z releasu se zapíšou; prázdné se přeskočí (prázdný `APP_KEY` ze
  šablony nikdy nesmaže skutečný),
- **výchozí:** klíče, které nastavil panel a které musí sedět se serverem,
  zůstanou panelové: databáze, kterou vytvořil, pošta (`MAIL_*`), Valkey webu
  (`REDIS_*`), kde aplikace publikuje do Reverbu a kde Reverb poslouchá,
  ovladače zvolené u Valkey/Horizonu. Klíče aplikace Reverbu (`REVERB_APP_*`)
  a `VITE_REVERB_*` vyhrávají z releasu (frontend je s nimi sestavený v CI),
  stejně jako klíče Web Push (`VAPID_*`, `VITE_VAPID_PUBLIC_KEY`). Panel je
  vygeneruje se zapnutím Reverbu, jen když v `.env` ještě nejsou, a vždy jako
  pár (formát `php artisan webpush:vapid`; `VAPID_SUBJECT` = adresa webu),
- **„Konfigurace z deploye má přednost“** (Nastavení deploye): platí `.env`
  z releasu celý; panel hlídá jen port, na kterém poslouchá Reverb.

Log deploye vypíše, kolik hodnot převzal a které klíče ponechal panelu.

## Vlastní příkazy po deployi

V Nastavení deploye lze zadat příkazy konzole aplikace (jeden na řádek,
`artisan` / `bin/console` na začátku je nepovinné), např.
`install:permissions` nebo `livewire:publish --assets`. Běží po migracích a
`optimize`, před přepnutím na nový release; když selžou, nový release se
nepoužije. Shell ani jiné programy spustit nejdou.

U balíku z CI panel před prvním `artisan` smaže `bootstrap/cache/*.php`:
cache sestavené na CI runneru znají jeho cesty a balíčky.

## Bez výpadku (zero-downtime)

- **Build vedle běžícího webu:** nový release vzniká v `releases/<čas>`,
  composer, npm, migrace, `optimize` a vlastní příkazy proběhnou dřív, než se
  cokoli přepne. `current` se pak přepne jednou atomickou operací.
- **Klasický režim (PHP-FPM):** FPM se plynule znovu načte; rozdělané
  požadavky dokončí (až 10 s), nové čekají ve frontě, žádný se nezahodí.
- **Worker režim (FrankenPHP / Octane):** server běží dál a vymění jen své PHP
  workery; nové načtou nový release. Žádný požadavek neselže.
- **Workery (fronty, Horizon, Messenger, Pulse):** každý release má vlastní
  instanci workeru, která běží ze svého adresáře `releases/<release>`, nikdy
  přes `current`. Po deployi:
  - nová instance (nový kód) se spustí hned a bere nové úlohy,
  - stará dostane pokyn skončit: rozdělanou úlohu dokončí **se starým kódem**
    a žádnou novou už nevezme (až hodinu, fronta mezitím nestojí),
  - release, ze kterého ještě stará instance běží, se při úklidu nesmaže.
  - Plánovač a Reverb se nesmí spustit dvakrát (dvojí úlohy, jeden port):
    nejdřív skončí starý, pak naběhne nový.
- **Kontrola a rollback:** když nový release neodpoví, `current` se stejně
  atomicky vrátí a workery se vrátí s ním.
- **Migrace** běží před přepnutím, chvíli tedy starý kód běží nad novým
  schématem: pište je zpětně kompatibilně (nejdřív přidat sloupec, odebrat
  až v dalším releasu).
