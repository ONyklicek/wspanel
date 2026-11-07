{{-- Table summary footer.

     $partialAnchor is a whole `wire:partial` attribute or an empty string, and it
     is composed in PHP for the same reason the row's is: Blade needs a non-word
     character on both sides of a directive, so an `@if` between two attributes
     cannot be spaced without costing a byte on every table that does not send
     rows. It matters here because a write moves the totals as surely as it moves
     the row — a total is computed over the whole filtered set — so the footer is
     queued alongside it. See Support\SummaryRenderer. --}}
{{-- Variables: $table, $component, $summaries, $summaryScope, $summaryScopeOptions,
     $isSelectable, $hasActions, $actionsPosition, $cellPadding, $isBordered, $visibleColumns, $colSpan,
     $stickyCellClass, $stickyLayers, $subRowGrandTotals (optional) --}}
{{-- The two sticky strings are empty unless Table::stickyActions() pinned the
     actions column, in which case the footer's copy of that column has to pin
     with the same offsets as the body's, or the pane stops one row short. The
     summary rows take `bg-inherit` for the same reason the header row does: the
     colour is on the <tfoot>, and a pinned cell inherits its opaque backdrop
     down that chain. See Support\StickyColumn. --}}
@php
    // Determine how many summary rows we need
    $maxRows = 0;
    foreach ($summaries as $colName => $summaryList) {
        $maxRows = max($maxRows, count($summaryList));
    }

    $summaryScope = $summaryScope ?? 'query';
    $summaryScopeOptions = $summaryScopeOptions ?? ['query', 'page'];
    $showScopeToggle = count($summaryScopeOptions) > 1;
    $subRowGrandTotals = $subRowGrandTotals ?? [];
@endphp

@if($maxRows > 0 || $subRowGrandTotals !== [])
    <tfoot{!! $partialAnchor ?? '' !!} class="bg-gray-50 dark:bg-gray-800/50 border-t-2 border-gray-300 dark:border-gray-600">
        {{-- Scope toggle row: this page / all / selection --}}
        @if($showScopeToggle)
            <tr>
                <td colspan="{{ $colSpan }}" class="{{ $cellPadding }} py-2">
                    <div class="flex items-center justify-end gap-2">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('wire-table::messages.summary_scope_label') }}</span>
                        <div class="inline-flex rounded-md border border-gray-200 dark:border-gray-600 overflow-hidden text-xs">
                            @foreach($summaryScopeOptions as $option)
                                <button type="button"
                                        wire:click="setSummaryScope('{{ $option }}')" data-testid="summary-scope-{{ $option }}"
                                        @class([
                                            'px-2.5 py-1 font-medium transition-colors',
                                            'bg-primary-600 text-white' => $summaryScope === $option,
                                            'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700' => $summaryScope !== $option,
                                            'border-l border-gray-200 dark:border-gray-600' => !$loop->first,
                                        ])>
                                    {{ __('wire-table::messages.summary_scope_'.$option) }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </td>
            </tr>
        @endif

        @for($i = 0; $i < $maxRows; $i++)
            <tr class="bg-inherit">
                {{-- Selection spacer — carries data-select-cell so the selection
                     column stays one addressable track through the footer rows. --}}
                @if($isSelectable)
                    <td class="w-12 {{ $cellPadding }}" data-select-cell></td>
                @endif

                {{-- Sub-row toggle spacer --}}
                @if($table->hasSubRows() && $table->isSubRowsExpandable())
                    <td class="w-10 {{ $cellPadding }}"></td>
                @endif

                {{-- Actions (start position) --}}
                @if($hasActions && $actionsPosition === 'start')
                    <td class="{{ $cellPadding }} {{ $isBordered ? 'border border-gray-200 dark:border-gray-700' : '' }} {{ $stickyCellClass }}">{!! $stickyLayers !!}
                        @if($i === 0)
                            <span class="relative text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('wire-table::messages.summary_total') }}</span>
                        @endif
                    </td>
                @endif

                {{-- Column cells --}}
                @foreach($visibleColumns as $column)
                    @php
                        $colName = $column->getName();
                        $colSummaries = $summaries[$colName] ?? [];
                        $entry = $colSummaries[$i] ?? null;
                    @endphp
                    <td class="{{ $cellPadding }} {{ $isBordered ? 'border border-gray-200 dark:border-gray-700' : '' }} {{ $column->getAlignmentClass() }}">
                        @if($entry)
                            <div class="text-xs">
                                <span class="text-gray-500 dark:text-gray-400">{{ $entry['label'] }}:</span>
                                <span class="text-gray-900 dark:text-white font-semibold">{{ $entry['value'] }}</span>
                            </div>
                        @endif
                    </td>
                @endforeach

                {{-- Actions (end position) --}}
                @if($hasActions && $actionsPosition === 'end')
                    <td class="{{ $cellPadding }} {{ $isBordered ? 'border border-gray-200 dark:border-gray-700' : '' }} {{ $stickyCellClass }}">{!! $stickyLayers !!}</td>
                @endif
            </tr>
        @endfor

        {{-- Sub-row grand totals: children across all parents. Sub-row columns
             don't align with the parent grid, so these render full-width. --}}
        @foreach($subRowGrandTotals as $colName => $entries)
            @foreach($entries as $entry)
                <tr>
                    <td colspan="{{ $colSpan }}" class="{{ $cellPadding }} {{ $isBordered ? 'border border-gray-200 dark:border-gray-700' : '' }} text-right">
                        <div class="text-xs">
                            <span class="text-gray-500 dark:text-gray-400">{{ $entry['label'] }}:</span>
                            <span class="text-gray-900 dark:text-white font-semibold">{{ $entry['value'] }}</span>
                        </div>
                    </td>
                </tr>
            @endforeach
        @endforeach
    </tfoot>
@endif
