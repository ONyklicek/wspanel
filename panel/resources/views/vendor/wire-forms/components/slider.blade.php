@php
    use NyonCode\WireForms\Components\Slider;

    assert($field instanceof Slider);

    // @entangle takes no wire:model modifiers — see CanBeLive::getEntangleModifier().

    $entangleModifier = $field->getEntangleModifier();
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

@once
<style>
    .wf-slider {
        -webkit-appearance: none; appearance: none;
        width: 100%; height: .5rem; border-radius: 9999px;
        --wf-track: #e5e7eb; cursor: pointer; outline: none;
    }
    .dark .wf-slider { --wf-track: #374151; }
    .wf-slider:disabled { opacity: .5; cursor: not-allowed; }
    .wf-slider::-webkit-slider-thumb {
        -webkit-appearance: none; appearance: none;
        width: 1.1rem; height: 1.1rem; border-radius: 9999px;
        background: var(--wf-fill); border: 2px solid #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,.3); cursor: pointer;
    }
    .wf-slider::-moz-range-thumb {
        width: 1.1rem; height: 1.1rem; border-radius: 9999px;
        background: var(--wf-fill); border: 2px solid #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,.3); cursor: pointer;
    }
    .dark .wf-slider::-webkit-slider-thumb { border-color: #1f2937; }
    .dark .wf-slider::-moz-range-thumb { border-color: #1f2937; }
    .wf-slider:focus-visible::-webkit-slider-thumb {
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--wf-fill) 35%, transparent);
    }
    .wf-slider:focus-visible::-moz-range-thumb {
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--wf-fill) 35%, transparent);
    }
</style>
@endonce

<div
    {{-- Body registered as `wireSlider`; only per-instance config here. --}}
    x-data="wireSlider({
        state: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.' . $entangleModifier : '' }},
        min: @js($field->getMin()),
        max: @js($field->getMax()),
    })"
    class="space-y-2"
>
    <div class="flex items-center gap-3">
        <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums shrink-0">
            {{ $field->getPrefix() }}{{ $field->getMin() }}{{ $field->getSuffix() }}
        </span>

        <input
            {!! $field->getExtraInputAttributesHtml() !!}
            type="range"
            x-model.number="value"
            data-testid="form-slider-{{ $field->getStatePath() }}"
            :style="{ background: trackBackground }"
            style="--wf-fill: {{ $field->getColor() }}"
            min="{{ $field->getMin() }}"
            max="{{ $field->getMax() }}"
            step="{{ $field->getStep() }}"
            @if($field->isDisabled()) disabled @endif
            class="wf-slider"
        />

        <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums shrink-0">
            {{ $field->getPrefix() }}{{ $field->getMax() }}{{ $field->getSuffix() }}
        </span>
    </div>

    @if($field->isShowValue())
        <div class="flex justify-center">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-primary-100 dark:bg-primary-900 text-primary-800 dark:text-primary-200 text-sm font-medium tabular-nums">
                @if($field->getPrefix())<span class="mr-0.5">{{ $field->getPrefix() }}</span>@endif
                <span x-text="value"></span>
                @if($field->getSuffix())<span class="ml-0.5">{{ $field->getSuffix() }}</span>@endif
            </span>
        </div>
    @endif
</div>

@include('wire-forms::partials.field-wrapper-end')
