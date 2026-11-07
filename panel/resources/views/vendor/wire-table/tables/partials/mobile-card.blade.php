{{--
    One stacked-layout card — compiled once for the table, filled per record.

    The card is a second full rendering of every record: the desktop rows are in
    the same document, hidden by CSS at this width. So it is worth exactly what
    the row is worth, and it is compiled the same way — every condition below is
    a property of the TABLE (which slots the card has, whether it is selectable,
    whether its actions collapse), decided once here instead of on every card.

    The per-record parts arrive as slots, already rendered: the key in its two
    encodings, the card's own classes, and each slot's cell HTML. Two conditions
    that are NOT table-level live in Support\CardRenderer instead, because they
    would bake wrongly here — whether the record has a url (its title becomes a
    link) and whether it has sub-rows to expand.

    `$partialAnchor` is a whole attribute or an empty string, composed in PHP for
    the same reason as the row's: Blade needs a non-word character on both sides
    of a directive, so an `@if` between two attributes cannot be spaced without
    costing a byte on every card of every table that does not use row partials.

    Mind the whitespace: it is reproduced from the loop this replaced, tag for
    tag. A run of whitespace between two tags is a DOM text node the morph walks.

    Variables: $isSelectable, $cardTitle, $cardMetric, $cardSubtitle, $hasMeta,
    $hasDetails, $hasMobileActions, $collapseMobileActions, $detailsClass,
    $actionsClass (all table-level), plus the slots.

    $inertSelection / $inertActions are the card's second shape, not per-record
    values: a table with Table::rowInactive() compiles this shell twice — live and
    inactive — and each record splices the one it needs, so it stays O(shapes).
    Which of the two goes inert is the table's own switch
    (Support\InactiveRow::selectable() / ::actions()); the strike, the dimming and
    the tint arrive through $cardClasses instead, which is a value.
--}}
<div
        class="{!! $cardClasses !!}"
        data-testid="table-card" @wireEl('table-card')
        data-row-key="{!! $key !!}"{!! $partialAnchor !!}
        @if($isSelectable) :class="isSelected({!! $keyJs !!}) ? 'ring-2 ring-primary-500 ring-inset bg-primary-50/50 dark:bg-primary-900/30' : ''" @endif
>{{-- Header: identifier on the left, the figure the list is read
    for on the right, actions after it. --}}<div
        class="flex items-start gap-3 px-4 pt-4 {{ $hasDetails ? 'pb-3' : 'pb-4' }}"
>@if($isSelectable)<label class="flex items-center pt-0.5 flex-shrink-0{{ $inertSelection ? ' opacity-40' : '' }}"@if($inertSelection) inert aria-hidden="true"@endif data-select-cell><input
        type="checkbox"
        x-on:change="toggle({!! $keyJs !!})"
        :checked="isSelected({!! $keyJs !!})"
        data-testid="table-card-select" @wireEl('table-card-select')
        aria-label="{{ __('wire-table::messages.select_row') }}"
        class="h-5 w-5 rounded-sm border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 dark:focus:ring-offset-gray-800 touch-manipulation"
><span class="sr-only">{{ __('wire-table::messages.select_row') }}</span></label>@endif<div
        class="flex-1 min-w-0" data-card-content
><div class="flex items-baseline gap-3">@if($cardTitle)<div
        class="min-w-0 font-medium text-gray-900 dark:text-white truncate text-base"
>{!! $title !!}</div>@endif@if($cardMetric)<div
        class="ml-auto shrink-0 font-semibold text-gray-900 dark:text-white text-base tabular-nums whitespace-nowrap"
        data-testid="table-card-metric" @wireEl('table-card-metric')
>{!! $metric !!}</div>@endif</div>@if($cardSubtitle)<div
        class="mt-0.5 text-sm text-gray-600 dark:text-gray-300 truncate"
>{!! $subtitle !!}</div>@endif@if($hasMeta)<div
        class="mt-1.5 flex flex-wrap items-center gap-2"
>{!! $meta !!}</div>@endif</div>@if($hasMobileActions && $collapseMobileActions)<div
        class="flex items-center justify-end flex-shrink-0 -mr-1{{ $inertActions ? ' opacity-40' : '' }}"@if($inertActions) inert=""@endif
>{!! $groupActions !!}</div>@endif</div>@if($hasDetails)<dl
        class="{{ $detailsClass }}" data-card-content
>{!! $details !!}</dl>@endif@if($hasMobileActions && ! $collapseMobileActions)<div
        class="{{ $actionsClass }}{{ $inertActions ? ' opacity-40' : '' }}"@if($inertActions) inert=""@endif
        data-testid="table-card-actions" @wireEl('table-card-actions')
>{!! $actions !!}</div>@endif{!! $subRows !!}</div>
