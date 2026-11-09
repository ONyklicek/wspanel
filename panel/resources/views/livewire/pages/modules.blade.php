<div class="space-y-6">
    <x-wire::section :heading="__('Klienti a balíčky')">
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __('Hostování pro víc klientů: každý klient se přihlásí do panelu jen ke svým webům, databázím a DNS, s limity podle balíčku a kvótou disku. Bez modulu je panel pro jednoho správce.') }}</p>
            <p>
                {{ __('Stav:') }}
                <x-wire::badge :color="$this->clientsEnabled() ? 'success' : 'gray'">{{ $this->clientsEnabled() ? __('zapnuto') : __('vypnuto') }}</x-wire::badge>
                @if ($this->clientsEnabled())
                    {{ __('· klientů:') }} {{ $this->clientCount() }}
                @endif
            </p>
            <p class="text-xs text-gray-500">{{ __('Při instalaci:') }} <span class="font-mono">install.sh --clients=yes</span>{{ __('. Vypnout jde, jen když nejsou žádní klienti.') }}</p>
        </div>
    </x-wire::section>
    <x-wire::section :heading="__('Herní servery')">
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __('Minecraft Java (Paper nebo Vanilla) a Factorio: každý server jako vlastní uživatel a služba s pamětí a CPU podle balíčku, port otevřený ve firewallu, konzole a hráči v panelu, zálohy a aktualizace jedním tlačítkem. Java a server hry se stáhnou až s prvním serverem (od vydavatele, s kontrolou otisku).') }}</p>
            <p>
                {{ __('Stav:') }}
                <x-wire::badge :color="$this->gamesEnabled() ? 'success' : 'gray'">{{ $this->gamesEnabled() ? __('zapnuto') : __('vypnuto') }}</x-wire::badge>
                @if ($this->gamesEnabled())
                    {{ __('· serverů:') }} {{ $this->gameCount() }}
                @endif
            </p>
            <p class="text-xs text-gray-500">{{ __('Při instalaci:') }} <span class="font-mono">install.sh --games=yes</span>{{ __('. Vypnout jde, jen když nejsou žádné herní servery.') }}</p>
        </div>
    </x-wire::section>
    <x-wire::section :heading="__('Pošta: schránky (IMAP, POP3, antispam)')">
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __('Příjem pošty pro domény webů, schránky s kvótou, aliasy, přeposílání a automatické odpovědi; odesílání z poštovních programů přes 587/465; Rspamd přesouvá spam do složky Junk. Odesílání pošty z webů funguje i bez toho.') }}</p>
            <p>{{ __('Stav:') }} <x-wire::badge :color="$this->mailHosting() ? 'success' : 'gray'">{{ $this->mailHosting() ? __('nainstalováno') : __('nenainstalováno') }}</x-wire::badge></p>
            <p class="text-xs text-gray-500">{{ __('Při instalaci:') }} <span class="font-mono">install.sh --mail-hosting=yes</span>.</p>
        </div>
    </x-wire::section>
    <x-wire::section :heading="__('Profilování PHP (XHProf + XHGui)')">
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __('Kolik času a paměti zabraly jednotlivé funkce aplikace, s přehledem v XHGui. Zapíná se u každého webu zvlášť (PHP-FPM); profily zůstávají v adresáři webu a vidí je jen ten, kdo vidí web.') }}</p>
            <p>{{ __('Stav:') }} <x-wire::badge :color="$this->xhprof() ? 'success' : 'gray'">{{ $this->xhprof() ? __('nainstalováno') : __('nenainstalováno') }}</x-wire::badge></p>
            <p class="text-xs text-gray-500">{{ __('Při instalaci:') }} <span class="font-mono">install.sh --xhprof=yes</span>.</p>
        </div>
    </x-wire::section>
    <x-wire::section :heading="__('Python (weby v Djangu, Flasku, FastAPI)')">
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __('Režim webu Python: aplikace běží pod gunicornem nebo uvicornem jako vlastní proces, každý release má vlastní virtualenv a deploy probíhá bez výpadku. Python a uv se na server dostanou až tímto krokem, základní instalace je bez nich.') }}</p>
            <p>{{ __('Úlohy na pozadí: u webu přidejte worker Celery (a Celery beat pro plánované úlohy). Opravná vydání Pythonu nainstaluje tlačítko Aktualizovat Python.') }}</p>
            <p>
                {{ __('Stav:') }}
                <x-wire::badge :color="$this->pythonVersions() !== [] ? 'success' : 'gray'">{{ $this->pythonVersions() !== [] ? __('nainstalováno') : __('nenainstalováno') }}</x-wire::badge>
                @if ($this->pythonVersions() !== [])
                    · {{ implode(', ', $this->pythonLabels()) }}
                @endif
            </p>
            <p class="text-xs text-gray-500">{{ __('Při instalaci:') }} <span class="font-mono">install.sh --python=3.13</span>.</p>
        </div>
    </x-wire::section>
    <x-wire::section :heading="__('Databázové servery')">
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>{{ __('Nainstalované:') }} {{ implode(', ', $this->dbEngines()) ?: __('žádný') }}{{ __('. Další doinstalujete v Hosting → Databáze → Servery (při instalaci') }} <span class="font-mono">--db=mariadb,postgres</span>).</p>
        </div>
    </x-wire::section>
</div>
