<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Panel napíše, když web přestane odpovídat, vyprší certifikát, spadne služba nebo dochází disk (správci), a když se problém vyřeší; volitelně i o dokončených deployích. Ozve se jen při změně: problém musí trvat dvě kontroly po sobě (dvě minuty). Kanály jsou vaše vlastní, klient dostává zprávy jen o svých webech.') }}</p>
    @if ($this->isAdmin())
        @php($smtp = $this->smtp())
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('E-maily odchází:') }}
            @if ($smtp['enabled'] ?? false)
                <x-wire::badge color="info">{{ __('přes :host:port', ['host' => $smtp['host'], 'port' => $smtp['port']]) }}</x-wire::badge> <span class="text-xs text-gray-500">{{ __('jako :from', ['from' => $smtp['from_address']]) }}</span>
            @else
                <x-wire::badge color="gray">{{ __('poštou tohoto serveru') }}</x-wire::badge>
            @endif
        </p>
        @php($heartbeat = $this->heartbeat())
        @if ($heartbeat['url'] !== null)
            <x-wire::callout :color="($heartbeat['last']['ok'] ?? true) ? 'success' : 'danger'" icon="outline:heart" :heading="__('Hlídání serveru zvenku je zapnuté')">
                {{ __('Healthchecks.io dostává signál každých pět minut.') }}
                @if ($heartbeat['last'] !== null)
                    {{ __('Naposledy') }} {{ \Illuminate\Support\Carbon::parse($heartbeat['last']['at'])->diffForHumans() }}:
                    {{ $heartbeat['last']['ok'] ? ($heartbeat['last']['failing'] > 0 ? __('odešel s :count vážnými problémy', ['count' => $heartbeat['last']['failing']]) : __('v pořádku')) : __('neodešel').' ('.$heartbeat['last']['error'].')' }}
                @endif
            </x-wire::callout>
        @endif
    @endif
    @livewire(\App\Livewire\NotificationChannelTable::class)
</div>
