@php
    use NyonCode\WireCore\Foundation\Support\ResponsiveGrid;

    /** @var array<int, mixed> $components */
    /** @var int|array<string|int, int|string> $columns */

    // The canonical owner resolves the grid, rather than a fifth copy of the
    // same match: this view used to stop at four columns and start reflowing at
    // `sm`, so an infolist asking for six got two and a phone got the desktop
    // layout at 640px. Sections and grids already went through ResponsiveGrid.
    $columnsClass = ResponsiveGrid::cols($columns);
@endphp

<div class="wire-infolist grid gap-4 sm:gap-6 {{ $columnsClass }}">
    @foreach($components as $component)
        @if($component->isVisible())
            {{-- The grid an entry's own span is resolved against; see
                 HasColumnSpan::inGridOf(). --}}
            {{ $component->inGridOf($columns) }}
        @endif
    @endforeach
</div>
