<div class="space-y-6">
    <x-wire::section heading="Klienti a balíčky">
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>Hostování pro víc klientů: každý klient se přihlásí do panelu jen ke svým webům, databázím a DNS, s limity podle balíčku a kvótou disku. Bez modulu je panel pro jednoho správce.</p>
            <p>
                Stav:
                <x-wire::badge :color="$this->clientsEnabled() ? 'success' : 'gray'">{{ $this->clientsEnabled() ? 'zapnuto' : 'vypnuto' }}</x-wire::badge>
                @if ($this->clientsEnabled())
                    · klientů: {{ $this->clientCount() }}
                @endif
            </p>
            <p class="text-xs text-gray-500">Při instalaci: <span class="font-mono">install.sh --clients=yes</span>. Vypnout jde, jen když nejsou žádní klienti.</p>
        </div>
    </x-wire::section>
    <x-wire::section heading="Pošta: schránky (IMAP, POP3, antispam)">
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>Příjem pošty pro domény webů, schránky s kvótou, aliasy, přeposílání a automatické odpovědi; odesílání z poštovních programů přes 587/465; Rspamd přesouvá spam do složky Junk. Odesílání pošty z webů funguje i bez toho.</p>
            <p>Stav: <x-wire::badge :color="$this->mailHosting() ? 'success' : 'gray'">{{ $this->mailHosting() ? 'nainstalováno' : 'nenainstalováno' }}</x-wire::badge></p>
            <p class="text-xs text-gray-500">Při instalaci: <span class="font-mono">install.sh --mail-hosting=yes</span>.</p>
        </div>
    </x-wire::section>
    <x-wire::section heading="Databázové servery">
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
            <p>Nainstalované: {{ implode(', ', $this->dbEngines()) ?: 'žádný' }}. Další doinstalujete v Hosting → Databáze → Servery (při instalaci <span class="font-mono">--db=mariadb,postgres</span>).</p>
        </div>
    </x-wire::section>
</div>
