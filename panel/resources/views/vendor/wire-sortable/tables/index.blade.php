@php
    use NyonCode\WireTable\Table;

    assert($table instanceof Table);
    /** @var mixed $records */
    /** @var mixed $component */

    $isReorderable = $table->isReorderable();
    $isColumnReorderable = $table->isColumnReorderable();
    $hasSortableFeatures = $isReorderable || $isColumnReorderable;
    $isReordering = $isReorderable && $component->isTableReordering();
    $orderColumn = $isReorderable ? $table->getOrderColumn() : null;
@endphp

@if($hasSortableFeatures)
    @php
        // Apply user-defined column order before the inner view renders so that
        // $table->getColumns() (called by wire-table::tables.index in multiple
        // places) returns columns in the persisted order instead of the original
        // PHP-defined order. Without this, Livewire's morph undoes the visual
        // reordering on every re-render.
        if ($isColumnReorderable && method_exists($component, 'getReorderableColumns')) {
            $reorderedColumns = $component->getReorderableColumns();
            if (!empty($reorderedColumns)) {
                $table->columns($reorderedColumns);
            }
        }
    @endphp

    {{-- The drag controller, ahead of the markup that references it. Livewire's
         @assets hoists the tag into the document head either way, so the
         position no longer decides whether wireSortable exists in time — but
         the emit order used to be exactly backwards (the factory was defined
         after its consumer), and source order should read the way the
         dependency runs. --}}
    @include('wire-sortable::partials.scripts')

    <div
            x-data="wireSortable({
                rowReorderable: {{ $isReorderable ? 'true' : 'false' }},
                columnReorderable: {{ $isColumnReorderable ? 'true' : 'false' }},
                isReordering: @entangle('isReordering'),
                orderColumn: '{{ $orderColumn }}',
                animation: @js((int) (config('wire-sortable.animation') ?? 150)),
                dragHandleHtml: @js($table->getDragHandleHtml()->toHtml()),
            })"
            class="wire-sortable-wrapper"
    >
        @include('wire-table::tables.index', [
            'table' => $table,
            'records' => $records,
            'component' => $component,
        ])
    </div>
@else
    @include('wire-table::tables.index', [
        'table' => $table,
        'records' => $records,
        'component' => $component,
    ])
@endif
