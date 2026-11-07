{{-- IconColumn cell --}}
@php
    /** @var string $colorClass canonical text color from HasColor::getTextColorClasses */
    /** @var string $iconHtml resolved icon svg */
@endphp

<span class="inline-flex items-center {{ $colorClass }}">
    {!! $iconHtml !!}
</span>
