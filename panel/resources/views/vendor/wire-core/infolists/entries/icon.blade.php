@php
    use NyonCode\WireCore\Infolists\Components\IconEntry;

    assert($field instanceof IconEntry);

    $spanClass = $field->getColumnSpanClass();
    $icon = $field->getResolvedIcon();
    $iconClass = 'w-5 h-5 '.$field->getIconColorClass();
    $tooltip = $field->getTooltip() ?? '';
@endphp

<div class="{{ $spanClass }}" @wireExtraAttributes($field)>
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel()])
    @endif

    <div class="text-sm">
        @if($icon)
            {!! icon($icon, 'w-4 h-4', $iconClass, $tooltip) !!}
        @else
            <span class="text-gray-500 dark:text-gray-400">{{ $field->getPlaceholder() ?? '-' }}</span>
        @endif
    </div>

    @if($field->hasActions())@include('wire-core::infolists.entry-actions')@endif
</div>
