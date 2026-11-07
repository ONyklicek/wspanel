<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')

    {{ $infolist }}

    @livewire(\App\Livewire\Sites\SiteProcesses::class, ['site' => $site->getKey()], key('processes-'.$site->getKey()))

    @livewire(\App\Livewire\Sites\SiteLogs::class, ['site' => $site->getKey()], key('logs-'.$site->getKey()))

    @include('wire-panels::pages.partials.action-modals')
</div>
