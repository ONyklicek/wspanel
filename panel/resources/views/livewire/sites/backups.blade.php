<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Záloha celého webu v jednom archivu: aktuální release, složka') }} <span class="font-mono">shared</span>
        (<span class="font-mono">.env</span>{{ __(', nahrané soubory, storage) a databáze webu. Obnova vytvoří nový release, dosavadní') }} <span class="font-mono">shared</span> {{ __('ponechá vedle a předtím uloží zálohu současného stavu – nic se neztratí.') }}
    </p>

    @livewire(\App\Livewire\Sites\BackupList::class, ['site' => $site->getKey()], key('backups-'.$site->getKey()))

    @include('wire-panels::pages.partials.action-modals')
</div>
