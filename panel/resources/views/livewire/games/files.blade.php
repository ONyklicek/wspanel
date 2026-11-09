<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Soubory herního serveru tak, jak je vidí jeho uživatel') }} <span class="font-mono">wsg_{{ $server->getKey() }}</span>.
        {{ __('Ve složce') }} <span class="font-mono">data</span> {{ __('je svět, nastavení a pluginy či módy, v') }} <span class="font-mono">server</span> {{ __('samotný server hry. Změny nastavení platí po restartu serveru; port a RCON v server.properties spravuje panel a při restartu je vrátí.') }}
    </p>

    @livewire(\App\Livewire\Sites\FileBrowser::class, ['game' => $server->getKey()], key('game-files-'.$server->getKey()))

    @include('wire-panels::pages.partials.action-modals')
</div>
