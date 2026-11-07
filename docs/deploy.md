# Deploy

Deploy nasadí novou verzi aplikace bez výpadku. Nová verze se připraví vedle
běžící (`releases/<čas>`) a až když je hotová a odpovídá, web se na ni přepne.

```
Git / CI ──► releases/20261107T140000Z ──► build ──► přepnutí current ──► kontrola
                                                         │                    │
                                                  web běží dál na        když neodpovídá:
                                                  staré verzi            návrat na předchozí
```

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
   zapíše sám. U Laravelu nezapomeňte na `APP_KEY`
   (`php artisan key:generate --show`).
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
   - Laravel: `migrate --force`, `storage:link`, `optimize`,
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

## Webhook: deploy po pushi nebo releasu

Deploy → **Webhook** → Zapnuto. Zvolte, co deploy spustí:

- **Push do větve:** nasadí se nejnovější commit nastavené větve.
- **Nový release nebo tag:** nasadí se přesně ten tag (`v1.4.0`). Push do
  větví se ignoruje.

Nastavení u Git hostingu (URL a secret zkopírujte z okna Webhook):

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
