{{-- BadgeColumn cell --}}
@php
    /** @var string $sizeClasses canonical badge sizing from HasSize::getBadgeSizeClasses */
    /** @var string $colorClasses canonical soft palette from HasColor::getBadgeColorClasses */
    /** @var string $iconHtml resolved icon svg (may be empty) */
    /** @var string $displayValue formatted (already-prepared) cell value */
    /** @var bool $isHtml whether the column opted into raw HTML via ->html() */
@endphp

<span @wireEl('table-badge') class="inline-flex items-center {{ $sizeClasses }} {{ $colorClasses }} rounded-full font-medium">
    {!! $iconHtml !!}@if($isHtml ?? false){!! $displayValue !!}@else{{ $displayValue }}@endif
</span>
