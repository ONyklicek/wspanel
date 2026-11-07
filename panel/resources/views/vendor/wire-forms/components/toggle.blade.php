@php /** @var \NyonCode\WireForms\Components\Toggle $field */
    $wireModifier = $field->getWireModelModifier();
    // @entangle takes no wire:model modifiers — see CanBeLive::getEntangleModifier().
    $entangleModifier = $field->getEntangleModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".{$wireModifier}" : '');
@endphp

@include('wire-forms::partials.field-wrapper-start')

    <div
        {{-- Honor live(): mirror the other entangle-based fields (slider, tags, …). --}}
        x-data="{ enabled: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.' . $entangleModifier : '' }} }"
        class="flex items-center gap-3"
    >
        <button
            {!! $field->getExtraInputAttributesHtml() !!}
            type="button"
            role="switch"
            :aria-checked="enabled ? 'true' : 'false'"
            @click="enabled = !enabled"
            data-testid="form-toggle-{{ $field->getStatePath() }}"
            aria-label="{{ $field->getLabel() ?: $field->getStatePath() }}"
            @if($field->isDisabled()) disabled @endif
            :class="enabled ? @js($field->getOnColorClasses()) : @js($field->getOffColorClasses())"
            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
        >
            <span
                :class="enabled ? 'translate-x-5' : 'translate-x-0'"
                class="pointer-events-none inline-flex h-5 w-5 transform items-center justify-center rounded-full bg-white text-gray-700 shadow ring-0 transition duration-200 ease-in-out"
            >
                @if($field->getOnIcon())
                    <span x-show="enabled" x-cloak>{!! icon($field->getOnIcon(), 'w-4 h-4', 'h-3 w-3') !!}</span>
                @endif
                @if($field->getOffIcon())
                    <span x-show="!enabled" x-cloak>{!! icon($field->getOffIcon(), 'w-4 h-4', 'h-3 w-3') !!}</span>
                @endif
            </span>
        </button>

        @if($field->getOnLabel() || $field->getOffLabel())
            <span class="text-sm text-gray-700 dark:text-gray-300" x-text="enabled ? @js($field->getOnLabel()) : @js($field->getOffLabel())"></span>
        @endif
    </div>

@include('wire-forms::partials.field-wrapper-end')
