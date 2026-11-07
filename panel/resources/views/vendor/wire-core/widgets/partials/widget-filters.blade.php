{{-- The filters over a whole dashboard: one selection every widget reads.

     A row of buttons for a filter declared `buttons()` — a handful of periods
     read at a glance — and a select for the rest, where the options are records
     and there may be dozens. The reset link appears only while something is
     narrowed, so a dashboard at its defaults shows the choices and nothing else.

     Every change goes through `setDashboardFilter()`, which checks the value
     against the options; the select's empty option is the filter's placeholder
     and resolves to its default.

     Renders nothing on a dashboard that declares no filters, so a page includes
     it unconditionally.

     Variables: $filterState (NyonCode\WireCore\Widgets\Support\DashboardFilterState) --}}
@php($filterState ??= \NyonCode\WireCore\Widgets\Support\DashboardFilterState::none())

@if($filterState->filters() !== [])
    <div class="wire-widget-filters flex flex-wrap items-center gap-2"
         data-testid="widget-filters" @wireEl('widget-filters')>
        @foreach($filterState->filters() as $filterName => $dashboardFilter)
            @php($current = $filterState->value($filterName))

            @if($dashboardFilter->isButtons())
                <div class="flex flex-wrap items-center gap-1" role="group" aria-label="{{ $dashboardFilter->getLabel() }}">
                    @foreach($dashboardFilter->getOptions() as $value => $optionLabel)
                        <button type="button"
                                wire:click="setDashboardFilter(@js($filterName), @js((string) $value))"
                                aria-pressed="{{ (string) $value === $current ? 'true' : 'false' }}"
                                data-testid="widget-filter-{{ $filterName }}-{{ $value }}"
                                @class([
                                    'rounded-full border px-3 py-1 text-xs font-semibold transition',
                                    'border-primary-600 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' => (string) $value === $current,
                                    'border-gray-200 text-gray-500 hover:text-gray-900 dark:border-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => (string) $value !== $current,
                                ])>{{ $optionLabel }}</button>
                    @endforeach
                </div>
            @else
                <select wire:change="setDashboardFilter(@js($filterName), $event.target.value)"
                        data-testid="widget-filter-{{ $filterName }}"
                        aria-label="{{ $dashboardFilter->getLabel() }}"
                        class="rounded-full border-gray-200 py-1 pl-3 pr-8 text-base sm:text-xs font-semibold text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <option value="">{{ $dashboardFilter->getPlaceholder() ?? $dashboardFilter->getLabel() }}</option>
                    @foreach($dashboardFilter->getOptions() as $value => $optionLabel)
                        <option value="{{ $value }}" @selected((string) $value === $current)>{{ $optionLabel }}</option>
                    @endforeach
                </select>
            @endif
        @endforeach

        @if($filterState->isNarrowed())
            <button type="button" wire:click="resetDashboardFilters"
                    data-testid="widget-filters-reset" @wireEl('widget-filters-reset')
                    class="text-xs font-semibold text-gray-500 underline underline-offset-2 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">
                {{ __('wire-core::messages.widget_filters_reset') }}
            </button>
        @endif
    </div>
@endif
