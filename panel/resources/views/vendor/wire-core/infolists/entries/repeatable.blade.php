@php
    use NyonCode\WireCore\Foundation\Support\ResponsiveGrid;
    use NyonCode\WireCore\Infolists\Components\RepeatableEntry;

    assert($field instanceof RepeatableEntry);

    $spanClass = $field->getColumnSpanClass('col-span-full');
    $columns = $field->getColumns();
    $rows = $field->getRows();
    $rowActions = $field->getActions();
    // The field ladder, from its one owner — see the note in
    // schema/step.blade.php; each entry is told it so its own span is drawn
    // against the columns a row actually has at that width.
    $ladder = is_array($columns) ? $columns : ResponsiveGrid::fieldColumns(is_int($columns) ? $columns : 1);
    $gridCols = ResponsiveGrid::cols($ladder);
@endphp

<div class="{{ $spanClass }}" @wireExtraAttributes($field)>
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel(), 'margin' => 'mb-2'])
    @endif

    <div class="text-sm">
        @if(count($rows))
            <div class="space-y-3">
                @foreach($rows as $rowIndex => $entries)
                    <div @class(['rounded-lg border border-gray-200 dark:border-gray-700 p-4' => $field->isContained()])>
                        <div @class(['grid gap-4', $gridCols])>
                            @foreach($entries as $entry)
                                @if($entry->isVisible())
                                    {{ $entry->inGridOf($ladder) }}
                                @endif
                            @endforeach
                        </div>

                        @if($rowActions !== [])
                            <div class="mt-3 flex flex-wrap items-center justify-end gap-1.5">
                                @foreach($rowActions as $rowAction)
                                    @include('wire-core::partials.component-action', ['action' => $rowAction, 'rowKey' => $rowIndex])
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <span class="text-gray-500 dark:text-gray-400">{{ $field->getPlaceholder() ?? '-' }}</span>
        @endif
    </div>
</div>
