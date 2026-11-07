@php
    use NyonCode\WireForms\Components\Rating;
    assert($field instanceof Rating);
    // @entangle takes no wire:model modifiers — see CanBeLive::getEntangleModifier().
    $entangleModifier = $field->getEntangleModifier();
    $colorClasses = $field->getColorClasses();
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

<div
    {{-- Body registered as `wireRating`; only per-instance config here. --}}
    x-data="wireRating({
        state: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.' . $entangleModifier : '' }},
        allowHalf: @js($field->isAllowHalf()),
        clearable: @js($field->isClearable()),
        disabled: @js($field->isDisabled()),
    })"
    class="inline-flex items-center gap-0.5"
>
    @for($i = 1; $i <= $field->getMax(); $i++)
        <button
            type="button"
            @click="clickStar({{ $i }}, $event)" data-testid="form-rating-{{ $field->getStatePath() }}-star-{{ $i }}" aria-label="{{ $i }}"
            @mousemove="setHalf({{ $i }}, $event)"
            @mouseleave="hovered = 0"
            :disabled="disabled"
            @class([
                'relative focus:outline-none transition-transform',
                'hover:scale-110' => !$field->isDisabled(),
                'cursor-not-allowed opacity-50' => $field->isDisabled(),
            ])
        >
            {{-- The three states of one position, each drawn by the canonical icon
                 owner rather than an inline <svg> (Rendering § Icons).

                 The glyph is `wire:star`, the framework's own — the same one
                 the read-only RatingColumn draws, so a rating does not look like
                 two different things depending on whether you can edit it.

                 Not Heroicons' star, which exists: all three states are emitted
                 for every position and switched by x-show in the browser, so
                 four <svg> per position and twenty per field at the default max.
                 Heroicons' is 336 B of path against this one's 104 (426 against
                 157 outlined), which measured 14 718 B per field against a
                 10 400 ceiling in FormFieldPayloadTest. The table pays far less
                 for the same choice — its cell render is memoised per distinct
                 rating — but one star beats a cheaper one and a prettier one.

                 x-show rides in as a root attribute, which is what lets an
                 Alpine-bound icon still come out of PHP. --}}
            {{-- Full star --}}
            {!! icon('wire:star', 'w-7 h-7', $colorClasses, '', ['x-show' => 'isFilled(' . $i . ')']) !!}

            {{-- Half star: the empty glyph with the filled one clipped to half its
                 width over it, so the set needs no third icon. currentColor is
                 inherited from the wrapper, which is why the outline and the filled
                 half share the accent colour the way the clipped version did. --}}
            <span x-show="isHalfFilled({{ $i }})" class="relative inline-flex {{ $colorClasses }}">
                {!! icon('wire:star-outline', 'w-7 h-7') !!}
                <span class="absolute inset-y-0 left-0 w-1/2 overflow-hidden">{!! icon('wire:star', 'w-7 h-7') !!}</span>
            </span>

            {{-- Empty star --}}
            {!! icon('wire:star-outline', 'w-7 h-7', 'text-gray-300 dark:text-gray-600', '', ['x-show' => '!isFilled(' . $i . ') && !isHalfFilled(' . $i . ')']) !!}
        </button>
    @endfor

    <span x-show="rating > 0" x-text="rating" class="ml-2 text-sm text-gray-500 dark:text-gray-400 tabular-nums"></span>
</div>

@include('wire-forms::partials.field-wrapper-end')
