{{-- The picture when there is one, the type card otherwise.
     Fills whatever box the caller gives it; `size` scales the contents. --}}
@if ($showsImage)
    <img
        @wireEl('file-thumb')
        src="{{ $url }}"
        @if ($srcset) srcset="{{ $srcset }}" @endif
        alt="{{ $alt ?? $name }}"
        {{-- Both are already known, and without them a grid of forty tiles
             reflows forty times as the images land. --}}
        @if ($width) width="{{ $width }}" @endif
        @if ($height) height="{{ $height }}" @endif
        loading="lazy"
        decoding="async"
        {{-- The average colour, so the grid has its shape and its rough colours
             before a single image has arrived. --}}
        @if ($placeholder) style="background-color: {{ $placeholder }}" @endif
        {{ $attributes->merge(['class' => 'h-full w-full object-cover']) }}
    >
@else
    <span
        {{ $attributes->merge(['class' => "flex h-full w-full flex-col items-center justify-center gap-1 {$colorClasses}"]) }}
        title="{{ $name }}"
    >
        @if ($scale['icon'] !== '')
            {!! icon($kind->icon(), $scale['icon'].' opacity-60') !!}
        @endif

        <span class="{{ $scale['text'] }} font-mono font-semibold tracking-wide">{{ $wordmark }}</span>
    </span>
@endif
