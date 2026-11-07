{{--
    The sub-row expander cell — rendered ONCE PER SHAPE, not once per row.

    Variables: $cellPadding, $borderClass, $hasToggle, $isExpanded, $keyJs.

    Three shapes exist and the Table caches one skeleton for each: no toggle (a record
    with no sub-rows still gets the cell, empty, or the columns stop lining up), toggle
    collapsed, toggle expanded. Within a shape the only per-record value is the record
    key, which the toggle uses in one Alpine expression — one slot.

    The `@if` is kept rather than resolved in PHP, and it is deliberate: the pair of
    Livewire morph markers it emits is baked into each compiled shape, so every row
    still carries them. A row's children can change between renders (a column reorder
    rewrites the cell list) and morphdom needs those boundaries — see §8f in
    architecture/plans/render-engine-htmlable-first.md, where removing an equivalent
    `@if` broke the reorder with both fuses still green.

    Tags touch on purpose: a whitespace run between two tags is one DOM text node.
--}}
<td wire:key="exp-{!! $key !!}" class="w-10 {{ $cellPadding }} {{ $borderClass }}">@if($hasToggle)@include('wire-table::tables.partials.sub-row-toggle', ['keyJs' => $keyJs, 'isExpanded' => $isExpanded])@endif</td>
