{{-- Table summaries for the stacked card view. --}}
{{-- Variables: $table, $component, $summaries, $summaryScope, $summaryScopeOptions,
     $subRowGrandTotals (optional), $visibleColumns --}}
@php
    // The desktop totals live in a <tfoot> of a table the card layout hides, so
    // on a phone there were no totals at all — in an accounting table, the number
    // the user came for. Rendered here as label/value rows instead, sharing the
    // right edge with the cards' own metrics.
    $subRowGrandTotals = $subRowGrandTotals ?? [];
    $summaryScope = $summaryScope ?? 'query';
    $summaryScopeOptions = $summaryScopeOptions ?? ['query', 'page'];
    $showScopeToggle = count($summaryScopeOptions) > 1;

    // Flattened to (label, value) pairs in column order: a card has no columns to
    // align to, so the grid the desktop footer mirrors means nothing here.
    $rows = [];
    foreach ($visibleColumns as $column) {
        foreach ($summaries[$column->getName()] ?? [] as $entry) {
            $rows[] = ['label' => $entry['label'], 'value' => $entry['value'], 'column' => $column->getLabel()];
        }
    }
    foreach ($subRowGrandTotals as $entries) {
        foreach ($entries as $entry) {
            $rows[] = ['label' => $entry['label'], 'value' => $entry['value'], 'column' => null];
        }
    }
@endphp

@if($rows !== [])
    <div{!! $partialAnchor ?? '' !!} class="border-t-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/50"
         data-testid="table-card-summary" @wireEl('table-card-summary')>
        @if($showScopeToggle)
            <div class="flex items-center justify-between gap-2 border-b border-gray-200 dark:border-gray-700 px-4 py-2.5">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('wire-table::messages.summary_scope_label') }}</span>
                <div class="inline-flex overflow-hidden rounded-md border border-gray-200 dark:border-gray-600 text-xs">
                    @foreach($summaryScopeOptions as $option)
                        <button type="button"
                                wire:click="setSummaryScope('{{ $option }}')"
                                data-testid="card-summary-scope-{{ $option }}"
                                @class([
                                    'px-2.5 py-1.5 font-medium transition-colors',
                                    'bg-primary-600 text-white' => $summaryScope === $option,
                                    'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300' => $summaryScope !== $option,
                                    'border-l border-gray-200 dark:border-gray-600' => ! $loop->first,
                                ])>
                            {{ __('wire-table::messages.summary_scope_'.$option) }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @foreach($rows as $row)
            <div class="flex items-baseline justify-between gap-3 px-4 py-2">
                <span class="min-w-0 truncate text-sm text-gray-600 dark:text-gray-300">
                    {{ $row['label'] }}
                    @if($row['column'])
                        <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $row['column'] }}</span>
                    @endif
                </span>
                <span class="shrink-0 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">
                    {{ $row['value'] }}
                </span>
            </div>
        @endforeach
    </div>
@endif
