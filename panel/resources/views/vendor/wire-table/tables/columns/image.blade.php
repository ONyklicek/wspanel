{{-- ImageColumn cell --}}
@php
    /** @var array<int, string> $urls resolved image URLs (already capped by stackLimit) */
    /** @var int $overflow how many images the stack limit hid */
    /** @var bool $stacked */
    /** @var string $sizeClasses */
    /** @var string $shapeClasses */
    /** @var string $ringClasses */

    // Stacked images overlap and need a ring to separate them from each other;
    // an unstacked gallery just wraps. A ring set via ring() still wins.
    $stackRing = $ringClasses !== '' ? $ringClasses : 'ring-2 ring-white dark:ring-gray-800';
    $imageClasses = trim($sizeClasses.' '.$shapeClasses.' '.($stacked ? $stackRing : $ringClasses).' object-cover');
@endphp

@if(count($urls) === 1 && ! $stacked)
    <img src="{{ $urls[0] }}" alt="" class="{{ $imageClasses }}">
@else
    <div @class(['flex items-center', '-space-x-2' => $stacked, 'flex-wrap gap-2' => ! $stacked])>
        @foreach($urls as $url)
            <img src="{{ $url }}" alt="" class="{{ $imageClasses }}">
        @endforeach

        @if($overflow > 0)
            {{-- Summarises the images stackLimit hid, sized to match the stack. --}}
            <span
                data-testid="image-stack-overflow" @wireEl('image-stack-overflow')
                class="{{ $sizeClasses }} {{ $shapeClasses }} {{ $stackRing }} inline-flex items-center justify-center bg-gray-100 dark:bg-gray-700 text-xs font-medium text-gray-600 dark:text-gray-300"
            >+{{ $overflow }}</span>
        @endif
    </div>
@endif
