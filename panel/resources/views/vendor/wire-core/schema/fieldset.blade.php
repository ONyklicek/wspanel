@php
    use NyonCode\WireCore\Foundation\Schema\Fieldset;
    use NyonCode\WireCore\Foundation\Support\ResponsiveGrid;

    assert($layout instanceof Fieldset);

    $columns = $layout->getColumns();
    // One owner for both shapes, and for the span each child is drawn with —
    // see the note in schema/step.blade.php.
    $ladder = is_array($columns) ? $columns : ResponsiveGrid::fieldColumns($columns);
    $columnsClass = ResponsiveGrid::cols($ladder);
@endphp

<fieldset class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
    @if($layout->getLabel())
        <legend class="px-2 text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ $layout->getLabel() }}
        </legend>
    @endif

    <div class="grid gap-4 {{ $columnsClass }}">
        @foreach($layout->getSchema() as $component)
            @if($component->isVisible())
                {{ $component->inGridOf($ladder) }}
            @endif
        @endforeach
    </div>
</fieldset>
