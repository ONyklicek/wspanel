{{-- TagsColumn cell. Column resolves the tags and their colors; this partial owns markup. --}}
@php
    /** @var array<int, array{label: string, colorClasses: string}> $chips */
    /** @var string $sizeClasses canonical badge sizing from HasSize::getBadgeSizeClasses */
    /** @var int $overflow number of tags hidden by limitList() */
    /** @var string $overflowClasses palette for the "+N" chip */
@endphp

<span class="inline-flex flex-wrap items-center gap-1">
    @foreach($chips as $chip)
        <span @wireEl('table-tag') class="inline-flex items-center {{ $sizeClasses }} {{ $chip['colorClasses'] }} rounded-full font-medium">{{ $chip['label'] }}</span>
    @endforeach

    @if($overflow > 0)
        <span @wireEl('table-tag-overflow') class="inline-flex items-center {{ $sizeClasses }} {{ $overflowClasses }} rounded-full font-medium">+{{ $overflow }}</span>
    @endif
</span>
