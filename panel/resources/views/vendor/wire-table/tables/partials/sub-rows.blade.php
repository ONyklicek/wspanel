{{-- Sub-rows for a parent record --}}
{{-- Variables: $table, $component, $record, $recordKey, $subRows, $colSpan, $cellPadding, $isBordered --}}
{{--
    Mind the whitespace below: the tags touch on purpose.

    This partial renders once per EXPANDED parent, so its layout is emitted for every
    expanded row — and a run of whitespace between two tags is one DOM text node the
    morph walks on every commit. Indented the way it was written, one expanded row cost
    37 of them and ~3 kB, most of that indentation for a panel that is mostly identical
    from parent to parent.

    Whitespace BETWEEN ATTRIBUTES is free (no node), so the attributes stay laid out and
    every conditional stays exactly where it was — the morph markers an `@if`/`@foreach`
    emits are load-bearing here, see §8f in
    architecture/plans/render-engine-htmlable-first.md.
--}}
@php
    $customSubRowView = $table->getSubRowView();
@endphp
{{-- Custom child renderer: hand full control to a user-supplied view --}}
@if($customSubRowView)
<tr wire:key="sub-rows-{{ $recordKey }}"><td colspan="{{ $colSpan }}" class="p-0"><div class="bg-gray-50/80 dark:bg-gray-800/50 border-t border-b border-gray-100 dark:border-gray-700/50">@include($customSubRowView, [
    'table' => $table,
    'component' => $component,
    'record' => $record,
    'recordKey' => $recordKey,
    'subRows' => $subRows,
])</div></td></tr>
@else
@php
    // Every derived number this panel draws — the totals, the colspan, whether
    // the filter bar has anything active in it — is decided by SubRowPanel and
    // read here. The stacked card renders the same panel and asks the same
    // owner; the four lines that computed the "show more" remainder used to be
    // copied into both. The rule for "a filter is active" was worse: the copy
    // here was the *correct* one, while SubRowFilters — the service the query
    // path actually asks — still answered the version from before the slots
    // were seeded.
    //
    // What is left below is aliasing, and it stays because the markup after it
    // must not move: the morph markers an `@if`/`@foreach` emits are
    // load-bearing, and a directive expression with NESTED parentheses —
    // `@if($a && $b->c())` — sends Livewire's marker injector down its
    // parenthesis-repair path, which can swallow a neighbouring directive's
    // opening marker. Decide in PHP, let Blade consume. See TablePayloadFuseTest's
    // marker-balance assertion.
    $panel = \NyonCode\WireTable\Support\SubRowPanel::for(
        $table, $component, $record, $recordKey, $subRows, $visibleSubRowColumns ?? null,
    );

    $subColumns = $table->getSubRowColumns();
    $visibleSubRowColumns = $panel->columns;
    $subRowActions = $table->getSubRowActions();
    $hasSubRowActions = $panel->hasActions;
    $isSortable = $table->isSubRowsSortable();
    $activeSort = $component->getSubRowSort();
    $subRowFilterValues = $panel->filterValues;
    $hasActiveSubRowFilter = $panel->hasActiveFilter;
    $hasSubRowFilterBar = $panel->hasFilterBar;
    $subRowSummaries = $panel->summaries;
    $remaining = $panel->remaining;
    $totalColCount = $panel->columnCount;
    $showSubSummaries = $panel->showsSummaries;
    $maxRows = $panel->summaryRowCount;
@endphp
<tr wire:key="sub-rows-{{ $recordKey }}"><td colspan="{{ $colSpan }}" class="p-0"><div class="bg-gray-50/80 dark:bg-gray-800/50 border-t border-b border-gray-100 dark:border-gray-700/50">{{-- Sub-row filters --}}
@if($hasSubRowFilterBar)<div
    class="flex items-center gap-2 px-4 py-2 border-b border-gray-100 dark:border-gray-700/50"
><span class="text-xs font-medium text-gray-500 dark:text-gray-400 mr-1">{{ __('wire-table::messages.filter_label') }}</span>@foreach($subColumns as $subCol)@if($subCol->isFilterable())<div class="w-40">{{-- Bind to the sub-row filter slot, not the parent table's column
    filters — otherwise the input silently filters the parent (or, on a
    name collision, filters nothing at all). --}}{!! $subCol->renderFilter(
    $subRowFilterValues[$subCol->getName()] ?? null,
    'tableState.rows.subRowFilters.'.$subCol->getName(),
) !!}</div>@endif @endforeach @if($hasActiveSubRowFilter)<button
    type="button"
    wire:click="resetSubRowFilters"
    data-testid="subrows-reset-filters" @wireEl('subrows-reset-filters')
    class="text-xs text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"
>✕ {{ __('wire-table::messages.reset') }}</button>@endif</div>@endif{{-- Sub-rows table --}}<table class="w-full text-sm"><thead><tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{-- Indent spacer --}}<th class="w-8"></th>@foreach($visibleSubRowColumns as $subCol)@php $colSortable = $isSortable && $table->isSubRowColumnSortable($subCol->getName()); @endphp<th class="px-3 py-2 font-medium">@if($colSortable)@php $isActive = $activeSort && $activeSort['column'] === $subCol->getName(); @endphp<button
    type="button"
    wire:click="sortSubRows('{{ $subCol->getName() }}')"
    data-testid="subrows-sort-{{ $subCol->getName() }}"
    {{-- `uppercase` restated: a `<button>` does not inherit the row's
         text-transform — see the same note in data-region.blade.php. --}}
    class="inline-flex items-center gap-1 uppercase hover:text-gray-700 dark:hover:text-gray-200 {{ $isActive ? 'text-gray-700 dark:text-gray-200' : '' }}"
><span>{{ $subCol->getLabel() }}</span>@if($isActive)@if($activeSort['direction'] === 'asc'){!! icon('outline:chevron-up', 'w-4 h-4', 'text-gray-500 dark:text-gray-400') !!}@else{!! icon('outline:chevron-down', 'w-4 h-4', 'text-gray-500 dark:text-gray-400') !!}@endif @else<span class="text-[10px] opacity-30">{!! icon('outline:chevron-up-down', 'w-4 h-4', 'text-gray-500 dark:text-gray-400 opacity-0 hover:opacity-100') !!}</span>@endif</button>@else{{ $subCol->getLabel() }}@endif</th>@endforeach @if($hasSubRowActions)<th class="px-3 py-2 font-medium text-right">{{ __('wire-table::messages.actions') }}</th>@endif</tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">@forelse($subRows as $subRow)<tr class="hover:bg-gray-100/50 dark:hover:bg-gray-700/20" wire:key="sub-row-{{ $recordKey }}-{{ $subRow->getKey() }}"><td class="w-8"></td>@foreach($visibleSubRowColumns as $subCol)<td class="px-3 py-2 text-gray-700 dark:text-gray-300 {{ $subCol->shouldWrap() ? '' : 'whitespace-nowrap' }}">{!! $subCol->renderCellFast($subRow) !!}</td>@endforeach @if($hasSubRowActions)<td class="px-3 py-2 whitespace-nowrap text-right"><div class="flex items-center justify-end gap-1">@foreach($subRowActions as $action){!! $action->render($subRow) !!}@endforeach</div></td>@endif</tr>@empty<tr><td colspan="{{ $totalColCount }}" class="px-3 py-4 text-center text-xs text-gray-500 dark:text-gray-400 italic">{{ __('wire-table::messages.no_sub_rows') }}</td></tr>@endforelse{{-- Show more --}}
@if($remaining > 0)<tr wire:key="sub-rows-more-{{ $recordKey }}"><td colspan="{{ $totalColCount }}" class="px-3 py-2 text-center"><button
    type="button"
    wire:click="showAllSubRows('{{ $recordKey }}')"
    data-testid="subrows-show-more" @wireEl('subrows-show-more')
    class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline"
>{{ __('wire-table::messages.show_more_count', ['count' => $remaining]) }}</button></td></tr>@endif</tbody>{{-- Sub-row summaries --}}
@if($showSubSummaries)<tfoot class="border-t-2 border-gray-200 dark:border-gray-600">@for($i = 0; $i < $maxRows; $i++)<tr class="text-xs font-medium text-gray-600 dark:text-gray-400"><td class="w-8"></td>@foreach($visibleSubRowColumns as $subCol)@php
    $colSummaries = $subRowSummaries[$subCol->getName()] ?? [];
    $entry = $colSummaries[$i] ?? null;
@endphp<td class="px-3 py-1.5">@if($entry)<span class="text-gray-500 dark:text-gray-400">{{ $entry['label'] }}:</span> <span class="text-gray-700 dark:text-gray-200 font-semibold">{{ $entry['value'] }}</span>@endif</td>@endforeach @if($hasSubRowActions)<td class="px-3 py-1.5"></td>@endif</tr>@endfor</tfoot>@endif</table></div></td></tr>
@endif
