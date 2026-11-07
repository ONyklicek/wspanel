<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Soubory webu tak, jak je vidí jeho systémový uživatel <span class="font-mono">{{ $site->system_user }}</span> (jen jeho domovská složka).
        Aplikace běží ze složky <span class="font-mono">current</span>; data a <span class="font-mono">.env</span> jsou v <span class="font-mono">shared</span>.
        Velké přenosy jsou pohodlnější přes SFTP.
    </p>

    @livewire(\App\Livewire\Sites\FileBrowser::class, ['site' => $site->getKey()], key('files-'.$site->getKey()))

    @include('wire-panels::pages.partials.action-modals')
</div>
