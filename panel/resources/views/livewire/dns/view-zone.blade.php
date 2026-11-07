<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')
    {{ $infolist }}
    @livewire(\App\Livewire\Dns\ZoneRecords::class, ['zone' => $zoneId], key('records-'.$zoneId))
    @include('wire-panels::pages.partials.action-modals')
</div>
