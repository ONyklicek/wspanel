# Vytížení serveru

Menu **Vytížení** ukazuje, jak je server zatížený teď i v minulosti a kdo ho
zatěžuje.

## Živé hodnoty (obnova každých 5 s)

| Hodnota | Co znamená | Kdy zpozornět |
|---|---|---|
| **CPU** | využití všech jader dohromady, plus **iowait** (čekání na disk) a **steal** (čas, který si vzal hostitel virtuálu) | trvale nad 70 %; iowait nad 10 % = pomalý disk; steal nad 5 % = přetížený hostitel |
| **Paměť** | RAM, kterou opravdu používají procesy. Cache se nepočítá, systém ji kdykoli uvolní. | nad 80 % |
| **Zátěž (load)** | kolik procesů průměrně chce CPU (1, 5, 15 minut) | trvale nad počtem jader |
| **Disk** | nejplnější oddíl | nad 80 %; při 100 % přestanou fungovat databáze i weby |
| **Swap** | odložená paměť na disk | rostoucí swap = málo RAM |
| **Síť, Disk I/O** | aktuální přenosy | – |

## Grafy

Minutové průměry procesoru, paměti, zátěže, sítě, diskového I/O a zaplnění
disku. Rozsah (hodina, 24 hodin, 7 a 30 dní) zvolíte nad grafem. Historie se
ukládá 30 dní. Záznam dělá plánovač panelu každou minutu (`panel:metrics`).

## Vytížení podle webů

Pro každý web součet všech jeho procesů (PHP-FPM, aplikace, workery, úlohy):
- **CPU** v % jednoho jádra (200 % = plně vytížená dvě jádra),
- **paměť**,
- **počet procesů**,
- **místo na disku** (přepočítává se nejvýš jednou za 15 minut).

Řádky jdou řadit, klik otevře detail webu. Paměť jednotlivých workerů ukazuje
tabulka Procesy na detailu webu.

## Disky a procesy

- **Disky:** všechny oddíly s obsazeností a inody. Inody jsou počet souborů:
  když dojdou, nejde zapisovat ani při volném místě.
- **Nejnáročnější procesy:** podle CPU a paměti, včetně uživatele. Podle
  uživatele `web_…` poznáte, kterému webu proces patří.

## Co dělat při vysokém vytížení

- **Jeden web bere CPU:** podívejte se do jeho logů. Typicky jde o bota,
  neoptimalizovaný dotaz nebo zacyklený worker.
- **Paměť a swap rostou:** workery s únikem paměti restartujte (Procesy →
  Restartovat). Laravel fronta se restartuje sama po hodině
  (`--max-time=3600`).
- **Plný disk:** podívejte se do sloupce „Na disku“ u webů a na zálohy v
  `/var/backups/wspanel`. Staré releasy maže deploy sám podle nastavení
  „Držet releasů“.
