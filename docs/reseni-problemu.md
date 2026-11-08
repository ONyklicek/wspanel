# Řešení problémů

## Web

**Web ukazuje „Web běží“ / úvodní stránku místo mé aplikace**
- Ještě neproběhl deploy (Laravel, Symfony, Node), nebo jste nenahráli soubory
  (Čisté PHP do `public_html/`).
- Detail webu → Přístup → **Poslední deploy** ukáže, jestli deploy proběhl.

**Stav „Neodpovídá“ / HTTP 500**
- Detail webu → **Logy**: PHP chyby, log aplikace.
- Laravel: chybí `APP_KEY`, nebo jiná hodnota v `.env` (Deploy → .env).
- Worker/Node režim: tabulka Procesy → Aplikace → **Log**.

**Prohlížeč hlásí chybu certifikátu**
- Doména nemíří na server (DNS → Kontrola DNS), nebo je zavřený port 80/443.
- Menu **SSL** ukazuje poslední chybu vydání certifikátu.
- Po opravě DNS se certifikát vydá sám do několika minut.

**Změna v souboru přes SFTP se neprojevila**
- Je zapnutá produkční OPcache (Deploy → Nastavení). Detail webu →
  **Restartovat**.
- Laravel s `artisan optimize` cachuje konfiguraci a routy. Po změně `.env`
  pomůže deploy nebo `php artisan optimize` přes SSH.

## Procesy a workery

**Worker „padá a restartuje se“**
- Procesy → worker → **Log**. Typicky:
  - chybějící Redis/Valkey konfigurace (`REDIS_HOST=127.0.0.1`),
  - chybějící tabulka `jobs` (spusťte migrace),
  - chyba v kódu.
- Po opravě: **Reset po chybě a spustit**.

**Worker „čeká na deploy“**
- Aplikace ještě není nasazená (chybí `artisan` / `bin/console`). Po prvním
  deployi se spustí sám.

**Horizon píše „Worker STOPPED Interrupted“**
- Při `balance => auto` Horizon workery sám přidává a ubírá. Chyba to není,
  pokud služba neběží v restartech (Server → Služby).

**Po deployi zůstávají služby workerů *failed* (`status=11/SEGV`), web ve FrankenPHP**
- Starší panel spouštěl workery FrankenPHP webu přes `frankenphp php-cli`, který
  padá, když PHP obslouží signál. Po aktualizaci panelu první deploy přepíše
  jednotky na systémové PHP. Staré záznamy uklidí `systemctl reset-failed`.

**Reverb / Valkey poslouchá na portu pod 1024**
- Záměr: porty webů jsou privilegované, port může obsadit jen služba daného webu.

## Deploy

Viz [Deploy → Typické problémy](deploy.md#typické-problémy). Celý log každého
deploye je na stránce Deploy (klik na řádek v Historii). Dále:

- **„Web nemá nastavený repozitář, nasazuje se jen z CI“:** v Deploy →
  Nastavení je repozitář prázdný. Deploy z Gitu i automatický deploy ho
  potřebují, balík z CI ne.
- **`storage:link`: „link already exists“, nahrané soubory nejsou vidět:**
  repozitář verzuje `public/storage/.gitignore`. Panel složku nahradí odkazem;
  soubor z repozitáře odeberte.
- **V releasu jsou soubory `._*`:** balík zabalený tarem na macOS. Použijte
  `COPYFILE_DISABLE=1 tar …`.
- **Chyby napojení na GitHub:** [GitHub → Problémy](github.md#problémy).
- Převod existující aplikace a vše, na co jsme přitom narazili:
  [Nasazení existujícího projektu](nasazeni-projektu.md#problémy-na-které-jsme-narazili).

## Pošta

**Mail z webu nedorazil**
1. **Pošta:** je web v režimu „odesílá“? V režimu „zachytává“ je zpráva v
   Mailpitu.
2. **Pošta → Odesílání z webů → Log pošty:** hledejte adresu příjemce.
   - `status=deferred` + `Connection timed out` znamená, že poskytovatel
     blokuje port 25. Nastavte relay.
   - `status=bounced` + `SPF`/`DKIM`/`DMARC` znamená, že chybí nebo nesedí DNS
     záznamy (Pošta → záznamy u domény).
3. Odesílatel musí být z domény webu. Jiná doména se odmítne (`Sender address
   rejected: not owned by user`).

## SSH / SFTP

**„Permission denied“**
- Detail webu → Nastavení → Přístup → SSH / SFTP: je přístup zapnutý? Je vložený správný
  klíč, nebo nastavené heslo?
- Uživatel je `web_<doména>` s podtržítky (`web_example_com`).
- Po 5 chybných pokusech je IP na hodinu zablokovaná (fail2ban). Na serveru:
  `fail2ban-client set sshd unbanip <IP>`.

**SFTP-only účet nejde přes `ssh`**
- To je záměr. Pro shell zvolte přístup **SSH + SFTP**.

## Panel

**„Agent není dostupný“**
- `systemctl status panel-agent` a `journalctl -u panel-agent`.

**Kde najdu, co panel na serveru udělal?**
- Menu **Audit**, nebo `/var/log/panel-agent/audit.log`.

**Užitečné příkazy na serveru (root)**
```
systemctl list-units 'wspanel-*'          # procesy a workery webů
journalctl -u wspanel-web_example_com-queue -n 100
journalctl -u panel-proxy -n 100           # certifikáty, proxy
postqueue -p                               # fronta pošty
pdnsutil list-all-zones                    # lokální DNS
```
