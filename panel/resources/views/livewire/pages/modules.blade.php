<div class="space-y-6">
    @include('partials.background-jobs')
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Volitelné části panelu. Základní instalace je bez nich; co zapnete, se doinstaluje jen tehdy, když je potřeba.') }}</p>

    <x-panel.card :actions="[$this->moduleAction('enableClients'), $this->moduleAction('disableClients')]" :heading="__('Klienti a balíčky')" :description="__('Hostování pro víc klientů: každý klient se přihlásí jen ke svým webům, databázím a DNS, s limity podle balíčku a kvótou disku. Bez modulu je panel pro jednoho správce.')">
        <x-slot:status><x-wire::badge :color="($this->clientsEnabled()) ? 'success' : 'gray'">{{ ($this->clientsEnabled()) ? __('zapnuto') : __('vypnuto') }}</x-wire::badge></x-slot:status>
        <p class="text-sm text-gray-600 dark:text-gray-300">@if ($this->clientsEnabled()){{ __('Klientů: :n. Vypnout jde, jen když nejsou žádní klienti.', ['n' => $this->clientCount()]) }}@else{{ __('Po zapnutí přibudou v menu Hosting Klienti a Balíčky.') }}@endif</p>
        <x-slot:footer>{{ __('Při instalaci:') }} <span class="font-mono">install.sh --clients=yes</span></x-slot:footer>
    </x-panel.card>

    <x-panel.card :actions="[$this->moduleAction('installMail'), $this->moduleAction('uninstallMail')]" :heading="__('Pošta: schránky (IMAP, POP3, antispam)')" :description="__('Příjem pošty pro domény webů, schránky s kvótou, aliasy, přeposílání a automatické odpovědi, odesílání z poštovních programů přes 587/465, antispam Rspamd. Odesílání pošty z webů funguje i bez toho.')">
        <x-slot:status><x-wire::badge :color="($this->mailHosting()) ? 'success' : 'gray'">{{ ($this->mailHosting()) ? __('nainstalováno') : __('nenainstalováno') }}</x-wire::badge></x-slot:status>
        <p class="text-sm text-gray-600 dark:text-gray-300">@if ($this->mailHosting()){{ __('Domény a schránky spravujete v menu Pošta; poštu pro doménu zapnete i při zakládání webu. Odinstalovat jde, když nezbývá žádná doména.') }}@else{{ __('Doinstaluje Dovecot a Rspamd; instalace trvá pár minut, panel mezitím můžete dál používat.') }}@endif</p>
        <x-slot:footer>{{ __('Při instalaci:') }} <span class="font-mono">install.sh --mail-hosting=yes</span></x-slot:footer>
    </x-panel.card>

    <x-panel.card :actions="[$this->moduleAction('installDns'), $this->moduleAction('uninstallDns')]" :heading="__('DNS server (PowerDNS)')" :description="__('Zóny domén přímo na tomto serveru: panel do nich sám zapisuje záznamy webů a pošty. Bez něj jde DNS spravovat v Cloudflare, nebo u registrátora podle záložky DNS webu.')">
        <x-slot:status><x-wire::badge :color="($this->dnsLocal()) ? 'success' : 'gray'">{{ ($this->dnsLocal()) ? __('nainstalováno') : __('nenainstalováno') }}</x-wire::badge></x-slot:status>
        <p class="text-sm text-gray-600 dark:text-gray-300">@if ($this->dnsLocal()){{ __('Zóny spravujete v menu Síť → DNS. Odinstalovat jde, když na serveru nezbývá žádná zóna.') }}@else{{ __('Doinstaluje PowerDNS a otevře port 53; panel mezitím můžete dál používat.') }}@endif</p>
        <x-slot:footer>{{ __('Při instalaci:') }} <span class="font-mono">install.sh --dns=powerdns</span></x-slot:footer>
    </x-panel.card>

    <x-panel.card :heading="__('Databázové servery')" :description="__('MariaDB, PostgreSQL a MongoDB; další engine i vyhrazený server pro jeden web přidáte v Databáze → Servery.')">
        <x-slot:status><x-wire::badge :color="($this->dbEngines() !== []) ? 'success' : 'gray'">{{ ($this->dbEngines() !== []) ? implode(', ', $this->dbEngines()) : __('žádný') }}</x-wire::badge></x-slot:status>
        <x-slot:buttons><x-wire::button size="sm" color="gray" outlined icon="outline:arrow-right" :href="\App\Livewire\Pages\DatabaseServersPage::url()">{{ __('Databázové servery') }}</x-wire::button></x-slot:buttons>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Engine jde jen přidat: odinstalace by smazala databáze webů.') }}</p>
        <x-slot:footer>{{ __('Při instalaci:') }} <span class="font-mono">install.sh --db=mariadb,postgres</span></x-slot:footer>
    </x-panel.card>

    <x-panel.card :actions="[$this->moduleAction('upgradePython'), $this->moduleAction('installPython'), $this->moduleAction('removePython')]" :heading="__('Python (Django, Flask, FastAPI)')" :description="__('Weby v Pythonu pod gunicornem nebo uvicornem, každý release s vlastním virtualenv, deploy bez výpadku; úlohy na pozadí přes worker Celery.')">
        <x-slot:status><x-wire::badge :color="($this->pythonVersions() !== []) ? 'success' : 'gray'">{{ ($this->pythonVersions() !== []) ? implode(', ', $this->pythonLabels()) : __('nenainstalováno') }}</x-wire::badge></x-slot:status>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Python a uv se na server dostanou až tímto krokem. Opravná vydání nainstaluje Aktualizovat Python.') }}</p>
        <x-slot:footer>{{ __('Při instalaci:') }} <span class="font-mono">install.sh --python=3.13</span></x-slot:footer>
    </x-panel.card>

    <x-panel.card :actions="[$this->moduleAction('installXhprof'), $this->moduleAction('uninstallXhprof')]" :heading="__('Profilování PHP (XHProf + XHGui)')" :description="__('Kolik času a paměti zabraly jednotlivé funkce aplikace, s přehledem v XHGui. Zapíná se u každého webu zvlášť (PHP-FPM); profily vidí jen ten, kdo vidí web.')">
        <x-slot:status><x-wire::badge :color="($this->xhprof()) ? 'success' : 'gray'">{{ ($this->xhprof()) ? __('nainstalováno') : __('nenainstalováno') }}</x-wire::badge></x-slot:status>
        <x-slot:footer>{{ __('Při instalaci:') }} <span class="font-mono">install.sh --xhprof=yes</span></x-slot:footer>
    </x-panel.card>

    <x-panel.card :actions="[$this->moduleAction('enableGames'), $this->moduleAction('disableGames')]" :heading="__('Herní servery')" :description="__('Minecraft (Paper, Vanilla, Fabric, Forge) a Factorio: každý server jako vlastní uživatel a služba s limity podle balíčku, konzole, hráči, pluginy a zálohy v panelu.')">
        <x-slot:status><x-wire::badge :color="($this->gamesEnabled()) ? 'success' : 'gray'">{{ ($this->gamesEnabled()) ? __('zapnuto') : __('vypnuto') }}</x-wire::badge></x-slot:status>
        <p class="text-sm text-gray-600 dark:text-gray-300">@if ($this->gamesEnabled()){{ __('Serverů: :n. Vypnout jde, jen když nejsou žádné herní servery.', ['n' => $this->gameCount()]) }}@else{{ __('Na server se nic neinstaluje: Java a server hry se stáhnou až s prvním herním serverem.') }}@endif</p>
        <x-slot:footer>{{ __('Při instalaci:') }} <span class="font-mono">install.sh --games=yes</span></x-slot:footer>
    </x-panel.card>
</div>
