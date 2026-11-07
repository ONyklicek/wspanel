{{--
    The selection cell — rendered ONCE per table, not once per row.

    Every part of this markup is table-static: the padding, the range-selection
    modifier prefix, both class lists, and the check icon. The only per-record value
    is the record key, which arrives as $keyJs — a Skeleton slot at compile time, the
    record's own `Js::from()` key at fill time. It appears four times under one
    encoding (inside an Alpine expression), so it is one slot.

    See Table::getSelectionCellSkeleton(); this partial stays the single source of
    the markup and the vendor:publish override point, exactly as it was — it is the
    RENDER that moved out of the row loop, not the template.

    $inert is the one thing about this cell that is NOT table-static, and it is
    still not per-record: a table with Table::rowInactive(..., fn ($row) => $row
    ->selectable(false)) compiles this partial twice — once plain, once inert —
    and each row splices the shape it needs. Two shapes, not N: the inert copy is
    the same markup for every inactive row. `inert` rather than a class, because
    pointer-events alone leaves the box in the tab order and answering Space.

    The tick DRAWS ITSELF IN, and the three x-transition attributes on the <span> are
    the whole mechanism. They sit there, not on the <svg>, for two reasons:
    `stroke-dashoffset` is an INHERITED property, so a utility on the span reaches the
    <path> two levels down and the browser hands it the interpolated value on every
    frame; and Alpine reads the duration off the element x-show owns to know when the
    enter is finished. The glyph brings its own `stroke-dasharray` (see
    packages/table/resources/icons/table.php).

    The hidden end is 12 — one unit past the 11-unit dash — so the mark is off the end
    of its own path rather than parked on its start point, where a round cap can leave a
    dot behind. The visible end is 0, which is ALSO what an undeclared
    `stroke-dashoffset` computes to: if a consumer's Tailwind build never generated these
    arbitrary utilities, the tick simply appears instead of never appearing at all.
    Written the other way round — hidden by default, revealed by a class — a missing
    utility would hide the mark permanently and read as a broken checkbox.

    Enter only, and all three attributes on one line. Both are the byte budget talking:
    TablePayloadFuseTest holds this cell to a splice, and a leave stage would cost three
    more attributes on every row to un-draw a tick that is losing its blue background in
    the same instant — which reads as a stutter anyway.

    Mind the whitespace: the tags below deliberately touch. A run of whitespace
    between two tags is one DOM text node, and the morph walks every one of them on
    every commit — this cell alone used to lay out ten of them per row. Whitespace
    BETWEEN attributes is free (no node), so the attributes stay laid out.
--}}
{{-- The whole cell toggles, not just the 16px box, which is under every touch-target
     guideline while the rest of the cell sits dead. While ranges are on, a modified
     click is left alone: Shift and mod mean range and add-to-selection, and the row
     controller answers those for the whole row, cell included. With ranges off nobody
     else would answer them, so the cell takes every click and toggles.

     data-select-cell marks the selection column for the sweep gesture (and the widened
     click target): the cell is found by this hook, never by column position — sortable
     prepends a drag-handle <td> and would shift every index. --}}
<td wire:key="sel-{!! $key !!}" class="w-12 {{ $cellPadding }} {{ $inert ? 'opacity-40' : 'cursor-pointer' }}"@if($inert) inert aria-hidden="true"@endif
    data-select-cell
    x-on:click="{{ $usesRangeSelection ? '$event.shiftKey || $event.ctrlKey || $event.metaKey || ' : '' }}toggle({!! $keyJs !!})"><div
        class="flex items-center justify-center"><button {{-- No handler of its own: a
        click (or Enter/Space on the focused box) bubbles to the cell, which owns the
        toggle. Two handlers would toggle twice. --}}
        type="button"
        role="checkbox"
        :aria-checked="isSelected({!! $keyJs !!})"
        aria-label="{{ __('wire-table::messages.select_row') }}"
        data-testid="table-row-select" @wireEl('table-row-select')
        class="relative h-4 w-4 rounded-sm border focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition-colors"
        :class="isSelected({!! $keyJs !!}) ? 'bg-primary-600 border-primary-600' : 'bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 hover:border-gray-400'"><span
            x-show="isSelected({!! $keyJs !!})"
            x-transition:enter="transition-[stroke-dashoffset] duration-200" x-transition:enter-start="[stroke-dashoffset:12]" x-transition:enter-end="[stroke-dashoffset:0]"
            x-cloak>{!! $checkIcon !!}</span></button></div></td>
