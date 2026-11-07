@php

    use NyonCode\WireForms\Components\ColorPicker;

    assert($field instanceof ColorPicker);

    $wireModifier = $field->getWireModelModifier();

    // @entangle takes no wire:model modifiers — see CanBeLive::getEntangleModifier().

    $entangleModifier = $field->getEntangleModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".$wireModifier" : '');
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

{{-- format() decides what the *state* holds; <input type="color"> only ever
     speaks hex, so the swatch is driven by a hex mirror and every write is
     converted back to the configured format. --}}
<div
    {{-- Body registered as `wireColorPicker`; only per-instance config here. --}}
    x-data="wireColorPicker({
        format: @js($field->getFormat()),
        state: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.' . $entangleModifier : '' }},
    })"
    class="flex items-center gap-2"
>
    <input
            type="color"
            {!! $field->getExtraInputAttributesHtml() !!}
            id="{{ $field->getId() }}"
            data-testid="form-color-{{ $field->getStatePath() }}"
            x-model="hex"
            @if($field->isDisabled()) disabled @endif
            class="h-10 w-14 rounded-sm border-gray-300 p-1 cursor-pointer dark:border-gray-600 transition-colors duration-150"
    />
    <input
            type="text"
            autocomplete="off"
            x-model="color"
            data-testid="form-color-{{ $field->getStatePath() }}-hex"
            @if($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
            @if($field->isDisabled()) disabled @endif
            @if($field->isReadOnly()) readonly @endif
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150 dark:bg-gray-800 dark:border-gray-600 dark:text-white text-base sm:text-sm"
    />

    @if($field->getSwatches())
        <div class="mt-2 flex flex-wrap gap-1">
            @foreach($field->getSwatches() as $swatch)
                <button
                    type="button"
                    x-on:click="pick('{{ $swatch }}')" data-testid="form-color-{{ $field->getStatePath() }}-swatch-{{ $swatch }}" aria-label="{{ $swatch }}"
                    @if($field->isDisabled()) disabled @endif
                    class="h-6 w-6 rounded-sm border border-gray-300 dark:border-gray-600 transition-transform hover:scale-110 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
                    style="background-color: {{ $swatch }}"
                    title="{{ $swatch }}"
                ></button>
            @endforeach
        </div>
    @endif
</div>

@include('wire-forms::partials.field-wrapper-end')
