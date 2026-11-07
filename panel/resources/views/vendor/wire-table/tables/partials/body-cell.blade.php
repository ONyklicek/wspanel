{{--
    One body cell — compiled ONCE per column, then filled per record.

    Variables: $cellPadding, $wrapClass, $borderClass, $alignment, $responsive,
    $name, $extraAttributes (raw, author-supplied), $content (the rendered cell).

    Every attribute here is column-static; only what sits between the tags varies by
    record. So a 50×10 page used to re-emit the same ten opening tags five hundred
    times and pay Blade's interpolation for each — this is rendered once per column
    and spliced ({@see the $columnMeta preamble in tables/index.blade.php}).

    Two things about the layout are load-bearing, not style:

    - The attributes stay on ONE line. Whitespace between attributes costs no DOM
      node, but it does cost bytes, and this tag is emitted once per cell — laying it
      out over five lines would put ~4 bytes × 3 back onto every cell of every row.
    - The single spaces inside `class` are deliberate: an empty $wrapClass or
      $alignment collapses to a double space, exactly as the interpolation this
      replaced did, so the attribute stays byte-identical rather than merely
      equivalent.

    The `@if` sits INSIDE the tag, where Livewire deliberately injects no morph
    markers — which is what keeps a cell free of them.
--}}
<td class="{{ $cellPadding }} {{ $wrapClass }} {{ $borderClass }} {{ $alignment }} dark:text-white {{ $responsive }}" data-testid="table-cell-{{ $name }}" data-column="{{ $name }}"@if($extraAttributes) {!! $extraAttributes !!}@endif>{!! $content !!}</td>
