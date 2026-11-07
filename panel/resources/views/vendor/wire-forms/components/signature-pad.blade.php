@php
    use NyonCode\WireForms\Components\SignaturePad;

    assert($field instanceof SignaturePad);

    // @entangle takes no wire:model modifiers — see CanBeLive::getEntangleModifier().
    $entangleModifier = $field->getEntangleModifier();
    $statePath = $field->getStatePath();
    $locked = $field->isDisabled() || $field->isReadOnly();
    $imageUrl = $field->getImageUrl(data_get($this, $statePath));
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

<div
    {{-- Body registered as `wireSignaturePad`; only per-instance config here. --}}
    x-data="wireSignaturePad({
        state: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.' . $entangleModifier : '' }},
        penColor: @js($field->getPenColor()),
        penWidth: @js($field->getPenWidth()),
        backgroundColor: @js($field->getBackgroundColor()),
        disabled: @js($locked),
        imageUrl: @js($imageUrl),
    })"
    @class([
        'relative overflow-hidden rounded-md border bg-white dark:bg-gray-800',
        'border-gray-300 dark:border-gray-600' => ! $errors->has($statePath),
        'border-red-500' => $errors->has($statePath),
        'opacity-50' => $locked,
    ])
>
    <canvas
        x-ref="canvas"
        id="{{ $field->getId() }}"
        data-testid="form-signature-{{ $statePath }}-canvas"
        role="img"
        aria-label="{{ $field->getLabel() ?? __('wire-forms::fields.signature.hint') }}"
        style="height: {{ $field->getHeight() }}px"
        @pointerdown="start($event)"
        @pointermove="draw($event)"
        @pointerup="stop()"
        @pointercancel="stop()"
        @class([
            'block w-full touch-none',
            'cursor-crosshair' => ! $locked,
            'cursor-not-allowed' => $locked,
        ])
    ></canvas>

    {{-- The baseline a signature is written on, and the prompt above it. Both
         sit under the canvas so a stroke is never drawn over them. --}}
    <div class="pointer-events-none absolute inset-x-6 bottom-6 border-b border-dashed border-gray-300 dark:border-gray-600"></div>
    <span
        x-show="empty"
        x-cloak
        class="pointer-events-none absolute inset-x-0 bottom-8 text-center text-sm text-gray-500 dark:text-gray-400"
    >{{ $field->getPlaceholder() ?? __('wire-forms::fields.signature.hint') }}</span>

    @unless($locked)
        <button
            type="button"
            x-show="! empty"
            x-cloak
            @click="clear()"
            data-testid="form-signature-{{ $statePath }}-clear"
            class="absolute right-2 top-2 rounded-md bg-white/80 px-2 py-1 text-xs text-gray-500 shadow-sm hover:text-gray-700 dark:bg-gray-900/70 dark:text-gray-400 dark:hover:text-gray-200"
        >{{ __('wire-forms::fields.signature.clear') }}</button>
    @endunless
</div>

@include('wire-forms::partials.field-wrapper-end')
