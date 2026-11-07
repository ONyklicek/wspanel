@php

    use NyonCode\WireCore\Foundation\Support\TextControl;
    use NyonCode\WireForms\Components\TextInput;

    assert($field instanceof TextInput);
    $wireModifier = $field->getWireModelModifier();
    $debounceModifier = $field->getDebounceModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".{$wireModifier}" : '') . $debounceModifier;
    $hasAffix = $field->hasAffix();
    // revealable(): a password the user can unmask. Alpine owns the swap so the
    // secret never leaves the input — no roundtrip, no value in the DOM.
    $isRevealable = $field->isRevealable();
@endphp

@include('wire-forms::partials.field-wrapper-start')

@if($isRevealable)
    <div x-data="{ revealed: false }" class="relative">
@endif

@if($hasAffix)
    <div class="flex rounded-md shadow-sm">
        @if($field->getPrefixAction())
            @include('wire-forms::partials.affix-action', [
                'action' => $field->getPrefixAction(),
                'statePath' => $field->getStatePath(),
                'position' => 'left',
            ])
        @elseif($field->getPrefixIcon())
            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-300 text-sm">
                    {!! icon($field->getPrefixIcon(), 'w-4 h-4', 'w-4 h-4') !!}
                </span>
        @elseif($field->getPrefix())
            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-300 text-sm">
                    {{ $field->getPrefix() }}
                </span>
        @endif
        @endif

        <input
                @if($isRevealable)
                    :type="revealed ? 'text' : '{{ $field->getInputType() }}'"
                @else
                    type="{{ $field->getInputType() }}"
                @endif
                id="{{ $field->getId() }}"
        @if($field->submitsNatively()){!! $field->getNativeBindingHtml() !!}@else{{ $wireAttr }}="{{ $field->getWireModelAttribute() }}"@endif
        {!! $field->getExtraInputAttributesHtml() !!}
        @if($field->getPlaceholder())
            placeholder="{{ $field->getPlaceholder() }}"
        @endif
        @if($field->getMinLength())
            minlength="{{ $field->getMinLength() }}"
        @endif
        @if($field->getMaxLength())
            maxlength="{{ $field->getMaxLength() }}"
        @endif
        @if($field->getMinValue() !== null)
            min="{{ $field->getMinValue() }}"
        @endif
        @if($field->getMaxValue() !== null)
            max="{{ $field->getMaxValue() }}"
        @endif
        @if($field->getStep())
            step="{{ $field->getStep() }}"
        @endif
        @if($field->getInputMode())
            inputmode="{{ $field->getInputMode() }}"
        @endif
        @if($field->getAutocomplete())
            autocomplete="{{ $field->getAutocomplete() }}"
        @endif
        @if($field->isDisabled())
            disabled
        @endif
        @if($field->isReadOnly())
            readonly
        @endif
        @if($field->hasAutofocus())
            autofocus
        @endif
        @if($field->isRequired())
            required
        @endif
        @if($field->getDatalistOptions())
            list="{{ $field->getId() }}-datalist"
        @endif
        @if($field->getDynamicMask())
            x-mask:dynamic="{{ $field->getDynamicMask() }}"
        @elseif($field->getMask())
            x-mask="{{ $field->getMask() }}"
        @endif
        @class([
            TextControl::base(),
            TextControl::rejected() => $errors->has($field->getStatePath()),
            'rounded-l-none' => $hasAffix && $field->hasPrefixContent(),
            'rounded-r-none' => $hasAffix && $field->hasSuffixContent(),
            // Room for the reveal button.
            'pr-10' => $isRevealable && ! $hasAffix,
        ])
        />

        @if($isRevealable)
            <button
                    type="button"
                    @click="revealed = ! revealed"
                    data-testid="form-input-{{ $field->getStatePath() }}-reveal"
                    :aria-label="revealed ? @js(__('Hide')) : @js(__('Show'))"
                    :aria-pressed="revealed"
                    tabindex="-1"
                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"
            >
                <span x-show="!revealed">{!! icon('outline:eye', 'w-4 h-4', 'w-5 h-5') !!}</span>
                <span x-show="revealed" x-cloak>{!! icon('outline:eye-slash', 'w-4 h-4', 'w-5 h-5') !!}</span>
            </button>
        @endif

        @if($hasAffix)
            @if($field->getSuffixAction())
                @include('wire-forms::partials.affix-action', [
                    'action' => $field->getSuffixAction(),
                    'statePath' => $field->getStatePath(),
                    'position' => 'right',
                ])
            @elseif($field->getSuffixIcon())
                <span class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-300 text-sm">
                    {!! icon($field->getSuffixIcon(), 'w-4 h-4', 'w-4 h-4') !!}
                </span>
            @elseif($field->getSuffix())
                <span class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-300 text-sm">
                    {{ $field->getSuffix() }}
                </span>
            @endif
    </div>
@endif

@if($isRevealable)
    </div>
@endif

@if($field->getDatalistOptions())
    <datalist id="{{ $field->getId() }}-datalist">
        @foreach($field->getDatalistOptions() as $option)
            <option value="{{ $option }}"></option>
        @endforeach
    </datalist>
@endif

@include('wire-forms::partials.field-wrapper-end')
