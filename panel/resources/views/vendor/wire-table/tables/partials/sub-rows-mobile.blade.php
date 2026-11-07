{{-- Sub-rows inside a stacked mobile card. --}}
{{-- Variables: $table, $component, $record, $recordKey, $visibleSubRowColumns,
     $isExpanded, $isSubRowsExpandable, $isSelectable --}}
@php
    // The desktop panel is a table; a phone has no room for one. Children render
    // as a list instead: name on the left, its figure right on the same edge as
    // the card's own metric, the supporting detail underneath.
    $subColumns = array_values($visibleSubRowColumns);
    $subCard = $table->getMobileCard($subColumns);
    $childTitle = $subCard->title();
    $childMetric = $subCard->metric();
    $childDetails = array_values(array_filter(
        $subCard->details(),
        fn ($c) => $c !== $childTitle && $c !== $childMetric,
    ));
    $childMeta = $subCard->meta();
    $hasSubRowActions = $table->hasSubRowActions();
    $subRowActionGroup = $hasSubRowActions ? $table->getMobileSubRowActionGroup() : null;
    $toggleLabel = $table->getSubRowsToggleLabel();

    // "2 items" beats "Details" on a phone — it says whether expanding is worth a
    // tap. But only when the number is already in memory: a collapsed row has no
    // eager-loaded children, so asking for it would cost one COUNT per card. A
    // base query with ->withCount('items') lights this up for free. Which of the
    // two things in memory to believe, and in what order, is the host's rule —
    // it was written out here as well until the third copy of it drifted.
    $collapsedCount = $isExpanded ? null : $component->getLoadedSubRowCount($record);
@endphp

@if($isExpanded)
    @php
        // The same panel the desktop draws as a table, so the same owner decides
        // its numbers — the four lines that used to sit here were a verbatim
        // copy of the four in tables/partials/sub-rows.blade.php.
        $subRows = $component->getSubRows($record);
        $panel = \NyonCode\WireTable\Support\SubRowPanel::for(
            $table, $component, $record, $recordKey, $subRows, $subColumns,
        );

        $subRowSummaries = $panel->summaries;
        $total = $panel->total;
        $remaining = $panel->remaining;
        $showSubRowSummaries = $panel->showsSummaries;
    @endphp

    @if($isSubRowsExpandable)
        <button
            type="button"
            wire:click="toggleRowExpansion('{{ $recordKey }}')"
            data-testid="table-card-subrows-toggle" @wireEl('table-card-subrows-toggle')
            aria-expanded="true"
            class="flex w-full items-center gap-2 border-t border-gray-100 dark:border-gray-700/50 px-4 py-2.5 text-left text-sm text-gray-500 dark:text-gray-400"
        >
            {!! icon('outline:chevron-right', 'w-3.5 h-3.5', 'rotate-90 shrink-0') !!}
            <span class="font-medium text-gray-900 dark:text-white">
                {{ $toggleLabel ?? trans_choice('wire-table::messages.sub_rows_count', $total, ['count' => $total]) }}
            </span>
        </button>
    @endif

    <div class="bg-gray-50 dark:bg-gray-900/40 border-t border-gray-100 dark:border-gray-700/50">
        @forelse($subRows as $subRow)
            <div class="flex items-baseline gap-3 border-b border-gray-100 dark:border-gray-700/50 px-4 py-2.5"
                 wire:key="card-sub-row-{{ $recordKey }}-{{ $subRow->getKey() }}"
                 data-testid="table-card-sub-row" @wireEl('table-card-sub-row')>
                <div class="min-w-0 flex-1">
                    @if($childTitle)
                        <div class="truncate text-sm text-gray-900 dark:text-white">
                            {!! $childTitle->renderCellFast($subRow) !!}
                        </div>
                    @endif
                    @if(count($childDetails) > 0 || count($childMeta) > 0)
                        {{-- One quiet line, clipped rather than wrapped: a child that
                             breaks over four lines costs more room than the row it
                             describes. --}}
                        <div class="mt-0.5 flex items-center gap-x-2 overflow-hidden whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                            @foreach($childMeta as $metaColumn)
                                <span class="shrink-0">{!! $metaColumn->renderCellFast($subRow) !!}</span>
                            @endforeach
                            @foreach($childDetails as $detailColumn)
                                <span class="truncate">{{ $detailColumn->getLabel() }}: {!! $detailColumn->renderCellFast($subRow) !!}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if($childMetric)
                    <div class="shrink-0 text-sm tabular-nums text-gray-700 dark:text-gray-300 whitespace-nowrap">
                        {!! $childMetric->renderCellFast($subRow) !!}
                    </div>
                @endif

                @if($hasSubRowActions)
                    <div class="-mr-2 flex shrink-0 items-center self-center">
                        {!! $subRowActionGroup->render($subRow) !!}
                    </div>
                @endif
            </div>
        @empty
            <div class="px-4 py-4 text-center text-xs italic text-gray-500 dark:text-gray-400">
                {{ __('wire-table::messages.no_sub_rows') }}
            </div>
        @endforelse

        @if($remaining > 0)
            <button
                type="button"
                wire:click="showAllSubRows('{{ $recordKey }}')"
                data-testid="table-card-subrows-more" @wireEl('table-card-subrows-more')
                class="w-full border-b border-gray-100 dark:border-gray-700/50 px-4 py-2.5 text-sm font-medium text-primary-600 dark:text-primary-400"
            >
                {{ __('wire-table::messages.show_more_count', ['count' => $remaining]) }}
            </button>
        @endif

        {{-- Per-parent subtotals, on the same right edge as the rows above. --}}
        @if($showSubRowSummaries)
            @foreach($subRowSummaries as $columnName => $entries)
                @foreach($entries as $entry)
                    <div class="flex items-baseline gap-3 px-4 py-2 text-sm"
                         data-testid="table-card-subrows-summary" @wireEl('table-card-subrows-summary')>
                        <span class="flex-1 font-semibold text-gray-700 dark:text-gray-200">{{ $entry['label'] }}</span>
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $entry['value'] }}</span>
                        {{-- Mirrors the overflow column above, so the total lands on the
                             same right edge as the amounts it sums. Reuses the utilities
                             the actions cell already ships — a one-off spacing class
                             would only exist in a rebuilt stylesheet. --}}
                        @if($hasSubRowActions)
                            <span class="-mr-2 w-10 shrink-0" aria-hidden="true"></span>
                        @endif
                    </div>
                @endforeach
            @endforeach
        @endif
    </div>
@elseif($isSubRowsExpandable)
    <button
        type="button"
        wire:click="toggleRowExpansion('{{ $recordKey }}')"
        data-testid="table-card-subrows-toggle" @wireEl('table-card-subrows-toggle')
        aria-expanded="false"
        class="flex w-full items-center gap-2 border-t border-gray-100 dark:border-gray-700/50 px-4 py-2.5 text-left text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300"
    >
        {!! icon('outline:chevron-right', 'w-3.5 h-3.5 shrink-0') !!}
        <span class="font-medium text-gray-900 dark:text-white">
            @if($toggleLabel)
                {{ $toggleLabel }}
            @elseif($collapsedCount !== null)
                {{ trans_choice('wire-table::messages.sub_rows_count', $collapsedCount, ['count' => $collapsedCount]) }}
            @else
                {{ __('wire-table::messages.details') }}
            @endif
        </span>
    </button>
@endif
