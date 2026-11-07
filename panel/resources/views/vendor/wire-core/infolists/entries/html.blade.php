@php
    use NyonCode\WireCore\Infolists\Components\HtmlEntry;

    assert($field instanceof HtmlEntry);

    $spanClass = $field->getColumnSpanClass();
    // Already through MentionRenderer: names and links are as fresh as this
    // request, never as fresh as whenever somebody typed them.
    $html = $field->getRenderedHtml();
@endphp

<div class="{{ $spanClass }}" @wireExtraAttributes($field)>
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel()])
    @endif

    @if($html !== '')
        <div class="wire-rich-content text-sm text-gray-900 dark:text-white">
            {!! $html !!}
        </div>
    @else
        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $field->getPlaceholder() ?? '-' }}</div>
    @endif

    @if($field->hasActions())@include('wire-core::infolists.entry-actions')@endif
</div>
