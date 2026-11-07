@php
    use NyonCode\WireForms\Components\Textarea;

    assert($field instanceof Textarea);

    $wireModifier = $field->getWireModelModifier();
    $debounceModifier = $field->getDebounceModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".{$wireModifier}" : '') . $debounceModifier;
@endphp

@include('wire-forms::partials.field-wrapper-start')

<textarea
        id="{{ $field->getId() }}"
        {{ $wireAttr }}="{{ $field->getWireModelAttribute() }}"
        {!! $field->getExtraInputAttributesHtml() !!}
        rows="{{ $field->getRows() }}"

        @if($field->getCols())
            cols="{{ $field->getCols() }}"
        @endif

        @if($field->getPlaceholder())
            placeholder="{{ $field->getPlaceholder() }}"
        @endif
@if($field->getMinLength())
    minlength="{{ $field->getMinLength() }}"
@endif
@if($field->getMaxLength())
    maxlength="{{ $field->getMaxLength() }}"
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
@if($field->getSpellcheck() !== null)
    spellcheck="{{ $field->getSpellcheck() ? 'true' : 'false' }}"
@endif
@if($field->isAutosize())
    x-data="{ resize() { $el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px' } }" x-init="resize()" @input="resize()"
@endif
@class([
    'block w-full rounded-md border-gray-300 shadow-sm',
    'focus:border-primary-500 focus:ring-primary-500',
    'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
    'dark:bg-gray-800 dark:border-gray-600 dark:text-white text-base sm:text-sm',
    'border-red-500 focus:border-red-500 focus:ring-red-500' => $errors->has($field->getStatePath()),
])
>
</textarea>

@include('wire-forms::partials.field-wrapper-end')
