@php
    use NyonCode\WireForms\Components\PhoneInput;

    assert($field instanceof PhoneInput);

    // @entangle takes no wire:model modifiers — see CanBeLive::getEntangleModifier().
    $entangleModifier = $field->getEntangleModifier();
    $statePath = $field->getStatePath();
    $hasError = $errors->has($statePath);
    $default = $field->getDefaultCountry();
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

<div
    {{-- Body registered as `wirePhoneInput`; only per-instance config here. --}}
    x-data="wirePhoneInput({
        state: @entangle($field->getWireModelAttribute()){{ $entangleModifier ? '.' . $entangleModifier : '' }},
        countries: @js($field->getCountryOptions()),
        defaultCountry: @js($default?->country),
    })"
    class="flex rounded-md shadow-sm"
>
    <select
        x-model="country"
        id="{{ $field->getId() }}_country"
        data-testid="form-phone-{{ $statePath }}-country"
        aria-label="{{ $field->getLabel() ? $field->getLabel() . ' — ' . __('Country') : __('Country') }}"
        @if($field->isDisabled()) disabled @endif
        @if($field->isReadOnly()) disabled @endif
        @class([
            'rounded-l-md border border-r-0 border-gray-300 bg-gray-50 py-2 pl-3 pr-7 text-base sm:text-sm text-gray-600',
            'focus:border-primary-500 focus:ring-1 focus:ring-primary-500',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            'dark:bg-gray-700 dark:border-gray-600 dark:text-gray-300',
            'border-red-500' => $hasError,
        ])
    >
        @foreach($field->getCountryOptions() as $option)
            <option value="{{ $option['country'] }}">{{ $option['label'] }}</option>
        @endforeach
    </select>

    <input
        type="tel"
        inputmode="tel"
        x-model="national"
        id="{{ $field->getId() }}"
        data-testid="form-phone-{{ $statePath }}-number"
        {!! $field->getExtraInputAttributesHtml() !!}
        placeholder="{{ $field->getPlaceholder() ?? __('Phone number') }}"
        @if($field->isDisabled()) disabled @endif
        @if($field->isReadOnly()) readonly @endif
        @if($field->hasAutofocus()) autofocus @endif
        @if($field->isRequired()) required @endif
        @class([
            'block w-full rounded-r-md border-gray-300 shadow-sm text-base sm:text-sm',
            'focus:border-primary-500 focus:ring-primary-500',
            'placeholder:text-gray-500 dark:placeholder:text-gray-400',
            'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
            'dark:bg-gray-800 dark:border-gray-600 dark:text-white',
            'border-red-500 focus:border-red-500 focus:ring-red-500' => $hasError,
        ])
    />
</div>

@include('wire-forms::partials.field-wrapper-end')
