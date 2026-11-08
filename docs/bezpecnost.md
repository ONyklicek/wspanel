# Bezpečnost

Panel je navržený tak, aby kompromitace jednoho webu, nebo i samotného panelu,
neznamenala kompromitaci serveru ani ostatních webů.

## Model v kostce

- Panel nemá root ani sudo. Všechno privilegované posílá agentovi přes
  `/run/panel-agent.sock`. Socket má práva `root:panel 0660` a agent navíc
  ověřuje UID protistrany přes `SO_PEERCRED`.
- Agent zná jen operace z `agent/internal/ops/ops.go`. Parametry ověřuje přes
  enum/regex a neznámé klíče odmítne. Příkazy spouští přes `exec.Command` s
  allowlistem binárek, nikdy přes shell.
- Před každým reloadem ověří config (`php-fpm -t`, `frankenphp validate`).
  Když selže, provedené kroky se vrátí zpět.
- Každá operace se zapíše do `/var/log/panel-agent/audit.log` (JSON lines).
- Každý web má vlastního systémového uživatele, `open_basedir`, FPM pool a
  `disable_functions` (včetně `proc_open` a `putenv`).
- Proxy má dvě vrstvy, protože Caddy `file_server` následuje symlinky i mimo root:
  - `panel-proxy` (edge) drží TLS klíče, ale `/srv` nevidí vůbec
    (`InaccessiblePaths`). Na web tier jen předává požadavky přes Unix socket.
  - `panel-web` obsluhuje statiku a FastCGI. Nemá žádná tajemství a přes ACL
    smí číst jen adresáře `public/`. Symlink, který si web nastrčí, tak vede
    nanejvýš k souborům, které jsou veřejné i tak.
- Workery (Horizon, queue, scheduler, Messenger) běží jako systemd unity pod
  uživatelem webu se sandboxem (`ProtectSystem=strict`, zapisovat smí jen do
  svého webu). Příkaz určuje framework, panel ho zadat nemůže.
- Firewall (UFW): porty SSH, 80 a 443 nejde z panelu zakázat ani smazat.
  Služby, na kterých běží panel, nejde z panelu zastavit.


## Izolace webů

- **Vlastní uživatel** `web_<doména>`, adresář `0750`. Ostatní weby ani web tier
  do něj nevidí, web tier smí číst jen `public/` (ACL).
- **PHP:**
  - `open_basedir` jen na adresář webu,
  - vlastní `/tmp` a sessions,
  - `disable_functions` (spouštění příkazů, `putenv`, `dl`).
- **Workery, Node, worker režim a každý krok deploye** běží jako systemd unity
  se sandboxem:
  - `ProtectSystem=strict`, zapisovat smí jen do svého webu,
  - `NoNewPrivileges`,
  - prázdný `CapabilityBoundingSet`,
  - `SocketBindDeny`, takže nesmí poslouchat na portech,
  - nevidí konfiguraci ani stav agenta a proxy.
- **SSH/SFTP:** bez tunelů a forwardingu, klíče spravuje jen panel, hesla
  jsou volitelná a hlídá je fail2ban.
- **Databáze:** každá má vlastního uživatele s právy jen na ni. Dočasné
  přístupy phpMyAdmin/Adminer platí 30 minut a jen pro jednu databázi.
- **Pošta:**
  - web posílá jen za svou doménu (SMTP přihlášení +
    `reject_sender_login_mismatch`, pro sendmail `local_login_sender_maps`),
  - DKIM se podepisuje podle ověřeného odesílatele,
  - zachycené zprávy v Mailpitu se zobrazují s CSP sandboxem.

## Porty aplikací

Node SSR a Reverb dostávají porty 600–999 na `127.0.0.1`. Porty pod 1024 může
bindovat jen proces s `CAP_NET_BIND_SERVICE`, takže si port restartující se
aplikace nemůže zabrat jiný web (PHP, SSH ani jiný Node proces).
- Provisioning nastaví `net.ipv4.ip_unprivileged_port_start=1024`. Kontejnery
  ho často mají na 0.
- Unita aplikace dostane capability a `SocketBindAllow` jen na svůj port.
- Agent si vynucení `SocketBindDeny` (cgroup BPF) ověří sondou. Když vynucené
  není, Node a Reverb odmítne. Výjimka `--allow-unenforced-socket-bind` je jen
  pro Docker Desktop.


## Panel a jeho přístupy

- Panel běží jako uživatel `panel` bez sudo. Vše privilegované jde přes agenta,
  který zná jen pevný seznam operací (`agent/internal/ops/ops.go`) a každou
  zapíše do auditu.
- **Webhooky** se ověřují podpisem (HMAC / token) a mají ochranu proti
  přehrání. **CI token** je oddělený od secretu webhooku, uložený jen jako
  hash a jde zrušit.
- **CI artefakty:**
  - agent je otevírá s `O_NOFOLLOW` a ověří, že je zapsal panel,
  - rozbaluje je pod uživatelem webu s limitem 4 GB.
- Tokeny Cloudflare, secrety webhooků a hesla relaye jsou v databázi panelu
  šifrovaně (`APP_KEY`), resp. jen na serveru s právy roota.

## Panel jen z vnitřní sítě

Weby mohou být veřejné a panel přitom dostupný jen z vybraných adres (kancelář,
VPN). **Server → Firewall → Přístup k panelu:** zadejte adresy nebo sítě
(`10.8.0.0/24`, `203.0.113.7`). Ostatním panel odpovídá jako neexistující stránka
(404), weby to neovlivní.

- Ze serveru samotného (`127.0.0.1`, SSH tunel) je panel přístupný vždy.
  Když se zamknete: `ssh -L 8443:127.0.0.1:443 root@server` a v prohlížeči
  `https://<panel>:8443` (nebo z terminálu znovu `install.sh --panel-allow=any`).
- Panel odmítne uložit seznam, který nezahrnuje adresu, ze které ho právě
  používáte.
- **Webhooky a CI API** (`/hooks/deploy/…`, `/api/deploy/…`) mohou zůstat
  veřejné, aby GitHub mohl spouštět deploy. Pokud je vypnete, použijte
  [automatický deploy kontrolou repozitáře](deploy.md#automatický-deploy) –
  ten nepotřebuje žádné spojení zvenku.
- **Webhook přes doménu webu:** u panelu, na který GitHub nedosáhne, posílá
  GitHub webhook na `https://<web>/.well-known/wspanel-deploy/<id>`. Tuto cestu
  dostane **jen web s automatickým deployem webhookem**, **jen s vlastním ID**
  a jen když jsou webhooky z internetu povolené (volba výše). Vypnete-li je,
  panel cestu ze všech webů odebere. Platí i přes omezení webu na IP a heslo.
  Panel doručení ověří podpisem HMAC secretem webu, odmítne opakované doručení
  a víc než 1 MB. Jiná část panelu přes domény webů dostupná není. Viz [GitHub → Panel jen v lokální síti](github.md#panel-jen-v-lokální-síti-nebo-veřejný).
- Totéž jde nastavit při instalaci: `install.sh --panel-allow=10.8.0.0/24,203.0.113.7`.
- Omezení se týká i Mailpitu a phpMyAdminu/Admineru (běží pod doménou panelu).

## Blokování podle zemí

**Server → Firewall → Blokování zemí:** nová spojení z adres vybraných zemí
server zahodí. Rozsahy adres jsou z ipdeny.com a obnovují se každý týden.

- **Jen správu serveru:** SSH a panel na vlastním portu. Weby a pošta zůstanou
  dostupné odkudkoli. Panel sdílející port 443 s weby tím chráněný není (na to
  je Přístup k panelu).
- **Celý server:** i weby a pošta.

Vaše adresa v okamžiku uložení a adresy z Přístupu k panelu se neblokují
nikdy. Rozpoznání země podle IP není dokonalé a VPN ho obejde: je to síto
proti hromadným útokům, ne zámek. Pravidla jsou vlastní tabulka nftables
vedle UFW a obnoví se i po restartu serveru.

## Databáze z internetu

Ve výchozím stavu poslouchají MariaDB a PostgreSQL jen lokálně. Otevřít je jde
v Databáze → Servery (viz [Databáze](databaze.md#přístup-z-internetu)): po síti
pak jde jen přihlášení přes TLS (`REQUIRE SSL`, `hostssl`), port se ve firewallu
otevře jen pro allowlist (nebo všem, s upozorněním na Přehledu) a fail2ban
blokuje hádání hesel. Hesla superuživatelů panel neukládá; agent uchovává jen
hash hesla MariaDB root (v souboru čitelném jen pro root), aby mohl vytvořit
síťový účet.

## Aktualizace systému

**Server → Aktualizace** ukáže balíčky k aktualizaci (bezpečnostní zvlášť)
a jestli server čeká na restart (nové jádro nebo knihovny). Odtud jde
nainstalovat jen bezpečnostní aktualizace, nebo všechny. Konfigurační soubory,
které panel upravil, zůstanou: nová výchozí verze balíčku je nepřepíše.
**Automatické bezpečnostní aktualizace** (unattended-upgrades) je nainstalují
samy každý den.

## Fail2ban

**Server → Fail2ban** ukáže adresy, které fail2ban zablokoval po opakovaně
neúspěšném přihlášení (SSH, pošta, databáze), i to, co hlídá. Jdou odtud
odblokovat.

## Dvoufázové přihlášení

Každý uživatel si ho zapne v profilu (aplikace v telefonu, záložní kódy).
**Server → Moduly → Dvoufázové přihlášení** ho může vyžadovat od klientů,
nebo od všech. Uživatel bez něj se pak po přihlášení dostane jen do profilu,
kde si ho nastaví.

## Docker Desktop (jen vývoj)

Docker Desktop na Macu nevynucuje cgroup BPF `SocketBindDeny`. Dev kontejner
proto běží s `--allow-unenforced-socket-bind`. Na skutečném serveru agent
vynucení ověří a bez něj Node/Reverb weby odmítne.
