# Nasazení existujícího projektu (průvodce)

Postup, jak na WSPanel převést skutečnou aplikaci Laravel, která už má vlastní
build v GitHub Actions, privátní Composer balíčky, fronty (Horizon), WebSockety
(Reverb) a plánovač. Vznikl při ověřování na reálném intranetu (Laravel 13,
Livewire 4, ~60 balíčků, Horizon, Reverb, Pulse) v obou režimech: PHP-FPM
i FrankenPHP. Každý krok popisuje, co udělat a proč.

Celý postup se dá odehrát automaticky proti dev serveru, viz
[Simulace na vlastním projektu](#simulace-na-vlastním-projektu).

## Přehled

```
GitHub Release ──▶ Action: checkout, composer --no-dev, npm build, .env, tar.gz
                                   │  POST /api/deploy/<id>/artifact (CI token)
                                   ▼
panel: rozbalit → .env sloučit → shared/ → údržba → migrace → storage:link
       → optimize → vlastní příkazy → přepnout current → kontrola → workery
                                   │
                                   ▼
Action čte stav a selže, když deploy selže / vrátí se zpět
```

Server nic nestahuje ani nebuildí: nepotřebuje přístup k privátním balíčkům ani
Node. Pokud chcete build na serveru (git clone + composer + npm), použijte
[deploy z Gitu](deploy.md) nebo [napojení na GitHub](github.md).

## 1. Web v panelu

1. **Weby → Nový web**: doména, režim **Classic (PHP-FPM)**, typ **Laravel**,
   PHP podle `composer.json`. Do FrankenPHP se přechází až po prvním funkčním
   deployi (krok 6), chyby se tak hledají po jedné.
2. **Další → Nová databáze**: panel zapíše `DB_*` do sdíleného `.env` webu.
3. **Další → Valkey**: zapnout a v „Použití“ zaškrtnout cache, session a fronty.
   Panel zapíše `REDIS_*`, `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION`.
   Pro Horizon nastavte *nic nemaže* (`noeviction`) a ukládání na disk.
4. **Deploy → Nastavení**:
   - **Repozitář nechte prázdný**: web se nasazuje jen z CI. Tlačítko Deploy
     z Gitu pak není dostupné.
   - **Vlastní příkazy**: to, co dnes dělá post-deploy skript na starém serveru,
     kromě toho, co panel dělá sám (`migrate --force`, `storage:link`, `optimize`).
     U intranetu: `install:permissions`, `erag:update-manifest`.
   - **Údržbová stránka během migrací**: zapnout, když migrace mění schéma,
     se kterým starý kód nepoběží.
5. **Deploy → Další → GitHub Actions / CI → Vytvořit CI token** a uložit ho
   v GitHubu jako secret `WSPANEL_TOKEN`. Nebo to nechte na panelu přes
   [napojení na GitHub](github.md) (volba „Uložit CI token do secretů“).
6. Workery (**Další → Přidat worker**) přidejte **až po prvním deployi**: do té
   doby není co spouštět (jen se zaregistrují).

## 2. Build v GitHub Actions

Kroky, které se osvědčily (odpovídají produkčnímu workflow intranetu):

```bash
# Laravel potřebuje adresáře pro package:discover (post-autoload-dump)
mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache

# Privátní Composer repozitář: přihlášení ze secrets, ne z auth.json v repozitáři
composer config http-basic.nyoncode.cz "$COMPOSER_USER" "$COMPOSER_PASSWORD"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

npm ci || (rm -rf node_modules && npm install)   # npm ci padá na cizí platformě (optional deps)
npm run build
bash scripts/publish-wire-assets.sh              # wireStack bundly jako soubory v public/

# .env pro produkci: šablona + produkční výchozí hodnoty + GitHub vars/secrets
cp .env.example .env    # pak APP_ENV=production, APP_DEBUG=false, APP_URL=…, …

rm -rf .git .github node_modules tests dev .idea auth.json
tar --exclude='storage/logs/*' -czf /tmp/build.tar.gz .
```

Poznámky z ověřování:

- **`auth.json` nepatří do repozitáře ani do balíku.** Je v něm přihlášení
  k privátnímu Composer repozitáři a s balíkem by skončilo na serveru. V CI ho
  nahraďte `composer config http-basic…` ze secrets. Pokud ho repozitář
  obsahuje, aspoň ho před zabalením smažte.
- **Balení na macOS** (self-hosted runner na Macu): `COPYFILE_DISABLE=1 tar …`.
  Jinak tar přibalí soubory `._*` (AppleDouble) a ty skončí v releasu.
- **Velikost:** balík intranetu má ~37 MB (vendor + build). Limit je 512 MB.
- **`APP_KEY` do `.env` v CI nedávejte**: panel ho při prvním deployi vygeneruje
  do sdíleného `.env` a dál ho drží. Vlastní klíč (migrace ze starého serveru)
  vložte jednou ručně v Deploy → Další → .env.
- **`VITE_*` proměnné** (např. klíč Reverbu pro Echo) se zapékají do JS při
  buildu. Musí být v `.env` už v CI, ne až na serveru.

## 3. Jak panel zachází s `.env`

Na serveru je jeden sdílený soubor `shared/.env`, nalinkovaný do každého releasu.
`.env` z balíku se do něj při deployi **sloučí**:

- klíče, které nastavuje panel (`DB_*`, `MAIL_*`, `REDIS_*`, ovladače, které jste
  dali do Valkey, `REVERB_*`), **zůstanou podle panelu**,
- všechno ostatní se vezme z balíku (v logu deploye: „N values taken into
  shared/.env; kept as the panel set them: …“),
- s volbou *Konfigurace z deploye vyhrává* (Deploy → Nastavení) platí `.env`
  z balíku celý. Panel pak hlídá jen port, na kterém poslouchá Reverb.

Výsledek je vidět v Deploy → Další → .env.

## 4. První deploy a co zkontrolovat

Po nahrání balíku sledujte log (Deploy → historie). U intranetu vypadal takto:

```
==> Unpack artifact
==> .env from the release: 105 values taken into shared/.env; kept as the panel set them: …
==> APP_KEY            none in shared/.env: a new one was generated
==> Link shared files  .env, storage -> shared/
==> Build              skipped: the artifact was built in CI
==> Maintenance page up (migrations)
==> Migrations
==> Storage link
==> Optimize
==> artisan install:permissions
==> artisan erag:update-manifest
==> current -> releases/…
health: HTTP 302
==> Maintenance page down
```

Kontrolní seznam:

- **`health: HTTP 302`** je v pořádku (přesměrování na přihlášení). Panel
  považuje za funkční každou odpověď kromě 5xx.
- **`public/storage`** musí být odkaz na `storage/app/public`. Pokud repozitář
  verzuje `public/storage/.gitignore` (u intranetu ano), je to složka a
  `storage:link` by hlásil „link already exists“. Nahrané veřejné soubory by pak
  nebyly vidět. Panel takovou složku (jen s tečkovými soubory) sám nahradí
  odkazem. V logu je to krok „Storage link: public/storage from the repository
  holds only a placeholder“. Doporučení: soubor z repozitáře odeberte.
- **Logy aplikace**: `storage/logs` (přes SFTP, nebo Detail webu → Logy) bez
  chyb.
- **Přihlášení**: projděte pár stránek za přihlášením. Na nové databázi je
  potřeba první účet (krok 5).

## 5. První uživatel na prázdné databázi

`php artisan wire:user` (a další generické příkazy) selže, když tabulka `users`
vyžaduje další sloupce. U intranetu to byly `username`, `first_name`,
`last_name`, schválení účtu (`status=active`) a ověřený e-mail. Spolehlivá cesta
je SSH (Detail webu → SSH / SFTP) a tinker v aktuálním releasu:

```bash
cd ~/current
php artisan tinker --execute '
$u = App\Models\User::forceCreate([
  "name" => "Admin", "first_name" => "Admin", "last_name" => "Firma", "username" => "admin",
  "email" => "admin@firma.cz", "password" => Hash::make("…"),
  "status" => "active", "email_verified_at" => now(),
]);
$u->assignRole("Super Admin");'
```

Nebo přeneste databázi ze starého serveru (Databáze → Import, nebo
[Import webu](weby.md)).

## 6. Workery

Detail webu → Další → Přidat worker. U intranetu: **Horizon**, **plánovač**,
**Reverb** (Pulse se sbírá přes plánovač). Panel ke každému workeru připraví, co
potřebuje (Horizon zapne Valkey, Reverb dostane port, klíče a cestu `/app`
na doméně webu) a spouští ho jako systemd službu pod uživatelem webu.

Co vypadá podezřele, ale je v pořádku:

- **Reverb a Valkey poslouchají na nízkém portu** (např. 606, 607). Porty webů
  jsou záměrně pod 1024: server je nastavený tak, že obyčejný uživatel tam
  poslouchat nemůže, a port dostane jen služba daného webu. Jiný web ho tak
  nemůže obsadit.
- **Horizon píše „Worker STOPPED Interrupted“**: při `balance => auto` Horizon
  workery sám přidává a ubírá. Chyba to není, pokud služba neběží v restartech
  (Server → Služby).
- Po deployi běží chvíli **dvě instance workeru** (stará dokončuje rozdělanou
  úlohu, nová už bere nové). Plánovač nikdy dvakrát.

## 7. Přechod na FrankenPHP (worker režim)

Aplikace zůstává v paměti mezi požadavky. Přihlašovací stránka intranetu
odpovídá místo desítek ms za ~16 ms.

1. **V repozitáři** (jednou):
   ```bash
   composer require laravel/octane
   php artisan octane:install --server=frankenphp   # vytvoří public/frankenphp-worker.php
   ```
   Bez `public/frankenphp-worker.php` web ve worker režimu nepoběží.
2. Ověřte, že je kód **worker-safe**: žádný stav ve statických proměnných ani
   singletonech mezi požadavky. Viz dokumentace Octane.
3. Nasaďte release s Octane ještě v PHP-FPM (nic se nezmění).
4. Detail webu → **Další → Změnit režim → Worker (FrankenPHP)**. Přepnutí
   proběhne bez výpadku: nový backend se připraví vedle starého.
5. Další release nasaďte jako obvykle. Po deployi panel restartuje PHP workery
   FrankenPHP přes jeho admin API (bez výpadku).

Co se tím změní:

- web obsluhuje FrankenPHP s PHP, které má zabudované (jiná minor verze než
  systémové PHP je normální),
- `php` v SSH a v krocích deploye je PHP z FrankenPHP,
- **workery webu (Horizon, fronty, plánovač, Reverb) dál běží na systémovém
  PHP CLI**: na verzi webu, jinak na nejnovější nainstalované. CLI zabudované ve
  FrankenPHP (1.13) totiž spadne (SIGSEGV), jakmile PHP obslouží signál. Worker
  by se při každém deployi místo dokončení úlohy ukončil pádem a jeho služba by
  zůstala ve stavu *failed*. Workery tak potřebují rozšíření i v systémovém PHP
  (Server → PHP).

## 8. Napojení na GitHub

Místo ručního kopírování tokenu a adres můžete web napojit na repozitář přes
GitHub API: Účet → **Integrace** → **Propojit s GitHubem**, pak u webu
Deploy → **GitHub** a výběr repozitáře. Postup se snímky obrazovky:
[GitHub → Návod krok za krokem](github.md#návod-první-deploy-z-githubu-krok-za-krokem). Deploye se pak ukazují přímo v GitHubu
(Deployments) s odkazem na web a log.

## Simulace na vlastním projektu

Celý postup (build jako CI → panel → deploy → kontroly → FrankenPHP) jde
odehrát proti dev serveru na Macu jedním příkazem:

```bash
make dev-up dev-provision
dev/simulate-project-deploy.sh ~/PhpstormProjects/firma/intranet.local \
    --domain intranet.local --workers horizon,scheduler,reverb \
    --commands install:permissions,erag:update-manifest \
    --build-cmd "bash scripts/publish-wire-assets.sh" --franken
```

Skript:

1. udělá čistý checkout `HEAD` (jako `actions/checkout`), `composer install
   --no-dev` (s `auth.json` projektu, pokud ho má, do balíku ho nepřibalí),
   `npm run build`, vlastní krok buildu a `.env` z `.env.example` s produkčními
   hodnotami (`--env KLÍČ=hodnota` přidá další),
2. v panelu založí web, databázi, Valkey, deploy bez repozitáře s vlastními
   příkazy a CI token (existující web stejné domény nejdřív smaže),
3. nahraje balík na `/api/deploy/<id>/artifact` a čeká na výsledek jako Action,
4. přidá workery a zkontroluje odpověď webu, odkaz `public/storage` a že žádná
   služba webu není *failed*,
5. s `--franken` přidá do buildu Octane, přepne web do worker režimu a nasadí
   ještě dva releasy. Druhý ověří, že se workery prvního zastavily čistě.

Web pak můžete projít v prohlížeči: do `/etc/hosts` přidejte
`127.0.0.1 intranet.local` a otevřete `https://intranet.local:8443` (interní
certifikát, viz [Weby → HTTPS](weby.md)).

## Problémy, na které jsme narazili

| Příznak | Příčina | Řešení |
|---|---|---|
| `storage:link`: „link already exists“, nahrané soubory nejsou vidět | repozitář verzuje `public/storage/.gitignore` | panel složku nahradí odkazem; soubor z repozitáře odeberte |
| v releasu jsou soubory `._*` | tar na macOS | `COPYFILE_DISABLE=1 tar …` |
| služby workerů ve stavu *failed* po každém deployi (FrankenPHP) | CLI z FrankenPHP padá na signálech | opraveno: workery běží na systémovém PHP; po aktualizaci panelu to srovná první deploy |
| Deploy → Nastavení nejde uložit bez repozitáře | starší verze panelu | aktualizovat panel; prázdný repozitář = jen deploy z CI |
| `wire:user`: „Field 'username' doesn't have a default value“ | aplikace má vlastní povinné sloupce | první účet přes tinker (krok 5) |
| po přihlášení „Čeká na schválení“ / „Potvrďte e-mail“ | pravidla aplikace | `status=active`, `email_verified_at` (krok 5) |
| web ve worker režimu vrací 502 | chybí `public/frankenphp-worker.php` nebo Octane | krok 7.1 |
