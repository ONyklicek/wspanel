{{-- Group subtotal row(s) — mirrors the summary footer layout inside the body --}}
{{-- Variables: $table, $component, $groupSummaries, $visibleColumns, $colSpan,
     $cellPadding, $isBordered, $isSelectable, $hasActions, $actionsPosition,
     $stickyCellClass, $stickyLayers, $partialAnchors --}}
{{-- The two sticky strings are empty unless Table::stickyActions() pinned the
     actions column; a subtotal row sits in the body, so its copy of that column
     pins exactly like a record's. Support\StickyColumn. --}}
{{-- $partialAnchors is one whole `wire:partial` attribute per subtotal row, or an
     empty array where the table does not send rows.

     ONE ANCHOR PER ROW rather than one for the group, because a subtotal is
     `@for`-many `<tr>`s and `wire:partial` addresses a single element. The
     alternative — a `<tbody>` per group — is valid HTML but changes the table's
     structure, and the delegated `wireRecordActions` controller is mounted on the
     one `<tbody>` precisely so a nested table cannot be mistaken for it. Anchoring
     each row keeps that invariant and needs no structural change at all.

     The count is stable between renders: it is the widest column's declared
     summary list, which is configuration, not data. --}}
{{-- Tags touch on purpose: this renders once per group, which on a table grouped by
     something high-cardinality is once per row, and every whitespace run between two
     tags is a DOM text node the morph walks. Whitespace between attributes is free.
     Every conditional stays — its morph markers are load-bearing (§8f). --}}
@php
    // Hoisted: a directive expression with nested parentheses sends Livewire's morph
    // marker injector down its paren-repair path, which can swallow a neighbouring
    // directive's opening marker — see the note in sub-rows.blade.php.
    $hasExpanderSpacer = $table->hasSubRows() && $table->isSubRowsExpandable();
    $groupMaxRows = 0;
    foreach ($groupSummaries as $colName => $summaryList) {
        $groupMaxRows = max($groupMaxRows, count($summaryList));
    }
@endphp
@for($i = 0; $i < $groupMaxRows; $i++)<tr{!! $partialAnchors[$i] ?? '' !!} class="bg-gray-50 dark:bg-gray-800/40">{{-- Selection spacer — carries data-select-cell so the selection column
    stays one addressable track through the subtotal rows. --}}
@if($isSelectable)<td class="w-12 {{ $cellPadding }}" data-select-cell></td>@endif{{-- Sub-row toggle spacer --}}
@if($hasExpanderSpacer)<td class="w-10 {{ $cellPadding }}"></td>@endif{{-- Actions (start position) --}}
@if($hasActions && $actionsPosition === 'start')<td class="{{ $cellPadding }} {{ $isBordered ? 'border border-gray-200 dark:border-gray-700' : '' }} {{ $stickyCellClass }}">{!! $stickyLayers !!}@if($i === 0)<span class="relative text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('wire-table::messages.summary_subtotal') }}</span>@endif</td>@endif{{-- Column cells --}}
@foreach($visibleColumns as $column)@php
    $colName = $column->getName();
    $colSummaries = $groupSummaries[$colName] ?? [];
    $entry = $colSummaries[$i] ?? null;
@endphp<td class="{{ $cellPadding }} {{ $isBordered ? 'border border-gray-200 dark:border-gray-700' : '' }} {{ $column->getAlignmentClass() }}">@if($entry)<div class="text-xs"><span class="text-gray-500 dark:text-gray-400">{{ $entry['label'] }}:</span> <span class="text-gray-900 dark:text-white font-medium">{{ $entry['value'] }}</span></div>@endif</td>@endforeach{{-- Actions (end position) --}}
@if($hasActions && $actionsPosition === 'end')<td class="{{ $cellPadding }} {{ $isBordered ? 'border border-gray-200 dark:border-gray-700' : '' }} {{ $stickyCellClass }}">{!! $stickyLayers !!}</td>@endif</tr>@endfor
