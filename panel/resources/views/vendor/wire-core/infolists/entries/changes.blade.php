@php
    use NyonCode\WireCore\Infolists\Components\ChangesEntry;

    assert($field instanceof ChangesEntry);

    $spanClass = $field->getColumnSpanClass('col-span-full');
    $rows = $field->getRows();
@endphp

<div class="{{ $spanClass }}" @wireExtraAttributes($field)>
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel()])
    @endif

    <div class="text-sm">
        @if(count($rows))
            {{-- The same partial the audit trail draws, so a diff reads the same
                 in a slide-over and on a page. --}}
            @include('wire-core::partials.change-table', ['rows' => $rows, 'dense' => $field->isDense()])
        @else
            <span class="text-gray-500 dark:text-gray-400">{{ $field->getPlaceholder() ?? '-' }}</span>
        @endif
    </div>

    @if($field->hasActions())@include('wire-core::infolists.entry-actions')@endif
</div>
