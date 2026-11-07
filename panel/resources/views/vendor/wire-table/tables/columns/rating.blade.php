{{-- RatingColumn cell. The column decides what each position is; this partial
     owns every tag, including the three star shapes.

     A half star is the empty one with a filled copy clipped to half its width on
     top, which is why that arm nests and the other two do not — the set needs no
     third icon.

     Mind the whitespace inside the star row: the stars sit in an inline-flex and
     a run of whitespace between two of them is a rendered gap, so the loop and
     its branches are written on one line with the tags touching. The row is
     memoised per distinct rating (renderViewCached), so this template is rendered
     once per rating value on the page, not once per record. --}}
@php
    /** @var array<int, string> $stars one of filled|half|empty per position */
    /** @var string $colorClass filled-star color classes */
    /** @var string $emptyClass empty-star color classes */
    /** @var string $filledIcon resolved filled star svg */
    /** @var string $emptyIcon resolved empty star svg */
    /** @var string $displayValue numeric value shown next to the stars ('' when hidden) */
    /** @var string $label accessible "3 out of 5" text */
@endphp

<span class="inline-flex items-center gap-1" role="img" aria-label="{{ $label }}">
    <span class="inline-flex items-center">@foreach($stars as $star)@if($star === 'filled')<span class="{{ $colorClass }}">{!! $filledIcon !!}</span>@elseif($star === 'half')<span class="relative inline-flex"><span class="{{ $emptyClass }}">{!! $emptyIcon !!}</span><span class="absolute inset-y-0 left-0 w-1/2 overflow-hidden {{ $colorClass }}">{!! $filledIcon !!}</span></span>@else<span class="{{ $emptyClass }}">{!! $emptyIcon !!}</span>@endif@endforeach</span>
    @if($displayValue !== '')
        <span class="text-sm text-gray-600 dark:text-gray-400">{{ $displayValue }}</span>
    @endif
</span>
