{{--
    The body row's OPENING TAG — compiled once for the whole table, filled per row.

    Variables: $rowClass, $keyJs, $tabindex, $rowIndex, $ariaRowIndex, $key (all
    slots at compile time, the record's own values at fill time), plus the
    table-level $keyboardNav, $rowClassBinding, $tableRole, $isSelectable.

    Yes, an opening tag with no closing one. The row's children are the genuinely
    per-record part — the context-menu panel, the selection cell, the cells, the
    action cells — and each is guarded by a conditional whose morph markers are
    load-bearing (§8f in architecture/plans/render-engine-htmlable-first.md: taking
    one away broke the column reorder). Wrapping them in a slot would swallow those
    conditionals, so the row loop keeps them and this partial contributes the tag
    they hang from.

    $partialAnchor is a whole attribute or an empty string, composed in PHP rather
    than by an `@if` here: Blade needs a non-word character on both sides of a
    directive, so an `@if` wedged between `@endif` and `wire:key` cannot be spaced
    without emitting a byte on every row of every table that does NOT use row
    partials. It carries the key slot inside it, which works because a slot is
    just a token in the compiled string.

    Every condition below is a property of the TABLE, not of a record — keyboard
    nav, the ARIA role, selection, the row-class binding, whether any record can be
    inactive at all are either on for the page or off for it — so the row has
    exactly one shape and these `@if`s are decided once instead of re-decided on
    every row. $rowState is the one exception in spirit and not in shape: the SLOT
    exists only for a table that declares the inactive state, and carries that
    row's `aria-disabled`/`data-inactive` pair or an empty string. They sit INSIDE the tag, where Livewire
    deliberately injects no morph markers, which is what keeps a row free of them.

    The record key arrives through TWO slots because it appears under two encodings:
    `e()` in an HTML attribute, and Js::from() inside an Alpine expression. One slot,
    one position, one encoding (see Foundation\View\Skeleton).

    ── Why it is all on ONE line ──────────────────────────────────────────────────

    This tag is emitted once per row. Whitespace between attributes costs no DOM
    node, but it does cost bytes, and laid out over eight lines it measured +50 B
    per row — half of what compiling the row won in the first place. The comments
    live up here, where Blade strips them, and the markup stays on one line where it
    costs nothing. The single spaces before each `@if` and the absence of one after
    are deliberate: they reproduce the emitted tag exactly.

    Two notes on the bindings, since they are easy to "simplify" wrongly:
    the roving tabindex is BOUND, not printed, because Livewire morphs the rows back
    to this markup on every update and would wipe an assigned tabstop; aria-selected
    is bound for the same reason — the selection lives in Alpine, and a printed value
    would snap back to the server's truth on the next morph, leaving the row lying
    about itself. aria-rowindex counts through the whole grid, not the page, so it
    carries the header rows plus this page's offset and survives paging.
--}}
<tr class="{!! $rowClass !!} {{ $keyboardNav ? 'focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500' : '' }}" @if($rowClassBinding):class="{!! str_replace('%key%', $keyJs, $rowClassBinding) !!}" @endif @if($keyboardNav)role="row" tabindex="{!! $tabindex !!}" :tabindex="rowTabindex({!! $keyJs !!}, {!! $rowIndex !!})" @endif @if($tableRole)aria-rowindex="{!! $ariaRowIndex !!}" @endif @if($isSelectable):aria-selected="isSelected({!! $keyJs !!}) ? 'true' : 'false'" @endif{!! $partialAnchor !!} wire:key="row-{!! $key !!}" data-testid="table-row" @wireEl('table-row') data-row-key="{!! $key !!}"@if($inactiveRows){!! $rowState !!}@endif>
