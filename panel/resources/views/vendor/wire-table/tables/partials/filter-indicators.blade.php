{{-- Active filter indicator chips (panel + column header filters) with
     per-filter remove buttons. --}}
{{-- Variables: $component --}}
@php
    $indicators = $component->getActiveFilterIndicators();
    $columnIndicators = $component->getActiveColumnFilterIndicators();
    $total = count($indicators) + count($columnIndicators);
@endphp

@if($total > 0)
    <div class="flex items-center gap-3 px-4 lg:px-6 py-2.5 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50">
        {{-- On a phone the chips scroll sideways instead of stacking into rows
             that push the table down; they wrap again once there is width to wrap
             into. The trailing fade says the row scrolls, and the reset stays
             pinned outside it so it never scrolls out of reach. Scrollbar-hiding
             and the fade are inline: the package is styled through a consumer's
             Tailwind build, so arbitrary utilities are not guaranteed to exist. --}}
        <div
                class="flex min-w-0 flex-1 items-center gap-2 overflow-x-auto sm:flex-wrap sm:overflow-visible"
                style="scrollbar-width:none;-webkit-mask-image:linear-gradient(90deg,#000 calc(100% - 1.5rem),transparent);mask-image:linear-gradient(90deg,#000 calc(100% - 1.5rem),transparent);contain: paint;"
                data-testid="filter-indicators" @wireEl('filter-indicators')
        >
            @foreach($indicators as $filterName => $indicatorLabel)
                <div class="shrink-0 sm:shrink">
                    @include('wire-table::tables.partials.filter-chip', [
                        'name' => $filterName,
                        'label' => $indicatorLabel,
                        'removeMethod' => 'removeTableFilter',
                        'keyPrefix' => 'filter-indicator',
                        'testidPrefix' => 'filter-chip',
                    ])
                </div>
            @endforeach

            @foreach($columnIndicators as $filterName => $indicatorLabel)
                <div class="shrink-0 sm:shrink">
                    @include('wire-table::tables.partials.filter-chip', [
                        'name' => $filterName,
                        'label' => $indicatorLabel,
                        'removeMethod' => 'removeColumnFilter',
                        'keyPrefix' => 'column-filter-indicator',
                        'testidPrefix' => 'column-filter-chip',
                    ])
                </div>
            @endforeach
        </div>

        @if($total > 1)
            <button
                    type="button"
                    wire:click="resetTableFilters"
                    data-testid="table-filter-reset" @wireEl('table-filter-reset')
                    class="shrink-0 whitespace-nowrap text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
            >
                {{ __('wire-table::messages.filter_reset') }}
            </button>
        @endif
    </div>
@endif
