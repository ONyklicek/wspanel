@php /** @var \NyonCode\WireCore\Foundation\Schema\Step $layout */
    use NyonCode\WireCore\Foundation\Support\ResponsiveGrid;

    $columns = $layout->getColumns();
    // One owner for both shapes, and for the span each child is drawn with:
    // a field grid ramps at `sm`, which used to be a local `match` here and in
    // four other views. `ResponsiveGrid::fieldColumns()` is that ladder, and
    // `span()` reads it too — so a child can never ask for a column this grid
    // does not have at that width, which CSS Grid answers by adding one.
    $ladder = is_array($columns) ? $columns : ResponsiveGrid::fieldColumns($columns);
    $columnsClass = ResponsiveGrid::cols($ladder);
@endphp

<div class="grid gap-4 {{ $columnsClass }}">
    @foreach($layout->getSchema() as $component)
        @if($component->isVisible())
            {{ $component->inGridOf($ladder) }}
        @endif
    @endforeach
</div>
