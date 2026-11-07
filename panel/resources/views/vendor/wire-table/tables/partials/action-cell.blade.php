{{--
    The row's action cell — compiled once for the table, filled per row.

    Variables: $cellPadding, $borderClass, $stickyCellClass and $stickyLayers
    (all table-level, baked at compile time) and $actions (a slot: this row's
    buttons, already rendered).

    The layers and the `relative` on the button row are what a pinned actions
    column needs and an ordinary one does not notice: with `stickyActions()` off
    both strings are empty and this is the same cell it always was. Why a pinned
    cell takes three layers rather than an opaque background: Support\StickyColumn.

    It lived inline in the row loop until the loop's conditionals moved into PHP.
    The buttons themselves stay per record — an action can be non-executable for
    one row and not the next — which is why they carry `wire:key="act-…"` now:
    that is what pairs them through a morph, in place of the `@foreach` markers
    this partial no longer emits.

    $inert is the second shape, not a per-record value: with Table::rowInactive(
    ..., fn ($row) => $row->actions(false)) the cell is compiled twice and each
    row splices the one it needs. `inert` rather than pointer-events, so the
    buttons leave the tab order too — and the host refuses the action anyway
    (Concerns\InteractsWithTableActions), since an inert attribute is a client
    fact and a forged request is not bound by it.

    $touchTrigger is the rendered row-touch-menu-trigger ("⋯") for a table with a
    touch menu, and an empty string otherwise — a string rather than an @if, so the
    compiled cell carries no morph markers for it.

    Mind the whitespace: the tags touch on purpose. A run of whitespace between
    two tags is a DOM text node that the morph walks on every commit, and this
    cell is emitted once per row. Whitespace between attributes is free.
--}}
<td data-row-actions class="{{ $cellPadding }} {{ $borderClass }} {{ $stickyCellClass }}{{ $inert ? ' opacity-40' : '' }}"@if($inert) inert=""@endif>{!! $stickyLayers !!}<div class="relative flex flex-wrap items-center gap-1 {{ $justifyClass }}">{!! $actions !!}{!! $touchTrigger !!}</div></td>
