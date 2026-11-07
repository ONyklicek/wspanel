{{-- BooleanColumn cell --}}
@php
    /** @var string $colorClass canonical text color from HasColor::getTextColorClasses */
    /** @var string $iconHtml resolved icon svg */
    /** @var string|null $label */
@endphp

<span class="inline-flex items-center {{ $colorClass }}">
    {!! $iconHtml !!}
    @if($label)<span class="ml-1.5">{{ $label }}</span>@endif
</span>
