@php
    use NyonCode\WireCore\Foundation\Schema\Grid;
    use NyonCode\WireCore\Foundation\Support\ResponsiveGrid;

    assert($layout instanceof Grid);

    // Both shapes through the canonical owner. The int case used to be a local
    // `match` that reflowed at `sm` and stopped at four columns, while the
    // standalone <x-wire::grid> tag over the same class already delegated and
    // reflowed at `md` — one Grid, two layouts, depending on which surface drew
    // it.
    $columns = $layout->getColumns();
    $columnsClass = ResponsiveGrid::cols($columns);
@endphp

<div class="grid gap-4 {{ $columnsClass }}">
    @foreach($layout->getSchema() as $component)
        @if($component->isVisible())
            {{-- Told which grid it is in, because a span is only meaningful
                 against a column count — and a span wider than the grid does not
                 clip, it makes CSS Grid add the missing track. See
                 HasColumnSpan::inGridOf(). --}}
            {{ $component->inGridOf($columns) }}
        @endif
    @endforeach
</div>
