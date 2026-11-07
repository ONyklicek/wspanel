@php
    use NyonCode\WireForms\Components\MorphToSelect;

    assert($field instanceof MorphToSelect);

    $wireModifier = $field->getWireModelModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".{$wireModifier}" : '');
    $typeOptions = $field->getTypeOptions();
    $typeStatePath = $field->getTypeStatePath();
    $idStatePath = $field->getIdStatePath();
@endphp

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

<div
    {{-- Body registered as `wireMorphToSelect`; only per-instance config here.
         The "clear the id when the type changes" watcher lives in its init(). --}}
    x-data="wireMorphToSelect({
        selectedType: $wire.entangle('{{ $typeStatePath }}'),
        idStatePath: @js($idStatePath),
        typeOptions: @js(collect($field->getTypes())->mapWithKeys(fn ($type) => [$type->getModelClass() => $type->getOptions()])->all()),
    })"
    class="grid grid-cols-2 gap-3"
>
    {{-- Type selector --}}
    <select
        id="{{ $field->getId() }}_type"
        {{ $wireAttr }}.live="{{ $typeStatePath }}"
        aria-label="{{ $field->getLabel() ? $field->getLabel() . ' — ' . __('Type') : __('Select type') }}" data-testid="form-select-{{ $field->getStatePath() }}-type"
        @if($field->isDisabled()) disabled @endif
        {{-- Match the searchable combobox trigger so all selects share one design. --}}
        @class([
            'block w-full rounded-md border border-gray-300 bg-white shadow-sm text-base sm:text-sm',
            'focus:border-primary-500 focus:ring-1 focus:ring-primary-500',
            'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            'dark:bg-gray-800 dark:border-gray-600 dark:text-white',
        ])
    >
        <option value="">{{ $field->getPlaceholder() ?? __('Select type...') }}</option>
        @foreach($typeOptions as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>

    {{-- ID selector (dynamic based on type) --}}
    <select
        id="{{ $field->getId() }}_id"
        {{ $wireAttr }}="{{ $idStatePath }}"
        aria-label="{{ $field->getLabel() ? $field->getLabel() . ' — ' . __('Record') : __('Select record') }}" data-testid="form-select-{{ $field->getStatePath() }}-record"
        x-bind:disabled="!selectedType"
        @if($field->isDisabled()) disabled @endif
        {{-- Match the searchable combobox trigger so all selects share one design. --}}
        @class([
            'block w-full rounded-md border border-gray-300 bg-white shadow-sm text-base sm:text-sm',
            'focus:border-primary-500 focus:ring-1 focus:ring-primary-500',
            'hover:border-gray-400 dark:hover:border-gray-500 transition-all duration-150',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            'dark:bg-gray-800 dark:border-gray-600 dark:text-white',
        ])
    >
        <option value="">{{ __('Select record...') }}</option>
        <template x-for="[value, label] in Object.entries(idOptions)" :key="value">
            <option :value="value" x-text="label"></option>
        </template>
    </select>
</div>

@include('wire-forms::partials.field-wrapper-end')
